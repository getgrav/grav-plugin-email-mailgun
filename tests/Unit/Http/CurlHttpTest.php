<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Tests\Unit\Http;

use Grav\Plugin\EmailMailgun\Http\CurlHttp;
use PHPUnit\Framework\TestCase;

/**
 * The one piece of wire format this plugin writes by hand.
 *
 * cURL will build a multipart body from an array, but it will not repeat a
 * field name — and repeating a field name is exactly how Mailgun is told that
 * an event type has more than one URL on it. So the body is built here, and
 * this is the test that says it is built the way the documented `curl -F`
 * examples build it.
 *
 * The rest of {@see CurlHttp} is cURL's, and a test that mocked cURL would be a
 * test of the mock.
 */
final class CurlHttpTest extends TestCase
{
    public function testAFieldIsOnePartAndAListIsSeveralUnderTheSameName(): void
    {
        $body = CurlHttp::multipart(['id' => 'delivered', 'url' => ['https://a.example', 'https://b.example']], 'B');

        self::assertSame(
            "--B\r\n"
            . "Content-Disposition: form-data; name=\"id\"\r\n\r\ndelivered\r\n"
            . "--B\r\n"
            . "Content-Disposition: form-data; name=\"url\"\r\n\r\nhttps://a.example\r\n"
            . "--B\r\n"
            . "Content-Disposition: form-data; name=\"url\"\r\n\r\nhttps://b.example\r\n"
            . "--B--\r\n",
            $body
        );
    }

    public function testAnEmptyBodyIsStillAClosedOne(): void
    {
        self::assertSame("--B--\r\n", CurlHttp::multipart([], 'B'));
    }

    /**
     * An API key travels in the `Authorization` header of every one of these
     * calls, so a plain HTTP address is refused before anything is sent.
     */
    public function testOnlyHttpsAddressesAreCalled(): void
    {
        $answer = (new CurlHttp())->get('http://api.mailgun.net/v3/domains');

        self::assertSame(0, $answer['status']);
        self::assertStringContainsString('only https', $answer['error']);
    }
}
