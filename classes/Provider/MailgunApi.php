<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Provider;

use Grav\Plugin\EmailMailgun\Http\Http;

/**
 * The four things this plugin asks Mailgun's API.
 *
 * Documentation, read 2026-09-05, from the reference pages under
 * `documentation.mailgun.com/docs/mailgun/api-reference/send/mailgun/`:
 *
 * | Call | Endpoint |
 * | --- | --- |
 * | list a domain's webhooks | `GET /v3/domains/{domain}/webhooks` |
 * | create one | `POST /v3/domains/{domain}/webhooks` with `id` and `url` |
 * | replace the URLs on one | `PUT /v3/domains/{domain}/webhooks/{type}` |
 * | read the signing key | `GET /v5/accounts/http_signing_key` |
 * | a domain's DNS records | `GET /v4/domains/{domain}` |
 *
 * There is a v4 webhooks endpoint that attaches one URL to several event types
 * in a single call. It is not used here: v3 is what every account has had for a
 * decade, the calls are cheap, and one refusal per event type is a message
 * naming the event type that was refused rather than one naming six.
 *
 * ## Regions
 *
 * Mailgun runs two entirely separate installations, `api.mailgun.net` and
 * `api.eu.mailgun.net`, and an account on one does not exist on the other. A
 * key used against the wrong one answers 401, which reads exactly like a bad
 * key — so the region the plugin already sends with is the region every call
 * here uses, and there is no guessing.
 *
 * ## What it does not do
 *
 * It does not delete a webhook. A merchant who wants one gone should remove it
 * in Mailgun's own dashboard, where they can see what else is pointed at it;
 * this plugin removing a webhook it did not create is the sort of thing that
 * takes a store's other integration down at four on a Friday.
 *
 * It does not store the API key. The key is a config value the merchant pasted
 * and this class is handed it per call.
 */
final class MailgunApi
{
    /** Their two installations. `region` in the plugin's config picks one. */
    public const US = 'https://api.mailgun.net';
    public const EU = 'https://api.eu.mailgun.net';

    /** The zone a Mailgun return-path CNAME points into. */
    public const ZONE = 'mailgun.org';

    public function __construct(private readonly Http $http)
    {
    }

    /** The API host for a region, defaulting to the US one as the config does. */
    public static function base(string $region): string
    {
        return strtolower(trim($region)) === 'eu' ? self::EU : self::US;
    }

    /**
     * Every webhook on a domain, as `type => list of URLs`.
     *
     * @return array{ok: bool, webhooks: array<string, list<string>>, message: string}
     */
    public function webhooks(string $apiKey, string $region, string $domain): array
    {
        $answer = $this->http->get(
            self::base($region) . '/v3/domains/' . rawurlencode($domain) . '/webhooks',
            self::auth($apiKey)
        );

        $refusal = self::refusal($answer);
        if ($refusal !== null) {
            return ['ok' => false, 'webhooks' => [], 'message' => $refusal];
        }

        return ['ok' => true, 'webhooks' => self::urlsIn($answer['body'] ?? []), 'message' => ''];
    }

    /**
     * Point one event type at a URL, without disturbing what is already there.
     *
     * Mailgun allows three URLs per event type and `POST` refuses a type that
     * already has one, so the existing list decides which call is made. That is
     * what makes the setup button safe to press twice.
     *
     * A URL already on the type that sits under this store's endpoint but is
     * not the address being registered is this store's own from before the
     * secret changed, and it is replaced rather than joined. Nobody else's
     * address can be under that endpoint, the old one answers 404, and the cap
     * of three is too small to spend a slot on a dead address.
     *
     * @param list<string> $existing the URLs already on this event type
     * @return array{ok: bool, message: string, changed: bool, repointed: bool}
     */
    public function pointAt(
        string $apiKey,
        string $region,
        string $domain,
        string $type,
        string $url,
        array $existing,
    ): array {
        if (\in_array($url, $existing, true)) {
            return ['ok' => true, 'message' => '', 'changed' => false, 'repointed' => false];
        }

        $endpoint = self::endpointOf($url);
        $others = [];
        $repointed = false;

        foreach ($existing as $registered) {
            if ($endpoint !== '' && str_starts_with($registered, $endpoint)) {
                $repointed = true;

                continue;
            }

            $others[] = $registered;
        }

        $base = self::base($region) . '/v3/domains/' . rawurlencode($domain) . '/webhooks';

        if ($existing === []) {
            $answer = $this->http->postForm($base, ['id' => $type, 'url' => $url], self::auth($apiKey));
        } else {
            // The cap only bites when this is a new address beside somebody
            // else's. Replacing this store's own leaves the count where it was.
            if (!$repointed && \count($existing) >= 3) {
                return [
                    'ok' => false,
                    'changed' => false,
                    'repointed' => false,
                    'message' => sprintf(
                        'Mailgun allows three webhook URLs per event type and "%s" already has three. '
                        . 'Remove one in Mailgun under Sending, Webhooks, then press the button again.',
                        $type
                    ),
                ];
            }

            $answer = $this->http->putForm(
                $base . '/' . rawurlencode($type),
                ['url' => array_values(array_merge($others, [$url]))],
                self::auth($apiKey)
            );
        }

        $refusal = self::refusal($answer);

        return $refusal === null
            ? ['ok' => true, 'message' => '', 'changed' => true, 'repointed' => $repointed]
            : ['ok' => false, 'message' => $refusal, 'changed' => false, 'repointed' => false];
    }

