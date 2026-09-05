<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailMailgun\Provider\MailgunReports;
use PHPUnit\Framework\TestCase;

/**
 * Mailgun's own documented payloads, read.
 *
 * Moved from the KahunaCart newsletter add-on's `ParserTest`, which held the
 * same fixtures and the same expectations for six providers at once. The
 * fixtures are byte for byte the ones that were there; the expectations are the
 * same facts written in the contract's vocabulary rather than that add-on's —
 * `bounced` where it said `bounce`, a send id as a string rather than an int.
 *
 * ## Where the fixtures came from
 *
 * Copied out of Mailgun's own documentation on 2026-09-04. Mailgun prints the
 * `event-data` object and the `signature` object on two different pages and
 * never prints an envelope, so each fixture here is the verbatim `event-data`
 * inside the verbatim `signature`, paired.
 *
 * That matters more than it sounds. Mailgun has renamed a payload field before,
 * and a parser written against a payload somebody remembered is a parser that
 * reads null forever without failing anything. A fixture taken from the
 * documentation and a test that reads it is the only thing that turns a rename
 * from a store that quietly stops recording bounces into a red bar.
 */
final class MailgunParserTest extends TestCase
{
    /**
     * Every documented sample, and the event it has to become.
     *
     * Written out longhand rather than generated, because a table built from
     * the same constants the parser reads would agree with the code however
     * wrong both were.
     *
     * @return iterable<string, array{0: string, 1: array<string, mixed>|null}>
     */
    public static function samples(): iterable
    {
        yield 'delivered' => ['delivered', [
            'type' => Event::DELIVERED,
            'hard' => null,
            'message_id' => '20260203192030.53383e583ab41f62@sample.mailgun.com',
            'email' => 'recipient@sample.mailgun.com',
        ]];

        // `permanent_fail` is a subscription name and never a payload value:
        // both failures arrive as `failed` and are told apart by `severity`.
        yield 'permanent failure' => ['failed-permanent', [
            'type' => Event::BOUNCED,
            'hard' => true,
            'email' => 'badrecipient@sample.mailgun.com',
        ]];

        yield 'temporary failure' => ['failed-temporary', [
            'type' => Event::BOUNCED,
            'hard' => false,
        ]];

        // A `failed` Mailgun never attempted: the address was already on one of
        // its own suppression lists, so nothing was handed to a receiving
        // server and this is a drop rather than a bounce.
        yield 'suppressed before it was sent' => ['failed-suppressed', [
            'type' => Event::DROPPED,
            'hard' => null,
            'email' => 'previously-bounced@sample.mailgun.com',
            'reason' => 'Not delivering to previously bounced address — suppress-bounce',
        ]];

        yield 'complaint' => ['complained', [
            'type' => Event::COMPLAINED,
            'hard' => null,
        ]];

        yield 'open' => ['opened', ['type' => Event::OPENED]];
        yield 'click' => ['clicked', ['type' => Event::CLICKED]];
    }

    /**
     * @param array<string, mixed>|null $expected null means "read no events"
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('samples')]
    public function testTheDocumentedSampleBecomesTheContractsEvent(string $fixture, ?array $expected): void
    {
        $payload = self::reports()->parse(self::request($fixture));

        if ($expected === null) {
            self::assertTrue($payload->isEmpty(), 'this sample is not one a store acts on');
            self::assertNotSame('', $payload->note, 'and it should say why');

            return;
        }

        self::assertCount(1, $payload->events, 'one documented sample is one event');
        $event = $payload->events[0]->toArray();

        foreach ($expected as $field => $value) {
            self::assertSame($value, $event[$field], "{$fixture}: {$field}");
        }
    }

    /**
     * Every sample carries a moment, because a chart with a null on it is a
     * chart with a gap in it.
     *
     * A date format nobody parsed reads as zero and gets quietly stamped with
     * the receiver's clock, and a whole store's charts are then wrong in a way
     * nobody can see.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('samples')]
    public function testEverySampleCarriesAMomentThatWasActuallyRead(string $fixture, ?array $expected): void
    {
        if ($expected === null) {
            self::assertTrue(true);

            return;
        }

        $event = self::reports()->parse(self::request($fixture))->events[0];

        self::assertGreaterThan(
            946684800,
            $event->at,
            "{$fixture}: the timestamp was not read, so the receiver's clock would stand in for it"
        );
    }

    /**
     * An event type this store does not act on is a note, not a refusal.
     *
     * Mailgun sends more than the five events the contract knows —
     * `accepted`, `unsubscribed`, `delivery_delayed` — and a store that ticked
     * every box in Mailgun's dashboard should get a 200 and a quiet log line
     * rather than a 400 that makes Mailgun retry for a week.
     */
    public function testAnEventTypeWeDoNotActOnIsSkippedRatherThanRefused(): void
    {
        foreach (['accepted', 'unsubscribed'] as $fixture) {
            $payload = self::reports()->parse(self::request($fixture));

            self::assertTrue($payload->isEmpty(), "{$fixture} should be skipped");
            self::assertFalse($payload->unreadable, "{$fixture} is readable, just uninteresting");
            self::assertMatchesRegularExpression('/act on/', $payload->note, $fixture);
        }
    }

