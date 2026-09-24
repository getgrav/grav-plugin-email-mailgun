<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Provider;

use Grav\Plugin\Email\Providers\Inbound\Address;
use Grav\Plugin\Email\Providers\Inbound\InboundAttachment;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundPayload;
use Grav\Plugin\Email\Providers\Inbound\InboundReceiver;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\Inbound\InboundUpload;
use Grav\Plugin\Email\Providers\Inbound\MimeParser;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\EmailMailgun\Http\CurlHttp;
use Grav\Plugin\EmailMailgun\Http\Http;

/**
 * Mail sent to a Mailgun receiving domain, read.
 *
 * Documentation, read 2026-09-23, under
 * `documentation.mailgun.com/docs/mailgun/user-manual/`:
 * `receive-forward-store/receive-http` (the forward fields and the `mime`
 * variant), `receive-forward-store/route-actions`,
 * `receive-forward-store/storing-and-retrieving-messages` (the store
 * notification, `message-url`, `Accept: message/rfc2822`, the spam headers)
 * and `webhooks/securing-webhooks` (the signature).
 *
 * ## Three ways a route delivers
 *
 * A Mailgun route runs `forward("https://…")` or `store(notify="https://…")`
 * on mail that matches it, and this reads all three results:
 *
 * - **Parsed forward.** A form post: `recipient` (the SMTP `RCPT TO`), `sender`
 *   (`MAIL FROM`), `from`, `subject`, `body-plain`, `body-html`,
 *   `stripped-text`, `stripped-signature`, `message-headers` (a JSON list of
 *   `[name, value]` pairs in their original order) and, when the message has
 *   attachments, `attachment-count`, `attachment-1` … `attachment-n` as files
 *   and `content-id-map`. With attachments it is `multipart/form-data`, so the
 *   request body is empty and everything is read from `parsedBody` and
 *   `files`, never from `body`.
 * - **Raw forward.** When the forward URL ends in `mime`, Mailgun sends
 *   `body-mime` (the whole message) instead of `body-plain` and `body-html`,
 *   as `application/x-www-form-urlencoded`. That is read with
 *   {@see InboundMessage::fromMime()}, and the form fields add the envelope.
 * - **Store and notify.** `store(notify=…)` keeps the message on Mailgun for up
 *   to three days and posts its fields plus a `message-url`. {@see parse()}
 *   answers an {@see InboundReference} for it, and {@see fetch()} downloads the
 *   message later from the consumer's worker with `Accept: message/rfc2822`,
 *   which answers JSON carrying `body-mime`. The download uses this plugin's
 *   `api_key` and only ever goes to a `mailgun.net` host over https, so a
 *   forged URL cannot collect the key.
 *
 * ## The signature
 *
 * The same HMAC as the delivery events, but in the form fields rather than a
 * JSON `signature` block: HMAC-SHA256 of `timestamp . token`, keyed with the
 * HTTP webhook signing key this plugin already keeps as `signing_key` for its
 * delivery reports, compared with `signature` (and `parent-signature`, for a
 * subaccount). Only those three fields are read before the check passes. The
 * timestamp must be within {@see MailgunReports::TOLERANCE} (900 seconds) of
 * this server's clock, and each token is accepted once: {@see ReplayCache}
 * keeps it for twice the tolerance, which covers every moment its timestamp
 * could still pass.
 *
 * ## Spam and sender checks
 *
 * With the receiving domain's spam filter set to "mark spam with MIME headers",
 * Mailgun adds `X-Mailgun-Sscore`, `X-Mailgun-Sflag`, `X-Mailgun-Spf` and
 * `X-Mailgun-Dkim-Check-Result`, which give `spamScore` and `auth` where the
 * message's own `Authentication-Results` did not.
 *
 * ## Answers Mailgun acts on
 *
 * 200 is done. 406 tells Mailgun to give up. Anything else is retried for eight
 * hours (10 and 15 minutes, then 30 minutes, 1, 2 and 4 hours).
 */
