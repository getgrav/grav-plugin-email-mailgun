<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Http;

/**
 * The handful of calls this plugin makes to Mailgun, behind one seam.
 *
 * Creating a webhook, replacing the URLs on one, reading the account's webhook
 * signing key, and asking what a sending domain's DNS is meant to look like.
 * Four calls in the whole plugin, and every one of them is the sort of thing a
 * test has to be able to answer for itself — a suite that reached the network
 * would be a suite that failed on a train, and one that failed differently
 * depending on what somebody had left in a Mailgun account.
 *
 * Deliberately small. No redirect policy to tune, no streaming, no retries:
 * none of the four needs any of it, and every one of them would be another
 * thing to get wrong in the one class that talks to the outside.
 *
 * ## Why forms rather than JSON
 *
 * Mailgun's webhook endpoints are documented as `multipart/form-data` and
 * their own examples are `curl -F`. They take a field more than once to mean a
 * list, which is how several URLs are attached to one event type, so a value
 * here may be a string or a list of strings.
 *
 * Authentication is HTTP basic with the user `api` and the API key as the
 * password, passed as an `Authorization` header rather than in the URL, so a
 * key never lands in anybody's access log.
 */
interface Http
{
    /**
     * GET a URL and read a JSON answer.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>|null, error: string}
     */
    public function get(string $url, array $headers = []): array;

    /**
     * POST a form body and read a JSON answer.
     *
     * @param array<string, string|list<string>> $fields a list value is sent as
     *        the same field name repeated, which is how Mailgun spells a list
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>|null, error: string}
     */
    public function postForm(string $url, array $fields, array $headers = []): array;

    /**
     * PUT a form body and read a JSON answer.
     *
     * @param array<string, string|list<string>> $fields
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>|null, error: string}
     */
    public function putForm(string $url, array $fields, array $headers = []): array;
}
