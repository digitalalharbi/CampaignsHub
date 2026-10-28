<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;

/**
 * META-SAAS-APP-001 — which Meta app a NEW customer connection is issued by.
 *
 * Live unless a platform admin chose the SaaS app (the `enabled` flag on the `meta.saas` row) after a successful test. An operator may
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

        return $row !== null && $row->enabled === true ? MetaCredentialProfile::Saas : MetaCredentialProfile::Live;
    }
}
