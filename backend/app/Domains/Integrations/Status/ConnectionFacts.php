<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Status;

use App\Domains\Integrations\Models\ProviderConnection;
use Carbon\CarbonInterface;

/**
 * INTEG-STATUS-MODEL-001 — what is KNOWN about one authorisation, as plain values.
 *
 * Plain values rather than a model so the five statuses can be proven from a table of facts without a
 * database, and so a caller that already loaded the row does not load it twice. Nothing here carries a
 * token, a key or a provider's own error text: `hasError` says «an error was recorded», never what it said.
 */
final class ConnectionFacts
{
    public function __construct(
        public readonly string $provider,
        /** False when the row is gone altogether (an account whose connection was deleted). */
        public readonly bool $exists = true,
        public readonly ?string $status = 'connected',
        public readonly ?CarbonInterface $tokenExpiresAt = null,
        public readonly mixed $scopes = null,
        public readonly ?CarbonInterface $insightsDeniedAt = null,
        public readonly bool $hasError = false,
        public readonly ?string $discoveryBlockedReason = null,
    ) {}

    public static function fromModel(ProviderConnection $c): self
    {
        return new self(
            provider: (string) $c->provider,
            exists: true,
            status: $c->status,
            tokenExpiresAt: $c->token_expires_at,
            scopes: $c->scopes,
            insightsDeniedAt: $c->insights_denied_at,
            hasError: $c->last_error !== null && $c->last_error !== '',
            discoveryBlockedReason: $c->discovery_blocked_reason,
        );
    }

    public static function missing(string $provider): self
    {
        return new self(provider: $provider, exists: false, status: null);
    }
}