    /**
     * A body that is not JSON at all is a note and no events, never an
     * exception.
     *
     * `parse()` runs on a public address anybody can post to, and the caller
     * answers 200 to a body it could not read — because a 4xx is what makes
     * Mailgun retry for days.
     */
    public function testAnUnreadableBodyIsANoteRatherThanAnException(): void
    {
        foreach (['this is not json', '', '[1,2,3]', '{"nothing":true}'] as $body) {
            $payload = self::reports()->parse(new WebhookRequest('POST', '/', [], [], $body));

            self::assertTrue($payload->isEmpty(), $body);
            self::assertTrue($payload->unreadable, $body);
            self::assertNotSame('', $payload->note, $body);
        }
    }

    /**
     * Mailgun sends `[]` rather than `{}` when there are no user variables,
     * which in PHP is a list where a map was expected.
     */
    public function testAnEmptyUserVariablesListIsSurvived(): void
    {
        $body = (string)json_encode([
            'signature' => ['timestamp' => '1737000000', 'token' => 't', 'signature' => 's'],
            'event-data' => [
                'event' => 'delivered',
                'timestamp' => 1737000000,
                'recipient' => 'a@example.com',
                'user-variables' => [],
                'storage' => [],
            ],
        ]);

        $event = self::reports()->parse(new WebhookRequest('POST', '/', [], [], $body))->events[0];

        self::assertNull($event->sendId);
        self::assertSame('a@example.com', $event->email);
    }

    /**
     * A store that has wired up a Mailgun user variable gets its send id back.
     *
     * Mailgun lower-cases variable names, so both spellings are read.
     */
    public function testASendIdIsReadOutOfTheUserVariables(): void
    {
        foreach ([SendHeader::name(), strtolower(SendHeader::name())] as $spelling) {
            $body = (string)json_encode([
                'signature' => ['timestamp' => '1737000000', 'token' => 't', 'signature' => 's'],
                'event-data' => [
                    'event' => 'delivered',
                    'timestamp' => 1737000000,
                    'recipient' => 'a@example.com',
                    'user-variables' => [$spelling => '41'],
                ],
            ]);

            $event = self::reports()->parse(new WebhookRequest('POST', '/', [], [], $body))->events[0];

            self::assertSame('41', $event->sendId, $spelling);
        }
    }

    /**
     * The receiving server's own words come first, because "550 5.5.0 mailbox
     * unavailable" is what a merchant wants; Mailgun's one-word `reason` after
     * it, because on its own it explains nothing.
     */
    public function testABouncesReasonIsTheServersLineThenMailgunsWord(): void
    {
        $event = self::reports()->parse(self::request('failed-permanent'))->events[0];

        self::assertNotNull($event->reason);
        self::assertStringContainsString('5.5.0 Requested action not taken', $event->reason);
        self::assertStringEndsWith('— bounce', $event->reason);
    }

    /** A complaint has no server line, so it gets a sentence of its own. */
    public function testAComplaintWithNoReasonStillSaysSomething(): void
    {
        self::assertSame('marked as spam', self::reports()->parse(self::request('complained'))->events[0]->reason);
    }

    /**
     * An open and a click carry only `message-id` in `message.headers`, so it
     * is not merely the best handle there, it is the only one.
     */
    public function testAnOpenCorrelatesOnTheMessageIdAlone(): void
    {
        $event = self::reports()->parse(self::request('opened'))->events[0];

        self::assertSame('20260205213049.8e3a7bf607f78309@sample.mailgun.com', $event->messageId);
        self::assertSame('q7DMpbLFRKW1QuiLC9XV4Q', $event->providerId);
    }

    // ------------------------------------------------------------- internals

    private static function reports(): MailgunReports
    {
        return new MailgunReports();
    }

    private static function request(string $fixture): WebhookRequest
    {
        return new WebhookRequest(
            'POST',
            '/webhook/mailgun',
            [],
            ['content-type' => 'application/json'],
            self::body($fixture)
        );
    }

    public static function body(string $fixture): string
    {
        $path = \dirname(__DIR__, 2) . "/fixtures/webhooks/{$fixture}.json";
        self::assertFileExists($path, "there is no documented sample at {$path}");

        return (string)file_get_contents($path);
    }
}
