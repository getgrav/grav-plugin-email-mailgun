<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Provider;

/**
 * The store's send id, read back out of Mailgun's user variables.
 *
 * The half of the newsletter's `Providers\SendHeader` that concerns Mailgun,
 * moved here. The store stamps {@see MailgunReports::SEND_HEADER} on every
 * message it sends; the contract's job at this end is to hand that value back
 * on an event, so a bounce can be tied to the exact message it came from.
 *
 * ## Mailgun does not echo the header
 *
 * Its event schema exposes four header fields — `to`, `from`, `subject` and
 * `message-id` — and drops every other one, so a custom `X-` header is
 * invisible here however carefully it was set. Their own mechanism for this is
 * user variables: `v:name=value` over the API, or an `X-Mailgun-Variables` JSON
 * header over SMTP, arriving back as `event-data.user-variables`.
 *
 * That is read here as a fallback for a store that has wired one up, and it is
 * not something this plugin sets. Correlation works over `Message-ID` already,
 * and Mailgun's own documentation warns twice that user variables are visible
 * to the recipient in the delivered message — a send row id is not a secret,
 * but it is not something to put in front of a customer either.
 *
 * ## The two shapes it arrives in
 *
 * `user-variables` is `[]` rather than `{}` when there are none, which in PHP
 * is a list where a map was expected, so anything that is not an array of the
 * right kind answers null rather than failing. Mailgun lower-cases variable
 * names, so both spellings are tried.
 */
final class SendVariables
{
    private function __construct()
    {
    }

    /**
     * A send id out of a map of user variables, under either spelling.
     *
     * @param mixed $map anything; a non-array answers null
     */
    public static function idIn(mixed $map, string $header): ?string
    {
        if (!\is_array($map)) {
            return null;
        }

        foreach ([$header, strtolower($header)] as $key) {
            if (!\array_key_exists($key, $map)) {
                continue;
            }

            $id = self::idFrom($map[$key]);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * A send id out of one value, however Mailgun typed it.
     *
     * The contract carries a send id as a string, because the Email plugin has
     * no idea what a store's send id looks like and should not pretend to.
     * What is refused here is only the obvious rubbish: an empty value, and one
     * long enough to be somebody filling a column rather than naming a send.
     */
    public static function idFrom(mixed $value): ?string
    {
        if (\is_int($value)) {
            return (string)$value;
        }

        if (\is_float($value)) {
            return $value === floor($value) ? (string)(int)$value : null;
        }

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || mb_strlen($value) > 190 ? null : $value;
    }
}