final class MailgunInbound implements InboundReceiver
{
    public const KEY = 'mailgun';

    /**
     * Mailgun accepts messages up to 25 MB. Attachments arrive as files at
     * their own size; a raw forward sends the MIME url-encoded, which grows it
     * by up to half again, so 50 MB leaves room for either.
     */
    public const MAX_BYTES = 50 * 1024 * 1024;

    /** How much a stored-message download may read: a 25 MB message, JSON-escaped. */
    public const FETCH_MAX_BYTES = 80 * 1024 * 1024;

    /** Seconds a stored-message download may take. */
    public const FETCH_TIMEOUT = 60;

    /** @var \Closure(): int */
    private \Closure $clock;

    private ReplayCache $replay;

    /**
     * @param array<string, mixed>   $defaults this plugin's own config, used where the caller's leaves a key out
     * @param (\Closure(): int)|null $clock    the current Unix time; tests pass their own
     */
    public function __construct(
        private readonly array $defaults = [],
        ?ReplayCache $replay = null,
        private readonly ?Http $http = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->replay = $replay ?? new ReplayCache(null, $this->clock);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Mailgun';
    }

    /**
     * The delivery reports' signing key: Mailgun signs route posts with the
     * same HTTP webhook signing key. `fetch()` also reads `api_key`, which
     * is not a verification key.
     */
    public function verificationKeys(): array
    {
        return [MailgunReports::SIGNING_KEY];
    }

    public function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    public function verify(InboundRequest $request, array $config): Verdict
    {
        $config += $this->defaults;
        $key = trim((string)(\is_scalar($config[MailgunReports::SIGNING_KEY] ?? null) ? $config[MailgunReports::SIGNING_KEY] : ''));
        if ($key === '') {
            return Verdict::refused('No Mailgun webhook signing key is configured, so nothing can be verified.');
        }

        $fields = self::fields($request);
        $timestamp = self::text($fields['timestamp'] ?? null);
        $token = self::text($fields['token'] ?? null);
        $signature = strtolower(self::text($fields['signature'] ?? null));
        $parent = strtolower(self::text($fields['parent-signature'] ?? null));

        if ($timestamp === '' || $token === '' || ($signature === '' && $parent === '')) {
            return Verdict::refused('The post carried no timestamp, token and signature fields.');
        }

        $now = ($this->clock)();
        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1 || abs($now - (int)$timestamp) > MailgunReports::TOLERANCE) {
            return Verdict::refused(sprintf(
                'The signed timestamp is outside the %d seconds allowed. Either the post is a replay or a clock is wrong.',
                MailgunReports::TOLERANCE
            ));
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $key);
        // Both comparisons always run, so a subaccount's parent signature costs the same time.
        $matched = hash_equals($expected, $signature);
        $matched = hash_equals($expected, $parent) || $matched;
        if (!$matched) {
            return Verdict::refused('The Mailgun signature did not match. The signing key differs, or the post was forged.');
        }

        if ($this->replay->seen($token, 2 * MailgunReports::TOLERANCE)) {
            return Verdict::refused('This Mailgun token was already used, so the post is a replay.');
        }

