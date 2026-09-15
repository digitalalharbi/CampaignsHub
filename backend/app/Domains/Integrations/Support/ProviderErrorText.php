<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

/**
 * INTEGRATION-ERROR-CONTRACT-001 — one place that prepares a provider's failure for storage.
 *
 * The owner met this as `SQLSTATE[22001] value too long for varchar(255)`, which is the database
 * refusing to record why Meta said no. Two things were wrong and the second caused the first:
 *
 *  - `provider_connections.last_error` and `integration_sync_runs.error` were `varchar(255)`, chosen
 *    when an error was a sentence. A Meta refusal is a sentence PLUS the identifiers that make it
 *    actionable — code, subcode, fbtrace_id, request id — and those alone can pass 255.
 *  - every writer invented its own bound: 250 here, 250 there, 300 in one place, and **1000** in
 *    `AccountDiscovery`, which is how a write into a 255-wide column threw rather than truncating.
 *
 * So the columns are `text` now, and this is the only thing that prepares what goes in them. Both
 * halves matter: widening the column without a single writer leaves five bounds to drift apart
 * again, and a single writer against a narrow column just relocates the exception.
 *
 * ## What it keeps, and what it removes
 *
 * It keeps everything that tells an operator what to DO — the provider's own sentence and every
 * identifier travelling with it. A truncated Meta error is worse than useless: `(#200) Ad account
 * owner has NOT grant ads_ma…` reads like a bug in the product rather than a permission the owner
 * has to grant.
 *
 * It removes credentials. Provider errors quote the request, and a request carries a token: these
 * rows are read by operators, printed in diagnostics and shown in the sync log, so a token in one is
 * a token on a screen. Redaction happens HERE rather than at each call site for the same reason the
 * bound does.
 */
final class ProviderErrorText
{
    /**
     * A generous ceiling, and deliberately not a tight one.
     *
     * `text` has no length limit, so this exists only to stop a pathological provider body — an HTML
     * error page, a stack trace — becoming a row nobody can read. It is far above any real provider
     * sentence plus its identifiers, so the truncation that caused this defect cannot recur quietly.
     */
    private const CEILING = 4000;

    /**
     * Patterns that carry a credential. Matched on the KEY rather than on the value's shape: a token
     * looks like any other opaque string, and guessing by shape redacts account ids and misses the
     * token that was url-encoded.
     *
     * @var list<string>
     */
    private const SECRET_KEYS = [
        'access_token', 'refresh_token', 'client_secret', 'app_secret', 'developer_token',
        'authorization', 'auth_token', 'id_token', 'api_key', 'apikey', 'password', 'secret',
    ];

    public static function forStorage(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $text = trim(self::redact($raw));

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= self::CEILING) {
            return $text;
        }

        // Says so, rather than ending mid-word and reading like the provider stopped talking.
        return mb_substr($text, 0, self::CEILING).' […truncated]';
    }

    /** Replace the VALUE of anything that names a credential, in query strings, JSON and headers. */
    private static function redact(string $text): string
    {
        $keys = implode('|', array_map('preg_quote', self::SECRET_KEYS));

        /*
         * Scheme first, key second, and the order is load-bearing.
         *
         * Run the other way round, the `authorization` key rule consumes the word «Bearer» — its
         * value stops at the space — and leaves the token sitting after a `[redacted]` that looks
         * like the job was done. Caught by a test asserting the token was gone rather than asserting
         * that something had been replaced.
         */
        $text = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._\-]+/i', '$1 [redacted]', $text);

        // key=value / key: value / "key":"value", each keeping the key so the reader knows what went.
        return (string) preg_replace('/(("|\')?('.$keys.')("|\')?\s*[:=]\s*("|\')?)[^"\'&,\s}]+/i', '$1[redacted]', $text);
    }
}
