<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Tests\Support;

use Grav\Plugin\EmailMailgun\Http\Http;

/**
 * {@see Http} with the answers written down in advance.
 *
 * A suite that reached Mailgun would be a suite that failed on a train, and one
 * that failed differently depending on what somebody had left in a Mailgun
 * account. This records every call so a test can say what was asked as well as
 * what was answered.
 *
 * Answers are queued by `METHOD URL`, matched on the URL's path so a test does
 * not have to repeat the region host. An unqueued call answers a 404, which is
 * a plain failure rather than a null nobody notices.
 */
final class FakeHttp implements Http
{
    /** @var list<array{method: string, url: string, fields: array<string, mixed>, headers: array<string, string>}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>|null, error: string}>> */
    private array $answers = [];

    /** @param array<string, mixed>|null $body */
    public function queue(string $method, string $path, int $status, ?array $body = null, string $error = ''): self
    {
        $this->answers[strtoupper($method) . ' ' . $path][] = [
            'status' => $status,
            'body' => $body,
            'error' => $error,
        ];

        return $this;
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->record('GET', $url, [], $headers);
    }

    public function postForm(string $url, array $fields, array $headers = []): array
    {
        return $this->record('POST', $url, $fields, $headers);
    }

    public function putForm(string $url, array $fields, array $headers = []): array
    {
        return $this->record('PUT', $url, $fields, $headers);
    }

    /** Every call made, as `METHOD path`. */
    public function trail(): array
    {
        return array_map(
            static fn (array $call): string => $call['method'] . ' ' . (string)parse_url($call['url'], \PHP_URL_PATH),
            $this->calls
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>|null, error: string}
     */
    private function record(string $method, string $url, array $fields, array $headers): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'fields' => $fields, 'headers' => $headers];

        $key = $method . ' ' . (string)parse_url($url, \PHP_URL_PATH);

        if (($this->answers[$key] ?? []) === []) {
            return ['status' => 404, 'body' => ['message' => 'nothing was queued for ' . $key], 'error' => ''];
        }

        return array_shift($this->answers[$key]);
    }
}
