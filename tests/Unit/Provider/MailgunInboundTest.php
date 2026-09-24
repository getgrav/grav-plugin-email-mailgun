<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Inbound\Address;
use Grav\Plugin\Email\Providers\Inbound\InboundAttachment;
use Grav\Plugin\Email\Providers\Inbound\InboundCapable;
use Grav\Plugin\Email\Providers\Inbound\InboundGateway;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\Inbound\InboundUpload;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\EmailMailgun\Provider\MailgunInbound;
use Grav\Plugin\EmailMailgun\Provider\MailgunInboundProvider;
use Grav\Plugin\EmailMailgun\Provider\MailgunProvider;
use Grav\Plugin\EmailMailgun\Provider\MailgunReports;
use Grav\Plugin\EmailMailgun\Provider\ReplayCache;
use Grav\Plugin\EmailMailgun\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * Mailgun route posts, from recorded payloads built on the field lists in
 * Mailgun's `receive-http` and `storing-and-retrieving-messages` pages.
 *
 * Every signature is computed here and then broken: a wrong key, a stale
 * timestamp and a token used twice are each a real attack and each refused.
 */
final class MailgunInboundTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/inbound/mailgun/';
    private const KEY = 'the-http-webhook-signing-key';
    private const NOW = 1790085900;

    // ------------------------------------------------------------ verify

    public function testASignedPostIsVerifiedFromTheFormFields(): void
    {
        $verdict = self::receiver()->verify(self::parsedRequest(self::signed([])), [MailgunReports::SIGNING_KEY => self::KEY]);

        self::assertTrue($verdict->ok, $verdict->reason);
        self::assertTrue($verdict->signed);
    }

    public function testABadSignatureIsRefused(): void
    {
        $receiver = self::receiver();

        $wrongKey = self::signed([], 'the-sending-api-key');
        $verdict = $receiver->verify(self::parsedRequest($wrongKey), [MailgunReports::SIGNING_KEY => self::KEY]);
        self::assertFalse($verdict->ok);
        self::assertStringContainsString('did not match', $verdict->reason);

        // A genuine signature over a different token.
        $swapped = self::signed([]);
        $swapped['token'] = str_repeat('b', 50);
        self::assertFalse($receiver->verify(self::parsedRequest($swapped), [MailgunReports::SIGNING_KEY => self::KEY])->ok);

        // No signature fields at all, and no key configured.
        self::assertFalse($receiver->verify(self::parsedRequest(['recipient' => 'x@example.com']), [MailgunReports::SIGNING_KEY => self::KEY])->ok);
        self::assertFalse($receiver->verify(self::parsedRequest(self::signed([])), [])->ok);
    }

    public function testTheJsonSignatureBlockOfAnEventWebhookIsNotAccepted(): void
    {
        $signed = self::signed([]);
        $json = (string)json_encode(['signature' => [
            'timestamp' => $signed['timestamp'], 'token' => $signed['token'], 'signature' => $signed['signature'],
        ], 'event-data' => []]);

        $verdict = self::receiver()->verify(
            new InboundRequest(headers: ['content-type' => 'application/json'], body: $json),
            [MailgunReports::SIGNING_KEY => self::KEY]
        );

        self::assertFalse($verdict->ok);
    }

    public function testAStaleTimestampIsRefused(): void
    {
        $receiver = self::receiver();
        $config = [MailgunReports::SIGNING_KEY => self::KEY];

        $stale = self::signed([], self::KEY, self::NOW - MailgunReports::TOLERANCE - 1);
        self::assertFalse($receiver->verify(self::parsedRequest($stale), $config)->ok);

        $future = self::signed([], self::KEY, self::NOW + MailgunReports::TOLERANCE + 1);
        self::assertFalse($receiver->verify(self::parsedRequest($future), $config)->ok);

        $edge = self::signed([], self::KEY, self::NOW - MailgunReports::TOLERANCE);
        self::assertTrue($receiver->verify(self::parsedRequest($edge), $config)->ok);
    }

    public function testAReplayedTokenIsRefused(): void
    {
        $receiver = self::receiver();
        $config = [MailgunReports::SIGNING_KEY => self::KEY];
        $fields = self::signed([]);

        self::assertTrue($receiver->verify(self::parsedRequest($fields), $config)->ok);
        $again = $receiver->verify(self::parsedRequest($fields), $config);
        self::assertFalse($again->ok);
        self::assertStringContainsString('already used', $again->reason);
    }

    public function testAForgedPostDoesNotSpendAToken(): void
    {
        $receiver = self::receiver();
        $config = [MailgunReports::SIGNING_KEY => self::KEY];
        $genuine = self::signed([]);
        $forged = $genuine;
        $forged['signature'] = str_repeat('0', 64);

        self::assertFalse($receiver->verify(self::parsedRequest($forged), $config)->ok);
        self::assertTrue($receiver->verify(self::parsedRequest($genuine), $config)->ok, 'the forgery must not block the real post');
    }

    public function testTheReplayCacheUsesAPsr16StoreAcrossInstances(): void
    {
        $store = new class {
            /** @var array<string, int> */
            public array $items = [];
            public ?int $ttl = null;

            public function has(string $key): bool
            {
                return isset($this->items[$key]);
            }

            public function set(string $key, mixed $value, ?int $ttl = null): bool
            {
                $this->items[$key] = (int)$value;
                $this->ttl = $ttl;

                return true;
            }
        };

        $config = [MailgunReports::SIGNING_KEY => self::KEY];
        $fields = self::signed([]);
        $clock = static fn (): int => self::NOW;

        // Two receivers, as two requests would have, sharing one store.
        $first = new MailgunInbound([], new ReplayCache($store, $clock), null, $clock);
        $second = new MailgunInbound([], new ReplayCache($store, $clock), null, $clock);

        self::assertTrue($first->verify(self::parsedRequest($fields), $config)->ok);
        self::assertFalse($second->verify(self::parsedRequest($fields), $config)->ok);
        self::assertSame(2 * MailgunReports::TOLERANCE, $store->ttl);
        self::assertStringNotContainsString($fields['token'], (string)array_key_first($store->items), 'the token is stored hashed');
    }

    public function testTheInMemoryReplayCacheForgetsAfterItsTtl(): void
    {
        $now = 1000;
        $cache = new ReplayCache(null, static function () use (&$now): int {
            return $now;
        });

        self::assertFalse($cache->seen('t', 60));
        self::assertTrue($cache->seen('t', 60));
        $now += 61;
        self::assertFalse($cache->seen('t', 60));
    }

    // ------------------------------------------------------------- parse

    public function testAParsedForwardWithTwoAttachmentsIsReadFromFieldsAndFiles(): void
    {
        [$fields, $files] = self::parsedFixture();
        $payload = self::receiver()->parse(self::parsedRequest($fields, $files), []);

        self::assertFalse($payload->unreadable, $payload->note);
        $message = $payload->items[0];
        self::assertInstanceOf(InboundMessage::class, $message);

        self::assertNull($message->raw);
        self::assertSame('mailgun', $message->receiver);
        self::assertSame('caf-reply-002@mail.example.net', $message->messageId);
        self::assertSame('ticket-42.abc@example.com', $message->inReplyTo);
        self::assertSame(['ticket-42.root@example.com', 'ticket-42.abc@example.com'], $message->references);
        self::assertSame(['customer@example.net', 'Ada Lovelace'], [$message->from->email, $message->from->name]);
        self::assertSame('Re: Café order', $message->subject);
        self::assertSame(gmmktime(14, 5, 0, 9, 22, 2026), $message->date);

        // The envelope is the SMTP recipient, with the plus token the visible To lost.
        self::assertSame(['support+t8f2k@inbound.example.com'], $message->envelopeTo);
        self::assertSame('t8f2k', (new Address($message->envelopeTo[0]))->detail());
        self::assertSame('support@inbound.example.com', $message->to[0]->email);
        self::assertSame('customer@example.net', $message->envelopeFrom);

        self::assertSame('Thanks, the café invoice is attached.', $message->providerStrippedText);
        self::assertStringNotContainsString("\r", (string)$message->text);
        self::assertSame(0.3, $message->spamScore);
        self::assertSame(['spf' => 'pass', 'dkim' => 'pass'], $message->auth);
        self::assertSame('multipart/mixed', $message->contentType, 'the structured Content-Type header is put back together');

        self::assertCount(2, $message->attachments);
        [$logo, $invoice] = $message->attachments;
        self::assertSame(['logo.png', 'image/png', 8, 'logo@example.net', true], [$logo->filename, $logo->contentType, $logo->size, $logo->contentId, $logo->inline]);
        self::assertNull($logo->content, 'an uploaded file stays on disk');
        self::assertSame("\x89PNG\r\n\x1a\n", $logo->bytes());
        self::assertSame(['invoice.pdf', 'application/pdf', null, false], [$invoice->filename, $invoice->contentType, $invoice->contentId, $invoice->inline]);
        self::assertSame("%PDF-1.4\n", $invoice->bytes());
    }

    public function testAMultipartPostIsNeverReadFromTheBody(): void
    {
        [$fields, $files] = self::parsedFixture();
        $request = new InboundRequest(
            headers: ['content-type' => 'multipart/form-data; boundary=x'],
            body: 'this is not what Mailgun sent and must be ignored',
            parsedBody: $fields,
            files: $files,
        );

        $message = self::receiver()->parse($request, [])->items[0];
        self::assertSame('caf-reply-002@mail.example.net', $message->messageId);
    }

    public function testABodyMimeForwardMatchesTheParsedOne(): void
    {
        parse_str((string)file_get_contents(self::FIXTURES . 'forward-mime.txt'), $mimeFields);
        $body = http_build_query(self::signed($mimeFields));

        // The wire format: url-encoded, left unparsed by the caller.
        $request = new InboundRequest(headers: ['content-type' => 'application/x-www-form-urlencoded'], body: $body);
        $receiver = self::receiver();
        self::assertTrue($receiver->verify($request, [MailgunReports::SIGNING_KEY => self::KEY])->ok);
        $raw = $receiver->parse($request, [])->items[0];

        self::assertInstanceOf(InboundMessage::class, $raw);
        self::assertSame((string)file_get_contents(self::FIXTURES . 'message.eml'), $raw->raw);

        [$fields, $files] = self::parsedFixture();
        $parsed = self::receiver()->parse(self::parsedRequest($fields, $files), [])->items[0];

        self::assertSame(self::comparable($parsed), self::comparable($raw));
    }

    public function testAStoreNotificationIsAReferenceAndFetchDownloadsTheMessage(): void
    {
        $fields = json_decode((string)file_get_contents(self::FIXTURES . 'store-notify.json'), true);
        $payload = self::receiver()->parse(self::parsedRequest($fields), []);

        self::assertFalse($payload->unreadable, $payload->note);
        $ref = $payload->items[0];
        self::assertInstanceOf(InboundReference::class, $ref);
        self::assertSame('mailgun', $ref->receiver);
        self::assertSame('BAABAQhhLyK0aW5ib3VuZC5leGFtcGxlLmNvbQ', $ref->id);
        self::assertSame($fields['message-url'], $ref->meta['url']);
        self::assertSame('caf-reply-002@mail.example.net', $ref->meta['message_id']);
        self::assertSame('support+t8f2k@inbound.example.com', $ref->meta['recipient']);
        self::assertNotFalse(json_encode($ref->meta), 'meta is stored as JSON by consumers');

        $http = (new FakeHttp())->queue(
            'GET',
            '/v3/domains/inbound.example.com/messages/BAABAQhhLyK0aW5ib3VuZC5leGFtcGxlLmNvbQ',
            200,
            json_decode((string)file_get_contents(self::FIXTURES . 'stored-message.json'), true)
        );

        $message = (new MailgunInbound([], null, $http))->fetch($ref, ['api_key' => 'key-abc']);

        self::assertSame(['GET /v3/domains/inbound.example.com/messages/BAABAQhhLyK0aW5ib3VuZC5leGFtcGxlLmNvbQ'], $http->trail());
        self::assertSame('message/rfc2822', $http->calls[0]['headers']['Accept']);
        self::assertSame('Basic ' . base64_encode('api:key-abc'), $http->calls[0]['headers']['Authorization']);

        self::assertSame((string)file_get_contents(self::FIXTURES . 'message.eml'), $message->raw);
        self::assertSame('BAABAQhhLyK0aW5ib3VuZC5leGFtcGxlLmNvbQ', $message->providerId);
        self::assertSame(['support+t8f2k@inbound.example.com'], $message->envelopeTo);
        self::assertSame('customer@example.net', $message->envelopeFrom);
        self::assertSame('Thanks, the café invoice is attached.', $message->providerStrippedText);
        self::assertSame('caf-reply-002@mail.example.net', $message->messageId);
        self::assertCount(2, $message->attachments);
    }

    public function testFetchUsesThePluginsOwnApiKeyWhenTheCallerPassesNone(): void
    {
        $http = (new FakeHttp())->queue('GET', '/v3/domains/d/messages/k', 200, ['body-mime' => "Subject: hi\r\n\r\nbody"]);
        $ref = new InboundReference('mailgun', 'k', ['url' => 'https://storage-us-east4.api.mailgun.net/v3/domains/d/messages/k']);

        (new MailgunInbound(['api_key' => 'key-from-plugin'], null, $http))->fetch($ref, []);

        self::assertSame('Basic ' . base64_encode('api:key-from-plugin'), $http->calls[0]['headers']['Authorization']);
    }

    public function testFetchNeverSendsTheKeyAnywhereButMailgun(): void
    {
        foreach ([
            'https://evil.example.com/v3/domains/d/messages/k',
            'https://mailgun.net.evil.example.com/x',
            'https://evilmailgun.net/x',
            'http://storage.api.mailgun.net/v3/domains/d/messages/k',
            'https://user@storage.api.mailgun.net/x',
            'https://storage.api.mailgun.net:8443/x',
        ] as $url) {
            $http = new FakeHttp();
            try {
                (new MailgunInbound([], null, $http))->fetch(new InboundReference('mailgun', 'k', ['url' => $url]), ['api_key' => 'key-abc']);
                self::fail('fetched ' . $url);
            } catch (\RuntimeException) {
            }
            self::assertSame([], $http->calls, $url);
        }

        // parse() refuses the same URLs before any reference is stored.
        $payload = self::receiver()->parse(self::parsedRequest(['message-url' => 'https://evil.example.com/x']), []);
        self::assertTrue($payload->unreadable);
    }

    public function testFetchFailuresThrowSoTheWorkerRetries(): void
    {
        $ref = new InboundReference('mailgun', 'k', ['url' => 'https://storage.api.mailgun.net/v3/domains/d/messages/k']);

        foreach ([[404, null], [500, ['message' => 'boom']], [200, ['recipient' => 'x']], [0, null]] as [$status, $body]) {
            $http = (new FakeHttp())->queue('GET', '/v3/domains/d/messages/k', $status, $body);
            try {
                (new MailgunInbound([], null, $http))->fetch($ref, ['api_key' => 'key-abc']);
                self::fail('no exception for ' . $status);
            } catch (\RuntimeException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }

        $this->expectException(\RuntimeException::class);
        (new MailgunInbound([], null, new FakeHttp()))->fetch($ref, []);
    }

    public function testAnythingElseIsUnreadableRatherThanAnError(): void
    {
        $receiver = self::receiver();

        foreach ([
            new InboundRequest(),
            new InboundRequest(headers: ['content-type' => 'application/json'], body: '{"event-data":{}}'),
            new InboundRequest(parsedBody: ['foo' => 'bar']),
        ] as $request) {
            $payload = $receiver->parse($request, []);
            self::assertTrue($payload->unreadable);
            self::assertSame([], $payload->items);
        }

        // Wrong types where strings belong: still a message, never a TypeError.
        $payload = $receiver->parse(self::parsedRequest([
            'recipient' => ['x'], 'message-headers' => '{not json', 'body-html' => [['x'], 'ok'],
            'content-id-map' => 12, 'from' => 'a@example.com',
        ], [new InboundUpload('attachment-1', '../../etc/passwd', '', 3, '/nonexistent', 0)]), []);
        self::assertFalse($payload->unreadable, $payload->note);
        self::assertSame('passwd', $payload->items[0]->attachments[0]->filename);
    }

    // --------------------------------------------------- provider and gateway

    public function testTheProviderOffersTheReceiverAndTheGatewayRunsIt(): void
    {
        $provider = new MailgunInboundProvider([MailgunReports::SIGNING_KEY => self::KEY]);

        self::assertInstanceOf(MailgunInboundProvider::class, $provider);
        self::assertInstanceOf(MailgunProvider::class, $provider);
        self::assertInstanceOf(InboundCapable::class, $provider);

        $registry = new ProviderRegistry();
        $registry->add(new MailgunInboundProvider(
            [MailgunReports::SIGNING_KEY => self::KEY],
            null,
            null,
            new ReplayCache(),
        ));
        $gateway = new InboundGateway(null, $registry);
        self::assertInstanceOf(MailgunInbound::class, $gateway->receiver('mailgun'));

        [$fields, $files] = self::parsedFixture();
        $fields = self::signed($fields, self::KEY, time());

        // The plugin's own signing key applies when the consumer passes only its own settings.
        $result = $gateway->receive('mailgun', self::parsedRequest($fields, $files), ['max_bytes' => 10 * 1024 * 1024]);
        self::assertSame(200, $result->status, $result->verdict->reason);
        self::assertCount(1, $result->messages());

        $replayed = $gateway->receive('mailgun', self::parsedRequest($fields, $files), []);
        self::assertSame(401, $replayed->status);
    }

    public function testTheInstructionsNameTheRouteTheKeyAndTheMimeAddress(): void
    {
        $receiver = new MailgunInbound([MailgunReports::SIGNING_KEY => 'do-not-print-me']);
        $text = $receiver->instructions('https://example.com/_helpdesk/inbound/mailgun/abc123');

        self::assertStringContainsString('https://example.com/_helpdesk/inbound/mailgun/abc123 ', $text);
        self::assertStringContainsString('https://example.com/_helpdesk/inbound/mailgun/abc123?format=mime', $text);
        self::assertStringContainsString('mxa.mailgun.org', $text);
        self::assertStringContainsString('Webhook Signing Key', $text);
        self::assertStringNotContainsString('do-not-print-me', $text);
        self::assertSame([MailgunReports::SIGNING_KEY], $receiver->verificationKeys());
        self::assertGreaterThan(25 * 1024 * 1024, $receiver->maxBytes());
    }

    // ------------------------------------------------------------ helpers

    private static function receiver(): MailgunInbound
    {
        $clock = static fn (): int => self::NOW;

        return new MailgunInbound([], new ReplayCache(null, $clock), null, $clock);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function signed(array $fields, string $key = self::KEY, int $time = self::NOW): array
    {
        $token = bin2hex(random_bytes(25));
        $fields['timestamp'] = (string)$time;
        $fields['token'] = $token;
        $fields['signature'] = hash_hmac('sha256', $time . $token, $key);

        return $fields;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<InboundUpload>  $files
     */
    private static function parsedRequest(array $fields, array $files = []): InboundRequest
    {
        return new InboundRequest(
            headers: ['content-type' => $files === [] ? 'application/x-www-form-urlencoded' : 'multipart/form-data; boundary=x'],
            body: '',
            parsedBody: $fields,
            files: $files,
        );
    }

    /** @return array{0: array<string, mixed>, 1: list<InboundUpload>} */
    private static function parsedFixture(): array
    {
        $fixture = json_decode((string)file_get_contents(self::FIXTURES . 'forward-parsed.json'), true);
        $files = array_map(
            static fn (array $f): InboundUpload => new InboundUpload($f['field'], $f['filename'], $f['type'], $f['size'], self::FIXTURES . $f['path']),
            $fixture['files']
        );

        return [$fixture['fields'], $files];
    }

    /**
     * Every field a consumer reads, as plain data. `raw` and `headers` are
     * left out on purpose: the parsed forward has no raw bytes, and its
     * header list is Mailgun's.
     *
     * @return array<string, mixed>
     */
    private static function comparable(InboundMessage $m): array
    {
        $addresses = static fn (array $list): array => array_map(
            static fn (Address $a): array => [$a->email, $a->name],
            $list
        );

        return [
            'messageId' => $m->messageId,
            'inReplyTo' => $m->inReplyTo,
            'references' => $m->references,
            'from' => [$m->from->email, $m->from->name],
            'to' => $addresses($m->to),
            'cc' => $addresses($m->cc),
            'replyTo' => $addresses($m->replyTo),
            'envelopeTo' => $m->envelopeTo,
            'envelopeFrom' => $m->envelopeFrom,
            'subject' => $m->subject,
            'date' => $m->date,
            'text' => $m->text,
            'html' => $m->html,
            'providerStrippedText' => $m->providerStrippedText,
            'attachments' => array_map(static fn (InboundAttachment $a): array => [
                $a->filename, $a->contentType, $a->size, $a->contentId, $a->inline, $a->bytes(),
            ], $m->attachments),
            'auth' => $m->auth,
            'spamScore' => $m->spamScore,
            'receiver' => $m->receiver,
            'contentType' => $m->contentType,
        ];
    }
}
