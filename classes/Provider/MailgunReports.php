<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Provider;

use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\Payload;
use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookRequest;

/**
 * Mailgun's events webhook, verified and read.
 *
 * Moved out of the KahunaCart newsletter add-on's `Providers\MailgunParser`,
 * where it lived because that add-on had nowhere else to put it. The
 * verification, the field reading and the skipping of unknown events are the
 * same; what changed is the vocabulary it answers in — the Email plugin's
 * contract rather than that add-on's private one — and the fact that the
 * signing key now comes out of this plugin's own config, beside the sending
 * credentials it already keeps.
 *
 * Documentation: `documentation.mailgun.com/docs/mailgun/user-manual/webhooks/`
 * — the `webhook-payloads` and `securing-webhooks` pages — plus
 * `/user-manual/events/events` for the event names. Read 2026-09-04.
 *
 * ## The envelope, and the name that never arrives
 *
 * `{signature: {timestamp, token, signature}, event-data: {…}}`, one event per
 * request.
 *
 * Mailgun's dashboard offers `temporary_fail` and `permanent_fail` as things to
 * subscribe to, and **neither of those strings ever appears in a payload**.
 * Both arrive with `event-data.event` set to `failed` and are told apart by
 * `severity`, which is `permanent` or `temporary`. A parser matching on the
 * subscription names would receive every bounce and act on none of them, which
 * is the sort of bug that looks like a provider outage for a week.
 *
 * ## Correlation
 *
 * `event-data.message.headers.message-id`, and Mailgun hands it back **without
 * the angle brackets**, which is the spelling a store's own send row usually
 * holds. `Event::of()` strips them either way, because the day one provider
 * changes its mind is a day nobody should spend debugging.
 *
 * On an open, a click or an unsubscribe, `message.headers` carries *only*
 * `message-id` — no `to`, no `subject` — so it is not merely the best handle
 * there, it is the only one.
 *
 * Custom headers do not come back at all. Mailgun's event schema exposes four
 * header fields — `to`, `from`, `subject` and `message-id` — and drops every
 * other one, so a custom `X-` header is invisible here however carefully it was
 * set. Their own mechanism for this is user variables: `v:name=value` over the
 * API, or an `X-Mailgun-Variables` JSON header over SMTP, arriving back as
 * `event-data.user-variables`. That is read as a fallback for a store that has
 * wired one up, and it is not something this plugin sets — correlation works
 * over `Message-ID` already, and Mailgun's own documentation warns twice that
 * user variables are visible to the recipient in the delivered message.
 *
 * ## The signature
 *
 * HMAC-SHA256 of `timestamp . token` — concatenated, no separator — keyed with
 * the account's **HTTP webhook signing key**, compared as a lower-case hex
 * digest against `signature.signature`. The signing key is a different string
 * from the sending API key and lives in a different part of Mailgun's
 * dashboard, which is why the config key is named `signing_key` and why the
 * field's help text says where to find it.
 *
 * The timestamp is checked for freshness, because a signature over a timestamp
 * and a token is otherwise a signature that is valid forever. The window is
 * fifteen minutes rather than five: Mailgun's own note asks callers not to be
 * aggressive here, since a queue on their side is a delay on ours.
 *
 * A subaccount's events carry a `parent-signature` computed the same way
 * against the parent account's key. It is accepted as an alternative, so a
 * store on a subaccount can paste the one key it has.
 */
final class MailgunReports implements DeliveryReports
{
    /** How stale a signed timestamp may be. Mailgun asks for latitude here. */
    public const TOLERANCE = 900;

    /** The config key this plugin keeps the webhook signing key under. */
    public const SIGNING_KEY = 'signing_key';

    /**
     * Their event names to the contract's.
     *
     * `accepted`, `unsubscribed`, `delivery_delayed` and the rest are absent on
     * purpose: an event nobody acts on is skipped with a note, never refused.
     *
     * `failed` is two different things and {@see SUPPRESS} is what tells them
     * apart. Usually it is a bounce: a receiving server refused the message and
     * Mailgun is passing that on. But Mailgun also reports a `failed` for a
     * message it never sent, because the address was already on one of its own
     * suppression lists, and its `reason` for that begins `suppress-`. Nothing
     * was handed to a receiving server, so that one is {@see Event::DROPPED}.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'delivered' => Event::DELIVERED,
        'failed' => Event::BOUNCED,
        'complained' => Event::COMPLAINED,
        'opened' => Event::OPENED,
        'clicked' => Event::CLICKED,
    ];

    /**
     * The prefix on the `reason` of a `failed` Mailgun never tried to send.
     *
     * `suppress-bounce`, `suppress-complaint` and `suppress-unsubscribe` are
     * the three, and they mean the address was on Mailgun's own list before the
     * send. A store reading one of these is being told what Mailgun already
     * knew rather than what a receiving server has just decided.
     */
    public const SUPPRESS = 'suppress-';

