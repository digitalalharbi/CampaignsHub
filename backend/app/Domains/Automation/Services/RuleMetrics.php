<?php

declare(strict_types=1);

namespace App\Domains\Automation\Services;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Metrics\Services\EntityMetricsAggregator;
use Illuminate\Support\Carbon;

/**
 * The figures a rule reads, per object and window, from the platform's own reported rows.
 *
 * Campaign objects are read from `daily_metrics` by their external campaign row; ad groups and ads from
 * `entity_daily_metrics` through `EntityMetricsAggregator`. Only bound accounts count. A sum over nothing
 * is null — «not reported» — and every ratio over a missing or zero denominator is null, never zero.
 */
final class RuleMetrics
{
    private const BASE = ['spend', 'impressions', 'clicks', 'conversions', 'revenue'];

    public function __construct(private readonly EntityMetricsAggregator $entities) {}

    /**
     * @param  list<string>  $ids  internal ids of the objects at this level
     * @return array<string, array<string, float|null>> id => figures
     */
    public function window(string $projectId, string $level, array $ids, Carbon $from, Carbon $to): array
    {
        if ($ids === []) {
            return [];
        }

        $raw = $level === 'campaign' ? $this->campaigns($projectId, $ids, $from, $to) : $this->entityRows($projectId, $level, $ids, $from, $to);

        $out = [];
        foreach ($ids as $id) {
            $b = $raw[$id] ?? [];
            $v = static fn (string $k): ?float => isset($b[$k]) ? (float) $b[$k] : null;
            $spend = $v('spend');
            $impr = $v('impressions');
            $clicks = $v('clicks');
            $conv = $v('conversions');
            $rev = $v('revenue');
            /*
             * FX-APPLY-001 — money with a withheld part (a row with no rate for its date) is NOT the sum of the rest: a EUR
             * account's spend with one unconverted day would be read as a smaller number and compared with a limit. The
             * money figures go null and the rule says «no_rate»; counts stay.
             */
            if ((int) ($b['money_withheld_rows'] ?? 0) > 0) {
                $spend = null;
                $rev = null;
            }
            $out[$id] = [
                'money_withheld' => (int) ($b['money_withheld_rows'] ?? 0) > 0 ? 1.0 : null,
                'spend' => $spend, 'impressions' => $impr, 'clicks' => $clicks, 'conversions' => $conv, 'revenue' => $rev,
                'cpa' => $spend !== null && $conv ? $spend / $conv : null,
                'roas' => $rev !== null && $spend ? $rev / $spend : null,
                'ctr' => $clicks !== null && $impr ? $clicks / $impr * 100 : null,
                'cpc' => $spend !== null && $clicks ? $spend / $clicks : null,
                'cpm' => $spend !== null && $impr ? $spend / ($impr / 1000) : null,
            ];
        }

        return $out;
    }

    /** @return array<string, array<string, float|null>> */
    private function campaigns(string $projectId, array $ids, Carbon $from, Carbon $to): array
    {
        $q = DailyMetric::withoutGlobalScopes()->where('project_id', $projectId)
            ->whereIn('external_campaign_id', $ids)
            ->whereIn('metric_key', self::BASE)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);
        BoundAccountVisibility::apply($q, 'daily_metrics');

        $out = [];
        foreach ($q->groupBy('external_campaign_id', 'metric_key')->selectRaw('external_campaign_id, metric_key, SUM(value) AS total, COUNT(*) FILTER (WHERE value IS NULL AND original_amount IS NOT NULL) AS withheld')->get() as $r) {
            $total = $r->getAttribute('total');
            $id = (string) $r->external_campaign_id;
            $out[$id][(string) $r->metric_key] = $total !== null ? (float) $total : null;
            if (in_array((string) $r->metric_key, ['spend', 'revenue'], true)) {
                $out[$id]['money_withheld_rows'] = ($out[$id]['money_withheld_rows'] ?? 0) + (int) $r->getAttribute('withheld');
            }
        }

        return $out;
    }

    /** @return array<string, array<string, float|null>> */
    private function entityRows(string $projectId, string $level, array $ids, Carbon $from, Carbon $to): array
    {
        $type = $level === 'ad' ? EntityDailyMetric::AD : EntityDailyMetric::AD_SET;
        $out = [];
        foreach ($this->entities->byEntity($projectId, $type, $from, $to) as $row) {
            if (in_array((string) $row['entity_id'], $ids, true)) {
                $out[(string) $row['entity_id']] = array_intersect_key($row, array_flip(self::BASE))
                    + ['money_withheld_rows' => (int) ($row['spend_withheld_rows'] ?? 0) + (int) ($row['revenue_withheld_rows'] ?? 0)];
            }
        }

        return $out;
    }
}
