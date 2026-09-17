<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;

/**
 * A share's campaign bound, narrowed to the ad accounts the link was granted — stated once.
 *
 * Content rows carry no account column, so the account ceiling travels through their campaigns,
 * resolved from `daily_metrics.external_account_id` (the identifier space the share's account axis is
 * validated in; `external_campaigns.external_account_id` is a different one and would match nothing).
 *
 * It lived privately inside `SharedCreativeView`, and the live report's own content lists — built by
 * `ReportAds` — never applied it: one link returned four creatives from one endpoint and twelve from
 * the other. Two readers of one rule is how that happened, so there is one.
 *
 * An empty account ceiling is «every account». An intersection that empties is the IMPOSSIBLE id,
 * never `[]`, because an empty campaign list reads as «no bound» further down.
 */
final class AccountCampaignCeiling
{
    /**
     * @param  list<string>  $campaignIds
     * @param  list<string>  $accountIds
     * @return list<string>
     */
    public static function campaigns(array $campaignIds, array $accountIds): array
    {
        if ($accountIds === []) {
            return $campaignIds;
        }

        $granted = DailyMetric::query()
            ->withoutGlobalScopes()
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->whereIn('external_account_id', $accountIds)
            ->whereNotNull('unified_campaign_id')
            ->distinct()
            ->pluck('unified_campaign_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $within = $campaignIds === []
            ? $granted
            : array_values(array_intersect($campaignIds, $granted));

        return $within === [] ? [ReportScope::IMPOSSIBLE] : $within;
    }
}
