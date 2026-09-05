<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Http;

/**
 * {@see Http} over cURL, with every switch that matters set explicitly.
 *
 * None of these settings is decoration:
 *
 * - **HTTPS only**, on the request and on any redirect. An API key travels in
 *   the `Authorization` header of every one of these calls, and a plain HTTP
 *   hop would hand it to whoever was listening.
 * - **Peer and host verification on.** Off in nobody's build by default, and
 *   worth stating anyway: this is the class where turning it off would be quiet
 *   and expensive.
 * - **Two redirects.** Enough for a provider that moved an endpoint, not enough
 *   to be walked around a network.
 * - **A response cap.** Mailgun's answers here are a few kilobytes. Ten
 *   megabytes of anything is somebody pointing this at a file server, and the
 *   write callback stops reading rather than filling memory.
 * - **Short timeouts.** The setup button and the deliverability screen both
 *   wait on these, and a merchant staring at a spinner for two minutes is worse
 *   off than one told quickly that Mailgun could not be reached.
 */
final class CurlHttp implements Http
{
    /** Seconds to connect. */
    public const CONNECT_TIMEOUT = 5;

    /** Seconds for the whole call. */
    public const TIMEOUT = 10;

    /** Bytes of response body kept before the transfer is abandoned. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function get(string $url, array $headers = []): array
    {
        return $this->run('GET', $url, null, $headers);
    }

    public function postForm(string $url, array $fields, array $headers = []): array
    {
        return $this->form('POST', $url, $fields, $headers);
    }

    public function putForm(string $url, array $fields, array $headers = []): array
    {
        return $this->form('PUT', $url, $fields, $headers);
    }

    // ------------------------------------------------------------- internals

    /**
     * @param array<string, string|list<string>> $fields
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>|null, error: string}
     */
    private function form(string $method, string $url, array $fields, array $headers): array
    {
        $boundary = '----GravEmailMailgun' . bin2hex(random_bytes(16));

        return $this->run($method, $url, self::multipart($fields, $boundary), $headers + [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * A multipart body, built here rather than handed to cURL as an array.
     *
     * cURL will build one from an array, but it will not repeat a field name,
     * and repeating a field name is exactly how Mailgun is told that an event
     * type has more than one URL on it.
     *
     * @param array<string, string|list<string>> $fields
     */
    public static function multipart(array $fields, string $boundary): string
    {
        $body = '';

        foreach ($fields as $name => $value) {
            foreach (\is_array($value) ? $value : [$value] as $one) {
                $body .= '--' . $boundary . "\r\n"
                    . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n"
                    . (string)$one . "\r\n";
            }
        }

        return $body . '--' . $boundary . "--\r\n";
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>|null, error: string}
     */
    private function run(string $method, string $url, ?string $payload, array $headers): array
    {
        if (!\function_exists('curl_init')) {
            return self::answer(0, null, 'this installation has no cURL');
        }

        if (!str_starts_with(strtolower(trim($url)), 'https://')) {
            return self::answer(0, null, 'only https addresses are called');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return self::answer(0, null, 'the request could not be started');
        }

        $lines = [];
        foreach ($headers + ['Accept' => 'application/json'] as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $raw = '';

        curl_setopt_array($handle, [
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            \CURLOPT_TIMEOUT => self::TIMEOUT,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 2,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_WRITEFUNCTION => static function ($_, string $chunk) use (&$raw): int {
                $raw .= $chunk;

                // Returning fewer bytes than were handed over is how cURL is
                // told to stop, which is the point: a body over the cap is
                // abandoned rather than assembled and then thrown away.
                return \strlen($raw) > self::MAX_BYTES ? 0 : \strlen($chunk);
            },
        ]);

        if ($payload !== null) {
            curl_setopt($handle, \CURLOPT_POSTFIELDS, $payload);
        }

        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? (string)curl_error($handle) : '';
        curl_close($handle);

        return self::answer($status, $raw === '' ? null : $raw, $error);
    }

    /** @return array{status: int, body: array<string, mixed>|null, error: string} */
    private static function answer(int $status, ?string $raw, string $error): array
    {
        $decoded = null;

        if ($raw !== null && trim($raw) !== '') {
            try {
                $parsed = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
                $decoded = \is_array($parsed) ? $parsed : null;
            } catch (\JsonException) {
                $decoded = null;
            }
        }

        return ['status' => $status, 'body' => $decoded, 'error' => $error];
    }
}
