<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Commerce\Services\ProjectStores;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * UNIFIED-001 — «حالة حداثة البيانات», computed once and read everywhere.
 *
 * ## Why one service
 *
 * Four surfaces answered «متى آخر تحديث؟» independently: the dashboard's freshness strip, the client
 * link's freshness footer, a client's analytics header, and the client list's «آخر مزامنة» column. They
 * used different columns and different cutoffs, so the same project could read `fresh` in one place and
 * `stale` in another on the same afternoon — and a reader who noticed had no way to tell which was
 * lying. Freshness is a claim about the data behind every other figure on the page; it cannot be the one
 * number the product computes four ways.
 *
 * ## Why stores are in here
 *
 * Every one of those four implementations looked only at `daily_metrics`. Once a project's numbers came
 * from a shop as well as from ad platforms (COMMERCE-001), a dashboard whose store had not synced in a
 * week still said «محدَّث» — the ad side was fresh, and nothing asked the other half. Revenue, orders,
 * AOV and ROAS all come off the store, so that badge was covering the exact figures it was least
 * entitled to vouch for. A source is a source here, whichever kind it is.
 *
 * ## The distinction the states preserve
 *
 * `data_as_of` is when the SOURCE said its figures were current; `last_checked_at` is when we last
 * asked. «We asked ten minutes ago and Meta's newest figures are from this morning» and «we have not
 * asked since Friday» are different situations, and only the second one is ours to fix — so both are
 * reported and neither is collapsed into the other. A source we have never read successfully is
 * `awaiting_credentials`, never a zero: a platform that spent nothing and a platform we cannot read
 * both show «0», and that is precisely the pair a client must be able to tell apart.
 */
final class DataFreshnessService
{
    /**
     * How old the source's own figures may be before a project reads as stale.
     *
     * Two days, not one: the platforms restate the previous day for hours, several sweep on a
     * multi-hour cron, and a badge that flipped to «قديم» every morning before the first sweep would be
     * ignored within a week — which is worse than not showing one.
     */
    public const STALE_AFTER_HOURS = 48;

    public function __construct(private readonly ProjectStores $stores) {}

