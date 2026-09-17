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
        /*
         * META-CANDIDATE-001 — a tenant's authorisation is ALWAYS the Live profile.
         *
         * Whatever `$extra` carries, `profile` is overwritten after it: the Candidate profile can only be
         * minted by `issueMetaCandidate()`, which only the platform owner's console calls.
         */
        $record = [
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'user_id' => $userId,
            'client_workspace_id' => $clientWorkspaceId,
            ...$extra,
        ];

        if ($provider === 'meta') {
            $record['profile'] = MetaCredentialProfile::Live->value;
        }

        return self::put($record);
    }

    /**
     * META-CANDIDATE-001 — the state for the platform owner's Candidate test.
     *
     * No tenant: the Candidate app belongs to the platform, and its connection is filed under no
     * workspace. Bound to the owner who started it and to the test run it will complete.
     */
    public static function issueMetaCandidate(int $userId, string $runId): string
    {
        return self::put([
            'tenant_id' => null,
            'provider' => 'meta',
            'user_id' => $userId,
            'client_workspace_id' => null,
            'candidate_run_id' => $runId,
            'profile' => MetaCredentialProfile::Candidate->value,
        ]);
    }

    /** @param array<string,mixed> $record */
    private static function put(array $record): string
    {
        $state = Str::random(48);

        if (($record['provider'] ?? null) === 'meta') {
            $record['nonce'] = Str::random(32);
            $record['binding'] = self::binding($state, $record);
        }

        Cache::put(self::PREFIX.$state, $record, now()->addMinutes((int) config('ad_platforms.state_ttl_minutes', 15)));

        return $state;
    }

    /**
     * The signature that ties the profile to the state, the tenant, the user and the nonce.
     *
     * The record already lives server-side, so the state string alone cannot be forged. This is the
     * second lock: a record whose profile was altered — or one written by anything other than `put()` —
     * no longer matches its own signature and is refused.
     *
     * @param  array<string,mixed>  $record
     */
    private static function binding(string $state, array $record): string
    {
        return hash_hmac('sha256', implode('|', [
            $state,
            (string) ($record['provider'] ?? ''),
            (string) ($record['profile'] ?? ''),
            (string) ($record['tenant_id'] ?? ''),
            (string) ($record['user_id'] ?? ''),
            (string) ($record['candidate_run_id'] ?? ''),
            (string) ($record['nonce'] ?? ''),
        ]), 'meta-oauth-state|'.(string) config('app.key'));
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

        if ($provider === 'meta' && ! self::metaProfileHolds($state, $record)) {
            return null;
        }

        return $record;
    }

    /**
     * META-CANDIDATE-001 — fail CLOSED on anything but a signed, coherent profile.
     *
     * A missing profile, an unknown one, a broken signature, a Live record with no tenant or a Candidate
     * record with one: each is refused before a token is exchanged, because the profile decides WHICH
     * app's secret is about to be sent.
     *
     * @param  array<string,mixed>  $record
     */
    private static function metaProfileHolds(string $state, array $record): bool
    {
        $profile = MetaCredentialProfile::tryFrom((string) ($record['profile'] ?? ''));

        if ($profile === null || ! is_string($record['binding'] ?? null) || ! is_string($record['nonce'] ?? null)) {
            return false;
        }

        if (! hash_equals(self::binding($state, $record), $record['binding'])) {
            return false;
        }

        return match ($profile) {
            MetaCredentialProfile::Live => is_string($record['tenant_id'] ?? null) && $record['tenant_id'] !== ''
                && ! isset($record['candidate_run_id']),
            MetaCredentialProfile::Candidate => ($record['tenant_id'] ?? null) === null
                && isset($record['user_id'], $record['candidate_run_id']),
        };
    }
}
