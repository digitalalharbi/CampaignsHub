<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Services\CreativeFunnel;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Concerns\ProjectScope;
use App\Support\AdPlatforms;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-side aggregation over daily_metrics for the ACTIVE project (BelongsToProject scope applies).
 * All figures are project-currency normalized. Base metrics are summed with conditional aggregation
 * (one pass, no N+1); derived KPIs (ROAS/CPA/CTR/CPC/CPM) are computed from the sums, never summed.
 */
final class MetricsAggregator
{
    /** Conditional-sum expressions that pivot the tall metric table into wide base columns. */
    private const PIVOT = [
        'impressions' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'impressions'), 0)",
        'clicks' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'clicks'), 0)",
        'conversions' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'conversions'), 0)",
        'spend' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'spend'), 0)",
        'revenue' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'revenue'), 0)",

        // DASH-010-D: objective-specific base metrics (tall table → new keys, no schema change).
        'reach' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'reach'), 0)",
        'video_views' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'video_views'), 0)",
        'video_completions' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'video_completions'), 0)",
        'landing_page_views' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'landing_page_views'), 0)",
        'leads' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'leads'), 0)",
        'qualified_leads' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'qualified_leads'), 0)",
        'purchases' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'purchases'), 0)",
        'installs' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'installs'), 0)",
        'registrations' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'registrations'), 0)",
        'in_app_events' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'in_app_events'), 0)",
        'engagements' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'engagements'), 0)",
        /*
         * The two basket steps — METRIC-NAMES-001.
         *
         * Both were already STORED and already read by the funnel; they were simply absent from the
         * pivot, so no surface could show «الإضافة للسلة» as a figure. A sales campaign is judged on
         * the path from a basket to an order, and a metric the product collects but cannot total is
         * a metric nobody can act on.
         *
         * They join the pivot rather than being recomputed anywhere: one definition, one place.
         */
        'add_to_cart' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'add_to_cart'), 0)",
        'checkout' => "COALESCE(SUM(value) FILTER (WHERE metric_key = 'checkout'), 0)",
    ];

    /**
     * The funnel's stages. Two of them (`add_to_cart`, `checkout`) are read HERE and nowhere else.
     *
     * The last stage is `purchases`, not `conversions` (FUNNEL-PURCHASE-001). It used to be
     * `conversions` while the label beneath it said **Purchase**, and those are not the same figure:
     * `conversions` is the sum of every event a campaign was optimised for — a lead, an install, a
     * registration, a form submit. On a lead-generation account the funnel therefore ended in a count
     * of LEADS with the word «الشراء» printed under it.
     *
     * It went unnoticed because Snapchat maps both canonical keys from `conversion_purchases`, so
     * the two were literally the same number and the label was accidentally true. TikTok is the
     * first platform to map them apart (`complete_payment` vs `conversion`), which turns a dormant
     * mislabelling into a figure a client would act on.
     *
     * An account that reports no `purchases` now ends the funnel in «لم تُرسل» rather than in
     * somebody else's number — which is the honest answer, and the one FUNNEL-NULL-001 already
     * built the display for. `conversions` remains everywhere it belongs: it is still in `PIVOT`,
     * still summed, still the «النتائج» figure on every dashboard. It is simply not the sale.
     */
    private const FUNNEL_STAGES = ['impressions', 'clicks', 'landing_page_views', 'add_to_cart', 'checkout', 'purchases'];

    /**
     * Every `metric_key` this engine reads (NORM-001).
     *
     * The union of the pivot and the funnel. The two used to diverge — `add_to_cart` and `checkout`
     * were stored and read by the funnel while being absent from `PIVOT` — so a caller asking «which
     * of my metrics does nothing read?» against the pivot alone was told those two were ignored.
     * They are in both now (METRIC-NAMES-001); the union stays because it is the honest expression
     * of the question, not because the two lists currently happen to agree.
     *
     * @return list<string>
     */
    /**
     * The money-truth annotations — provenance, not metrics.
     *
     * These describe `spend` and `revenue` (was anything withheld, and what did the platform actually
     * report) rather than measuring anything themselves. They deliberately have no catalogue entry:
     * a `MetricDefinition` for «spend_withheld_rows» would offer it as a KPI somebody could chart,
     * and it is not one. Callers that check «is every emitted metric defined» subtract these.
     *
     * @return list<string>
     */
    public static function moneyTruthKeys(): array
    {
        return array_keys(self::MONEY_TRUTH);
    }

    public static function readKeys(): array
    {
        return array_values(array_unique([...array_keys(self::PIVOT), ...self::FUNNEL_STAGES]));
    }

    /**
     * FX-WITHHELD-UI-001 — money truth, kept OUT of `PIVOT` on purpose.
     *
     * `PIVOT` is also `readKeys()`: the list of metric keys the connectors are asked to fetch. These
     * four are aggregates over columns we already store, not metrics any platform reports, so putting
     * them in `PIVOT` would have made the sync request «spend_withheld_rows» from Snapchat.
     *
     * `*_original` sums ONLY the withheld rows. It summed every original at first, including rows
     * that converted perfectly well, so a project mixing converted and unconvertible money reported
     * an original larger than the amount actually withheld — 5,100 where 5,000 was withheld. It went
     * unnoticed because production's rows are entirely withheld and entirely USD; the breakdown test
     * is what exposed it, by grouping a converted row beside an unconvertible one.
     *
     * They exist because `COALESCE(SUM(value), 0)` is right for arithmetic and wrong for a KPI card.
     * When FX-001 withholds a row there is no rate, `value` is null on purpose, and the coalesce turns
     * that into a literal 0 — indistinguishable from a day that truly cost nothing. Production paid
     * for the difference: 3,465.33 USD of real Snapchat spend rendered as «0» under a label saying
     * «لم ترسله المنصة», when the platform had sent it and we withheld it.
     */
    private const MONEY_TRUTH = [
        'spend_withheld_rows' => "COUNT(*) FILTER (WHERE metric_key = 'spend' AND value IS NULL AND original_amount IS NOT NULL)",
        'spend_original' => "COALESCE(SUM(original_amount) FILTER (WHERE metric_key = 'spend' AND value IS NULL AND original_amount IS NOT NULL), 0)",
        'revenue_withheld_rows' => "COUNT(*) FILTER (WHERE metric_key = 'revenue' AND value IS NULL AND original_amount IS NOT NULL)",
        'revenue_original' => "COALESCE(SUM(original_amount) FILTER (WHERE metric_key = 'revenue' AND value IS NULL AND original_amount IS NOT NULL), 0)",

        /*
         * The currency the original is IN — without it the number cannot be shown.
         *
         * «3,465.33» beside a project that reports in SAR reads as riyals, which is a worse lie than
         * the zero it replaces. `MIN` rather than an aggregate over many: a withheld figure is
         * withheld precisely because one rate is missing, so these rows share a currency. If a second
         * ever appears the reader gets one of them and the ORIGINAL total is the sum across both,
         * which would be wrong — so the count below is what a caller must check first.
         */
        'money_original_currency' => 'MIN(original_currency) FILTER (WHERE value IS NULL AND original_amount IS NOT NULL)',
        'money_original_currencies' => 'COUNT(DISTINCT original_currency) FILTER (WHERE value IS NULL AND original_amount IS NOT NULL)',
    ];

    /** When set, every aggregation is scoped to this single unified campaign (command center). */
    private ?string $campaignId = null;

    /** When set, every aggregation is scoped to this set of unified campaigns (a shared link's ceiling). */
    private ?array $campaignIds = null;

    /** When set, every aggregation is scoped to these project ids (a client spans many projects). */
    private ?array $projectIds = null;

    /** When set, every aggregation is scoped to these ad platforms (dashboard command-center filter). */
    private ?array $providers = null;

    /** When set, every aggregation is scoped to campaigns with these objectives (objective-KPI filter). */
    private ?array $objectives = null;

    /** When set, every aggregation is scoped to these ad accounts (a report scope's account axis). */
    private ?array $accountIds = null;

    /**
     * Return a campaign-scoped copy of the aggregator — every subsequent totals/byProvider/timeseries/
     * funnel/budget call is filtered to this campaign's metrics (on top of the project/tenant scope).
     * A clone keeps the shared singleton stateless.
     */
    public function forCampaign(?string $campaignId): self
    {
        $clone = clone $this;
        $clone->campaignId = $campaignId;

        return $clone;
    }

    /**
     * Return a copy scoped to a SET of unified campaigns (LIVEREP-001 — a shared link's ceiling).
     *
     * `forCampaign()` takes one id because the command centre looks at one campaign. A shared link is
     * given a chosen handful, and passing an empty set must mean «no campaigns», never «all of them» —
     * the same fail-closed rule `forProjects()` already follows, and for the same reason: this bound is
     * the only thing standing between a client's link and somebody else's numbers.
     *
     * @param  list<string>  $campaignIds
     */
    public function forCampaigns(array $campaignIds): self
    {
        $clone = clone $this;
        $clone->campaignIds = array_values(array_unique(array_filter($campaignIds)));

        return $clone;
    }

    /**
     * Return a copy scoped to a set of project ids — used by the Client Command Center to aggregate a
     * single client's metrics across ALL its projects, while the tenant scope still fails closed.
     * Reuses every derived-KPI formula unchanged (no parallel metrics engine).
     *
     * @param  list<string>  $projectIds
     */
    public function forProjects(array $projectIds): self
    {
        $clone = clone $this;
        $clone->projectIds = $projectIds;

        return $clone;
    }

    /**
     * Return a copy scoped to a set of ad platforms (Snapchat/TikTok/Meta/Google Ads/X/LinkedIn). Empty →
     * no platform filter. Backend-supported dashboard platform filter — every KPI/chart/breakdown it
     * produces is limited to these providers (never a React-only filter).
     *
     * @param  list<string>  $providers
     */
    public function forProviders(array $providers): self
    {
        $clone = clone $this;
        $clone->providers = $providers === [] ? null : array_values($providers);

        return $clone;
    }

    /**
     * Return a copy scoped to campaigns with these objectives (DASH-010-D objective filter). Objective lives
     * on unified_campaigns; we scope daily_metrics via the campaign id. Empty → no objective filter.
     *
     * @param  list<string>  $objectives
     */
    public function forObjectives(array $objectives): self
    {
        $clone = clone $this;
        $clone->objectives = $objectives === [] ? null : array_values($objectives);

        return $clone;
    }

    /**
     * Return a copy scoped to a set of ad accounts (§14.5 — the report scope's account axis).
     *
     * `daily_metrics.external_account_id` is the account the row was ingested for, so this is a real
     * bound on every figure rather than a label. Empty → no account filter, matching `forProviders()`:
     * this axis is one an operator ADDS to narrow, and an unset axis has never meant «nothing» here.
     * The fail-closed spelling for «nothing matches» belongs to the scope object, which writes an
     * impossible id rather than an empty list.
     *
     * @param  list<string>  $accountIds
     */
    public function forAccounts(array $accountIds): self
    {
        $clone = clone $this;
        $clone->accountIds = $accountIds === [] ? null : array_values(array_unique($accountIds));

        return $clone;
    }

    /**
     * When true the ACTIVE-project scope is lifted; the tenant scope always stays (see acrossProjects()).
     */
    private bool $acrossProjects = false;

    /**
     * A copy that is not confined to whichever project the request has open.
     *
     * For callers that already name the entity they mean and are not looking through a project's eyes —
     * the alert evaluator being the case this exists for. It runs from the scheduler over one tenant's
     * rules, and a campaign id already names exactly one project, so the active-project filter can only
     * do harm: evaluated while some other project happened to be open, every rule would quietly match
     * nothing and the run would report zero alerts raised rather than an error.
     *
     * The TENANT scope is untouched and non-negotiable. This lifts one bound, deliberately and by name.
     */
    public function acrossProjects(): self
    {
        $clone = clone $this;
        $clone->acrossProjects = true;

        return $clone;
    }

    /**
     * DEMO-LIVE-AGGREGATION-ISOLATION-001 — an operational total never quietly contains demo money.
     *
     * ## The defect
     *
     * `base()` had no `is_demo` filter of any kind, so every aggregate this class produces — the
     * dashboard KPIs, the platform breakdown, the campaign ranking — summed real rows and seeded
     * ones together whenever both were in scope. ANALYTICS-PROVENANCE-001 made that VISIBLE by
     * reporting «mixed», and a badge is where it stopped: the figures directly beneath it were
     * still adding invented spend to a customer's real spend. Naming a leak is not closing it.
     *
     * ## The policy, and why it is derived rather than configured
     *
     * There is no `is_demo` column on `projects` or `tenants` — demo-ness is a fact about ROWS, put
     * there by the seeders. So the scope's nature is read from the rows themselves:
     *
     *   - the scope holds any live row  → it is an OPERATIONAL scope, and demo rows are excluded;
     *   - the scope holds only demo rows → it is a DEMO scope, and they are exactly what to show.
     *
     * A live project that somehow acquired demo rows therefore reports its own real numbers, and the
     * demo tenant keeps working as a demo. Nothing has to be flagged by hand, and no environment
     * variable decides whose money is real.
     *
     * ## Why the scope decides and not the window
     *
     * Deliberately evaluated WITHOUT the date range. If the window decided, a project with live rows
     * in June and demo rows in July would switch policy as somebody dragged the date picker — the
     * same KPI meaning two different things on two ranges, with nothing on screen to say so.
     */
    private function excludesDemo(): bool
    {
        return $this->excludesDemo ??= $this->scoped(DailyMetric::query())
            // Qualified, as every column in this class is: `byCampaign()` left-joins
            // `unified_campaigns`, and an unqualified name is one added column away from ambiguous.
            ->where('daily_metrics.is_demo', false)
            ->exists();
    }

    /** Memo for {@see excludesDemo()} — one existence check per aggregator, not per aggregate. */
    private ?bool $excludesDemo = null;

    /**
     * The scope filters alone: tenant, project, campaign, provider, account, objective. No date, no
     * demo policy.
     *
     * Extracted so `excludesDemo()` can ask about the whole scope rather than one window, and so
     * `provenance()` can count BOTH kinds — a provenance report filtered by the demo policy would
     * only ever be able to say «live», which is the one answer it exists to be able to doubt.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<DailyMetric>  $query
     * @return \Illuminate\Database\Eloquent\Builder<DailyMetric>
     */
    private function scoped(mixed $query): mixed
    {
        $query->when($this->acrossProjects, fn ($q) => $q->withoutGlobalScope(ProjectScope::class));

        if ($this->campaignId !== null) {
            $query->where('unified_campaign_id', $this->campaignId);
        }

        if ($this->campaignIds !== null) {
            $query->whereIn(
                'daily_metrics.unified_campaign_id',
                $this->campaignIds ?: ['00000000-0000-0000-0000-000000000000'],
            );
        }

        if ($this->projectIds !== null) {
            $query->whereIn('daily_metrics.project_id', $this->projectIds ?: ['00000000-0000-0000-0000-000000000000']);
        }

        if ($this->providers !== null) {
            $query->whereIn('daily_metrics.provider', $this->providers);
        }

        if ($this->accountIds !== null) {
            $query->whereIn('daily_metrics.external_account_id', $this->accountIds);
        }

        if ($this->objectives !== null) {
            $query->whereIn('daily_metrics.unified_campaign_id', function ($sub) {
                $sub->select('id')->from('unified_campaigns')->whereIn('objective', $this->objectives);
            });
        }

        return $query;
    }

    private function base(Carbon $from, Carbon $to): Builder
    {
        $query = $this->baseIncludingDemo($from, $to);

        if ($this->excludesDemo()) {
            // Operational scope: the customer's own rows are the answer, and a seeded row added to
            // them is not a rounding error — it is invented money inside a real total.
            $query->where('daily_metrics.is_demo', false);
        }

        return $query->toBase();
    }

    /**
     * The same scope and window with NO demo policy — for counting what is actually there.
     *
     * @return \Illuminate\Database\Eloquent\Builder<DailyMetric>
     */
    private function baseIncludingDemo(Carbon $from, Carbon $to): mixed
    {
        return $this->scoped(DailyMetric::query())
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);
    }

    /** @return array<string, float> base sums + derived KPIs for the period. */
    public function totals(Carbon $from, Carbon $to): array
    {
        $select = array_merge(self::PIVOT, self::MONEY_TRUTH);

        $row = $this->base($from, $to)->selectRaw(implode(', ', array_map(
            fn ($expr, $alias) => "{$expr} AS {$alias}",
            $select,
            array_keys($select),
        )))->first();

        return $this->withDerived((array) $row);
    }

    /**
     * Which base metrics any platform actually sent in this window — UX-METRICS-001.
     *
     * `PIVOT` coalesces every sum to `0`, which is right for arithmetic and wrong for a KPI card: a
     * platform that does not count landing-page views and a platform that counted none of them both
     * produce «0», and a reader looking at «Landing page views 0» beside forty thousand impressions
     * concludes the campaign is broken. FUNNEL-NULL-001 fixed exactly this for the funnel by
     * dropping the COALESCE; the totals cannot follow it there without changing what every surface
     * that sums them receives, so the distinction is published BESIDE the figures instead.
     *
     * `true` means at least one row for that key exists in the window, so a zero is measured.
     * `false` means nothing was ever sent, and the card must say so rather than print a zero.
     * Derived ratios are absent from this map on purpose: they are computed, never sent, and their
     * own `null` already carries «there was no denominator».
     *
     * @return array<string, bool>
     */
    public function reportedKeys(Carbon $from, Carbon $to): array
    {
        $present = $this->base($from, $to)
            ->distinct()
            ->pluck('metric_key')
            ->map(static fn ($k): string => (string) $k)
            ->all();

        $out = [];
        foreach (array_keys(self::PIVOT) as $key) {
            $out[$key] = in_array($key, $present, true);
        }

        return $out;
    }

    /**
     * The same answer, per platform — because «reported» is a fact about a CONNECTOR, not a scope.
     *
     * Meta publishes reach and X does not. A scope-wide map says reach was reported, so an X page
     * showing the coalesced `0` would state that an X campaign reached nobody. The distinction only
     * exists per provider, which is where the question is actually asked.
     *
     * @return array<string, array<string,bool>> provider → metric key → was it ever sent
     */
    public function reportedKeysByProvider(Carbon $from, Carbon $to): array
    {
        $rows = $this->base($from, $to)
            ->select('provider', 'metric_key')
            ->distinct()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->provider][(string) $row->metric_key] = true;
        }

        foreach ($out as $provider => $present) {
            foreach (array_keys(self::PIVOT) as $key) {
                $out[$provider][$key] = $present[$key] ?? false;
            }
        }

        return $out;
    }

    /**
     * REPORT-OBJECTIVE-005 — what the single «conversions» figure above actually is.
     *
     * `SUM(conversions)` over more than one platform is the sum of each platform's own claim, and
     * those claims OVERLAP: a sale a shopper clicked from Snapchat on Tuesday and from Meta on
     * Thursday is reported in full by both. There is no shared key that would let us prove two
     * conversions are one sale — conversion payloads carry no order id — so the sum cannot be
     * deduplicated, and it is NOT a count of unique orders.
     *
     * The figure is not removed. It is the only conversion number available before a store is
     * connected, it is what every platform optimises against, and deleting it would leave the
     * dashboard with nothing to say. What was missing was the sentence beside it — the contract is
     * explicit that per-platform figures may be shown but «never summed into unique unified orders»,
     * and an unlabelled total is read as exactly that.
     *
     * Returned in the PAYLOAD rather than added by each page, so a surface cannot print the number
     * while forgetting the caveat. One platform contributing means no overlap is possible, and the
     * basis says so instead of warning about a risk that does not exist.
     *
     * Deliberately NOT a key inside `totals()`. That map is metrics, and every key in it is asserted
     * against the canonical catalogue — a provenance field smuggled in there would either fail that
     * guard or force it to be loosened, and the guard is worth more than the convenience.
     *
     * @return array<string,mixed>
     */
    public function conversionsBasis(Carbon $from, Carbon $to): array
    {
        $providers = $this->base($from, $to)
            ->where('metric_key', 'conversions')
            ->where('value', '>', 0)
            ->distinct()
            ->pluck('provider')
            ->map(static fn ($p): string => AdPlatforms::canonical((string) $p))
            ->unique()
            ->values()
            ->all();

        $overlapping = count($providers) > 1;

        return [
            'source' => 'platform_reported',
            'label_ar' => 'ما أبلغت به المنصات',
            'label_en' => 'Platform-Reported',
            'providers' => AdPlatforms::sort($providers),
            'may_double_count' => $overlapping,
            'is_unique_order_count' => false,
            'note_ar' => $overlapping
                ? 'مجموع ما أبلغت به '.count($providers).' منصات، وليس عدد طلبات فريدة — البيعة الواحدة قد تُبلَّغ من أكثر من منصة.'
                : 'ما أبلغت به المنصة عن التحويلات التي تعتقد أن إعلانها تسبب بها.',
            'note_en' => $overlapping
                ? 'The sum of what '.count($providers).' platforms each reported — not a count of unique orders, because one sale can be reported by more than one platform.'
                : 'What the platform reported for the conversions it believes its ads caused.',
        ];
    }

    /** @return list<array<string, mixed>> one row per provider, ordered by spend desc, with share of spend. */
    /**
     * ANALYTICS-PROVENANCE-001 — whether the figures in scope are real, demo, or both.
     *
     * Every metric surface rendered `<DemoBadge />` unconditionally, so a project syncing live
     * Snapchat spend was labelled «بيانات تجريبية · Demo» beside its own real money. A badge that is
     * always on says nothing, and this one says something false: it is the product's promise that a
     * figure is NOT a customer's real spend.
     *
     * `is_demo` is on every row already and is what the demo seeder sets, so the answer is a count
     * rather than an inference from environment or a frontend constant — neither of which knows
     * whose rows these are.
     *
     * `mixed` is reported rather than resolved. A project holding both is a real state, and choosing
     * one label for it would hide demo rows inside a live total, which is the leak this exists to
     * make visible.
     *
     * @return array{source:'live'|'demo'|'mixed'|'none', live_rows:int, demo_rows:int}
     */
    public function provenance(Carbon $from, Carbon $to): array
    {
        $row = $this->baseIncludingDemo($from, $to)->toBase()
            ->selectRaw('COUNT(*) FILTER (WHERE daily_metrics.is_demo = false) AS live_rows')
            ->selectRaw('COUNT(*) FILTER (WHERE daily_metrics.is_demo = true) AS demo_rows')
            ->first();

        $live = (int) ($row->live_rows ?? 0);
        $demo = (int) ($row->demo_rows ?? 0);

        return [
            'source' => match (true) {
                $live > 0 && $demo > 0 => 'mixed',
                $demo > 0 => 'demo',
                $live > 0 => 'live',
                default => 'none',
            },
            'live_rows' => $live,
            'demo_rows' => $demo,
        ];
    }

    public function byProvider(Carbon $from, Carbon $to): array
    {
        $rows = $this->base($from, $to)
            ->select('provider')
            /*
             * MONEY-TRUTH-002 — provenance per ROW, not only on the grand total.
             *
             * `totals()` learned to carry the withheld amounts; these breakdowns did not, so platform
             * comparison and campaign ranking still received a coalesced 0 with nothing to say it was
             * withheld. The same lie, one level down: a platform that spent 4,128.93 USD ranked as
             * having spent nothing, beneath a summary card showing the real figure.
             */
            ->selectRaw(implode(', ', array_map(
                fn ($e, $a) => "{$e} AS {$a}",
                array_merge(self::PIVOT, self::MONEY_TRUTH),
                array_keys(array_merge(self::PIVOT, self::MONEY_TRUTH)),
            )))
            ->groupBy('provider')
            ->get()
            ->map(fn ($r) => ['provider' => $r->provider] + $this->withDerived((array) $r))
            ->all();

        $totalSpend = array_sum(array_column($rows, 'spend')) ?: 1;
        foreach ($rows as &$r) {
            $r['spend_share'] = round($r['spend'] / $totalSpend, 4);
        }
        usort($rows, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        return $rows;
    }

    /** @return list<array<string, mixed>> one row per unified campaign (id/name/provider) ranked by spend. */
    /**
     * CAMPAIGN-020: side-by-side comparison of a chosen set of campaigns over one window.
     *
     * Every campaign is measured with the SAME sums and the SAME derived-KPI formulas as the rest of the
     * engine — there is no parallel comparison maths. Each row carries its own objective so the UI can
     * refuse to blend KPIs across different objectives, and the daily series / platform split / top
     * creatives are each fetched in ONE grouped query rather than per campaign.
     *
     * @param  list<string>  $campaignIds
     * @return list<array<string,mixed>>
     */
    public function compare(array $campaignIds, Carbon $from, Carbon $to): array
    {
        $ids = array_values(array_unique(array_filter($campaignIds)));
        if ($ids === []) {
            return [];
        }

        // Identity comes from unified_campaigns so a campaign with NO metrics in the window still appears
        // in the comparison (as zeros) instead of silently vanishing.
        $meta = DB::table('unified_campaigns')->whereIn('id', $ids)
            ->select('id', 'name', 'objective', 'status', 'client_display_name', 'total_budget', 'budget_currency')
            ->get()->keyBy('id');

        $totals = $this->base($from, $to)->whereIn('daily_metrics.unified_campaign_id', $ids)
            ->select('daily_metrics.unified_campaign_id as campaign_id')
            ->selectRaw(implode(', ', array_map(fn ($e, $a) => "{$e} AS {$a}", self::PIVOT, array_keys(self::PIVOT))))
            ->groupBy('daily_metrics.unified_campaign_id')
            ->get()->keyBy('campaign_id');

        $series = $this->base($from, $to)->whereIn('daily_metrics.unified_campaign_id', $ids)
            ->select('daily_metrics.unified_campaign_id as campaign_id', 'metric_date')
            ->selectRaw(implode(', ', array_map(fn ($e, $a) => "{$e} AS {$a}", self::PIVOT, array_keys(self::PIVOT))))
            ->groupBy('daily_metrics.unified_campaign_id', 'metric_date')
            ->orderBy('metric_date')
            ->get()->groupBy('campaign_id');

        $platforms = $this->base($from, $to)->whereIn('daily_metrics.unified_campaign_id', $ids)
            ->select('daily_metrics.unified_campaign_id as campaign_id', 'daily_metrics.provider')
            ->selectRaw("COALESCE(SUM(value) FILTER (WHERE metric_key = 'spend'), 0) AS spend")
            ->selectRaw("COALESCE(SUM(value) FILTER (WHERE metric_key = 'conversions'), 0) AS conversions")
            ->groupBy('daily_metrics.unified_campaign_id', 'daily_metrics.provider')
            ->get()->groupBy('campaign_id');

        $creatives = $this->topCreatives($ids, $from, $to);

        return array_values(array_map(function (string $id) use ($meta, $totals, $series, $platforms, $creatives): array {
            $m = $meta->get($id);

            return [
                'campaign_id' => $id,
                'name' => $m->name ?? '—',
                'objective' => $m->objective ?? null,
                'status' => $m->status ?? null,
                'client_display_name' => $m->client_display_name ?? null,
                'total_budget' => $m->total_budget ?? null,
                'budget_currency' => $m->budget_currency ?? null,
                'totals' => $this->withDerived((array) ($totals->get($id) ?? new \stdClass)),
                'series' => collect($series->get($id) ?? [])
                    ->map(fn ($r) => ['date' => Carbon::parse($r->metric_date)->toDateString()] + $this->withDerived((array) $r))
                    ->values()->all(),
                'platforms' => collect($platforms->get($id) ?? [])
                    ->map(fn ($r) => ['provider' => $r->provider, 'spend' => round((float) $r->spend, 2), 'conversions' => round((float) $r->conversions, 2)])
                    ->sortByDesc('spend')->values()->all(),
                'creatives' => $creatives[$id] ?? [],
            ];
        }, $ids));
    }

    /**
     * Top creatives per campaign by spend, from the creative tables (not daily_metrics). Thumbnails are
     * passed through as stored — never fabricated; the UI shows "preview unavailable" when null.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, list<array<string,mixed>>>
     */
    private function topCreatives(array $campaignIds, Carbon $from, Carbon $to, int $perCampaign = 3): array
    {
        $rows = DB::table('creative_daily_metrics as m')
            ->join('external_creatives as c', 'c.id', '=', 'm.creative_id')
            ->whereIn('m.campaign_id', $campaignIds)
            ->whereBetween('m.metric_date', [$from->toDateString(), $to->toDateString()])
            ->select('m.campaign_id', 'c.id as creative_id', 'c.name', 'c.format', 'c.thumbnail_url', 'c.provider')
            ->selectRaw('SUM(m.spend) AS spend, SUM(m.impressions) AS impressions, SUM(m.clicks) AS clicks, SUM(m.conversions) AS conversions')
            ->groupBy('m.campaign_id', 'c.id', 'c.name', 'c.format', 'c.thumbnail_url', 'c.provider')
            ->orderByDesc('spend')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $bucket = &$out[$r->campaign_id];
            if (count($bucket ?? []) >= $perCampaign) {
                continue;
            }
            $spend = (float) $r->spend;
            $conv = (float) $r->conversions;
            $impr = (float) $r->impressions;
            $bucket[] = [
                'creative_id' => $r->creative_id,
                'name' => $r->name,
                'format' => $r->format,
                'provider' => $r->provider,
                'thumbnail_url' => $r->thumbnail_url,
                'spend' => round($spend, 2),
                'impressions' => round($impr, 2),
                'clicks' => round((float) $r->clicks, 2),
                'conversions' => round($conv, 2),
                'cpa' => $conv > 0 ? round($spend / $conv, 2) : null,
                'cpm' => $impr > 0 ? round($spend / $impr * 1000, 2) : null,
            ];
            unset($bucket);
        }

        return $out;
    }

    public function byCampaign(Carbon $from, Carbon $to): array
    {
        $rows = $this->base($from, $to)
            ->leftJoin('unified_campaigns', 'unified_campaigns.id', '=', 'daily_metrics.unified_campaign_id')
            ->select('daily_metrics.unified_campaign_id as campaign_id', 'unified_campaigns.name as campaign_name', 'unified_campaigns.client_display_name as client_display_name', 'unified_campaigns.objective as objective', 'unified_campaigns.objective_source as objective_source')
            ->selectRaw('MAX(daily_metrics.provider) AS provider')
            /*
             * MONEY-TRUTH-002 — the same provenance, qualified for the join.
             *
             * This breakdown left-joins `unified_campaigns`, so every column has to name its table or
             * Postgres cannot resolve it. The withheld expressions read two further columns —
             * `original_amount` and `original_currency` — which is why the qualifier below covers four
             * names rather than the original two.
             */
            ->selectRaw(implode(', ', array_map(
                function ($e, $a) {
                    foreach (['metric_key', 'original_amount', 'original_currency', 'value'] as $column) {
                        $e = preg_replace('/(?<!\.)\b'.$column.'\b/', 'daily_metrics.'.$column, $e);
                    }

                    return $e." AS {$a}";
                },
                array_merge(self::PIVOT, self::MONEY_TRUTH),
                array_keys(array_merge(self::PIVOT, self::MONEY_TRUTH)),
            )))
            /*
             * `objective` joins the grouping because a report has to know what each campaign's money
             * was FOR, not only what it did (§14.6). `objective_source` rides with it because the
             * column DEFAULTS to `other`, so the value alone cannot tell «classified as unclassified»
             * from «nobody has looked» — and a report that read the default as a declaration would
             * file every unclassified project as brand spend.
             *
             * Neither can change the row count: both are columns of `unified_campaigns` and the group
             * is already keyed by that table's id.
             */
            ->groupBy('daily_metrics.unified_campaign_id', 'unified_campaigns.name', 'unified_campaigns.client_display_name', 'unified_campaigns.objective', 'unified_campaigns.objective_source')
            ->get()
            ->map(fn ($r) => [
                'campaign_id' => $r->campaign_id,
                'campaign_name' => $r->campaign_name,
                'client_display_name' => $r->client_display_name,
                'objective' => $r->objective,
                'objective_source' => $r->objective_source,
                'provider' => $r->provider,
            ] + $this->withDerived((array) $r))
            ->all();

        usort($rows, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        return $rows;
    }

    /** @return list<array<string, mixed>> daily rows: date + requested base metrics + derived roas/cpa. */
    public function timeseries(Carbon $from, Carbon $to): array
    {
        return $this->base($from, $to)
            ->select('metric_date')
            ->selectRaw(implode(', ', array_map(fn ($e, $a) => "{$e} AS {$a}", self::PIVOT, array_keys(self::PIVOT))))
            ->groupBy('metric_date')
            ->orderBy('metric_date')
            ->get()
            ->map(fn ($r) => ['date' => Carbon::parse($r->metric_date)->toDateString()] + $this->withDerived((array) $r))
            ->all();
    }

    /** @return array<string, list<array<string,mixed>>> daily series per provider (for per-platform charts). */
    public function timeseriesByProvider(Carbon $from, Carbon $to): array
    {
        $rows = $this->base($from, $to)
            ->select('provider', 'metric_date')
            ->selectRaw(implode(', ', array_map(fn ($e, $a) => "{$e} AS {$a}", self::PIVOT, array_keys(self::PIVOT))))
            ->groupBy('provider', 'metric_date')
            ->orderBy('metric_date')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->provider][] = ['date' => Carbon::parse($r->metric_date)->toDateString()] + $this->withDerived((array) $r);
        }

        return $out;
    }

    /**
     * Conversion funnel with per-step transition rate, drop-off and cost per stage.
     *
     * Returns `['stages' => …, 'spend' => …]` rather than the bare stage list it used to
     * (UNIFIED-002). The spend was always computed here — every `cost_per` on every stage divides by
     * it — and was then thrown away, so the funnel published a dozen figures derived from a number
     * it never stated. A reader could multiply their way back to it and could not read it, and
     * nothing reconciled the funnel against the dashboard, which is exactly where a page that had
     * grown its own query would have hidden.
     *
     * ## A stage nobody reported is null, not zero (FUNNEL-NULL-001)
     *
     * Every stage used to be `COALESCE(SUM(…), 0)`, so a platform that does not count basket adds and
     * a platform that counted zero of them produced the same figure on the most-read chart in the
     * product. A client reading «0 add to cart» beside 176 purchases concludes the funnel is broken,
     * or that we are — and they are reading a sentence about what the platform sends, not about their
     * campaign. So the COALESCE is gone: a null sum means no row for that key exists in the window,
     * which is exactly «never sent», while a real measured zero still sums to 0 and still says zero.
     * `reported` carries that distinction explicitly so no caller has to infer it from a null.
     *
     * This mattered less while `AccountMetricsSyncer` could not carry those stages at all (they were
     * dropped before storage, so every funnel was missing them for one indistinguishable reason).
     * Now that it does carry them, the two reasons must be told apart.
     *
     * `from_stage` names the step this one's rate is measured AGAINST — the nearest stage above that
     * was actually reported, not the one above it in theory. A funnel whose middle is unreported says
     * «purchases per landing-page view» rather than dividing by a step that is not on the screen.
     * This is the shape {@see CreativeFunnel} already established for
     * one creative; the project funnel now answers the same way, so the two cannot read differently.
     */
    public function funnel(Carbon $from, Carbon $to): array
    {
        $stages = self::FUNNEL_STAGES;
        $labels = [
            'impressions' => 'Impressions', 'clicks' => 'Clicks', 'landing_page_views' => 'Landing Page View',
            'add_to_cart' => 'Add to Cart', 'checkout' => 'Checkout', 'purchases' => 'Purchase',
        ];
        // Deliberately NOT wrapped in COALESCE — see the note above. The null IS the answer.
        $selects = array_map(fn ($s) => "SUM(value) FILTER (WHERE metric_key = '{$s}') AS {$s}", $stages);
        $selects[] = "COALESCE(SUM(value) FILTER (WHERE metric_key = 'spend'), 0) AS spend";
        $row = (array) $this->base($from, $to)->selectRaw(implode(', ', $selects))->first();

        $spend = (float) ($row['spend'] ?? 0);
        $out = [];
        $prev = null;
        $prevStage = null;
        foreach ($stages as $s) {
            $reported = ($row[$s] ?? null) !== null;
            $count = $reported ? round((float) $row[$s]) : null;

            // A rate needs both sides and a non-zero denominator: «100% of nothing» is not a
            // conversion rate, and neither is «a share of a step the platform never sent».
            $ratio = $count !== null && $prev !== null && $prev > 0 ? $count / $prev : null;

            $out[] = [
                'stage' => $s,
                'label' => $labels[$s],
                'reported' => $reported,
                'count' => $count,
                'from_stage' => $count !== null ? $prevStage : null,
                'step_rate' => $ratio !== null ? round($ratio, 4) : null,
                'drop_off' => $ratio !== null ? round(1 - $ratio, 4) : null,
                'cost_per' => $count !== null && $count > 0 ? round($spend / $count, 2) : null,
            ];

            // Only a reported stage becomes the denominator for the next one.
            if ($count !== null) {
                $prev = $count;
                $prevStage = $s;
            }
        }

        return ['stages' => $out, 'spend' => round($spend, 2)];
    }

    /** Planned vs spent budget with pacing (over/under) and a linear end-of-period projection. */
    public function budgetPacing(Carbon $from, Carbon $to, Carbon $today): array
    {
        $spentByCampaign = $this->base($from, $to)
            ->select('unified_campaign_id')
            ->selectRaw("COALESCE(SUM(value) FILTER (WHERE metric_key = 'spend'), 0) AS spent")
            ->groupBy('unified_campaign_id')
            ->pluck('spent', 'unified_campaign_id');

        $periodDays = max(1, $from->diffInDays($to) + 1);
        $elapsedDays = max(1, $from->diffInDays($today->min($to)) + 1);
        $elapsedFraction = min(1.0, $elapsedDays / $periodDays);

        // Metrics not linked to a unified campaign group under a null key — exclude it so we never
        // pass an empty string to a uuid column.
        $campaignIds = $spentByCampaign->keys()->filter(fn ($k) => $k !== null && $k !== '')->values();
        $campaigns = $campaignIds->isEmpty()
            ? collect()
            : DB::table('unified_campaigns')
                ->whereIn('id', $campaignIds->all())
                ->get(['id', 'name', 'total_budget', 'budget_currency', 'status']);

        $rows = [];
        foreach ($campaigns as $c) {
            $budget = (float) ($c->total_budget ?? 0);
            $spent = (float) ($spentByCampaign[$c->id] ?? 0);
            $expected = $budget * $elapsedFraction;
            $projected = $elapsedFraction > 0 ? $spent / $elapsedFraction : $spent;
            $rows[] = [
                'campaign_id' => $c->id,
                'campaign_name' => $c->name,
                'status' => $c->status,
                'budget' => round($budget, 2),
                'spent' => round($spent, 2),
                'remaining' => round($budget - $spent, 2),
                'consumed_pct' => $budget > 0 ? round($spent / $budget, 4) : null,
                'pace' => $expected > 0 ? round($spent / $expected, 3) : null, // >1 over-pacing, <1 under
                'projected_spend' => round($projected, 2),
            ];
        }
        usort($rows, fn ($a, $b) => ($b['pace'] ?? 0) <=> ($a['pace'] ?? 0));

        return $rows;
    }

    /** Adds ROAS/CPA/CTR/CPC/CPM to a row of base sums. */
    private function withDerived(array $row): array
    {
        $impr = (float) ($row['impressions'] ?? 0);
        $clicks = (float) ($row['clicks'] ?? 0);
        $conv = (float) ($row['conversions'] ?? 0);
        $spend = (float) ($row['spend'] ?? 0);
        $revenue = (float) ($row['revenue'] ?? 0);
        $reach = (float) ($row['reach'] ?? 0);
        $videoViews = (float) ($row['video_views'] ?? 0);
        $videoCompletions = (float) ($row['video_completions'] ?? 0);
        $lpv = (float) ($row['landing_page_views'] ?? 0);
        $leads = (float) ($row['leads'] ?? 0);
        $qualifiedLeads = (float) ($row['qualified_leads'] ?? 0);
        $purchases = (float) ($row['purchases'] ?? 0);
        $installs = (float) ($row['installs'] ?? 0);
        $registrations = (float) ($row['registrations'] ?? 0);
        $inAppEvents = (float) ($row['in_app_events'] ?? 0);
        $engagements = (float) ($row['engagements'] ?? 0);

        return [
            // base sums
            'impressions' => round($impr, 2),
            'clicks' => round($clicks, 2),
            'conversions' => round($conv, 2),
            'spend' => round($spend, 2),
            'revenue' => round($revenue, 2),

            /*
             * FX-WITHHELD-UI-001 — carried through, because this method returns a fresh literal and
             * anything not named here is silently dropped. That is exactly how the withholding
             * became invisible: the aggregate existed in SQL and never reached a caller.
             */
            'spend_withheld_rows' => (int) ($row['spend_withheld_rows'] ?? 0),
            'spend_original' => round((float) ($row['spend_original'] ?? 0), 2),
            'revenue_withheld_rows' => (int) ($row['revenue_withheld_rows'] ?? 0),
            'revenue_original' => round((float) ($row['revenue_original'] ?? 0), 2),
            'money_original_currency' => $row['money_original_currency'] ?? null,
            'money_original_currencies' => (int) ($row['money_original_currencies'] ?? 0),
            'reach' => round($reach, 2),
            'video_views' => round($videoViews, 2),
            'video_completions' => round($videoCompletions, 2),
            'landing_page_views' => round($lpv, 2),
            'leads' => round($leads, 2),
            'qualified_leads' => round($qualifiedLeads, 2),
            'purchases' => round($purchases, 2),
            'installs' => round($installs, 2),
            'registrations' => round($registrations, 2),
            'in_app_events' => round($inAppEvents, 2),
            'engagements' => round($engagements, 2),
            // derived KPIs (computed from the sums, never summed)
            'roas' => $spend > 0 ? round($revenue / $spend, 3) : null,
            'cpa' => $conv > 0 ? round($spend / $conv, 2) : null,
            'ctr' => $impr > 0 ? round($clicks / $impr, 5) : null,
            'cpc' => $clicks > 0 ? round($spend / $clicks, 3) : null,
            'cpm' => $impr > 0 ? round($spend / $impr * 1000, 2) : null,
            'frequency' => $reach > 0 ? round($impr / $reach, 2) : null,
            'cpl' => $leads > 0 ? round($spend / $leads, 2) : null,
            'cpi' => $installs > 0 ? round($spend / $installs, 2) : null,
            'cpe' => $engagements > 0 ? round($spend / $engagements, 3) : null,
            'aov' => $purchases > 0 ? round($revenue / $purchases, 2) : null,
            'conversion_rate' => $clicks > 0 ? round($conv / $clicks, 5) : null,
            'engagement_rate' => $impr > 0 ? round($engagements / $impr, 5) : null,
            'video_completion_rate' => $videoViews > 0 ? round($videoCompletions / $videoViews, 5) : null,
        ];
    }
}
