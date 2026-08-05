<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Commerce\Services\StoreFunnelService;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;

/**
 * LIVEREP-001 — the figures behind a live shared link, recomputed on every request.
 *
 * ## The rule this class exists to enforce
 *
 * **A client's filters may only ever NARROW what the link was given.** The share carries a ceiling —
 * one project, a chosen set of campaigns, a set of platforms, an earliest and a latest date — and every
 * incoming filter is intersected with it. Ask for a campaign that is not in the ceiling and you get the
 * ceiling's campaigns; ask for a date before it starts and you get its start. There is no path through
 * this class by which a parameter widens anything, which is why the intersection happens here, once,
 * rather than in the controller where a later endpoint could forget it.
 *
 * That matters more here than anywhere else in the product, because this is the only surface reachable
 * **without a session**. The tenant and project scopes that protect every other query are driven by the
 * signed-in user; there is no user here. So the scope is entered explicitly from the share's own row,
 * and the aggregator is additionally bounded by campaign id — belt and braces, because a project bound
 * alone would still expose a sibling campaign the client was never shown.
 *
 * ## Honesty about freshness
 *
 * «Live» is a claim about THIS system recomputing, not about the ad platforms having just reported. The
 * two are different and conflating them would be a lie the client cannot check. So every response
 * carries, per platform: when its data was last refreshed, whether a sync has ever succeeded, and
 * whether it is connected at all. A platform with no credentials reports `awaiting_credentials` and is
 * shown as such — never as a zero, which reads as «we spent nothing» rather than «we cannot see it».
 */
final class LiveReportService
{
    /** Filters a client may narrow by. Anything else in the query string is ignored, not honoured. */
    private const NARROWABLE = ['from', 'to', 'providers', 'campaigns'];

    public function __construct(
        private readonly MetricsAggregator $metrics,
        private readonly TenantContext $tenants,
        private readonly ProjectContext $projects,
    ) {}

