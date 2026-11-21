<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ProviderConnection;

/**
 * LINKEDIN-SCOPES-001 — what a token was actually granted, read the same way everywhere.
 *
 * OAuth says a granted scope is space-delimited; LinkedIn answers with COMMAS (`r_ads,r_ads_reporting`). Splitting
 * on spaces alone stored one scope named «r_ads,r_ads_reporting» on every LinkedIn connection, so no question of the
 * form «was X granted?» could ever be answered. Both delimiters are read here, from a string or from a stored list —
 * including the one-element lists already on Production, so they need no rewrite.
 *
 * An EMPTY result means «not recorded», never «nothing granted»: callers must not treat it as a refusal.
 */
final class GrantedScopes
{
    /**
     * The providers whose consent a later product feature depends on, with a grant worth comparing. LinkedIn only:
     * it is the provider whose reads fail on a scope the consent did not ask for (proven 2026-11-13).
     */
    private const COMPARED = ['linkedin'];

    /** @return list<string> */
    public static function parse(mixed $raw): array
    {
        $parts = [];

        foreach (is_array($raw) ? $raw : [$raw] as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            foreach (preg_split('/[\s,]+/', (string) $item) ?: [] as $scope) {
                if ($scope !== '') {
                    $parts[] = $scope;
                }
            }
        }

        return array_values(array_unique($parts));
    }

    /**
     * The scopes this product asks for that the connection's recorded grant lacks.
     *
     * Empty when the grant is unrecorded, or the provider is not one whose grant is compared — a prompt to reconnect
     * must only appear where reconnecting is the proven remedy.
     *
     * @return list<string>
     */
    public static function missing(ProviderConnection $connection): array
    {
        if (! in_array((string) $connection->provider, self::COMPARED, true)) {
            return [];
        }

        /*
         * The TOKEN's stored grant decides, exactly as the connector decides (LinkedInConnector::fetchAds) — so the hub row
         * and the creative card cannot disagree. The column is not used: on rows written before LINKEDIN-SCOPES-001 it may
         * hold the requested list restated as a grant.
         */
        try {
            $granted = self::parse(app(TokenVault::class)->stored($connection)->scope);
        } catch (\Throwable) {
            $granted = [];
        }

        if ($granted === []) {
            return [];
        }

        $wanted = app(ProviderConfigurationService::class)->scopes((string) $connection->provider);

        return array_values(array_diff($wanted, $granted));
    }
}