        return Verdict::verified();
    }

    public function parse(InboundRequest $request, array $config): InboundPayload
    {
        try {
            $fields = self::fields($request);
            if ($fields === []) {
                return InboundPayload::unreadable('The post carried no form fields; Mailgun routes post a form.');
            }

            $url = self::text($fields['message-url'] ?? null);
            if ($url !== '') {
                if (!self::isMailgunUrl($url)) {
                    return InboundPayload::unreadable('The stored message URL is not an https address on mailgun.net.');
                }

                return InboundPayload::of([self::reference($url, $fields)]);
            }

            $mime = self::text($fields['body-mime'] ?? null);
            if ($mime !== '') {
                return InboundPayload::of([self::overlay(InboundMessage::fromMime($mime, self::KEY), $fields)]);
            }

            $known = ['recipient', 'sender', 'from', 'body-plain', 'body-html', 'message-headers'];
            if (array_intersect($known, array_keys($fields)) === []) {
                return InboundPayload::unreadable('The form carried none of the fields of a Mailgun route post.');
            }

            return InboundPayload::of([self::overlay(self::fromFields($fields, $request->files), $fields)]);
        } catch (\Throwable $e) {
            return InboundPayload::unreadable('The Mailgun post could not be read: ' . $e->getMessage());
        }
    }

    /**
     * Download a message a `store(notify=…)` route kept. Runs in the consumer's
     * worker. Throws when it cannot, so the worker retries; Mailgun keeps the
     * message for three days.
     */
    public function fetch(InboundReference $ref, array $config): InboundMessage
    {
        $config += $this->defaults;
        $url = self::text($ref->meta['url'] ?? null);
        if (!self::isMailgunUrl($url)) {
            throw new \RuntimeException('The stored message URL is not an https address on mailgun.net; it was not fetched.');
        }

        $apiKey = trim(self::text($config['api_key'] ?? null));
        if ($apiKey === '') {
            throw new \RuntimeException('Mailgun keeps this message until it is downloaded, which needs the API key in the Email Mailgun plugin.');
        }

        $http = $this->http ?? new CurlHttp(self::FETCH_MAX_BYTES, self::FETCH_TIMEOUT);
        $answer = $http->get($url, [
            'Authorization' => 'Basic ' . base64_encode('api:' . $apiKey),
            'Accept' => 'message/rfc2822',
        ]);

        $status = (int)($answer['status'] ?? 0);
        if ($status === 0) {
            throw new \RuntimeException('Mailgun could not be reached: ' . self::text($answer['error'] ?? null));
        }
        if ($status === 404) {
            throw new \RuntimeException('Mailgun no longer has this message; stored messages are kept for three days.');
        }
        if ($status < 200 || $status >= 300) {
            $said = self::text(($answer['body'] ?? [])['message'] ?? null);
            throw new \RuntimeException(sprintf('Mailgun answered %d%s', $status, $said !== '' ? ': ' . $said : ''));
        }

        $body = \is_array($answer['body'] ?? null) ? $answer['body'] : [];
        $mime = self::text($body['body-mime'] ?? null);
        if ($mime === '') {
            throw new \RuntimeException('Mailgun answered without the message in it (no body-mime).');
        }

        $fields = [
            'recipient' => self::text($body['recipient'] ?? null) ?: self::text($ref->meta['recipient'] ?? null),
            'stripped-text' => self::text($ref->meta['stripped_text'] ?? null),
        ];
        if (\array_key_exists('sender', $body) || \array_key_exists('sender', $ref->meta)) {
            $fields['sender'] = self::text($body['sender'] ?? null) ?: self::text($ref->meta['sender'] ?? null);
        }

        return self::overlay(InboundMessage::fromMime($mime, self::KEY), $fields)->with(['providerId' => $ref->id]);
    }

    public function instructions(string $webhookUrl): string
    {
        $mimeUrl = $webhookUrl . (str_contains($webhookUrl, '?') ? '&' : '?') . 'format=mime';

        return 'First, Mailgun has to receive mail for your domain. In Mailgun, add a receiving domain (a subdomain such as '
            . 'inbound.example.com keeps it apart from the mail you already get) and add the MX records Mailgun lists for it: '
            . 'mxa.mailgun.org and mxb.mailgun.org with priority 10 in the US region, mxa.eu.mailgun.org and mxb.eu.mailgun.org '
            . 'in the EU region. To keep your support mailbox where it is, forward it to an address on that domain instead. '
            . 'Then open Receiving, then Routes, and create a route. For the expression choose Match Recipient and enter the '
            . 'address, for example support@inbound.example.com; to catch plus addresses such as support+abc@ as well, use '
            . 'match_recipient("^support(\\+.*)?@inbound\\.example\\.com$"). '
            . 'For the action tick Forward and paste this address: ' . $webhookUrl . ' '
            . 'Mailgun then posts each message already parsed, with attachments as files. To get the raw message byte for byte '
            . 'instead, forward to this address, which ends in "mime": ' . $mimeUrl . ' '
            . 'For very large mail you can tick Store and notify with the same address instead of Forward; Mailgun keeps the '
            . 'message for three days and this site downloads it with the API key in the Email Mailgun plugin. '
            . 'Mailgun signs every post with the HTTP webhook signing key, the same key the delivery reports use: find it under '
            . 'Sending, then Webhooks, and paste it into the Webhook Signing Key field of the Email Mailgun plugin.';
    }

    // ------------------------------------------------------------- internals

    /**
     * The form fields. `parsedBody` whenever there is one; a url-encoded body
     * a caller left unparsed is decoded here. A multipart body is never read
     * from `body`, which PHP leaves empty for it anyway.
     *
     * @return array<string, mixed>
     */
    private static function fields(InboundRequest $request): array
    {
        if ($request->parsedBody !== []) {
            return $request->parsedBody;
        }

        if ($request->body !== '' && $request->contentType() === 'application/x-www-form-urlencoded') {
            parse_str($request->body, $fields);

            return $fields;
        }

        return [];
    }

    /**
     * The message from Mailgun's parsed fields. The headers go through the
     * Email plugin's own MIME parser as a header block, so ids, dates, names
     * and verdicts are normalised exactly as on the raw path.
     *
     * @param array<string, mixed> $fields
     * @param list<InboundUpload>  $files
     */
    private static function fromFields(array $fields, array $files): InboundMessage
    {
        $headers = self::headers($fields['message-headers'] ?? null);
        $base = self::headersOnly($headers);

        $html = self::html($fields['body-html'] ?? null);
        $text = self::text($fields['body-plain'] ?? null);
        $attachments = self::attachments($files, $fields['content-id-map'] ?? null, $html);

        $changes = [
            'raw' => null,
            'text' => $text === '' ? null : self::lines($text),
            'html' => $html === '' ? null : self::lines($html),
            'headers' => $headers,
            'attachments' => $attachments,
            'contentType' => self::contentType($headers, $text !== '', $html !== '', $attachments !== []),
            'receiver' => self::KEY,
        ];

        if ($base->from->isEmpty()) {
            $changes['from'] = Address::parse(self::text($fields['from'] ?? null));
        }
        if ($base->subject === '') {
            $changes['subject'] = trim((string)preg_replace('/[\r\n\t]+/', ' ', self::text($fields['subject'] ?? null)));
        }
        if ($base->messageId === null) {
            $id = trim(self::text($fields['Message-Id'] ?? $fields['message-id'] ?? null), " \t<>");
            if ($id !== '') {
                $changes['messageId'] = strtolower($id);
            }
        }

        return $base->with($changes);
    }

    /**
     * What Mailgun knows that the message does not say: the SMTP envelope, its
     * stripped reply, and its spam and sender checks where the message carried
     * no verdict of its own.
     *
     * @param array<string, mixed> $fields
     */
    private static function overlay(InboundMessage $message, array $fields): InboundMessage
    {
        $changes = [];

        $recipients = [];
        foreach (Address::parseList(self::text($fields['recipient'] ?? null)) as $address) {
            $recipients[] = $address->email;
        }
        if ($recipients !== []) {
            $changes['envelopeTo'] = $recipients;
        }

        if (\array_key_exists('sender', $fields)) {
            $changes['envelopeFrom'] = trim(self::text($fields['sender']), " \t<>");
        }

        $stripped = self::text($fields['stripped-text'] ?? null);
        if ($stripped !== '') {
            $changes['providerStrippedText'] = self::lines($stripped);
        }

        if ($message->spamScore === null) {
            $score = $message->header('X-Mailgun-Sscore');
            if ($score !== null && preg_match('/-?\d+(?:\.\d+)?/', $score, $m)) {
                $changes['spamScore'] = (float)$m[0];
            }
        }

        $auth = $message->auth;
        $spf = $message->header('X-Mailgun-Spf');
        if (!isset($auth['spf']) && $spf !== null && preg_match('/^\s*([a-z]+)/i', $spf, $m)) {
            $auth['spf'] = strtolower($m[1]);
        }
        $dkim = $message->header('X-Mailgun-Dkim-Check-Result');
        if (!isset($auth['dkim']) && $dkim !== null && preg_match('/^\s*([a-z]+)/i', $dkim, $m)) {
            $auth['dkim'] = strtolower($m[1]);
        }
        if ($auth !== $message->auth) {
            $changes['auth'] = $auth;
        }

        return $changes === [] ? $message : $message->with($changes);
    }

    /**
     * A stored message, as a reference the worker fetches later. `meta` keeps
     * what a consumer may want to show before then, and what the fetch cannot
     * get back from the download (the stripped reply).
     *
     * @param array<string, mixed> $fields
     */
    private static function reference(string $url, array $fields): InboundReference
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $id = rawurldecode(basename($path));

        $messageId = trim(self::text($fields['Message-Id'] ?? $fields['message-id'] ?? null), " \t<>");
        if ($messageId === '') {
            $messageId = trim((string)self::headersOnly(self::headers($fields['message-headers'] ?? null))->messageId);
        }

        $meta = [
            'url' => $url,
            'domain' => self::text($fields['domain'] ?? null),
            'recipient' => self::text($fields['recipient'] ?? null),
            'from' => self::text($fields['from'] ?? null),
            'subject' => self::text($fields['subject'] ?? null),
            'message_id' => strtolower($messageId),
            'stripped_text' => self::text($fields['stripped-text'] ?? null),
        ];
        if (\array_key_exists('sender', $fields)) {
            $meta['sender'] = self::text($fields['sender']);
        }

        return new InboundReference(self::KEY, $id !== '' ? $id : hash('sha256', $url), $meta);
    }

    /** https, and a host that is mailgun.net or under it: the only place the API key is ever sent. */
    private static function isMailgunUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));

        return $host === 'mailgun.net' || str_ends_with($host, '.mailgun.net');
    }

    /**
     * `message-headers`: a JSON list of `[name, value]` pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function headers(mixed $value): array
    {
        $list = \is_string($value) ? json_decode($value, true) : $value;
        $out = [];
        foreach (\is_array($list) ? $list : [] as $pair) {
            if (!\is_array($pair) || \count($pair) < 2) {
                continue;
            }
            $pair = array_values($pair);
            $name = self::text($pair[0]);
            if ($name === '' || preg_match('/^[\x21-\x39\x3B-\x7E]+$/', $name) !== 1) {
                continue;
            }
            $out[] = [$name, self::headerValue($pair[1])];
        }

        return $out;
    }

    /**
     * One header value. Mailgun writes most as strings, and some structured
     * ones (`Content-Type`, `Content-Disposition`) as `[value, {param: value}]`,
     * which is put back together here.
     */
    private static function headerValue(mixed $value): string
    {
        if (!\is_array($value)) {
            return self::text($value);
        }

        $value = array_values($value);
        $out = self::text($value[0] ?? null);
        foreach (\is_array($value[1] ?? null) ? $value[1] : [] as $param => $paramValue) {
            $out .= '; ' . $param . '="' . addcslashes(self::text($paramValue), '"\\') . '"';
        }

        return $out;
    }

    /**
     * A header block with no body, read by the Email plugin's parser. The
     * content headers are left out: with no body under them they would only
     * make the parser look for parts that are not there.
     *
     * @param list<array{0: string, 1: string}> $headers
     */
    private static function headersOnly(array $headers): InboundMessage
    {
        $block = '';
        foreach ($headers as [$name, $value]) {
            $lower = strtolower($name);
            if ($lower === 'content-type' || $lower === 'content-transfer-encoding') {
                continue;
            }
            $block .= $name . ': ' . str_replace(["\r", "\n"], ' ', $value) . "\r\n";
        }

        return (new MimeParser())->parse($block . "\r\n");
    }

    /** `body-html`: documented as a string on some pages and a list of HTML parts on others. */
    private static function html(mixed $value): string
    {
        if (\is_array($value)) {
            return implode("\n", array_filter(array_map(static fn ($part): string => self::text($part), $value), static fn (string $part): bool => $part !== ''));
        }

        return self::text($value);
    }

    /**
     * The `attachment-N` files, in order, with their Content-IDs from
     * `content-id-map` (`{"<id>": "attachment-1"}`). A part the HTML refers to
     * as `cid:` is inline. The bytes stay where PHP put them.
     *
     * @param list<InboundUpload> $files
     * @return list<InboundAttachment>
     */
    private static function attachments(array $files, mixed $map, string $html): array
    {
        $ids = [];
        $decoded = \is_string($map) ? json_decode($map, true) : $map;
        foreach (\is_array($decoded) ? $decoded : [] as $cid => $name) {
            $cid = trim((string)$cid, " \t<>");
            $name = self::text($name);
            if ($cid !== '' && $name !== '') {
                $ids[$name] = $cid;
            }
        }

        $numbered = [];
        foreach ($files as $file) {
            if (!$file instanceof InboundUpload || $file->error !== 0) {
                continue;
            }
            if (preg_match('/^attachment-(\d+)$/', $file->field, $m) === 1) {
                $numbered[(int)$m[1]] = $file;
            }
        }
        ksort($numbered);

        $out = [];
        foreach ($numbered as $file) {
            $type = strtolower(trim(explode(';', $file->type, 2)[0]));
            if ($type === '' || !str_contains($type, '/')) {
                $type = 'application/octet-stream';
            }
            $contentId = $ids[$file->field] ?? $ids[$file->filename] ?? null;
            $out[] = new InboundAttachment(
                self::safeFilename($file->filename, \count($out) + 1),
                $type,
                $file->size,
                $contentId,
                $contentId !== null && stripos($html, 'cid:' . $contentId) !== false,
                null,
                $file->tmpPath,
            );
        }

        return $out;
    }

    /**
     * The top-level media type from `Content-Type`, or the type a mail client
     * would have used for these parts.
     *
     * @param list<array{0: string, 1: string}> $headers
     */
    private static function contentType(array $headers, bool $text, bool $html, bool $attachments): string
    {
        foreach ($headers as [$name, $value]) {
            if (strtolower($name) === 'content-type') {
                $type = strtolower(trim(explode(';', $value, 2)[0]));
                if (str_contains($type, '/')) {
                    return $type;
                }
            }
        }

        if ($attachments) {
            return 'multipart/mixed';
        }
        if ($text && $html) {
            return 'multipart/alternative';
        }

        return $html ? 'text/html' : 'text/plain';
    }

    /** A bare, printable filename, as the Email plugin's parser makes them. */
    private static function safeFilename(string $name, int $index): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string)preg_replace('/[\x00-\x1F\x7F]+/', '', $name);
        $name = trim($name, " .\t");
        $name = str_replace(['/', ':', '*', '?', '"', '<', '>', '|'], '_', $name);

        return $name === '' ? 'attachment-' . $index . '.bin' : $name;
    }

    private static function lines(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) || \is_int($value) || \is_float($value) ? (string)$value : '';
    }
}
