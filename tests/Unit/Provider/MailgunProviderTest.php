<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\EmailMailgun\Provider\MailgunApi;
use Grav\Plugin\EmailMailgun\Provider\MailgunProvider;
use Grav\Plugin\EmailMailgun\Provider\MailgunReports;
use Grav\Plugin\EmailMailgun\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * What this plugin says about Mailgun when something asks.
 *
 * The cheap methods are pinned as facts rather than as "whatever the code says",
 * because every one of them is read by a screen somewhere and a wrong answer is
 * invisible: an SPF host nobody checks, a capability nobody questions, a
 * verification key named one thing here and another in the blueprint.
 */
final class MailgunProviderTest extends TestCase
{
    public function testItAnswersForTheEngineItRegisters(): void
    {
        $provider = new MailgunProvider();

        self::assertInstanceOf(Provider::class, $provider);
        self::assertSame(['mailgun'], $provider->engines());
        self::assertSame('mailgun', $provider->key());
        self::assertSame('Mailgun', $provider->label());
    }

    /** The registry is what the Email plugin hands round, so it has to take it. */
    public function testTheRegistryFindsItByEngineAndByKey(): void
    {
        $registry = new ProviderRegistry();
        $registry->add(new MailgunProvider());

        self::assertInstanceOf(MailgunProvider::class, $registry->forEngine('mailgun'));
        self::assertInstanceOf(MailgunProvider::class, $registry->byKey('mailgun'));
        self::assertNull($registry->forEngine('postmark'));
    }

    /**
     * The three capability answers, and the reason each is what it is.
     *
     * Custom headers reach the wire over SMTP because there the headers are the
     * message, and over the API because Symfony's Mailgun bridge sends an
     * unrecognised header as an `h:` field. Nothing comes back, because
     * Mailgun's event schema exposes four header fields and drops the rest.
     */
    public function testItSaysWhatItDoesToHeaders(): void
    {
        $capabilities = (new MailgunProvider())->capabilities();

        self::assertTrue($capabilities->customHeaders);
        self::assertTrue($capabilities->unsubscribeHeaders);
        self::assertFalse($capabilities->echoesHeaders);
        self::assertStringContainsString('Message-ID', $capabilities->echoNote);
        self::assertStringContainsString('nothing setting up', $capabilities->echoNote);
    }

    public function testItReportsTheSixEventsMailgunSends(): void
    {
        $reports = (new MailgunProvider())->reports();

        self::assertNotNull($reports);
        self::assertSame(
            [Event::DELIVERED, Event::BOUNCED, Event::COMPLAINED, Event::OPENED, Event::CLICKED, Event::DROPPED],
            $reports->events(),
            'dropped is a failed Mailgun never attempted, and it has no event name of its own'
        );

        foreach ($reports->events() as $event) {
            self::assertContains($event, Event::TYPES, 'every event has to be one the contract knows');
        }

        self::assertSame(['signing_key'], $reports->verificationKeys());
        self::assertSame('X-Grav-Send-Id', $reports->sendHeader());
        self::assertSame(SendHeader::name(), $reports->sendHeader(), 'the Email plugin names it');
    }

    /**
     * The SPF include and the return-path zone are the same host, which looks
     * like a mistake and is not, and there is no DKIM zone because Mailgun
     * publishes the key as a TXT record on the store's own domain.
     */
    public function testItKnowsMailgunsDns(): void
    {
        $domain = (new MailgunProvider())->domain();

        self::assertSame('mailgun.org', $domain->spfInclude);
        self::assertNull($domain->dkimZone);
        self::assertSame('mailgun.org', $domain->returnPathZone);
    }

    /** With no API key there is nothing to ask with, so it does not pretend. */
    public function testWithNoApiKeyThereIsNoLookup(): void
    {
        self::assertNull((new MailgunProvider())->domain()->lookup);
        self::assertSame([], (new MailgunProvider())->domain()->ask('example.com'));
    }

    /**
     * The selector comes out of the records Mailgun says the domain should
     * have, because there is no other way to learn it from outside.
     */
    public function testTheLookupReadsSelectorsAndReturnPathsOutOfTheRecords(): void
    {
        $http = (new FakeHttp())->queue('GET', '/v4/domains/mail.example.com', 200, [
            'domain' => ['name' => 'mail.example.com'],
            'sending_dns_records' => [
                ['record_type' => 'TXT', 'name' => 'krs._domainkey.mail.example.com', 'value' => 'k=rsa; p=MIGf'],
                ['record_type' => 'TXT', 'name' => 'mail.example.com', 'value' => 'v=spf1 include:mailgun.org ~all'],
                ['record_type' => 'CNAME', 'name' => 'email.mail.example.com', 'value' => 'mailgun.org'],
            ],
        ]);

        $facts = $this->provider($http)->domain()->ask('Mail.Example.com.');

        self::assertSame(['krs'], $facts['selectors']);
        self::assertSame(['email.mail.example.com'], $facts['return_paths']);
    }

    /**
     * A provider's API being slow, revoked or renamed is an unanswered
     * question, not a broken settings screen.
     */
    public function testALookupThatFailsAnswersNothingRatherThanThrowing(): void
    {
        $http = (new FakeHttp())
            ->queue('GET', '/v4/domains/mail.example.com', 401, ['message' => 'Invalid private key'])
            ->queue('GET', '/v4/domains/mail.example.com', 0, null, 'Operation timed out')
            ->queue('GET', '/v4/domains/mail.example.com', 200, ['domain' => ['name' => 'mail.example.com']]);

        $provider = $this->provider($http);

        self::assertSame([], $provider->domain()->ask('mail.example.com'), 'a refused key');
        self::assertSame([], $provider->domain()->ask('mail.example.com'), 'a network failure');
        self::assertSame([], $provider->domain()->ask('mail.example.com'), 'an answer with no records in it');
        self::assertSame([], $provider->domain()->ask('   '), 'and no domain at all');
    }

    public function testTheEuRegionIsAskedOfTheEuHost(): void
    {
        $http = new FakeHttp();

        $provider = new MailgunProvider(
            ['api_key' => 'k', 'domain' => 'mail.example.com', 'region' => 'eu'],
            $http
        );

        $provider->domain()->ask('mail.example.com');

        self::assertStringStartsWith(MailgunApi::EU, $http->calls[0]['url']);
    }

    /**
     * The instructions are for a merchant doing it by hand, so they name the
     * screens and say which of the two keys is which.
     */
    public function testTheInstructionsAreInPlainWords(): void
    {
        $said = (new MailgunProvider())->instructions();

        self::assertStringContainsString('Webhooks', $said);
        self::assertStringContainsString('Permanent Failure', $said);
        self::assertStringContainsString('different string from the sending API key', $said);
        self::assertStringNotContainsString('PLUGIN_EMAIL_MAILGUN', $said, 'a language key is not instructions');
    }

    public function testItOffersASetupButton(): void
    {
        self::assertNotNull((new MailgunProvider())->setup());
    }

    // ------------------------------------------------------------- internals

    private function provider(FakeHttp $http): MailgunProvider
    {
        return new MailgunProvider(['api_key' => 'the-api-key', 'domain' => 'mail.example.com'], $http);
    }
}
