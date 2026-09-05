<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Provider;

use Grav\Plugin\Email\Providers\Capabilities;
use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\WebhookSetup;
use Grav\Plugin\EmailMailgun\Http\CurlHttp;
use Grav\Plugin\EmailMailgun\Http\Http;

/**
 * Everything Mailgun knows about itself, in the plugin that already talks to it.
 *
 * Registered on the Email plugin's `onEmailProviders` event. Anything on the
 * site that wants to record a bounce, check a sending domain's DNS or find out
 * whether an unsubscribe header survived the trip asks the Email plugin, and
 * the Email plugin asks this.
 *
 * Before this, the KahunaCart newsletter add-on carried a Mailgun webhook
 * parser, a row in a table of SPF hosts and a note about custom headers — three
 * things about Mailgun in a plugin that has nothing to do with Mailgun. All
 * three are here now.
 *
 * ## Nothing here does any work
 *
 * `onEmailProviders` fires on any request that asks about a provider,
 * including an admin screen being drawn, so building this must cost nothing.
 * Every method answers a value object; the only thing that reaches the network
 * is the setup button being pressed, or the `DomainFacts` lookup being asked a
 * question, and both of those happen behind a closure the caller decides to
 * call.
 *
 * @see \Grav\Plugin\EmailMailgun\Provider\MailgunReports for the webhook
 * @see \Grav\Plugin\EmailMailgun\Provider\MailgunSetup   for the button
 */
final class MailgunProvider implements Provider
{
    /** The engine key this plugin registers on `onEmailEngines`. */
    public const ENGINE = 'mailgun';

    /**
     * The plain-English instructions, for a merchant doing it by hand.
     *
     * The language key is looked up first where Grav is running, so a
     * translated site gets its own words; this is the fallback and the source
     * of the English.
     */
    public const INSTRUCTIONS_KEY = 'PLUGIN_EMAIL_MAILGUN.WEBHOOK_INSTRUCTIONS';

    public const INSTRUCTIONS = 'In Mailgun, open Sending, then Webhooks, and pick the sending domain this store '
        . 'uses. Add the store\'s webhook address once for each of Delivered Messages, Permanent Failure, '
        . 'Temporary Failure, Spam Complaints, Opens and Clicks. Then find the HTTP webhook signing key under your '
        . 'profile menu, on the API Security page — it is a different string from the sending API key — and paste '
        . 'it into the Webhook Signing Key field in this plugin\'s settings. The Set up button does all of that for '
        . 'you if the API key here is an account key rather than a domain sending key.';

    /** @param array<string, mixed> $config this plugin's own config block */
    public function __construct(
        private readonly array $config = [],
        private readonly ?Http $http = null,
        private readonly ?\Closure $keeper = null,
    ) {
    }

    /** @return list<string> */
    public function engines(): array
    {
        return [self::ENGINE];
    }

    public function key(): string
    {
        return self::ENGINE;
    }

    public function label(): string
    {
        return 'Mailgun';
    }

    /**
     * What this transport does to a message on the way out.
     *
     * **Custom headers reach the wire.** Over SMTP because there the headers
     * are the message; over the API because Symfony's Mailgun bridge sends
     * every header it does not otherwise recognise as an `h:` field, which is
     * Mailgun's own way of saying "put this on the message".
     *
     * **The unsubscribe headers survive** for the same reason. `List-Unsubscribe`
     * and `List-Unsubscribe-Post` are what put the unsubscribe button at the top
     * of a Gmail message, and a bulk sender without one is what a spammer looks
     * like.
     *
     * **The header does not come back.** Mailgun's event schema exposes four
     * header fields and drops the rest, and no setting changes that. There is
     * nothing a merchant can do about it, so the note says what happens instead
     * rather than asking them to go and configure something.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            customHeaders: true,
            unsubscribeHeaders: true,
            echoesHeaders: false,
            echoNote: 'Mailgun\'s delivery events carry only four of a message\'s headers, so a custom one never '
                . 'comes back. Events are tied to the message they came from by Message-ID instead, which needs '
                . 'nothing setting up.',
        );
    }

    public function reports(): ?DeliveryReports
    {
        return new MailgunReports();
    }

    public function setup(): ?WebhookSetup
    {
        return new MailgunSetup($this->api(), $this->keeper);
    }

    /**
     * Mailgun's DNS conventions, and a way to ask about one domain.
     *
     * The SPF include and the return-path zone are both `mailgun.org`, which
     * looks like a mistake and is not: the same zone carries the sending hosts
     * and the CNAME targets.
     *
     * There is no DKIM zone, because Mailgun publishes the public key as a TXT
     * record on the store's own domain rather than as a CNAME pointing into one
     * of theirs. That makes the selector unguessable from outside — hence the
     * lookup, which asks Mailgun what the records for a domain are meant to say
     * and reads the selector out of them.
     */
    public function domain(): DomainFacts
    {
        $apiKey = trim((string)($this->config['api_key'] ?? ''));
        $region = (string)($this->config['region'] ?? 'us');

        return new DomainFacts(
            spfInclude: MailgunApi::ZONE,
            dkimZone: null,
            returnPathZone: MailgunApi::ZONE,
            lookup: $apiKey === ''
                ? null
                : fn (string $domain): array => $this->api()->domainFacts($apiKey, $region, $domain),
        );
    }

    public function instructions(): string
    {
        return self::translate(self::INSTRUCTIONS_KEY, self::INSTRUCTIONS);
    }

    // ------------------------------------------------------------- internals

    private function api(): MailgunApi
    {
        return new MailgunApi($this->http ?? new CurlHttp());
    }

    /**
     * A language key where Grav is running, the English behind it where it is
     * not.
     *
     * Guarded on both sides because this class is a unit-testable value object
     * that must work with no Grav booted, and because a translation file that
     * has not been loaded answers with the key itself rather than throwing.
     */
    private static function translate(string $key, string $fallback): string
    {
        if (!class_exists(\Grav\Common\Grav::class)) {
            return $fallback;
        }

        try {
            $grav = \Grav\Common\Grav::instance();
            $language = $grav['language'] ?? null;

            if ($language === null) {
                return $fallback;
            }

            $translated = trim((string)$language->translate([$key]));

            return $translated === '' || $translated === $key ? $fallback : $translated;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
