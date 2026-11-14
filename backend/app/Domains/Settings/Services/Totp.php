<?php

declare(strict_types=1);

namespace App\Domains\Settings\Services;

/**
 * Self-contained TOTP (RFC 6238, HMAC-SHA1, 6 digits, 30s step) — no external dependency. Used for
 * two-factor auth: generate a base32 secret, build the otpauth URI for authenticator apps, verify a
 * code with a ±1 step window to tolerate clock drift.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    /** Cryptographically random base32 secret (default 160 bits). */
    public function generateSecret(int $length = 32): string
    {
        $bytes = random_bytes((int) ceil($length * 5 / 8));
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[ord($bytes[$i % strlen($bytes)]) % 32];
        }

        return $out;
    }

    public function uri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    /** Verify a code within a ±$window step tolerance. */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        return $this->matchingStep($secret, $code, $window) !== null;
    }

    /**
     * The 30-second step a code belongs to, within ±$window, or null when it matches none.
     *
     * TWO-STEP-001 — the step is what replay protection remembers: a code accepted for step N is refused for N and every step
     * before it, so the same six digits cannot open a second session inside their own validity window. Every candidate is
     * compared, matched or not, so the time taken does not say which step matched.
     */
    public function matchingStep(string $secret, string $code, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $counter = intdiv(time(), self::PERIOD);
        $matched = null;
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->at($secret, $counter + $i), $code)) {
                $matched ??= $counter + $i;
            }
        }

        return $matched;
    }

    /** The code for a given step — used by tests and the acceptance suite to act as an authenticator app would. */
    public function codeAt(string $secret, int $step): string
    {
        return $this->at($secret, $step);
    }

    private function at(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $part, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $secret = rtrim(strtoupper($secret), '=');
        $bits = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
