<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\SetupResult;
use Grav\Plugin\Email\Providers\WebhookSetup;

/**
 * "Set up in Mailgun", which is the one button a merchant should have to press.
 *
 * A merchant who has already pasted an API key into this plugin should not then
 * have to find Mailgun's dashboard, work out which of the things called
 * "webhooks" is the right one, add the same URL six times under six event
 * names, and then find the signing key on an entirely different page under a
 * profile menu. Mailgun's API does all of it, so this does all of it.
 *
 * ## What it registers, and what it deliberately does not
 *
 * Six of Mailgun's eight webhook types, for the five events the contract
 * knows: `delivered`, `permanent_fail` and `temporary_fail` (which are both
 * bounces and are told apart in the payload, not by their names), `complained`,
 * `opened` and `clicked`.
 *
 * Not `accepted`, and not `unsubscribed`. `accepted` fires the moment Mailgun
 * takes a message and says nothing about whether it arrived, so a store
 * registering it would be generating traffic to ignore. `unsubscribed` is
 * Mailgun's own unsubscribe list rather than the store's, and a store that
 * treats it as one of its own would be unsubscribing people who never asked it
 * to.
 *
 * ## Pressing the button twice
 *
 * The existing webhooks are read first. An event type already pointed at this
 * URL is left alone; one pointed somewhere else gains this URL beside what is
 * there, because a store's other integration is not this plugin's to break.
 * Mailgun caps a type at three URLs, and a type already holding three is a
 * refusal naming that type rather than a silent failure.
 *
 * ## Pressing it after the secret changed
 *
 * A new secret is a new address, so the URL Mailgun holds is posting at one
 * that answers 404 and the store looks as though nothing is registered. It is
 * still recognisably this store's: it sits under the same endpoint and only the
 * secret on the end is different. So on each event type it is replaced by the
 * new address rather than joined by it, which is also what keeps the cap of
 * three from being spent on an address nothing answers.
 *
 * ## The signing key
 *
 * Read back from `GET /v5/accounts/http_signing_key` and handed to the closure
 * this class was built with, which is what writes it into the plugin's config.
 * Doing it here is the difference between a merchant pressing one button and a
 * merchant hunting through a profile menu for a string that looks exactly like
 * the API key they already pasted and is not.
 *
 * That call needs an account key. A domain sending key can manage that domain's
 * webhooks and cannot read the account's signing key, so a refusal there is
 * ordinary: the webhooks are still made, and the message says plainly that the
 * signing key has to be pasted by hand or an account key used instead.
 */
final class MailgunSetup implements WebhookSetup
{
    /**
     * The contract's event names to Mailgun's webhook types.
     *
     * A bounce is two of theirs, which is the whole reason this is a map to
     * lists rather than a map to strings.
     *
     * @var array<string, list<string>>
     */
    public const TYPES = [
        Event::DELIVERED => ['delivered'],
        Event::BOUNCED => ['permanent_fail', 'temporary_fail'],
        Event::COMPLAINED => ['complained'],
        Event::OPENED => ['opened'],
        Event::CLICKED => ['clicked'],
    ];

    /** @var (\Closure(string): mixed)|null */
    private ?\Closure $keeper;

    /**
     * @param (\Closure(string): mixed)|null $keeper what to do with the signing
     *        key once it has been read: the plugin hands over something that
     *        writes it into its own config, and whatever it answers is ignored.
     *        Null means nobody is keeping it, and the message says so.
     */
    public function __construct(
        private readonly MailgunApi $api,
        ?\Closure $keeper = null,
    ) {
        $this->keeper = $keeper;
    }

    public function permissionsNeeded(): string
    {
        return 'The API key has to be an account key with permission to manage webhooks — a domain sending key '
            . 'cannot create them. Reading the webhook signing key back needs an account key as well. Both are '
            . 'under your profile menu in Mailgun, on the API Security page.';
    }

    public function create(string $url, array $events, array $config): SetupResult
    {
        $apiKey = trim((string)($config['api_key'] ?? ''));
        $domain = strtolower(trim((string)($config['domain'] ?? '')));
        $region = (string)($config['region'] ?? 'us');
        $url = trim($url);

        if ($apiKey === '') {
            return SetupResult::failed('There is no Mailgun API key in this plugin\'s settings yet.');
        }

        if ($domain === '') {
            return SetupResult::failed('There is no Mailgun sending domain in this plugin\'s settings yet.');
        }

        if ($url === '') {
            return SetupResult::failed('There is no webhook address to register yet.');
        }

        $wanted = self::typesFor($events);
        if ($wanted === []) {
            return SetupResult::failed('None of the events asked for is one Mailgun can report.');
        }

        $existing = $this->api->webhooks($apiKey, $region, $domain);
        if (!$existing['ok']) {
            return SetupResult::failed($existing['message']);
        }

        $added = 0;
        $repointed = 0;
        foreach ($wanted as $type) {
            $answer = $this->api->pointAt(
                $apiKey,
                $region,
                $domain,
                $type,
                $url,
                $existing['webhooks'][$type] ?? []
            );

            if (!$answer['ok']) {
                return SetupResult::failed($answer['message']);
            }

            $added += $answer['changed'] ? 1 : 0;
            $repointed += $answer['repointed'] ? 1 : 0;
        }

        return SetupResult::ok($this->done($added, $repointed, \count($wanted), $domain, $apiKey, $region));
    }

    // ------------------------------------------------------------- internals

    /**
     * Mailgun's webhook types for the events asked for, in a fixed order.
     *
     * An event Mailgun cannot report is dropped rather than refused: the caller
     * hands over what a store acts on, and a store acting on something this
     * provider never reports is a fact about the store, not an error.
     *
     * @param list<string> $events
     * @return list<string>
     */
    public static function typesFor(array $events): array
    {
        $types = [];

        foreach (array_keys(self::TYPES) as $event) {
            if (\in_array($event, $events, true)) {
                foreach (self::TYPES[$event] as $type) {
                    $types[] = $type;
                }
            }
        }

        return $types;
    }

    /**
     * The sentence a merchant reads when the button has finished.
     *
     * It says what changed rather than "done", because a button that says
     * "done" when it did nothing is a button nobody trusts the second time.
     */
    private function done(int $added, int $repointed, int $total, string $domain, string $apiKey, string $region): string
    {
        if ($added === 0) {
            $webhooks = sprintf('All %d Mailgun webhooks for %s were already pointed here.', $total, $domain);
        } elseif ($added === $total) {
            $webhooks = sprintf('All %d Mailgun webhooks for %s now point here.', $total, $domain);
        } else {
            $webhooks = sprintf(
                '%d of the %d Mailgun webhooks for %s were added; the rest were already pointed here.',
                $added,
                $total,
                $domain
            );
        }

        if ($repointed > 0) {
            $webhooks = 'Mailgun had this store\'s webhook registered with an older secret. It now points at this '
                . 'address. ' . $webhooks;
        }

        if ($this->keeper === null) {
            return $webhooks . ' Paste the HTTP webhook signing key into this plugin\'s settings to have the '
                . 'events verified.';
        }

        $key = $this->api->signingKey($apiKey, $region);

        if (!$key['ok']) {
            return $webhooks . ' The webhook signing key could not be read, so paste it here by hand: it is in '
                . 'Mailgun under your profile menu, on the API Security page. (' . $key['message'] . ')';
        }

        ($this->keeper)($key['key']);

        return $webhooks . ' The webhook signing key was read from Mailgun and saved, so the events will be '
            . 'verified as they arrive.';
    }
}
