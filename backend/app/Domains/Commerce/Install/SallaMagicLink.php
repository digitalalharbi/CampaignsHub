<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Install;

use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SALLA-ONBOARD-001 — a signed, single-use, five-minute link that opens a session without a password.
 *
 * It is minted ONLY after Salla itself has just proved the merchant (a completed authorisation or a Salla
 * webhook-signed event), never from a request a stranger can make, and it carries nothing to guess: the user,
 * the store, an expiry and a nonce, signed with HMAC-SHA256 under a key derived from the app secret. Redeeming
 * checks the signature (constant time), the expiry, and that the nonce has not been used — the nonce is
 * consumed atomically, so a replay, a double click or a forwarded copy fails.
 *
 * It grants a session for exactly the named user and nothing else. Whether that person may be signed in at all
 * (disabled, suspended, review account expired) is decided by the caller exactly as for any other sign-in.
 */
final class SallaMagicLink
{
    public const TTL_SECONDS = 300;

    private const NONCE_PREFIX = 'salla-magic:';

    public static function issue(User $user, string $storeId, ?int $now = null): string
    {
        $now ??= time();
        $payload = self::b64(json_encode([
            'u' => (string) $user->getKey(),
            's' => $storeId,
            'n' => Str::random(24),
            'e' => $now + self::TTL_SECONDS,
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::b64(hash_hmac('sha256', $payload, self::key(), true));
    }

    /**
     * @return array{user_id: string, store_id: string}|null null for ANY failure — the caller never learns which
     */
    public static function redeem(string $token, ?int $now = null): ?array
    {
        $now ??= time();
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        $expected = self::b64(hash_hmac('sha256', $parts[0], self::key(), true));
        if (! hash_equals($expected, $parts[1])) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($data) || ! isset($data['u'], $data['s'], $data['n'], $data['e']) || (int) $data['e'] < $now) {
            return null;
        }

        // Atomic: add() only succeeds for the first redeemer, so the same link cannot open two sessions.
        if (! Cache::add(self::NONCE_PREFIX.hash('sha256', (string) $data['n']), 1, self::TTL_SECONDS + 60)) {
            return null;
        }

        return ['user_id' => (string) $data['u'], 'store_id' => (string) $data['s']];
    }

    private static function key(): string
    {
        // The configuration an install really runs on is the operator's stored one, which `PlatformCredentials` reads.
        $secret = (string) PlatformCredentials::for('salla')->get('client_secret');

        // Derived, domain-separated, and never the bare app secret. With no secret configured nothing can be
        // signed or verified: an empty key must not become a key everyone knows.
        if ($secret === '') {
            throw new \RuntimeException('The Salla app secret is not configured, so no sign-in link can be signed.');
        }

        return hash_hmac('sha256', 'campaignshub:salla-magic-link:v1', $secret, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
