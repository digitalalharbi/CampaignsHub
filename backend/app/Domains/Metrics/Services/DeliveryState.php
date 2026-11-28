<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DELIVERY-STATE-001 — one rule for «شغّالة / واقفة» everywhere: budgets, the dashboards of every role, the active-campaigns
 * count, the accounts page and the quiet window of alerts and digests. Nobody sets it by hand; it is read from the data.
 *
 * DELIVERY-RULE-7D-001 — Owner 2026-11-21 (replaces the 3-day «spend AND impressions» rule of 2026-11-20):
 *  - the judgement is the SPEND of the last 7 days (today and the six before) — one riyal is running;
 *  - spend in the window is itself proof the data is coming in; with NO spend the data is asked: a platform whose selected
 *    accounts were last refreshed before the window (or were tried and never refreshed) is stopped because its data stopped
 *    coming in («no_sync»), not because it is known to be silent («no_spend»). Either way it is not running;
 *  - a platform the project selected that spent nothing in the window is listed as stopped (it used to be left out);
 *  - the budget is the third leg and is applied by whoever holds it ({@see self::reason()}): a project whose budget ran out
 *    is stopped for that reason first.
 *
 * Reads only the accounts each project still selects (BoundAccountVisibility), like every figure reader. Callers pass project
 * ids already inside the reader's scope, so the read is not tenant-scoped again: a scheduler has no tenant set.
 */
final class DeliveryState
{
    public const DAYS = 7;

    public const RUNNING = 'running';

    public const NO_SPEND = 'no_spend';

    public const NO_SYNC = 'no_sync';

    public const BUDGET_SPENT = 'budget_spent';

    /**
     * @param  list<string>  $projectIds
     * @return array<string, array<string, bool>> project id → provider → running
     */
    public function byProject(array $projectIds): array
    {
        $out = [];
        foreach ($this->detail($projectIds) as $project => $providers) {
            foreach ($providers as $provider => $d) {
                $out[$project][$provider] = $d['state'] === self::RUNNING;
            }
        }

        return $out;
    }