    /** @var (callable(): int) */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return list<string> */
    public function events(): array
    {
        return array_values(array_unique([...array_values(self::TYPES), Event::DROPPED]));
    }

    /** @return list<string> */
    public function verificationKeys(): array
    {
        return [self::SIGNING_KEY];
    }

    public function sendHeader(): string
    {
        return SendHeader::name();
    }

    public function verify(WebhookRequest $request, array $config): Verdict
    {
        $key = trim((string)($config[self::SIGNING_KEY] ?? ''));
        if ($key === '') {
            return Verdict::refused('no Mailgun signing key is configured');
        }

        $body = $request->json();
        $signature = \is_array($body['signature'] ?? null) ? $body['signature'] : null;

        if ($signature === null) {
            return Verdict::refused('the body carried no signature block');
        }

        $timestamp = trim((string)($signature['timestamp'] ?? ''));
        $token = trim((string)($signature['token'] ?? ''));

        if ($timestamp === '' || $token === '') {
            return Verdict::refused('the signature block was incomplete');
        }

        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1
            || abs(($this->clock)() - (int)$timestamp) > self::TOLERANCE) {
            return Verdict::refused('the signed timestamp was outside the tolerance');
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $key);

        // Both spellings are compared, and both comparisons run: the store may
        // have pasted the parent account's key for a subaccount's events, and
        // returning early on the first would make the two cost different times.
        $matched = hash_equals($expected, strtolower(trim((string)($signature['signature'] ?? ''))));
        $matched = hash_equals($expected, strtolower(trim((string)($signature['parent-signature'] ?? '')))) || $matched;

        return $matched ? Verdict::verified() : Verdict::refused('the Mailgun signature did not match');
    }

    public function parse(WebhookRequest $request): Payload
    {
        $body = $request->json();
        if ($body === null) {
            return Payload::unreadable('the body was not a JSON object');
        }

        $data = \is_array($body['event-data'] ?? null) ? $body['event-data'] : null;
        if ($data === null) {
            return Payload::unreadable('the body carried no event-data');
        }

        $name = strtolower(trim((string)($data['event'] ?? '')));
        $type = self::TYPES[$name] ?? null;

        if ($type === null) {
            return Payload::nothing(sprintf('Mailgun reported "%s", which this store does not act on', $name));
        }

        $hard = null;
        if ($type === Event::BOUNCED) {
            // A `failed` Mailgun never attempted, because the address was
            // already on one of its suppression lists. Not a bounce: nothing
            // was handed to a receiving server.
            if (str_starts_with(strtolower(trim((string)($data['reason'] ?? ''))), self::SUPPRESS)) {
                $type = Event::DROPPED;
            } else {
                $hard = strtolower(trim((string)($data['severity'] ?? ''))) === 'permanent';
            }
        }

        $message = \is_array($data['message'] ?? null) ? $data['message'] : [];
        $headers = \is_array($message['headers'] ?? null) ? $message['headers'] : [];

        return Payload::of([Event::of(
            $type,
            $hard,
            (string)($data['recipient'] ?? ''),
            (string)($headers['message-id'] ?? ''),
            (string)($data['id'] ?? ''),
            Moment::parse($data['timestamp'] ?? null) ?? 0,
            self::reason($data, $type),
            SendHeader::idIn($data['user-variables'] ?? null),
        )]);
    }

    // ------------------------------------------------------------- internals

    /**
     * Mailgun's own words about why.
     *
     * The receiving server's own line first, because "550 5.1.1 user unknown"
     * is what a merchant wants; Mailgun's one-word `reason` (`bounce`,
     * `generic`, `suppress-bounce`) after it, because on its own it explains
     * nothing.
     *
     * @param array<string, mixed> $data
     */
    private static function reason(array $data, string $type): ?string
    {
        $status = \is_array($data['delivery-status'] ?? null) ? $data['delivery-status'] : [];

        $parts = array_filter([
            trim((string)($status['message'] ?? '')) ?: trim((string)($status['description'] ?? '')),
            trim((string)($data['reason'] ?? '')),
        ], static fn (string $part): bool => $part !== '');

        if ($parts !== []) {
            return implode(' — ', $parts);
        }

        return $type === Event::COMPLAINED ? 'marked as spam' : null;
    }
}
