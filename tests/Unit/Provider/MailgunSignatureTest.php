<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailMailgun\Provider\MailgunReports;
use PHPUnit\Framework\TestCase;

/**
 * The signature, computed here rather than pasted, and then broken.
 *
 * Moved from the KahunaCart newsletter add-on's `SignatureTest`, which held the
 * same three cases among the signature tests for six providers.
 *
 * Computing the signature in the test rather than pasting one is what makes the
 * negative cases mean anything: a pasted signature can only ever prove that one
 * string does not equal another, whereas a computed one proves the verifier
 * agrees with Mailgun's documented algorithm and then disagrees with everything
 * else.
 */
final class MailgunSignatureTest extends TestCase
{
    public function testABadHmacIsRefused(): void
    {
        $key = 'the-http-webhook-signing-key';
        $now = 1737000000;
        $token = str_repeat('a', 50);

        $reports = new MailgunReports(static fn (): int => $now);

        $genuine = self::body($key, (string)$now, $token);
        $verdict = $reports->verify(self::request($genuine), [MailgunReports::SIGNING_KEY => $key]);
        self::assertTrue($verdict->ok);
        self::assertTrue($verdict->signed, 'Mailgun signs, so a good one is verified rather than merely unsigned');

        // Signed with a different key, which is the mistake a merchant actually
        // makes: Mailgun's sending key and its webhook signing key are two
        // different strings in two different parts of their dashboard.
        $wrongKey = self::body('the-sending-api-key', (string)$now, $token);
        $verdict = $reports->verify(self::request($wrongKey), [MailgunReports::SIGNING_KEY => $key]);
        self::assertFalse($verdict->ok);
        self::assertStringContainsString('signature did not match', $verdict->reason);
    }

    /**
     * No key configured at all is a refusal, not a shrug.
     *
     * Without this, the store's webhook address would be an unauthenticated
     * write endpoint on any site that had not filled the field in.
     */
    public function testAMissingKeyIsRefused(): void
    {
        $now = 1737000000;
        $reports = new MailgunReports(static fn (): int => $now);
        $body = self::body('any-key', (string)$now, str_repeat('a', 50));

        foreach ([[], [MailgunReports::SIGNING_KEY => ''], [MailgunReports::SIGNING_KEY => '   ']] as $config) {
            $verdict = $reports->verify(self::request($body), $config);

            self::assertFalse($verdict->ok);
            self::assertStringContainsString('no Mailgun signing key', $verdict->reason);
        }
    }

    /** A body with no signature block in it, and one with half of one. */
    public function testAnIncompleteSignatureBlockIsRefused(): void
    {
        $reports = new MailgunReports(static fn (): int => 1737000000);
        $config = [MailgunReports::SIGNING_KEY => 'a-key'];

        $none = $reports->verify(self::request('{"event-data":{}}'), $config);
        self::assertFalse($none->ok);
        self::assertStringContainsString('no signature block', $none->reason);

        $half = (string)json_encode(['signature' => ['timestamp' => '1737000000'], 'event-data' => []]);
        $verdict = $reports->verify(self::request($half), $config);
        self::assertFalse($verdict->ok);
        self::assertStringContainsString('incomplete', $verdict->reason);
    }

    /**
     * A subaccount's events carry a `parent-signature` against the parent
     * account's key, so a store on a subaccount can paste the one key it has.
     *
     * The token and timestamp come from Mailgun's own documented subaccount
     * signature block; only the two digests are computed, because theirs were
     * made with a key nobody has.
     */
    public function testTheParentSignatureIsAccepted(): void
    {
        $documented = json_decode(MailgunParserTest::body('signature-subaccount'), true);
        self::assertIsArray($documented);
        self::assertArrayHasKey('parent-signature', $documented, 'the documented block carries both digests');

        $parentKey = 'the-parent-accounts-signing-key';
        $now = 1737000000;
        $token = (string)$documented['token'];

        $body = (string)json_encode([
            'signature' => [
                'timestamp' => (string)$now,
                'token' => $token,
                'signature' => hash_hmac('sha256', $now . $token, 'some-other-key'),
                'parent-signature' => hash_hmac('sha256', $now . $token, $parentKey),
            ],
            'event-data' => ['event' => 'delivered'],
        ]);

        $reports = new MailgunReports(static fn (): int => $now);

        self::assertTrue($reports->verify(self::request($body), [MailgunReports::SIGNING_KEY => $parentKey])->ok);
    }

    /**
     * A signature over a timestamp and a token is valid forever unless the
     * timestamp is checked, so an old one is a replay and is refused.
     */
    public function testAStaleTimestampIsRefused(): void
    {
        $key = 'the-http-webhook-signing-key';
        $signedAt = 1737000000;
        $body = self::body($key, (string)$signedAt, str_repeat('c', 50));

        $stale = new MailgunReports(static fn (): int => $signedAt + 7200);
        $verdict = $stale->verify(self::request($body), [MailgunReports::SIGNING_KEY => $key]);
        self::assertFalse($verdict->ok);
        self::assertStringContainsString('tolerance', $verdict->reason);

        // Inside the window, the same body is fine — the window is Mailgun's
        // asked-for fifteen minutes rather than five, because a queue on their
        // side is a delay on ours.
        $fresh = new MailgunReports(static fn (): int => $signedAt + MailgunReports::TOLERANCE - 1);
        self::assertTrue($fresh->verify(self::request($body), [MailgunReports::SIGNING_KEY => $key])->ok);
    }

    /** A timestamp that is not a number at all never reaches the arithmetic. */
    public function testANonsenseTimestampIsRefused(): void
    {
        $reports = new MailgunReports(static fn (): int => 1737000000);

        $body = (string)json_encode([
            'signature' => ['timestamp' => 'yesterday', 'token' => 'abc', 'signature' => 'def'],
            'event-data' => ['event' => 'delivered'],
        ]);

        self::assertFalse($reports->verify(self::request($body), [MailgunReports::SIGNING_KEY => 'k'])->ok);
    }

    /**
     * The verifier reads the raw bytes rather than a re-encoded body.
     *
     * Mailgun's digest is over the timestamp and the token rather than the
     * whole body, so this one is cheap here — but it is the rule the contract
     * asks for and a rewrite of this class that decoded and re-encoded would
     * pass every other test in this file.
     */
    public function testTheSignedFieldsAreReadFromTheBodyAsItArrived(): void
    {
        $key = 'k';
        $now = 1737000000;
        $token = 'a-token-with  spaces  in  it';

        $body = "  \n" . self::body($key, (string)$now, $token) . "\n  ";

        $reports = new MailgunReports(static fn (): int => $now);

        self::assertTrue($reports->verify(self::request($body), [MailgunReports::SIGNING_KEY => $key])->ok);
    }

    // ------------------------------------------------------------- internals

    private static function request(string $body): WebhookRequest
    {
        return new WebhookRequest('POST', '/webhook/mailgun', [], ['content-type' => 'application/json'], $body);
    }

    private static function body(string $key, string $timestamp, string $token): string
    {
        return (string)json_encode([
            'signature' => [
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => hash_hmac('sha256', $timestamp . $token, $key),
            ],
            'event-data' => ['event' => 'delivered', 'recipient' => 'a@example.com'],
        ]);
    }
}
