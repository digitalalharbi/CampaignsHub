<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Connectors\Enums;

use App\Domains\Integrations\Connectors\Contracts\Connector;

/**
 * A discrete capability a {@see Connector} may declare.
 * A connector advertises only the capabilities it can HONESTLY perform — declaring a capability is a
 * promise about the adapter surface, not a claim that live credentials exist (that is state, not shape).
 */
enum Capability: string
{
    case OAuth = 'oauth';
    case SelectAccount = 'select_account';
    case CampaignSync = 'campaign_sync';
    case AdGroupSync = 'adgroup_sync';
    case AdsSync = 'ads_sync';
    case CreativeSync = 'creative_sync';
    case MetricsSync = 'metrics_sync';
    case TokenRefresh = 'token_refresh';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Normalize a raw list of capability keys to their canonical string values, silently dropping any
     * key that is not a real capability (config typos never become fake promises).
     *
     * @param  array<int,string>  $keys
     * @return list<string>
     */
    public static function normalize(array $keys): array
    {
        $valid = self::values();

        return array_values(array_filter($keys, static fn (string $k): bool => in_array($k, $valid, true)));
    }
}
