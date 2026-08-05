<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * INTEG-RETRY-001 — one HTTP client for every ad platform, with one retry policy.
 *
 * ## What is retried, and what is not
 *
 * Retried: a connection that never landed, a 429, and any 5xx. Those are the platform being busy or
 * briefly broken, and trying again is the correct answer.
 *
 * NOT retried: every other 4xx. A 400 is a request we built wrong, a 401 is a token that has died, a
 * 403 is a permission we were never granted. Retrying any of them changes nothing except how long the
 * customer waits for the same failure, and on a rate-limited API it spends the budget that the calls
 * which WOULD have worked needed.
 *
 * ## Why the backoff is what it is
 *
 * Exponential, and it honours `Retry-After` when the platform sends one — every one of the six does on
 * a 429, and ignoring it is how an integration goes from throttled to blocked. The delays are
 * deliberately longer than a web request would tolerate, which is exactly why syncs are queued: this
 * client is never on the path of somebody waiting for a page.
 */
final class PlatformHttp
{
    /** Attempts in total, not retries after the first. */
    private const ATTEMPTS = 4;

    /** Base backoff, doubled each attempt: 1s, 2s, 4s. */
    private const BASE_BACKOFF_MS = 1000;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    private const TIMEOUT_SECONDS = 60;

    public static function client(string $platform): PendingRequest
    {
        return Http::acceptJson()
            ->withUserAgent('CampaignsHub/1.0 (+integrations; '.$platform.')')
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS)
            ->retry(
                times: self::ATTEMPTS,
                sleepMilliseconds: self::backoff(...),
                when: self::isWorthRetrying(...),
                throw: false,
            );
    }

    /**
     * How long to wait before attempt `$attempt` (1-based, so the first wait is attempt 1).
     *
     * A platform that tells us when to come back is believed, within reason — an hour-long
     * `Retry-After` is real on some quota resets, and sleeping a queue worker for an hour is not a
     * retry, it is an outage. Past two minutes the job gives up and is re-driven by the scheduler.
     */
    public static function backoff(int $attempt, ?RequestException $exception = null): int
    {
        $retryAfter = $exception?->response?->header('Retry-After');

        if (is_numeric($retryAfter)) {
            return (int) min((float) $retryAfter * 1000, 120_000);
        }

        return self::BASE_BACKOFF_MS * (2 ** ($attempt - 1));
    }

    /** @param Request $request the outgoing request, unused — the decision is entirely about the answer */
    public static function isWorthRetrying(\Exception $exception, ?PendingRequest $request = null): bool
    {
        if ($exception instanceof ConnectionException) {
            return true; // never landed; nothing was done twice
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }

    /**
     * Did this answer succeed in the platform's own terms?
     *
     * Two of the six answer 200 for a failure — TikTok with a non-zero `code`, and Snapchat with a
     * `request_status` of `ERROR` — so a caller that reads `$response->successful()` alone stores an
     * error body as data. Anything that reads a platform answer should go through here.
     */
    public static function succeeded(Response $response): bool
    {
        if ($response->failed()) {
            return false;
        }

        /** @var array<string,mixed> $body */
        $body = $response->json() ?? [];

        if (array_key_exists('code', $body) && is_numeric($body['code'])) {
            return (int) $body['code'] === 0;
        }

        if (array_key_exists('request_status', $body)) {
            return strtoupper((string) $body['request_status']) === 'SUCCESS';
        }

        return true;
    }

    /** The most useful sentence available about why an answer was not a success. */
    public static function reason(Response $response): string
    {
        /** @var array<string,mixed> $body */
        $body = $response->json() ?? [];

        foreach (['message', 'error_description', 'display_message', 'debug_message'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                return $body[$key];
            }
        }

        if (isset($body['error']) && is_array($body['error']) && isset($body['error']['message'])) {
            return (string) $body['error']['message'];
        }

        return 'HTTP '.$response->status().': '.mb_substr(trim($response->body()), 0, 200);
    }
}