    /**
     * Every source feeding these projects — ad platforms and stores alike — with its own age and state.
     *
     * @param  list<string>  $projectIds
     * @param  list<string>|null  $providers  restrict the ad-platform rows (a client link's ceiling); null = all
     * @return list<array<string,mixed>>
     */
    public function sources(string $tenantId, array $projectIds, ?array $providers = null, ?Carbon $now = null): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds)));

        if ($projectIds === []) {
            return [];
        }

        $now ??= Carbon::now();

        return [
            ...$this->adPlatformSources($projectIds, $providers, $now),
            ...$this->storeSources($tenantId, $projectIds, $now),
        ];
    }

    /**
     * One rolled-up verdict over those sources, for a window.
     *
     * `partial` outranks `stale` and `sync_failed` outranks both: a reader who is told the data is
     * merely old will wait, and a reader told a sync failed will go and look. The more actionable fact
     * wins.
     *
     * @param  list<string>  $projectIds
     * @return array{state:string,last_sync_at:?string,missing_days:int,sync_failed:bool,sources:list<array<string,mixed>>}
     */
    public function state(string $tenantId, array $projectIds, Carbon $from, Carbon $to, ?array $providers = null, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $sources = $this->sources($tenantId, $projectIds, $providers, $now);

        if ($sources === []) {
            return ['state' => 'no_data', 'last_sync_at' => null, 'missing_days' => 0, 'sync_failed' => false, 'sources' => []];
        }

        /*
         * A gap in the daily metrics is only a gap when something was expected to fill it.
         *
         * `missingDays()` counts days of the window with no `daily_metrics` row. On a store-only
         * project there are no ad platforms to write one, so every day of the window counts as missing
         * and the badge reads `partial` forever — a permanent warning about an absence that is simply
         * what the project is. The live review caught it doing exactly that: «partial, 30 missing
         * days» on a project whose only source was a shop that had synced twenty minutes earlier.
         */
        $hasAdPlatform = array_filter($sources, static fn (array $s): bool => $s['kind'] === 'ad_platform') !== [];
        $missing = $hasAdPlatform ? $this->missingDays($projectIds, $from, $to) : 0;
        $failed = array_filter($sources, static fn (array $s): bool => $s['state'] === 'failed');
        $stale = array_filter($sources, static fn (array $s): bool => $s['state'] === 'stale');

        $lastSync = collect($sources)->pluck('data_as_of')->filter()->max();

        return [
            'state' => match (true) {
                $failed !== [] => 'sync_failed',
                $missing > 0 => 'partial',
                $stale !== [] => 'stale',
                default => 'fresh',
            },
            'last_sync_at' => $lastSync === null ? null : (string) $lastSync,
            'missing_days' => $missing,
            'sync_failed' => $failed !== [],
            'sources' => $sources,
        ];
    }

    /**
     * The newest moment any source behind these projects said its figures were current.
     *
     * Bulk-safe: the client list asks this for a page of clients at once, so it takes every project in
     * one call and folds per project rather than being asked once per client.
     *
     * @param  list<string>  $projectIds
     * @return array<string, string> project id => ISO-8601
     */
    public function lastSyncByProject(string $tenantId, array $projectIds): array
    {
        $out = [];

        foreach ($this->sources($tenantId, $projectIds) as $source) {
            $at = $source['data_as_of'] ?? null;
            $projectId = (string) $source['project_id'];

            if ($at !== null && (! isset($out[$projectId]) || $at > $out[$projectId])) {
                $out[$projectId] = (string) $at;
            }
        }

        return $out;
    }

    /**
     * Ad platforms: newest figure per (project, provider), against that provider's newest run.
     *
     * @param  list<string>  $projectIds
     * @param  list<string>|null  $providers
     * @return list<array<string,mixed>>
     */
    private function adPlatformSources(array $projectIds, ?array $providers, Carbon $now): array
    {
        $data = DailyMetric::withoutGlobalScopes()
            ->whereIn('project_id', $projectIds)
            ->when($providers !== null && $providers !== [], fn ($q) => $q->whereIn('provider', $providers))
            ->toBase()
            ->select('project_id', 'provider')
            ->selectRaw('MAX(data_freshness_at) AS data_as_of')
            ->selectRaw('MAX(metric_date) AS latest_metric_date')
            ->groupBy('project_id', 'provider')
            ->get();

        $runs = MetricSyncRun::withoutGlobalScopes()
            ->whereIn('project_id', $projectIds)
            ->when($providers !== null && $providers !== [], fn ($q) => $q->whereIn('provider', $providers))
            ->toBase()
            ->select('project_id', 'provider')
            /*
             * INTEG-RUNTIME §8 — «we reached the provider» is three statuses, not two.
             *
             * `no_data` belongs here: we asked and they answered. Leaving it out would make an account
             * that is simply quiet look like one we have never been able to read, and then age it into
             * a staleness alert about a problem that does not exist.
             */
            ->selectRaw("MAX(finished_at) FILTER (WHERE status IN ('success', 'no_data', 'partial_mapping')) AS succeeded_at")
            ->selectRaw('MAX(finished_at) AS checked_at')
            ->selectRaw("MAX(finished_at) FILTER (WHERE status = 'failed') AS failed_at")
            ->groupBy('project_id', 'provider')
            ->get()
            ->keyBy(fn ($r) => $r->project_id.'|'.$r->provider);

        $rows = [];

        /*
         * Every provider the caller ASKED about gets a row, whether or not anything was ever read from
         * it — and a provider with a run but no metric rows gets one too.
         *
         * A platform we have never successfully read is the single most important row on this list, and
         * it is the one with no data to build a row out of. Emitting only what the tables happen to
         * contain makes that platform silently disappear, which is indistinguishable on the page from a
         * platform that was fine and quiet. «Awaiting credentials» has to be said out loud, in the place
         * the figure would have gone.
         */
        $keys = [];

        if ($providers !== null) {
            foreach ($projectIds as $projectId) {
                foreach ($providers as $provider) {
                    $keys[$projectId.'|'.$provider] = ['project_id' => $projectId, 'provider' => $provider];
                }
            }
        }

        foreach ($data as $d) {
            $keys[$d->project_id.'|'.$d->provider] = ['project_id' => $d->project_id, 'provider' => $d->provider];
        }
        foreach ($runs as $key => $r) {
            $keys[$key] ??= ['project_id' => $r->project_id, 'provider' => $r->provider];
        }

        $byKey = $data->keyBy(fn ($r) => $r->project_id.'|'.$r->provider);

        foreach ($keys as $key => $identity) {
            $d = $byKey->get($key);
            $run = $runs->get($key);
            $dataAsOf = $d?->data_as_of;

            $rows[] = [
                'kind' => 'ad_platform',
                'project_id' => (string) $identity['project_id'],
                'provider' => (string) $identity['provider'],
                'account_id' => null,
                'name' => null,
                'data_as_of' => $this->iso($dataAsOf),
                'latest_metric_date' => $d?->latest_metric_date ? Carbon::parse((string) $d->latest_metric_date)->toDateString() : null,
                'last_checked_at' => $this->iso($run?->checked_at),
                'last_sync_error' => null,
                'state' => $this->verdict(
                    succeededAt: $run?->succeeded_at,
                    checkedAt: $run?->checked_at,
                    failedAt: $run?->failed_at,
                    dataAsOf: $dataAsOf,
                    now: $now,
                ),
            ];
        }

        return $rows;
    }

    /**
     * Stores: the shop's own last sweep, against its newest commerce run.
     *
     * Scoped through {@see ProjectStores} rather than by tenant, so a project's freshness never rests on
     * a shop belonging to a different client of the same agency.
     *
     * @param  list<string>  $projectIds
     * @return list<array<string,mixed>>
     */
    private function storeSources(string $tenantId, array $projectIds, Carbon $now): array
    {
        $byProject = $this->stores->accountIdsByProject($projectIds);

        if ($byProject === []) {
            return [];
        }

        $allIds = array_values(array_unique(array_merge(...array_values($byProject))));

        $stores = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('account_type', 'store')
            ->whereIn('id', $allIds)
            ->get(['id', 'name', 'provider', 'provider_connection_id', 'last_synced_at'])
            ->keyBy(fn (ExternalAccount $s) => (string) $s->getKey());

        $runs = IntegrationSyncRun::withoutGlobalScopes()
            ->whereIn('project_id', $projectIds)
            ->where('type', 'commerce')
            ->toBase()
            ->select('provider_connection_id')
            /*
             * INTEG-RUNTIME §8 — «we reached the provider» is three statuses, not two.
             *
             * `no_data` belongs here: we asked and they answered. Leaving it out would make an account
             * that is simply quiet look like one we have never been able to read, and then age it into
             * a staleness alert about a problem that does not exist.
             */
            ->selectRaw("MAX(finished_at) FILTER (WHERE status IN ('success', 'no_data', 'partial_mapping')) AS succeeded_at")
            ->selectRaw('MAX(finished_at) AS checked_at')
            ->selectRaw("MAX(finished_at) FILTER (WHERE status = 'failed') AS failed_at")
            ->selectRaw('(ARRAY_AGG(error ORDER BY finished_at DESC NULLS LAST))[1] AS last_error')
            ->groupBy('provider_connection_id')
            ->get()
            ->keyBy('provider_connection_id');

        $rows = [];

        foreach ($byProject as $projectId => $storeIds) {
            foreach ($storeIds as $storeId) {
                $store = $stores->get((string) $storeId);

                if ($store === null) {
                    continue; // an account row that is not a store of this tenant
                }

                $run = $runs->get((string) $store->provider_connection_id);
                $dataAsOf = $store->last_synced_at;

                $rows[] = [
                    'kind' => 'store',
                    'project_id' => (string) $projectId,
                    'provider' => (string) $store->provider,
                    'account_id' => (string) $store->getKey(),
                    'name' => (string) $store->name,
                    'data_as_of' => $this->iso($dataAsOf),
                    'latest_metric_date' => null,
                    'last_checked_at' => $this->iso($run?->checked_at),
                    'last_sync_error' => $run?->last_error,
                    'state' => $this->verdict(
                        succeededAt: $run?->succeeded_at,
                        checkedAt: $run?->checked_at,
                        failedAt: $run?->failed_at,
                        dataAsOf: $dataAsOf,
                        now: $now,
                    ),
                ];
            }
        }

        return $rows;
    }

    /**
     * One source's verdict, from one set of rules.
     *
     * Order matters and is deliberate. A source we have never read successfully is
     * `awaiting_credentials` whatever else is true of it — calling that «stale» would imply we once had
     * figures and they aged, which is a claim about data that never arrived. A source whose LATEST run
     * failed is `failed` even if an older run succeeded, because the figures on the page are now
     * knowingly behind and somebody has to go and look.
     */
    private function verdict(mixed $succeededAt, mixed $checkedAt, mixed $failedAt, mixed $dataAsOf, Carbon $now): string
    {
        /*
         * Figures on the table DISPROVE «awaiting credentials», whatever the run log says.
         *
         * The first cut asked the run tables alone, and the live review caught it calling a store with
         * six orders and a sweep twenty minutes old «awaiting credentials» — because that particular
         * store's run row was not there to be found. Runs get pruned by retention, and rows predate the
         * day their table started recording; data does neither. «We have never obtained figures from
         * this source» is a claim the figures themselves refute, and a page that shows revenue beside a
         * badge saying we cannot read the shop contradicts itself in front of the reader.
         */
        if ($succeededAt === null && $dataAsOf === null) {
            return 'awaiting_credentials';
        }

        if ($failedAt !== null && $succeededAt !== null && Carbon::parse((string) $failedAt)->gte(Carbon::parse((string) $succeededAt))) {
            return 'failed';
        }

        if ($dataAsOf === null || Carbon::parse((string) $dataAsOf)->lt($now->copy()->subHours(self::STALE_AFTER_HOURS))) {
            return 'stale';
        }

        return 'fresh';
    }

    /**
     * Days in the window with no metric row at all.
     *
     * Counted only up to today: a window running to the end of the month is not «missing» the days that
     * have not happened yet, and reporting them as gaps would put every forward-looking report into a
     * permanent `partial`.
     *
     * @param  list<string>  $projectIds
     */
    private function missingDays(array $projectIds, Carbon $from, Carbon $to): int
    {
        $end = $to->copy()->min(Carbon::today());

        if ($end->lt($from)) {
            return 0;
        }

        $withData = (int) DB::table('daily_metrics')
            ->whereIn('project_id', $projectIds)
            ->whereBetween('metric_date', [$from->toDateString(), $end->toDateString()])
            ->distinct()
            ->count('metric_date');

        return max(0, ((int) $from->diffInDays($end) + 1) - $withData);
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}
