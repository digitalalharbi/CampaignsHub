<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use Illuminate\Database\Eloquent\Builder;

/**
 * META-MULTI-APP-001 — every registered Meta app, its role, and which connections belong to it.
 *
 * Extends the profile model (live | candidate | saas) rather than replacing it: a «registered app» is a profile whose credential
 * row exists. Nothing here ever returns a secret; an App ID is public.
 *
 * ## Whose connection is this?
 *
 * A connection whose issuing app was VERIFIED by Meta (`issuing_app_verified_at`) belongs to the app named by `issuing_app_id`.
 * Every other connection belongs to its `app_profile` and is counted as UNVERIFIED — a recorded assumption, not a proof — so an uncertain
 * row is visible instead of being silently attributed.
 */
final class MetaAppRegistry
{
    public const ROLE_PRIMARY = 'primary';

    public const ROLE_LEGACY_SYNC_ONLY = 'legacy_sync_only';

    public const ROLE_NOT_PRIMARY = 'not_primary';

    public const ROLE_TEST_ONLY = 'test_only';

    /**
     * @return array{apps: list<array<string, mixed>>, unattributed: int}
     */
    public function overview(): array
    {
        $primary = MetaAppRouting::default();
        $apps = [];
        foreach ([MetaCredentialProfile::Live, MetaCredentialProfile::Saas, MetaCredentialProfile::Candidate] as $profile) {
            $credentials = PlatformCredentials::forMeta($profile);
            $counts = $this->belonging($profile);
            $apps[] = [
                'profile' => $profile->value,
                'role' => $this->role($profile, $primary),
                'app_id' => $credentials->get('client_id'),
                'configured' => $credentials->isConfigured(),
                'connections' => $counts['total'],
                'verified_connections' => $counts['verified'],
                'unverified_connections' => $counts['unverified'],
            ];
        }

        // Verified to an App ID no registered app carries (an App ID the platform has since moved away from).
        $known = array_values(array_filter(array_map(static fn (array $a) => $a['app_id'], $apps)));
        $unattributed = $this->active()->whereNotNull('issuing_app_verified_at')
            ->when($known !== [], fn ($q) => $q->whereNotIn('issuing_app_id', $known))->count();

        return ['apps' => $apps, 'unattributed' => $unattributed];
    }

    /**
     * The non-revoked connections that belong to one app, split into verified and unverified.
     *
     * @return array{total: int, verified: int, unverified: int}
     */
    public function belonging(MetaCredentialProfile $profile): array
    {
        if ($profile === MetaCredentialProfile::Candidate) {
            return ['total' => 0, 'verified' => 0, 'unverified' => 0];
        }

        $appId = PlatformCredentials::forMeta($profile)->get('client_id');
        $verified = $appId === null ? 0 : $this->active()->whereNotNull('issuing_app_verified_at')->where('issuing_app_id', $appId)->count();
        $unverified = $this->active()->whereNull('issuing_app_verified_at')
            ->when(
                $profile === MetaCredentialProfile::Live,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('app_profile')->orWhere('app_profile', MetaCredentialProfile::Live->value)),
                fn ($q) => $q->where('app_profile', $profile->value),
            )->count();

        return ['total' => $verified + $unverified, 'verified' => $verified, 'unverified' => $unverified];
    }

    /** Why an app's credentials may not be forgotten right now: the connections still belonging to it, or null when none do. */
    public const REASON_HAS_CONNECTIONS = 'connections_belong_to_app';

    /** @return array{reason: string, connections: int, unverified: int}|null */
    public function removalBlockedBy(MetaCredentialProfile $profile): ?array
    {
        $counts = $this->belonging($profile);

        return $counts['total'] === 0 ? null : ['reason' => self::REASON_HAS_CONNECTIONS, 'connections' => $counts['total'], 'unverified' => $counts['unverified']];
    }

    private function role(MetaCredentialProfile $profile, MetaCredentialProfile $primary): string
    {
        return match (true) {
            $profile === MetaCredentialProfile::Candidate => self::ROLE_TEST_ONLY,
            $profile === $primary => self::ROLE_PRIMARY,
            $profile === MetaCredentialProfile::Live => self::ROLE_LEGACY_SYNC_ONLY,
            default => self::ROLE_NOT_PRIMARY,
        };
    }

    /** @return Builder<ProviderConnection> */
    private function active()
    {
        return ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')->where('status', '!=', 'revoked');
    }
}
