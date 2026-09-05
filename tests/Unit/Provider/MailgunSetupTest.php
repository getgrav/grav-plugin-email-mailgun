<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\EmailMailgun\Provider\MailgunApi;
use Grav\Plugin\EmailMailgun\Provider\MailgunSetup;
use Grav\Plugin\EmailMailgun\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * The setup button, against a Mailgun that answers whatever the test says.
 *
 * The three cases the brief asks for — a success, a refused key with Mailgun's
 * own sentence coming through, and the network failing — plus the two that
 * matter as much in practice: pressing the button twice, and a merchant whose
 * key can make webhooks but cannot read the account's signing key.
 */
final class MailgunSetupTest extends TestCase
{
    private const URL = 'https://shop.example.com/newsletter/webhook/mailgun/abcdef';

    private const WEBHOOKS = '/v3/domains/mail.example.com/webhooks';

    private const KEY_PATH = '/v5/accounts/http_signing_key';

    /** @var list<string> the five events the contract knows */
    private const EVENTS = [
        Event::DELIVERED,
        Event::BOUNCED,
        Event::COMPLAINED,
        Event::OPENED,
        Event::CLICKED,
    ];

    public function testAFreshAccountGetsSixWebhooksAndTheSigningKey(): void
    {
        $http = (new FakeHttp())
            ->queue('GET', self::WEBHOOKS, 200, ['webhooks' => []])
            ->queue('GET', self::KEY_PATH, 200, ['http_signing_key' => 'the-signing-key']);

        foreach (range(1, 6) as $ignored) {
            $http->queue('POST', self::WEBHOOKS, 200, ['message' => 'Webhook has been created']);
        }

        $kept = [];
        $result = $this->button($http, static function (string $key) use (&$kept): void {
            $kept[] = $key;
        })->create(self::URL, self::EVENTS, self::config());

        self::assertTrue($result->ok, $result->message);
        self::assertSame(['the-signing-key'], $kept, 'the key is handed to the plugin to save');
        self::assertStringContainsString('All 6 Mailgun webhooks', $result->message);
        self::assertStringContainsString('saved', $result->message);
        self::assertNull($result->webhookId, 'Mailgun gives a webhook no id of its own');

        // The six types, and deliberately not `accepted` or `unsubscribed`.
        $ids = [];
        foreach ($http->calls as $call) {
            if ($call['method'] === 'POST') {
                $ids[] = $call['fields']['id'];
                self::assertSame(self::URL, $call['fields']['url']);
            }
        }

        self::assertSame(
            ['delivered', 'permanent_fail', 'temporary_fail', 'complained', 'opened', 'clicked'],
            $ids
        );
    }

    /**
     * Pressing the button twice must not leave a store with the same address
     * registered twice, and must not take somebody else's webhook away.
     */
    public function testPressingItAgainChangesNothingAndKeepsOtherIntegrations(): void
    {
        $other = 'https://someone-elses-thing.example.net/hook';

        $http = (new FakeHttp())
            ->queue('GET', self::WEBHOOKS, 200, ['webhooks' => [
                'delivered' => ['urls' => [self::URL]],
                'permanent_fail' => ['urls' => [self::URL]],
                'temporary_fail' => ['urls' => [self::URL]],
                'complained' => ['urls' => [self::URL]],
                'opened' => ['urls' => [$other]],
                'clicked' => ['urls' => []],
            ]])
            ->queue('PUT', self::WEBHOOKS . '/opened', 200, ['message' => 'Webhook has been updated'])
            ->queue('POST', self::WEBHOOKS, 200, ['message' => 'Webhook has been created'])
            ->queue('GET', self::KEY_PATH, 200, ['http_signing_key' => 'the-signing-key']);

        $result = $this->button($http)->create(self::URL, self::EVENTS, self::config());

        self::assertTrue($result->ok, $result->message);
        self::assertStringContainsString('2 of the 6', $result->message);

        // The one that already had somebody else's URL keeps it.
        $put = array_values(array_filter($http->calls, static fn (array $c): bool => $c['method'] === 'PUT'))[0];
        self::assertSame([$other, self::URL], $put['fields']['url']);

        // Nothing was posted for the four that were already pointed here.
        self::assertSame(
            ['GET ' . self::WEBHOOKS, 'PUT ' . self::WEBHOOKS . '/opened', 'POST ' . self::WEBHOOKS, 'GET ' . self::KEY_PATH],
            $http->trail()
        );
    }

    public function testNothingChangedIsSaidPlainlyRatherThanCalledDone(): void
    {
        $all = [];
        foreach (['delivered', 'permanent_fail', 'temporary_fail', 'complained', 'opened', 'clicked'] as $type) {
            $all[$type] = ['urls' => [self::URL]];
        }

        $http = (new FakeHttp())
            ->queue('GET', self::WEBHOOKS, 200, ['webhooks' => $all])
            ->queue('GET', self::KEY_PATH, 200, ['http_signing_key' => 'k']);

        $result = $this->button($http)->create(self::URL, self::EVENTS, self::config());

        self::assertTrue($result->ok);
        self::assertStringContainsString('were already pointed here', $result->message);
    }

    /** A key Mailgun refuses, with Mailgun's own sentence coming through. */
    public function testARefusedKeySaysWhatMailgunSaid(): void
    {
        $http = (new FakeHttp())->queue('GET', self::WEBHOOKS, 401, ['message' => 'Invalid private key']);

        $result = $this->button($http)->create(self::URL, self::EVENTS, self::config());

        self::assertFalse($result->ok);
        self::assertStringContainsString('account key', $result->message);
        self::assertStringContainsString('manage webhooks', $result->message);
    }

