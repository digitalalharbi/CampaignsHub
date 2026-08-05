<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use Illuminate\Support\Carbon;

/**
 * INTEG-OAUTH-001 — what a platform handed back, in one shape.
 *
 * The six platforms disagree about almost everything here: TikTok wraps its answer in `data` and calls
 * the token `access_token` inside it, Meta returns seconds in `expires_in` and no refresh token at all
 * for a long-lived token, LinkedIn returns `expires_in` plus a separate refresh expiry, Snapchat
 * returns the standard triple. Normalising at the edge means the vault, the connectors and the refresh
 * command all deal with ONE thing, and a platform's quirk lives in the one method that reads it.
 *
 * `raw` is kept because a token response often carries the only copy of something we later need — an
 * advertiser id, an organisation, a granted scope list — and throwing it away means going back to the
 * platform to ask what it already told us.
 */
final class OAuthTokens
{
    /** @param array<string,mixed> $raw */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken = null,
        public readonly ?Carbon $expiresAt = null,
        public readonly ?string $scope = null,
        public readonly array $raw = [],
    ) {}

    /** True when the token is gone, or close enough that starting a sync with it is a bad bet. */
    public function isExpired(?int $skewMinutes = null): bool
    {
        if ($this->expiresAt === null) {
            return false; // no stated expiry — Meta system tokens and X app tokens behave this way
        }

        $skew = $skewMinutes ?? (int) config('ad_platforms.refresh_skew_minutes', 60);

        return $this->expiresAt->lessThanOrEqualTo(Carbon::now()->addMinutes($skew));
    }

    /** @return array<string,mixed> the encrypted-at-rest shape stored on the credential */
    public function toStorage(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'scope' => $this->scope,
            'raw' => $this->raw,
        ];
    }

    /** @param array<string,mixed> $stored */
    public static function fromStorage(array $stored): self
    {
        $expiresAt = $stored['expires_at'] ?? null;

        return new self(
            accessToken: (string) ($stored['access_token'] ?? ''),
            refreshToken: isset($stored['refresh_token']) && $stored['refresh_token'] !== null
                ? (string) $stored['refresh_token']
                : null,
            expiresAt: is_string($expiresAt) && $expiresAt !== '' ? Carbon::parse($expiresAt) : null,
            scope: isset($stored['scope']) && $stored['scope'] !== null ? (string) $stored['scope'] : null,
            raw: is_array($stored['raw'] ?? null) ? $stored['raw'] : [],
        );
    }
}
