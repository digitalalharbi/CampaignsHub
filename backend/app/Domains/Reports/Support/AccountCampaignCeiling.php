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
 *
 * REPORT-SOURCE-ISOLATION-001 — resolved INSIDE the report's own project. An ad account may be bound to
 * two projects, and its metric rows are filed under each; without the project bound the ceiling of a
 * link in one project admitted the campaigns the same account ran for the other, which the content
 * section then listed. The project is required, not optional: a caller with no project gets the
 * fail-closed «nothing», never the account's campaigns across every project it serves.
 */
final class AccountCampaignCeiling
{
    /**
     * @param  list<string>  $campaignIds
     * @param  list<string>  $accountIds
     * @param  string  $projectId  the report's or link's own project; `''` matches nothing
     * @return list<string>
     */
    public static function campaigns(array $campaignIds, array $accountIds, string $projectId): array
    {
        if ($accountIds === []) {
            return $campaignIds;
        }

        $project = $projectId === '' ? ReportScope::IMPOSSIBLE : $projectId;

        /*
         * PERF-RECOVERY-001 — resolved once per report build.
         *
         * A link over accounts resolves this for its own ceiling and again for every content list it renders, and each resolution is the
         * same scan of `daily_metrics` (668 ms apiece on Production). What it depends on is the account list and the project, so that is
         * the key; the memo lives only inside one build, for one tenant and project (see `ReportReadMemo`).
         */
        $sorted = array_values(array_unique($accountIds));
        sort($sorted);

        $granted = ReportReadMemo::remember(
            'account-campaign-ceiling|'.$project.'|'.implode(',', $sorted),
            fn (): array => DailyMetric::query()
                ->withoutGlobalScopes()
                ->where('daily_metrics.project_id', $project)
                ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
                ->whereIn('external_account_id', $accountIds)
                ->whereNotNull('unified_campaign_id')
                ->distinct()
                ->pluck('unified_campaign_id')
                ->map(static fn ($id): string => (string) $id)
                ->all(),
        );

        $within = $campaignIds === []
            ? $granted
            : array_values(array_intersect($campaignIds, $granted));

        return $within === [] ? [ReportScope::IMPOSSIBLE] : $within;
    }
}