    /**
     * The address without its secret: everything up to and including the last
     * slash. Two addresses that share it belong to the same store.
     */
    private static function endpointOf(string $url): string
    {
        $cut = strrpos($url, '/');

        return $cut === false || $cut < \strlen('https://x/') ? '' : substr($url, 0, $cut + 1);
    }

    /**
     * The account's HTTP webhook signing key.
     *
     * A domain sending key cannot read this — it is account-level — so a
     * refusal here is ordinary and is not a reason to call the whole setup a
     * failure. The caller says so and asks the merchant to paste the key.
     *
     * @return array{ok: bool, key: string, message: string}
     */
    public function signingKey(string $apiKey, string $region): array
    {
        $answer = $this->http->get(self::base($region) . '/v5/accounts/http_signing_key', self::auth($apiKey));

        $refusal = self::refusal($answer);
        if ($refusal !== null) {
            return ['ok' => false, 'key' => '', 'message' => $refusal];
        }

        $key = trim((string)(($answer['body'] ?? [])['http_signing_key'] ?? ''));

        return $key === ''
            ? ['ok' => false, 'key' => '', 'message' => 'Mailgun answered without a signing key in it']
            : ['ok' => true, 'key' => $key, 'message' => ''];
    }

    /**
     * What a sending domain's DNS is meant to say, as far as Mailgun will tell.
     *
     * `sending_dns_records` carries the records the account is expecting to
     * find: a `TXT` at `<selector>._domainkey.<domain>` for DKIM, and a `CNAME`
     * pointing into Mailgun's own zone for the return path. Reading the records
     * rather than a named field is what makes this survive their renaming one,
     * and it is the only way to learn a selector at all — Mailgun's default is
     * `mailgun` or `smtp` depending on how old the domain is, and an account
     * using automatic sender security rotates it.
     *
     * Never throws, and the empty answer is a real answer meaning "could not
     * say".
     *
     * @return array{selectors?: list<string>, return_paths?: list<string>}
     */
    public function domainFacts(string $apiKey, string $region, string $domain): array
    {
        $domain = strtolower(trim(rtrim($domain, '.')));
        if (trim($apiKey) === '' || $domain === '') {
            return [];
        }

        try {
            $answer = $this->http->get(
                self::base($region) . '/v4/domains/' . rawurlencode($domain),
                self::auth($apiKey)
            );
        } catch (\Throwable) {
            return [];
        }

        if (self::refusal($answer) !== null) {
            return [];
        }

        $records = ($answer['body'] ?? [])['sending_dns_records'] ?? null;
        if (!\is_array($records)) {
            return [];
        }

        $selectors = [];
        $returnPaths = [];

        foreach ($records as $record) {
            if (!\is_array($record)) {
                continue;
            }

            $name = strtolower(trim(rtrim((string)($record['name'] ?? ''), '.')));
            $value = strtolower(trim(rtrim((string)($record['value'] ?? ''), '.')));
            $kind = strtoupper(trim((string)($record['record_type'] ?? '')));

            if ($name === '') {
                continue;
            }

            if (preg_match('/^([a-z0-9_-]+)\._domainkey\b/', $name, $matches) === 1) {
                $selectors[] = $matches[1];

                continue;
            }

            if ($kind === 'CNAME' && ($value === self::ZONE || str_ends_with($value, '.' . self::ZONE))) {
                $returnPaths[] = $name;
            }
        }

        return [
            'selectors' => array_values(array_unique($selectors)),
            'return_paths' => array_values(array_unique($returnPaths)),
        ];
    }

    // ------------------------------------------------------------- internals

    /**
     * HTTP basic, user `api`, the key as the password, in a header.
     *
     * @return array<string, string>
     */
    private static function auth(string $apiKey): array
    {
        return ['Authorization' => 'Basic ' . base64_encode('api:' . trim($apiKey))];
    }

    /**
     * Mailgun's own sentence about why it said no, or null when it said yes.
     *
     * Their error bodies are `{"message": "..."}` on every one of these
     * endpoints, and their sentences are good ones — "Domain not found",
     * "Invalid private key" — so they are passed through to the merchant rather
     * than replaced with a status code.
     *
     * @param array{status: int, body: array<string, mixed>|null, error: string} $answer
     */
    private static function refusal(array $answer): ?string
    {
        if ($answer['status'] === 0) {
            return $answer['error'] !== ''
                ? 'Mailgun could not be reached: ' . $answer['error']
                : 'Mailgun could not be reached';
        }

        if ($answer['status'] >= 200 && $answer['status'] < 300) {
            return null;
        }

        $said = trim((string)(($answer['body'] ?? [])['message'] ?? ''));

        if ($answer['status'] === 401) {
            return 'Mailgun refused the API key. It has to be an account key with permission to manage '
                . 'webhooks, and it has to belong to the same region the plugin is sending through.';
        }

        return $said !== ''
            ? 'Mailgun refused it: ' . $said
            : sprintf('Mailgun answered %d', $answer['status']);
    }

    /**
     * Their `{webhooks: {delivered: {urls: [...]}}}` as `type => urls`.
     *
     * An event type with nothing on it is absent from their answer on some
     * accounts and present with an empty list on others, and both mean the
     * same thing here.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, list<string>>
     */
    private static function urlsIn(?array $body): array
    {
        $webhooks = \is_array($body['webhooks'] ?? null) ? $body['webhooks'] : [];
        $out = [];

        foreach ($webhooks as $type => $hook) {
            if (!\is_array($hook)) {
                continue;
            }

            $urls = [];
            foreach (\is_array($hook['urls'] ?? null) ? $hook['urls'] : [] as $url) {
                $url = trim((string)$url);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }

            $out[strtolower(trim((string)$type))] = $urls;
        }

        return $out;
    }
}
