<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;

/**
 * DELIVERY-STATE-001 — Owner 2026-11-20: one rule for «شغّالة / واقفة» everywhere (budgets, finance, project managers, the
 * active-campaigns count): a campaign, platform or project is running when it reported BOTH spend and impressions in the
 * last 3 days (today and the two before). A budget with money left can sit under campaigns that stopped; this says so.
 *
 * Reads only the accounts each project still selects (BoundAccountVisibility), like every figure reader. Callers pass
 * project ids already inside the reader's scope, so the read is not tenant-scoped again: a scheduler has no tenant set.
 */
final class DeliveryState
{
    public const DAYS = 3;

    /**
     * @param  list<string>  $projectIds
     * @return array<string, array<string, bool>> project id → provider → running (a provider with no spend in the window is absent)
     */
    public function byProject(array $projectIds): array
    {
        $out = [];
        foreach ($this->rows($projectIds, 'provider') as $project => $byKey) {
            foreach ($byKey as $provider => $keys) {
                $out[$project][$provider] = isset($keys['spend'], $keys['impressions']);
            }
            ksort($out[$project]);
        }

        return $out;
    }

    /**
     * @param  list<string>  $projectIds
     * @return list<string> the unified campaigns that ran (spend AND impressions) in the window
     */
    public function runningCampaigns(array $projectIds): array
    {
        $ids = [];
        foreach ($this->rows($projectIds, 'unified_campaign_id') as $byKey) {
            foreach ($byKey as $campaign => $keys) {
                if ($campaign !== '' && isset($keys['spend'], $keys['impressions'])) {
                    $ids[] = (string) $campaign;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * DELIVERY-QUIET-001 — Owner 2026-11-20: a project with no data is not watched. Nothing is reported, digested or alerted
     * about it until it moves again: at least one riyal of spend OR one impression. By default the window is the last 3 days;
     * a report over its own period passes that period, so a monthly summary still covers a project that spent earlier on.
     *
     * @param  list<string>  $projectIds
     * @return list<string> the ones that moved
     */
    public function moving(array $projectIds, ?string $from = null, ?string $to = null): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map('strval', $projectIds))));
        if ($projectIds === []) {
            return [];
        }

        return BoundAccountVisibility::apply(DailyMetric::withoutGlobalScopes(), 'daily_metrics')
            ->whereIn('daily_metrics.project_id', $projectIds)
            ->where('daily_metrics.metric_date', '>=', $from ?? now()->subDays(self::DAYS - 1)->toDateString())
            ->when($to !== null, fn ($q) => $q->where('daily_metrics.metric_date', '<=', $to))
            ->whereIn('daily_metrics.metric_key', ['spend', 'impressions'])
            ->where('daily_metrics.value', '>', 0)
            ->distinct()
            ->pluck('daily_metrics.project_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $projectIds
     * @return array<string, array<string, array<string, true>>> project → group value → metric keys seen
     */
    private function rows(array $projectIds, string $by): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = BoundAccountVisibility::apply(DailyMetric::withoutGlobalScopes(), 'daily_metrics')
            ->whereIn('daily_metrics.project_id', $projectIds)
            ->where('daily_metrics.metric_date', '>=', now()->subDays(self::DAYS - 1)->toDateString())
            ->whereIn('daily_metrics.metric_key', ['spend', 'impressions'])
            ->where('daily_metrics.value', '>', 0)
            ->groupBy('daily_metrics.project_id', "daily_metrics.{$by}", 'daily_metrics.metric_key')
            ->get(['daily_metrics.project_id', "daily_metrics.{$by}", 'daily_metrics.metric_key']);

        $seen = [];
        foreach ($rows as $r) {
            $seen[(string) $r->project_id][(string) ($r->{$by} ?? '')][(string) $r->metric_key] = true;
        }

        return $seen;
    }
}
