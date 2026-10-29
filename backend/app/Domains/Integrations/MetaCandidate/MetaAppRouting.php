<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;

/**
 * META-SAAS-APP-001 — which Meta app a NEW customer connection is issued by.
 *
 * Live unless a platform admin chose the SaaS app (the `enabled` flag on the `meta.saas` row) after a successful test AND the Owner
 * recorded that Meta approved it (`review_confirmed_at`). An operator may
 * also name an app for one connection. Nothing here touches an EXISTING connection: each stays with the app that issued its token.
 */
final class MetaAppRouting
{
    /** The app for one new connection: the one asked for by name, else the platform default. */
    public static function forNewConnection(?string $asked): MetaCredentialProfile
    {
        $named = MetaCredentialProfile::tryFrom((string) $asked);
        if ($named === MetaCredentialProfile::Live || $named === MetaCredentialProfile::Saas) {
            return $named;
        }

        return self::default();
    }

    public static function default(): MetaCredentialProfile
    {
        $row = ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Saas->storageKey())->first();

        return $row !== null && $row->enabled === true && $row->review_confirmed_at !== null && self::saasEligibility()['eligible']
            ? MetaCredentialProfile::Saas : MetaCredentialProfile::Live;
    }

    /**
     * Whether the SaaS app may issue a customer connection at all — the ONE rule every path asks: its credentials are complete and its
     * latest test round trip passed with exactly the credentials configured now.
     *
     * @return array{eligible: bool, reason: string|null}
     */
    public static function saasEligibility(): array
    {
        $credentials = app(MetaSaasCredentials::class);
        if (! $credentials->isConfigured()) {
            return ['eligible' => false, 'reason' => 'not_configured'];
        }
        $run = MetaCandidateConnection::latestRun(MetaCredentialProfile::Saas);
        if ($run === null || $run->status !== 'succeeded') {
            return ['eligible' => false, 'reason' => 'no_successful_test'];
        }
        if (! hash_equals((string) $run->credential_fingerprint, (string) $credentials->fingerprint())) {
            return ['eligible' => false, 'reason' => 'credentials_changed_since_test'];
        }

        return ['eligible' => true, 'reason' => null];
    }
}