    /**
     * Build the payload for a live share, with the caller's filters intersected against its ceiling.
     *
     * @param  array<string, mixed>  $requested  raw query input — untrusted, never used unintersected
     * @param  string  $currency  the report's own currency; every figure here is already normalised to it
     * @return array<string, mixed>
     */
    public function build(ReportShare $share, array $requested, string $currency = 'SAR'): array
    {
        $scope = $this->ceiling($share);
        $applied = $this->intersect($scope, $requested);

        /*
         * Enter the share's own tenant and project.
         *
         * There is no authenticated user on this request, so nothing has set these. They come from the
         * share row — the one thing on this request whose provenance is known, because it was written by
         * an authenticated operator when the link was created.
         */
        $this->tenants->setTenantId((string) $share->tenant_id);
        $this->projects->setProjectId($scope['project_id']);

        $from = Carbon::parse($applied['from']);
        $to = Carbon::parse($applied['to']);

        $engine = $this->metrics
            ->forCampaigns($scope['campaign_ids'])
            ->forProviders($applied['providers']);

        // A narrowed campaign set is applied on top of the ceiling, never instead of it.
        if ($applied['campaigns'] !== []) {
            $engine = $engine->forCampaigns($applied['campaigns']);
        }

        $totals = $engine->totals($from, $to);
        $previous = $this->previousPeriod($engine, $from, $to);

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $from->diffInDays($to) + 1,
            ],
            'currency' => $currency,
            'totals' => $totals,
            'deltas' => $this->deltas($totals, $previous),
            'timeseries' => $engine->timeseries($from, $to),
            'platforms' => $engine->byProvider($from, $to),
            'campaigns' => $engine->byCampaign($from, $to),
            'funnel' => $engine->funnel($from, $to),
            /*
             * FUNNEL-001 in a client link — the same section the operator reads, for the same project.
             *
             * Built by the SAME service the analytics tab calls, deliberately: a client link that
             * computed the funnel its own way would be a second answer to «كم طلبًا جاء من الإعلان؟»,
             * and the first time the two disagreed nobody would know which to believe.
             *
             * Null when the project has no store, rather than a funnel of nulls the page would render
             * as a section that failed to load.
             */
            'store_funnel' => $this->storeFunnel($share, $scope['project_id'], $from, $to),
            'freshness' => $this->freshness($scope['project_id'], $scope['providers']),
            /*
             * LIVEREP-002 — the metrics the operator chose, in the order they chose to show them.
             *
             * Empty means «all of them», which is what a link built before metric selection existed
             * gets, and what an operator who skipped the step means. The client's page renders THIS
             * list rather than a fixed set, so unticking «Revenue» actually removes the card instead
             * of merely blanking a number — a blank card still tells the reader a figure exists and
             * is being withheld.
             */
            'metrics' => array_values(array_filter(
                (array) ($share->scope['metrics'] ?? []),
                static fn ($m): bool => is_string($m) && $m !== '',
            )),
            'available' => [
                'providers' => $scope['providers'],
                'campaigns' => $this->campaignChoices($scope['campaign_ids']),
                'earliest' => $scope['earliest'],
                'latest' => $scope['latest'],
            ],
            'applied' => $applied,
        ];
    }

    /**
     * The ceiling, normalised, with every value forced into the shape the rest of this class expects.
     *
     * Read defensively: this is JSON written by an earlier version of the app, and a missing key must
     * degrade to «nothing», not to «everything». `campaign_ids => []` means the link shows no campaign
     * data at all, which is a visibly broken link somebody will report — the opposite mistake is a link
     * that silently shows the whole project.
     *
     * @return array{project_id: string, campaign_ids: list<string>, providers: list<string>, earliest: string, latest: string}
     */
    private function ceiling(ReportShare $share): array
    {
        $scope = $share->scope ?? [];

        return [
            'project_id' => (string) ($scope['project_id'] ?? ''),
            'campaign_ids' => array_values(array_filter((array) ($scope['campaign_ids'] ?? []))),
            'providers' => array_values(array_filter((array) ($scope['providers'] ?? []))),
            'earliest' => (string) ($scope['earliest'] ?? Carbon::now()->subDays(30)->toDateString()),
            'latest' => (string) ($scope['latest'] ?? Carbon::now()->toDateString()),
        ];
    }

    /**
     * Intersect what was asked for with what was granted. Never a union, never a replacement.
     *
     * Dates are CLAMPED rather than rejected: a client dragging a date picker past the window should see
     * the window's edge, not an error they cannot act on. Sets are INTERSECTED: asking for a platform
     * outside the ceiling drops that platform from the request rather than failing it, and asking for
     * nothing means the whole ceiling, which is the only case where the result is as wide as the grant.
     *
     * @param  array{project_id: string, campaign_ids: list<string>, providers: list<string>, earliest: string, latest: string}  $scope
     * @param  array<string, mixed>  $requested
     * @return array{from: string, to: string, providers: list<string>, campaigns: list<string>}
     */
    private function intersect(array $scope, array $requested): array
    {
        $requested = array_intersect_key($requested, array_flip(self::NARROWABLE));

        $earliest = Carbon::parse($scope['earliest'])->startOfDay();
        $latest = Carbon::parse($scope['latest'])->startOfDay();

        $from = $this->clamp($requested['from'] ?? null, $earliest, $latest, $earliest);
        $to = $this->clamp($requested['to'] ?? null, $earliest, $latest, $latest);
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'providers' => $this->within($requested['providers'] ?? null, $scope['providers']),
            'campaigns' => $this->within($requested['campaigns'] ?? null, $scope['campaign_ids']),
        ];
    }

    /** A requested date, pinned inside the granted window; unparseable or absent falls back to `$default`. */
    private function clamp(mixed $value, Carbon $min, Carbon $max, Carbon $default): Carbon
    {
        if (! is_string($value) || $value === '') {
            return $default->copy();
        }

        try {
            $date = Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return $default->copy();
        }

        return $date->lessThan($min) ? $min->copy() : ($date->greaterThan($max) ? $max->copy() : $date);
    }

    /**
     * The requested members that the ceiling actually contains. Empty request → empty result, which the
     * caller reads as «the whole ceiling»; a request of only forbidden members also lands here, so the
     * failure mode of a tampered URL is the link's normal view rather than somebody else's data.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function within(mixed $requested, array $allowed): array
    {
        if (! is_array($requested) || $requested === []) {
            return [];
        }

        $asked = array_map(strval(...), $requested);

        return array_values(array_intersect($asked, $allowed));
    }

    /** The same window immediately before this one, for period-over-period deltas. */
    private function previousPeriod(MetricsAggregator $engine, Carbon $from, Carbon $to): array
    {
        $days = $from->diffInDays($to) + 1;

        return $engine->totals($from->copy()->subDays($days), $from->copy()->subDay());
    }

    /**
     * Change per metric as a RATIO — 0.26 for +26% — or null where there was nothing to compare against.
     *
     * A ratio, not a percentage, because that is what every other surface in this product emits
     * (`ReportGenerator` line for line) and what the shared `TrendPill` formats. Returning 26.2 here
     * rendered «2620%» on the client's page: a real figure, multiplied by a hundred, sitting next to a
     * correct spend total — the kind of wrong that looks like a spectacular month rather than a bug.
     * Caught by opening the page, not by a test, which is why the sweep at the end of this feature
     * reads the rendered numbers rather than the payload.
     *
     * Null rather than 100%: growth from zero is not a percentage, and rendering one invites a client to
     * read «+100%» as a real doubling of a real number.
     *
     * @return array<string, float|null>
     */
    private function deltas(array $current, array $previous): array
    {
        $out = [];
        foreach ($current as $key => $value) {
            $before = (float) ($previous[$key] ?? 0);
            $out[$key] = $before > 0.0 ? round(((float) $value - $before) / abs($before), 4) : null;
        }

        return $out;
    }

    /**
     * Per-platform data age and connection state — the honest half of the word «live».
     *
     * `data_freshness_at` is when the SOURCE said its figures were current, which is the number a client
     * should judge the report by. The sync run's `finished_at` is when we last asked. Both are reported,
     * because «we asked ten minutes ago and Meta's latest figures are from this morning» is a different
     * situation from «we have not asked since Friday», and only one of them is our problem to fix.
     *
     * @param  list<string>  $providers
     * @return list<array<string, mixed>>
     */
    /**
     * The store funnel for this link's project, or null when there is no store to build one from.
     *
     * A share that hides revenue hides it HERE too. The funnel is a second place a figure could reach
     * a client, and a hide flag that covered the KPI cards and not this would leak the exact number the
     * operator chose to withhold — which is worse than never having offered the flag.
     *
     * @return array<string,mixed>|null
     */
    private function storeFunnel(ReportShare $share, string $projectId, Carbon $from, Carbon $to): ?array
    {
        $funnel = app(StoreFunnelService::class)->build((string) $share->tenant_id, $projectId, $from, $to);

        if (($funnel['coverage']['stores'] ?? 0) === 0) {
            return null;
        }

        if ($share->hide_revenue) {
            $funnel['totals']['revenue'] = null;
            $funnel['totals']['gross_revenue'] = null;
            $funnel['totals']['attributed_revenue'] = null;
            $funnel['derived']['roas'] = null;
            $funnel['derived']['attributed_roas'] = null;
            $funnel['derived']['aov'] = null;
            $funnel['comparisons']['products'] = [];
            $funnel['stages'] = array_map(static function (array $stage): array {
                if ($stage['key'] === 'revenue') {
                    $stage['value'] = null;
                }

                return $stage;
            }, $funnel['stages']);
        }

        if ($share->hide_spend) {
            $funnel['totals']['spend'] = null;
            $funnel['derived']['cpa'] = null;
            $funnel['derived']['cac'] = null;
            // ROAS and attributed ROAS are spend-derived; leaving them would let a reader divide back
            // to the figure the operator hid.
            $funnel['derived']['roas'] = null;
            $funnel['derived']['attributed_roas'] = null;
            $funnel['comparisons']['platforms'] = [];
        }

        return $funnel;
    }

    private function freshness(string $projectId, array $providers): array
    {
        if ($projectId === '' || $providers === []) {
            return [];
        }

        $lastData = DailyMetric::query()
            ->where('project_id', $projectId)
            ->whereIn('provider', $providers)
            ->selectRaw('provider, MAX(data_freshness_at) AS at')
            ->groupBy('provider')
            ->pluck('at', 'provider');

        $lastRun = MetricSyncRun::query()
            ->where('project_id', $projectId)
            ->whereIn('provider', $providers)
            ->selectRaw('provider, MAX(finished_at) FILTER (WHERE status IN (\'success\', \'partial\')) AS at')
            ->groupBy('provider')
            ->pluck('at', 'provider');

        return array_map(fn (string $provider): array => [
            'provider' => $provider,
            'data_as_of' => $this->iso($lastData[$provider] ?? null),
            'last_checked_at' => $this->iso($lastRun[$provider] ?? null),
            /*
             * Never synced is stated, not implied by a zero.
             *
             * A platform we have never successfully read shows the same «0 spend» as a platform that
             * genuinely spent nothing, and a client cannot tell those apart. This flag is what lets the
             * page say «awaiting credentials» in the place where the number would otherwise sit.
             */
            'state' => isset($lastRun[$provider]) ? 'synced' : 'awaiting_credentials',
        ], $providers);
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }

    /**
     * The campaigns the link may show, by name, so the client's filter reads as names rather than ids.
     *
     * `client_display_name` wins over `name` where one is set: the internal name is often an operator's
     * shorthand («KSA-Q3-retarget-v2»), and this list is read by the client.
     *
     * @param  list<string>  $ids
     * @return list<array{id: string, name: string}>
     */
    private function campaignChoices(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return UnifiedCampaign::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'client_display_name'])
            ->map(fn (UnifiedCampaign $c): array => [
                'id' => (string) $c->id,
                'name' => (string) ($c->client_display_name ?: $c->name),
            ])
            ->all();
    }
}