    /** A domain that is not on this account, in Mailgun's own words. */
    public function testMailgunsOwnWordsComeThroughOnAPlainRefusal(): void
    {
        $http = (new FakeHttp())->queue('GET', self::WEBHOOKS, 404, ['message' => 'Domain not found: mail.example.com']);

        $result = $this->button($http)->create(self::URL, self::EVENTS, self::config());

        self::assertFalse($result->ok);
        self::assertStringContainsString('Domain not found: mail.example.com', $result->message);
    }

    public function testANetworkFailureIsASentenceRatherThanAnException(): void
    {
        $http = (new FakeHttp())->queue('GET', self::WEBHOOKS, 0, null, 'Could not resolve host: api.mailgun.net');

        $result = $this->button($http)->create(self::URL, self::EVENTS, self::config());

        self::assertFalse($result->ok);
        self::assertStringContainsString('Mailgun could not be reached', $result->message);
        self::assertStringContainsString('Could not resolve host', $result->message);
    }

    /**
     * A domain sending key can make the webhooks and cannot read the account's
     * signing key, which is ordinary rather than a failure.
     */
    public function testAKeyThatCannotReadTheSigningKeyStillMakesTheWebhooks(): void
    {
        $http = (new FakeHttp())->queue('GET', self::WEBHOOKS, 200, ['webhooks' => []]);

        foreach (range(1, 6) as $ignored) {
            $http->queue('POST', self::WEBHOOKS, 200, ['message' => 'Webhook has been created']);
        }

        $http->queue('GET', self::KEY_PATH, 401, ['message' => 'Invalid private key']);

        $kept = [];
        $result = $this->button($http, static function (string $key) use (&$kept): void {
            $kept[] = $key;
        })->create(self::URL, self::EVENTS, self::config());

        self::assertTrue($result->ok, 'the webhooks were made, which is what the button was for');
        self::assertSame([], $kept);
        self::assertStringContainsString('paste it here by hand', $result->message);
        self::assertStringContainsString('API Security', $result->message);
    }

    /** An event type already holding Mailgun's three URLs is a plain refusal. */
    public function testAnEventTypeAlreadyHoldingThreeUrlsIsRefusedByName(): void
    {
        $http = (new FakeHttp())->queue('GET', self::WEBHOOKS, 200, ['webhooks' => [
            'delivered' => ['urls' => ['https://a.example/1', 'https://b.example/2', 'https://c.example/3']],
        ]]);

        $result = $this->button($http)->create(self::URL, self::EVENTS, self::config());

        self::assertFalse($result->ok);
        self::assertStringContainsString('"delivered" already has three', $result->message);
        self::assertStringContainsString('press the button again', $result->message);
    }

    public function testTheMissingSettingsAreSaidOneAtATime(): void
    {
        $setup = $this->button(new FakeHttp());

        self::assertStringContainsString(
            'no Mailgun API key',
            $setup->create(self::URL, self::EVENTS, ['domain' => 'mail.example.com'])->message
        );

        self::assertStringContainsString(
            'no Mailgun sending domain',
            $setup->create(self::URL, self::EVENTS, ['api_key' => 'k'])->message
        );

        self::assertStringContainsString(
            'no webhook address',
            $setup->create('  ', self::EVENTS, self::config())->message
        );

        self::assertStringContainsString(
            'None of the events',
            $setup->create(self::URL, ['dropped'], self::config())->message
        );
    }

    /** The EU account is a different installation, and its calls go there. */
    public function testTheEuRegionCallsTheEuHost(): void
    {
        $http = (new FakeHttp())
            ->queue('GET', self::WEBHOOKS, 200, ['webhooks' => []])
            ->queue('GET', self::KEY_PATH, 200, ['http_signing_key' => 'k']);

        foreach (range(1, 6) as $ignored) {
            $http->queue('POST', self::WEBHOOKS, 200, []);
        }

        $this->button($http)->create(self::URL, self::EVENTS, array_merge(self::config(), ['region' => 'eu']));

        foreach ($http->calls as $call) {
            self::assertStringStartsWith(MailgunApi::EU, $call['url']);
        }
    }

    /** A bounce is two of Mailgun's types, and they are the only two. */
    public function testABounceAloneAsksForBothFailureTypes(): void
    {
        self::assertSame(['permanent_fail', 'temporary_fail'], MailgunSetup::typesFor([Event::BOUNCED]));
        self::assertSame([], MailgunSetup::typesFor([Event::DROPPED]));
    }

    /** The sentence a merchant reads before pressing the button. */
    public function testThePermissionsSentenceNamesTheKeyAndWhereItIs(): void
    {
        $said = $this->button(new FakeHttp())->permissionsNeeded();

        self::assertStringContainsString('account key', $said);
        self::assertStringContainsString('domain sending key', $said);
        self::assertStringContainsString('API Security', $said);
    }

    // ------------------------------------------------------------- internals

    private function button(FakeHttp $http, ?\Closure $keeper = null): MailgunSetup
    {
        return new MailgunSetup(new MailgunApi($http), $keeper ?? static function (string $key): void {
        });
    }

    /** @return array<string, string> */
    private static function config(): array
    {
        return ['api_key' => 'the-api-key', 'domain' => 'mail.example.com', 'region' => 'us'];
    }
}
