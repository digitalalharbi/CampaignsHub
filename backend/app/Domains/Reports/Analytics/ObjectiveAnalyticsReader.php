<?php

declare(strict_types=1);

namespace App\Domains\Reports\Analytics;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Concerns\ProjectScope;
use Illuminate\Support\Carbon;

/**
 * REPORT-OBJECTIVE-ANALYTICS-001 — the sums the objective section is computed from.
 *
 * The same bounds as `ObjectivePerformance` — project, campaign, platform and account axes, each
 * failing closed on an empty list — and the same bindings-aware account visibility, so this section
 * and the direct/blended block beside it describe exactly the same rows.
 *
 * Two things it reads that the older service does not, because the section needs them to be truthful:
 *
 *   `<key>_rows`      how many rows actually CARRIED the figure. A `COALESCE(SUM, 0)` cannot tell
 *                     «reported zero» from «never reported»; a row count can.
 *   `<key>_withheld`  money rows FX-001 left unconverted. Their converted value is null, so a sum
 *                     over the rest is a partial figure and is reported as unavailable, not as less.
 */
final class ObjectiveAnalyticsReader
{
    /**
     * @param  list<string>|null  $projectIds
     * @param  list<string>|null  $campaignIds
     * @param  list<string>|null  $providers
     * @param  list<string>|null  $accountIds
     */
    public function __construct(
        private readonly ?array $projectIds = null,
        private readonly ?array $campaignIds = null,
        private readonly ?array $providers = null,
        private readonly ?array $accountIds = null,
    ) {}

    /** @return list<array<string,mixed>> one row per (objective, provider) */
    public function byObjectiveProvider(Carbon $from, Carbon $to): array
    {
        return $this->read($from, $to, ['unified_campaigns.objective', 'daily_metrics.provider'], [
            'unified_campaigns.objective AS objective', 'daily_metrics.provider AS provider',
        ]);
    }

    /** @return list<array<string,mixed>> one row per (objective, day) */
    public function byObjectiveDay(Carbon $from, Carbon $to): array
    {
        return $this->read($from, $to, ['unified_campaigns.objective', 'daily_metrics.metric_date'], [
            'unified_campaigns.objective AS objective', 'daily_metrics.metric_date AS date',
        ]);
    }

    /**
     * @param  list<string>  $groupBy
     * @param  list<string>  $select
     * @return list<array<string,mixed>>
     */
    private function read(Carbon $from, Carbon $to, array $groupBy, array $select): array
    {
        $query = $this->bounded($from, $to);

        /*
         * DEMO-LIVE-AGGREGATION-ISOLATION-001 — the same rule the aggregator applies: once a scope
         * holds any real row, seeded rows leave its totals.
         */
        if ((clone $query)->where('daily_metrics.is_demo', false)->exists()) {
            $query->where('daily_metrics.is_demo', false);
        }

        $query->groupBy($groupBy)->select($select);

        foreach (ObjectiveMetricFamilies::BASE as $key) {
            $query->selectRaw("SUM(daily_metrics.value) FILTER (WHERE daily_metrics.metric_key = '{$key}' AND daily_metrics.value IS NOT NULL) AS {$key}");
            $query->selectRaw("COUNT(*) FILTER (WHERE daily_metrics.metric_key = '{$key}' AND (daily_metrics.value IS NOT NULL OR daily_metrics.original_amount IS NOT NULL)) AS {$key}_rows");
            $query->selectRaw("COUNT(*) FILTER (WHERE daily_metrics.metric_key = '{$key}' AND daily_metrics.value IS NULL AND daily_metrics.original_amount IS NOT NULL) AS {$key}_withheld");
        }

        return $query->toBase()->get()->map(static fn ($row): array => (array) $row)->all();
    }

    private function bounded(Carbon $from, Carbon $to): mixed
    {
        return DailyMetric::query()
            ->when($this->projectIds !== null, fn ($q) => $q->withoutGlobalScope(ProjectScope::class))
            ->whereBetween('daily_metrics.metric_date', [$from->toDateString(), $to->toDateString()])
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->join('unified_campaigns', 'unified_campaigns.id', '=', 'daily_metrics.unified_campaign_id')
            ->when($this->projectIds !== null, fn ($q) => $q->whereIn(
                'daily_metrics.project_id',
                $this->projectIds ?: ['00000000-0000-0000-0000-000000000000'],
            ))
            ->when($this->campaignIds !== null, fn ($q) => $q->whereIn(
                'daily_metrics.unified_campaign_id',
                $this->campaignIds ?: ['00000000-0000-0000-0000-000000000000'],
            ))
            ->when($this->providers !== null, fn ($q) => $q->whereIn('daily_metrics.provider', $this->providers ?: ['__none__']))
            ->when($this->accountIds !== null, fn ($q) => $q->whereIn(
                'daily_metrics.external_account_id',
                $this->accountIds ?: ['00000000-0000-0000-0000-000000000000'],
            ));
    }
}