    /**
     * Every advertising platform each project selects, with its state and why.
     *
     * @param  list<string>  $projectIds
     * @return array<string, array<string, array{state: string, last_spend: string|null, synced_at: string|null}>>
     */
    public function detail(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map('strval', $projectIds))));
        if ($projectIds === []) {
            return [];
        }
        $from = $this->from();

        // The last day each project/platform spent inside the window.
        $spent = [];
        BoundAccountVisibility::apply(DailyMetric::withoutGlobalScopes(), 'daily_metrics')
            ->whereIn('daily_metrics.project_id', $projectIds)
            ->where('daily_metrics.metric_date', '>=', $from)
            ->where('daily_metrics.metric_key', 'spend')
            ->where('daily_metrics.value', '>', 0)
            ->groupBy('daily_metrics.project_id', 'daily_metrics.provider')
            ->selectRaw('daily_metrics.project_id, daily_metrics.provider, MAX(daily_metrics.metric_date) AS last_date')
            ->get()
            ->each(function ($r) use (&$spent): void {
                $spent[(string) $r->project_id][AdPlatforms::canonical((string) $r->provider)] = Carbon::parse((string) $r->getAttribute('last_date'))->toDateString();
            });

        // The platforms each project selects, and the freshest successful refresh among their accounts.
        $synced = [];
        DB::table('project_integration_bindings as b')
            ->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->whereIn('b.project_id', $projectIds)
            ->where('b.is_active', true)
            ->where('b.purpose', 'advertising')
            ->where(fn ($q) => $q->whereNull('a.status')->orWhere('a.status', '<>', 'superseded'))
            ->groupBy('b.project_id', 'b.provider')
            ->selectRaw('b.project_id, b.provider, MAX(a.last_synced_at) AS synced_at, MAX(a.last_sync_attempt_at) AS tried_at')
            ->get()
            ->each(function ($r) use (&$synced): void {
                $provider = AdPlatforms::canonical((string) $r->provider);
                if (! in_array($provider, AdPlatforms::ORDER, true)) {
                    return;
                }
                $synced[(string) $r->project_id][$provider] = [
                    'at' => $r->synced_at === null ? null : Carbon::parse($r->synced_at)->toIso8601String(),
                    'tried' => $r->tried_at !== null,
                ];
            });

        $out = [];
        foreach ($projectIds as $project) {
            $providers = array_unique(array_merge(array_keys($spent[$project] ?? []), array_keys($synced[$project] ?? [])));
            foreach ($providers as $provider) {
                $last = $spent[$project][$provider] ?? null;
                $sync = $synced[$project][$provider] ?? null;
                $syncedAt = $sync['at'] ?? null;
                // Stale only on evidence: refreshed before the window, or tried and never refreshed. An account nobody has tried yet,
                // or history filed without an account record, says nothing about its data having stopped.
                $stale = $sync !== null && ($syncedAt !== null ? Carbon::parse($syncedAt)->toDateString() < $from : $sync['tried']);

                $out[$project][$provider] = [
                    'state' => $last !== null ? self::RUNNING : ($stale ? self::NO_SYNC : self::NO_SPEND),
                    'last_spend' => $last,
                    'synced_at' => $syncedAt,
                ];
            }
            if (isset($out[$project])) {
                ksort($out[$project]);
            }
        }

        return $out;
    }

    /**
     * The one reason a unit (a client, a project) reads as it does, from its platforms and its budget.
     *
     * Order: a budget that ran out stops everything under it; then any platform running is running; then, if every platform's
     * data stopped coming in, that is the reason; otherwise nothing was spent in the window.
     *
     * @param  list<string>  $states  the platforms' states
     */
    public static function reason(array $states, ?float $remaining): string
    {
        if ($remaining !== null && $remaining <= 0) {
            return self::BUDGET_SPENT;
        }
        if (in_array(self::RUNNING, $states, true)) {
            return self::RUNNING;
        }
        if ($states !== [] && array_diff($states, [self::NO_SYNC]) === []) {
            return self::NO_SYNC;
        }

        return self::NO_SPEND;
    }

    /**
     * @param  list<string>  $projectIds
     * @return list<string> the unified campaigns that spent in the window
     */
    public function runningCampaigns(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return BoundAccountVisibility::apply(DailyMetric::withoutGlobalScopes(), 'daily_metrics')
            ->whereIn('daily_metrics.project_id', $projectIds)
            ->where('daily_metrics.metric_date', '>=', $this->from())
            ->where('daily_metrics.metric_key', 'spend')
            ->where('daily_metrics.value', '>', 0)
            ->whereNotNull('daily_metrics.unified_campaign_id')
            ->distinct()
            ->pluck('daily_metrics.unified_campaign_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * DELIVERY-QUIET-001 — Owner 2026-11-20: a project that stopped is not watched. It delivered before (spend or impressions
     * on record) and has neither spent nor shown one impression in the window: nothing is reported, digested or alerted about it
     * until it moves again — one riyal or one impression. A project with no delivery on record yet is still being set up, and its
     * setup notices (no budget, no account) keep arriving. By default the window is the rule's own (DAYS); a report over its own
     * period passes that period, so a monthly summary still covers a project that spent earlier in it.
     *
     * `$strict` (NOTIFY-STOPPED-001) drops the «nothing on record yet» exception: only a project that moved in the window is kept.
     *
     * @param  list<string>  $projectIds
     * @return list<string> the ones still watched (not quiet), in the order given
     */
    public function watched(array $projectIds, ?string $from = null, ?string $to = null, bool $strict = false): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map('strval', $projectIds))));
        if ($projectIds === []) {
            return [];
        }
        $from ??= $this->from();

        $delivered = fn () => BoundAccountVisibility::apply(DailyMetric::withoutGlobalScopes(), 'daily_metrics')
            ->whereIn('daily_metrics.project_id', $projectIds)
            ->whereIn('daily_metrics.metric_key', ['spend', 'impressions'])
            ->where('daily_metrics.value', '>', 0);

        $moved = array_flip($delivered()->where('daily_metrics.metric_date', '>=', $from)
            ->when($to !== null, fn ($q) => $q->where('daily_metrics.metric_date', '<=', $to))
            ->distinct()->pluck('daily_metrics.project_id')->map(static fn ($id): string => (string) $id)->all());
        $rest = array_values(array_filter($projectIds, static fn (string $id): bool => ! isset($moved[$id])));
        $before = $rest === [] ? [] : array_flip($delivered()->whereIn('daily_metrics.project_id', $rest)->where('daily_metrics.metric_date', '<', $from)
            ->distinct()->pluck('daily_metrics.project_id')->map(static fn ($id): string => (string) $id)->all());

        return array_values(array_filter($projectIds, static fn (string $id): bool => isset($moved[$id]) || (! $strict && ! isset($before[$id]))));
    }

    /**
     * NOTIFY-STOPPED-001 — Owner 2026-11-28: «حملات واقفة لأكثر من 7 أيام لا يوجد إنفاق — المفروض ما يوصل إشعارات لها».
     *
     * The projects that SPENT (one riyal is enough) between `$from` and `$to`, through the accounts each still selects. Unlike
     * {@see self::watched()} a project with no spend on record is NOT kept «because it may be setting up»: a notice about money,
     * a digest or an alert is about a project that is moving money, and a project that never spent, or stopped more than the
     * window ago, is told nothing until it spends again.
     *
     * @param  list<string>  $projectIds
     * @return list<string> in the order given
     */
    public function spent(array $projectIds, string $from, string $to): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map('strval', $projectIds))));
        if ($projectIds === []) {
            return [];
        }
        $moved = array_flip(BoundAccountVisibility::apply(DailyMetric::withoutGlobalScopes(), 'daily_metrics')
            ->whereIn('daily_metrics.project_id', $projectIds)
            ->where('daily_metrics.metric_key', 'spend')
            ->where('daily_metrics.value', '>', 0)
            ->where('daily_metrics.metric_date', '>=', $from)
            ->where('daily_metrics.metric_date', '<=', $to)
            ->distinct()->pluck('daily_metrics.project_id')->map(static fn ($id): string => (string) $id)->all());

        return array_values(array_filter($projectIds, static fn (string $id): bool => isset($moved[$id])));
    }

    /** The first day of the window: today and the DAYS-1 before. */
    public function from(): string
    {
        return now()->subDays(self::DAYS - 1)->toDateString();
    }
}
