<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * INTEG-OAUTH-001 — the `state` that ties a returning browser to the request that started the flow.
 *
 * ## Why this is not decoration
 *
 * The OAuth callback is a PUBLIC route. It has to be — the platform redirects a browser to it and no
 * session or bearer token survives that hop. Without a state that we minted and recorded, anybody who
 * can reach the URL can post a code and have us open a connection **for a tenant of their choosing**:
 * classic CSRF, except the prize is a live platform credential attached to somebody else's workspace.
 *
 * So the state carries the tenant, and the tenant is read from THIS record rather than from anything
 * in the callback's query string. A tampered state does not resolve; a genuine one resolves to exactly
 * the workspace whose operator started the flow.
 *
 * ## Single use, and short
 *
 * The record is forgotten the moment it is claimed, so a callback replayed from browser history — or
 * from a proxy log, or from somebody's shoulder — finds nothing. It also expires on its own, because a
 * flow somebody abandoned should not stay claimable for the rest of the day.
 */
final class AuthorizationState
{
    private const PREFIX = 'ads-oauth-state:';

    /**
     * Record a new authorisation attempt and return the opaque state to send to the platform.
     *
     * @param  array<string,mixed>  $extra  anything the callback needs and cannot re-derive
     */
    public static function issue(
        string $tenantId,
        string $provider,
        ?int $userId = null,
        ?string $clientWorkspaceId = null,
        array $extra = [],
    ): string {
        $state = Str::random(48);

        Cache::put(self::PREFIX.$state, [
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'user_id' => $userId,
            'client_workspace_id' => $clientWorkspaceId,
            ...$extra,
        ], now()->addMinutes((int) config('ad_platforms.state_ttl_minutes', 15)));

        return $state;
    }

    /**
     * Claim a state exactly once.
     *
     * @return array<string,mixed>|null null when it never existed, already expired, was already used,
     *                                  or belongs to a different provider than the callback claims
     */
    public static function claim(string $state, string $provider): ?array
    {
        /** @var array<string,mixed>|null $record */
        $record = Cache::pull(self::PREFIX.$state);

        if ($record === null) {
            return null;
        }

        // The provider is in the URL and in the record; a mismatch means one of them was tampered with.
        if (($record['provider'] ?? null) !== $provider) {
            return null;
        }

        return $record;
    }
}
