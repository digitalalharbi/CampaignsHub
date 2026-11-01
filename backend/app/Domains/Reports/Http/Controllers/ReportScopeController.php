<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\LeadDestination;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CampaignDestinations;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportScopeTemplate;
use App\Domains\Reports\Services\ReportObjectiveLens;
use App\Domains\Reports\Support\ReportScope;
use App\Domains\Reports\Support\ReportScopeHierarchy;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * §14.5 — choosing what a report covers, and saving that choice to use again.
 *
 * Three things live here, and they are one unit because they are the same decision at three moments:
 * what CAN be chosen (`options`), what THIS report is about (`update`, editable in place), and what
 * somebody wants to choose again next month (the template endpoints).
 *
 * ## Editable in place, deliberately
 *
 * `update()` changes a report's scope and regenerates it rather than creating a second report. The
 * alternative — a new report per adjustment — breaks the thing an operator most needs: a link already
 * with the client keeps working and starts telling the truth. Creating a second report would leave the
 * first one live, unchanged and wrong, and the client holding the wrong link with no sign of it.
 *
 * ## Every id is checked against this project before it is stored
 *
 * `ReportScope` normalises shape, not authority. A campaign id from another tenant would be a valid
 * uuid, and storing it would be a scope naming somebody else's campaign — harmless today because
 * every read is tenant-scoped, and exactly the kind of thing that stops being harmless the moment one
 * query is written without a scope. So ids are filtered against what this project actually has, and
 * an id that survives is one this operator could have seen anyway.
 */
final class ReportScopeController extends Controller
{
    /**
     * What is choosable, per axis, for this project.
     *
     * Platforms and accounts are read from the METRICS rather than from the integrations list: a
     * platform that is connected but has never returned a row would appear in the picker, and a
     * report scoped to it would show an empty chart with no explanation. This offers what there is
     * data for.
     */
    public function options(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        /*
         * UX-MULTISELECT-SCALE-001 — reaching the rows that did not fit.
         *
         * The bound below is honestly STATED — `truncated.ad_sets` is a fact the picker can print
         * rather than letting a short list read as a complete one. What it could not do is let
         * anybody reach past it: the picker's search box filters what it was SENT, so on a project
         * with five hundred ad sets, number four hundred could not be selected by any route. An
         * operator meets that as «my ad set is not in the system», and the report they build omits
         * it silently.
         *
         * One axis per search, because a search is a question about the list being searched.
         * Re-filtering the other nine by a term typed into this control would empty lists nobody
         * touched, and re-sending them all on every keystroke would be slower than the problem.
         */
        $request->validate([
            'axis' => ['nullable', Rule::in(array_keys(self::SEARCHABLE))],
            'q' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // REPORT-SETUP-001 — the dependent filters. Each narrows the lists below it, server-side,
            // so a picker cannot offer a campaign that the choices above it exclude.
            'providers' => ['nullable', 'array'],
            'providers.*' => ['string', 'max:40'],
            'account_ids' => ['nullable', 'array'],
            'account_ids.*' => ['uuid'],
            'objectives' => ['nullable', 'array'],
            'objectives.*' => ['string', 'max:40'],
            'destinations' => ['nullable', 'array'],
            'destinations.*' => [Rule::in(LeadDestination::values())],
        ]);

        $fProviders = array_values(array_map('strtolower', array_filter((array) $request->input('providers', []), 'is_string')));
        $fAccounts = array_values(array_filter((array) $request->input('account_ids', []), 'is_string'));
        $fObjectives = array_values(array_filter((array) $request->input('objectives', []), 'is_string'));
        $fDestinations = array_values(array_filter((array) $request->input('destinations', []), 'is_string'));

        $axis = $request->string('axis')->toString();
        $term = trim($request->string('q')->toString());

        if ($axis !== '') {
            return $this->searchAxis($axis, $project, $term);
        }

        /*
         * REPORT-SCOPE-SELECTION-001 — the builder's lists are BOUNDED, and every bound is stated.
         *
         * Campaigns had no limit at all: a project with four hundred of them sent four hundred rows
         * into a picker, which is the cardinality this requirement exists for. The other four axes
         * had a silent `limit(500)`, which is worse than no limit — a list that stops without saying
         * so tells an operator their ad set does not exist, and the report they build then quietly
         * omits it. The same rule the campaign filter already follows: fetch one past the cap so
         * «there are more» is a FACT, and say it.
         */
        /*
         * The window the report is about, where the caller named one.
         *
         * One aggregate for the whole project rather than a query per campaign: a project with four
         * hundred campaigns is exactly the cardinality this requirement exists for, and four hundred
         * round trips to decide a heading is not a grouping, it is an outage.
         */
        $from = $request->date('from');
        $to = $request->date('to');
        $lastActive = $from !== null && $to !== null
            ? app(MetricsAggregator::class)->forProjects([$project])->lastActiveByCampaign($from, $to)
            : [];

        $destinationOf = app(CampaignDestinations::class)->for(
            UnifiedCampaign::query()->where('project_id', $project)->pluck('id')->map(fn ($id): string => (string) $id)->all(),
        );

        [$campaigns, $campaignsMore] = $this->bounded(
            UnifiedCampaign::query()
                ->where('project_id', $project)
                // SECURITY CLUSTER 5 — a campaign of a deselected account is not the project's to name.
                ->tap(fn ($q) => BoundAccountVisibility::unifiedCampaigns($q))
                ->when($fObjectives !== [], fn ($q) => $q->whereIn('objective', $fObjectives))
                ->when($fProviders !== [] || $fAccounts !== [], fn ($q) => $q->where(fn ($w) => $w->whereIn(
                    'id',
                    ExternalCampaign::query()
                        ->where('project_id', $project)
                        ->when($fProviders !== [], fn ($e) => $e->whereIn('provider', $fProviders))
                        ->when($fAccounts !== [], fn ($e) => $e->whereIn('external_account_id', $fAccounts))
                        ->whereNotNull('unified_campaign_id')
                        ->select('unified_campaign_id'),
                )
                    /*
                     * REPORT-SCOPE-HIERARCHY-001 — and the campaigns an account has FIGURES for. The
                     * structure join alone left a metrics-only account with an empty campaign list,
                     * which is the same two sources the account list and the server's check use.
                     */
                    ->when($fAccounts !== [], fn ($x) => $x->orWhereIn(
                        'id',
                        DailyMetric::query()
                            ->where('project_id', $project)
                            ->whereIn('external_account_id', $fAccounts)
                            ->when($fProviders !== [], fn ($m) => $m->whereIn('provider', $fProviders))
                            ->whereNotNull('unified_campaign_id')
                            ->select('unified_campaign_id'),
                    ))))
                ->when($fDestinations !== [], fn ($q) => $q->whereIn('id', array_keys(array_filter(
                    $destinationOf,
                    fn (LeadDestination $d): bool => in_array($d->value, $fDestinations, true),
                )) ?: ['00000000-0000-0000-0000-000000000000']))
                ->orderBy('name')
                ->orderBy('id'),
            ['id', 'name', 'client_display_name', 'status', 'objective'],
            fn (UnifiedCampaign $c): array => [
                'id' => (string) $c->getKey(),
                'name' => (string) ($c->client_display_name ?: $c->name),
                'status' => $c->status,
                'objective' => $c->objective,
                // LEAD-DESTINATION-001 — where this campaign's results happen, or null when unstated.
                'destination' => isset($destinationOf[(string) $c->getKey()]) ? $destinationOf[(string) $c->getKey()]->value : null,
                /*
                 * REPORT-SCOPE-SELECTION-001 — did this campaign RUN in the period being reported on?
                 *
                 * «Reportability = campaign lifecycle + selected period + canonical status — NOT a
                 * simplistic `status === active` frontend filter», because a campaign inactive today
                 * may have been active during a historical report window. Filtering on today's status
                 * would silently drop its spend from a report about last month.
                 *
                 * Null where no period was asked about, which the picker reads as «no claim made»
                 * rather than as «did not run» — an absence of a question is not an answer.
                 */
                'last_active_on' => $lastActive[(string) $c->getKey()] ?? null,
            ],
        );

        $providers = DailyMetric::query()
            ->where('project_id', $project)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->distinct()->orderBy('provider')->pluck('provider')->all();

        $accountIds = DailyMetric::query()
            ->where('project_id', $project)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->whereNotNull('external_account_id')
            ->distinct()->pluck('external_account_id')->all();

        /*
         * REPORT-SCOPE-HIERARCHY-001 — and the accounts that have CAMPAIGNS but no figures yet.
         *
         * The picker offered only accounts with metrics, while the campaign list (and the server's
         * check) also counts an account through its synced campaigns. So a campaign that never ran
         * belonged to an account the picker did not list — ticking every account offered still left
         * it out, and a report could not be scoped to it. One definition of «an account of this
         * project»: figures OR campaigns.
         */
        $structureAccountIds = ExternalCampaign::query()
            ->where('project_id', $project)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'external_campaigns'))
            ->whereNotNull('unified_campaign_id')
            ->whereNotNull('external_account_id')
            ->distinct()->pluck('external_account_id')->all();
        /*
         * REPORT-BOUND-ACCOUNTS-001 — and the accounts the project BINDS, whether or not anything has arrived.
         *
         * «Figures OR campaigns» still left an authorised account invisible until its first sync
         * brought a campaign: NASEL was bound to TikTok, Meta, Google and ChatGPT Ads, and the picker
         * offered TikTok alone because only it had campaigns. The binding is the ownership record; the
         * picker lists every active one and says, per account, what has arrived (`state`).
         */
        $metricAccountIds = array_map('strval', $accountIds);
        $boundAccountIds = BoundAccountVisibility::activeAccountIds($project);
        $accountIds = array_values(array_unique(array_merge(
            $metricAccountIds,
            array_map('strval', $structureAccountIds),
            $boundAccountIds,
        )));

        $accounts = $accountIds === [] ? [] : ExternalAccount::query()
            ->whereIn('id', $accountIds)
            ->when($fProviders !== [], fn ($q) => $q->whereIn('provider', $fProviders))
            ->orderBy('name')
            ->get(['id', 'name', 'provider', 'external_id', 'last_synced_at'])
            ->map(fn (ExternalAccount $a): array => [
                'id' => (string) $a->getKey(),
                'name' => $a->name ?: $a->external_id,
                'provider' => $a->provider,
                // data = figures exist; no_data = synced, none for this period yet; unsynced = never synced.
                'state' => in_array((string) $a->getKey(), $metricAccountIds, true) ? 'data' : ($a->last_synced_at === null ? 'unsynced' : 'no_data'),
            ])->all();
        $providers = array_values(array_unique(array_merge($providers, array_map(static fn (array $a): string => (string) $a['provider'], $accounts))));
        sort($providers);

        /*
         * REPORT-SCOPE-HIERARCHY-001 — an account with figures and no account row is still an account.
         *
         * Metrics seeded or synced before the account row existed carry an `external_account_id` that
         * resolves to no `ExternalAccount`. The list used to drop it, and a saved report must now name
         * its accounts — so a project in that state could not be reported on at all. Listed by its id,
         * the same fallback the link builder uses when a provider gives no name.
         */
        $named = array_column($accounts, 'id');
        $unnamed = array_values(array_diff(array_map('strval', $accountIds), $named));
        if ($unnamed !== []) {
            $providerOf = DailyMetric::query()
                ->where('project_id', $project)
                ->whereIn('external_account_id', $unnamed)
                ->groupBy('external_account_id')
                ->selectRaw('external_account_id, MIN(provider) AS provider')
                ->pluck('provider', 'external_account_id');
            foreach ($unnamed as $id) {
                $provider = (string) ($providerOf[$id] ?? '');
                if ($fProviders !== [] && ! in_array(strtolower($provider), $fProviders, true)) {
                    continue;
                }
                $accounts[] = ['id' => $id, 'name' => $id, 'provider' => $provider, 'state' => 'data'];
            }
        }

        [$adSets, $adSetsMore] = $this->bounded(
            ExternalAdSet::query()->where('project_id', $project)->tap(fn ($q) => BoundAccountVisibility::applyThroughCampaign($q, 'external_ad_sets.external_campaign_id', 'external_ad_sets.project_id'))->orderBy('name')->orderBy('id'),
            ['id', 'name', 'provider', 'unified_campaign_id', 'status'],
            fn (ExternalAdSet $s): array => [
                'id' => (string) $s->getKey(),
                'name' => $s->name,
                'provider' => $s->provider,
                'campaign_id' => (string) $s->unified_campaign_id,
            ],
        );

        [$ads, $adsMore] = $this->bounded(
            ExternalAd::query()->where('project_id', $project)->tap(fn ($q) => BoundAccountVisibility::applyThroughCampaign($q, 'external_ads.external_campaign_id', 'external_ads.project_id'))->orderBy('name')->orderBy('id'),
            ['id', 'name', 'provider', 'unified_campaign_id', 'status'],
            fn (ExternalAd $a): array => [
                'id' => (string) $a->getKey(),
                'name' => $a->name,
                'provider' => $a->provider,
                'campaign_id' => (string) $a->unified_campaign_id,
            ],
        );

        [$creatives, $creativesMore] = $this->bounded(
            ExternalCreative::query()->where('project_id', $project)->tap(fn ($q) => BoundAccountVisibility::applyThroughCampaign($q, 'external_creatives.external_campaign_id', 'external_creatives.project_id'))->orderBy('name')->orderBy('id'),
            ['id', 'name', 'client_display_name', 'provider', 'format', 'campaign_id'],
            fn (ExternalCreative $c): array => [
                'id' => (string) $c->getKey(),
                'name' => (string) ($c->client_display_name ?: $c->name),
                'provider' => $c->provider,
                'format' => $c->format,
                'campaign_id' => (string) $c->campaign_id,
            ],
        );

        return ApiResponse::success([
            'campaigns' => $campaigns,
            'providers' => $providers,
            'accounts' => $accounts,
            'ad_sets' => $adSets,
            'ads' => $ads,
            'creatives' => $creatives,
            'objectives' => array_map(
                fn (CampaignObjective $o): array => [
                    'key' => $o->value,
                    'labels' => $o->labels(),
                    'path' => $o->path()->value,
                ],
                CampaignObjective::cases(),
            ),
            'paths' => array_map(
                fn (MarketingPath $p): array => [
                    'key' => $p->value,
                    'labels' => $p->labels(),
                    'headline_metrics' => $p->headlineMetrics(),
                ],
                MarketingPath::cases(),
            ),
            // The destinations present in this project, with how many campaigns each holds.
            'destinations' => array_values(array_filter(array_map(
                function (LeadDestination $d) use ($destinationOf): array {
                    return [
                        'key' => $d->value,
                        'labels' => $d->label(),
                        'messaging' => $d->isMessaging(),
                        'campaign_count' => count(array_filter($destinationOf, fn (LeadDestination $x): bool => $x === $d)),
                    ];
                },
                LeadDestination::cases(),
            ), fn (array $row): bool => $row['campaign_count'] > 0)),
            'metrics' => self::METRICS,
            /*
             * Which axes did not fit, so the picker can say so where the choice is made.
             *
             * An operator who cannot see their ad set has two possible explanations — it was not
             * synced, or the list stopped — and they lead to opposite actions. The response is the
             * only place that knows which.
             */
            'truncated' => [
                'campaigns' => $campaignsMore,
                'ad_sets' => $adSetsMore,
                'ads' => $adsMore,
                'creatives' => $creativesMore,
            ],
            'limit' => self::OPTION_LIMIT,
            /*
             * What each axis can actually bound, stated up front.
             *
             * The picker offers ad sets and ads because operators think in them, and this is where it
             * learns to say «figures stay at campaign grain» beside those two rather than letting the
             * reader assume otherwise.
             */
            'grain' => [
                'figures' => ReportScope::FIGURE_AXES,
                'resolved_to_campaign' => ReportScope::RESOLVED_AXES,
                'creatives_only' => ['creative_ids'],
            ],
        ], 'Scope options.');
    }

    /**
     * How many of each entity the builder offers before it says «there are more».
     *
     * Five hundred was already the ad-set/ad/creative ceiling; it stays, because the number was never
     * the problem — the silence was. Campaigns join it, having had no ceiling at all.
     */
    private const OPTION_LIMIT = 500;

    /**
     * One page of options, plus the fact of whether more exist.
     *
     * One row beyond the cap is fetched so «more» is measured rather than inferred from a full page.
     * Inferring it is wrong in both directions: exactly 500 entities would report more that are not
     * there, and a caller who trusted a short page would never ask.
     *
     * @param  list<string>  $columns
     * @return array{0: list<array<string,mixed>>, 1: bool}
     */
    /**
     * The four axes a person searches by name, and how each one is read.
     *
     * `client_display_name` first for the two that have one: an operator searching a client report's
     * builder is looking for the name that report will PRINT, and matching only the internal name
     * would fail on exactly the rows where the two differ — which is the case the display name exists
     * for.
     *
     * @var array<string, array{model: class-string, columns: list<string>, search: list<string>}>
     */
    private const SEARCHABLE = [
        'campaigns' => [
            'model' => UnifiedCampaign::class,
            'columns' => ['id', 'name', 'client_display_name', 'status', 'objective'],
            'search' => ['client_display_name', 'name'],
        ],
        'ad_sets' => [
            'model' => ExternalAdSet::class,
            'columns' => ['id', 'name', 'provider', 'unified_campaign_id', 'status'],
            'search' => ['name'],
        ],
        'ads' => [
            'model' => ExternalAd::class,
            'columns' => ['id', 'name', 'provider', 'unified_campaign_id', 'status'],
            'search' => ['name'],
        ],
        'creatives' => [
            'model' => ExternalCreative::class,
            'columns' => ['id', 'name', 'client_display_name', 'provider', 'format', 'campaign_id'],
            'search' => ['client_display_name', 'name'],
        ],
    ];

    /**
     * One axis, filtered where the rows live — UX-MULTISELECT-SCALE-001.
     *
     * The truncation flag is recomputed against the FILTERED set, so «there are more» keeps meaning
     * «more that match this». A flag left over from the unfiltered list would tell somebody who has
     * just narrowed to one result that their list is incomplete.
     */
    private function searchAxis(string $axis, string $project, string $term): JsonResponse
    {
        $spec = self::SEARCHABLE[$axis];

        /** @var Builder $query */
        $query = $spec['model']::query()->where('project_id', $project);

        // SECURITY CLUSTER 5 — the search reads the same rows the full list does: an entity of a deselected account is not named.
        match ($axis) {
            'campaigns' => BoundAccountVisibility::unifiedCampaigns($query),
            'ad_sets' => BoundAccountVisibility::applyThroughCampaign($query, 'external_ad_sets.external_campaign_id', 'external_ad_sets.project_id'),
            'ads' => BoundAccountVisibility::applyThroughCampaign($query, 'external_ads.external_campaign_id', 'external_ads.project_id'),
            default => BoundAccountVisibility::applyThroughCampaign($query, 'external_creatives.external_campaign_id', 'external_creatives.project_id'),
        };

        if ($term !== '') {
            $query->where(function ($q) use ($spec, $term): void {
                foreach ($spec['search'] as $column) {
                    $q->orWhere($column, 'ILIKE', '%'.$term.'%');
                }
            });
        }

        [$rows, $more] = $this->bounded(
            $query->orderBy('name')->orderBy('id'),
            $spec['columns'],
            fn ($row): array => $this->shapeOption($axis, $row),
        );

        return ApiResponse::success([
            $axis => $rows,
            'truncated' => [$axis => $more],
            'limit' => self::OPTION_LIMIT,
        ], 'Scope options.');
    }

    /** One row, in the shape the full payload already sends for that axis. */
    private function shapeOption(string $axis, mixed $row): array
    {
        return match ($axis) {
            'campaigns' => [
                'id' => (string) $row->getKey(),
                'name' => (string) ($row->client_display_name ?: $row->name),
                'status' => $row->status,
                'objective' => $row->objective,
            ],
            'creatives' => [
                'id' => (string) $row->getKey(),
                'name' => (string) ($row->client_display_name ?: $row->name),
                'provider' => $row->provider,
                'format' => $row->format,
                'campaign_id' => (string) $row->campaign_id,
            ],
            default => [
                'id' => (string) $row->getKey(),
                'name' => $row->name,
                'provider' => $row->provider,
                'campaign_id' => (string) $row->unified_campaign_id,
            ],
        };
    }

    private function bounded(mixed $query, array $columns, callable $shape): array
    {
        $rows = $query->limit(self::OPTION_LIMIT + 1)->get($columns);
        $more = $rows->count() > self::OPTION_LIMIT;

        return [$rows->take(self::OPTION_LIMIT)->map($shape)->values()->all(), $more];
    }

    /** The metrics a report may be told to show. The catalogue's client-meaningful subset. */
    private const METRICS = [
        ['key' => 'spend', 'ar' => 'الإنفاق', 'en' => 'Spend'],
        ['key' => 'impressions', 'ar' => 'الظهور', 'en' => 'Impressions'],
        ['key' => 'reach', 'ar' => 'الوصول', 'en' => 'Reach'],
        ['key' => 'clicks', 'ar' => 'النقرات', 'en' => 'Clicks'],
        ['key' => 'ctr', 'ar' => 'نسبة النقر', 'en' => 'CTR'],
        ['key' => 'cpc', 'ar' => 'تكلفة النقرة', 'en' => 'CPC'],
        ['key' => 'cpm', 'ar' => 'تكلفة الألف ظهور', 'en' => 'CPM'],
        ['key' => 'conversions', 'ar' => 'النتائج', 'en' => 'Results'],
        ['key' => 'add_to_cart', 'ar' => 'الإضافات للسلة', 'en' => 'Add to cart'],
        ['key' => 'purchases', 'ar' => 'المشتريات', 'en' => 'Purchases'],
        ['key' => 'revenue', 'ar' => 'الإيرادات', 'en' => 'Revenue'],
        ['key' => 'roas', 'ar' => 'العائد على الإنفاق', 'en' => 'ROAS'],
        ['key' => 'cpa', 'ar' => 'تكلفة النتيجة', 'en' => 'Cost per result'],
    ];

    /**
     * Set a report's scope and rebuild it — the same report, the same link, new bounds.
     *
     * `reports.view` is the gate, matching every other write on this controller's sibling: an operator
     * who may create a report may say what it covers. Narrowing is not a privileged act; SHARING one
     * is, and that is gated separately on `reports.share`.
     */
    public function update(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $model = Report::query()->where('project_id', $project)->findOrFail($report);
        $before = $model->scope;

        // REPORT-SCOPE-HIERARCHY-001 — editing a saved report's scope names its accounts too.
        ReportScopeHierarchy::verifyFlat($project, (array) $request->input('scope', []));
        $scope = $this->validated($request, $project);

        // REPORT-KPI-CONTRACT-001 — the objective the editor sent is the report's measurement TEMPLATE: it moves to the report and the
        // stored scope keeps no objective unless «filter campaigns by objective» was explicitly switched on.
        $template = $scope->objectiveTemplate();

        $model->forceFill([
            'scope' => $scope->isUnbounded() ? null : $scope->toArray(),
            'campaign_objective' => $template ?? $model->campaign_objective,
            'status' => 'processing',
            'error' => null,
        ])->save();

        GenerateReportJob::dispatch((string) $model->getKey());

        $audit->log(
            action: 'report.scope.updated',
            entityType: Report::class,
            entityId: (string) $model->getKey(),
            before: ['scope' => $before],
            after: ['scope' => $model->scope],
        );

        return ApiResponse::success([
            'id' => (string) $model->getKey(),
            'scope' => $model->scope,
            'explain' => $scope->explain(),
            'status' => $model->status,
        ], 'Report scope updated; regenerating.');
    }

    /**
     * What a scope being BUILT would actually cover — REPORT-SCOPE-SELECTION-001 §C.
     *
     * `explain()` has been returned by three endpoints since it was written: a report's saved scope,
     * a saved template, and the result of saving one. None of them is the builder, so the one
     * sentence that says «selecting two ad sets does not narrow to ad-set grain» was reachable
     * everywhere except the screen where an operator makes that selection.
     *
     * A POST rather than a GET because a scope is twelve arrays and belongs in a body, not in a
     * query string that a proxy will truncate at the first project with two hundred campaigns.
     *
     * It reads and stores nothing, so `reports.view` is the whole of what it needs — an operator who
     * may look at a report may ask what a scope would cover. The ids still go through `validated()`,
     * which drops what does not belong to this project and fills an emptied axis with the impossible
     * id: an explanation of a scope that quietly widened back to «everything» would be worse than no
     * explanation at all.
     */
    public function explain(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $scope = $this->validated($request, $project);

        return ApiResponse::success([
            'scope' => $scope->toArray(),
            'bound_axes' => $scope->boundAxes(),
            'explain' => $scope->explain(),
        ]);
    }

    /** The scope on a report, with what each bound axis actually reaches. */
    public function show(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $model = Report::query()->where('project_id', $project)->findOrFail($report);
        $scope = ReportScope::fromReport($model->scope);

        // The editor's objective chips read `objectives`; for a report whose objective lives on the report (and not in its scope) they are
        // filled from it for DISPLAY only — saving moves them straight back to the report, so no data bound is stored.
        $shown = $scope->toArray();
        $objective = (string) ($model->campaign_objective ?? '');
        if (! $scope->objectiveFilter && $objective !== '' && isset(ReportObjectiveLens::TEMPLATE_MEMBERS[$objective])) {
            $shown['objectives'] = ReportObjectiveLens::TEMPLATE_MEMBERS[$objective];
        }

        return ApiResponse::success([
            'scope' => $shown,
            'objective' => $objective === '' ? null : $objective,
            'explain' => $scope->explain(),
            'bound_axes' => $scope->boundAxes(),
            /*
             * WHO the report is for — the scope editor cannot decide what it may name without it.
             *
             * A client report must not name campaigns, ad sets, ads or ad accounts, and an internal
             * one must keep every one of them. The editor is opened by report id alone, so it had no
             * way to tell the two apart and offered the whole hierarchy to both. Answered here rather
             * than threaded through the list, because the report row is the truth about its own
             * audience and the list is only a view of it.
             */
            'audience' => $model->audience,
        ], 'Report scope.');
    }

    // ---- reusable templates -------------------------------------------------------------------

    public function templates(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        // This project's templates AND the tenant-wide ones, because a template naming only platforms
        // or a marketing path is precisely the sort meant to be reused across clients.
        $rows = ReportScopeTemplate::query()
            ->where(fn ($q) => $q->where('project_id', $project)->orWhereNull('project_id'))
            ->orderBy('name')
            ->get()
            ->map(fn (ReportScopeTemplate $t): array => $this->templateShape($t))
            ->all();

        return ApiResponse::success(['templates' => $rows], 'Scope templates.');
    }

    public function storeTemplate(Request $request, AuditLogger $audit, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:400'],
            'shared' => ['nullable', 'boolean'],
        ]);

        $scope = $this->validated($request, $project);

        /*
         * A tenant-wide template may not name project-specific ids.
         *
         * «Reusable» and «names three campaigns of one project» cannot both be true: applied to
         * another project the campaign axis would resolve to nothing and the report would come back
         * empty, which reads as a data problem rather than as a template that never applied. Refused
         * with a message that says which axes are the problem, rather than silently dropping them.
         */
        $shared = (bool) ($data['shared'] ?? false);
        if ($shared) {
            // SECURITY CLUSTER 5 — a row every project reads is the workspace administrator's to create.
            abort_unless((new ReportScopeTemplate(['project_id' => null]))->mayBeChangedBy($request->user()), 403, 'Only a workspace administrator can save a tenant-wide template.');
            $this->refuseProjectSpecificAxes($scope);
        }

        $template = ReportScopeTemplate::create([
            'project_id' => $shared ? null : $project,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'scope' => $scope->toArray(),
            'created_by' => $request->user()->id,
        ]);

        $audit->log(
            action: 'report.scope_template.created',
            entityType: ReportScopeTemplate::class,
            entityId: (string) $template->getKey(),
            after: ['name' => $template->name, 'shared' => $shared],
        );

        return ApiResponse::success($this->templateShape($template), 'Scope template saved.', status: 201);
    }

    /** Editing a template never touches the reports built from it — a template is a starting point. */
    public function updateTemplate(Request $request, string $project, string $template): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $model = $this->findTemplate($project, $template);
        abort_unless($model->mayBeChangedBy($request->user()), 403, 'Only a workspace administrator can change a tenant-wide template.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:400'],
        ]);

        if ($request->has('scope')) {
            $scope = $this->validated($request, $project);
            // The same refusal storeTemplate makes: one project's ids do not belong in a row every project reads.
            if ($model->project_id === null) {
                $this->refuseProjectSpecificAxes($scope);
            }
            $data['scope'] = $scope->toArray();
        }

        $model->update($data);

        return ApiResponse::success($this->templateShape($model->fresh()), 'Scope template updated.');
    }

    public function destroyTemplate(Request $request, string $project, string $template): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $model = $this->findTemplate($project, $template);
        abort_unless($model->mayBeChangedBy($request->user()), 403, 'Only a workspace administrator can remove a tenant-wide template.');
        $model->delete();

        return ApiResponse::success(null, 'Scope template removed.');
    }

    private function refuseProjectSpecificAxes(ReportScope $scope): void
    {
        $projectSpecific = array_values(array_intersect(
            $scope->boundAxes(),
            ['campaign_ids', 'ad_set_ids', 'ad_ids', 'creative_ids', 'account_ids', 'project_ids', 'client_ids'],
        ));

        abort_if(
            $projectSpecific !== [],
            422,
            'A shared template cannot name project-specific selections: '.implode(', ', $projectSpecific),
        );
    }

    private function findTemplate(string $project, string $template): ReportScopeTemplate
    {
        return ReportScopeTemplate::query()
            ->where(fn ($q) => $q->where('project_id', $project)->orWhereNull('project_id'))
            ->findOrFail($template);
    }

    /** @return array<string, mixed> */
    private function templateShape(ReportScopeTemplate $t): array
    {
        $scope = $t->toScope();

        return [
            'id' => (string) $t->getKey(),
            'name' => $t->name,
            'description' => $t->description,
            'shared' => $t->project_id === null,
            'scope' => $scope->toArray(),
            'bound_axes' => $scope->boundAxes(),
            'explain' => $scope->explain(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    // ---- validation ---------------------------------------------------------------------------

    /**
     * The submitted scope, with every id checked against what this project actually has.
     *
     * An id that does not belong here is DROPPED rather than refused, with one deliberate exception:
     * dropping every id on an axis the caller did use would silently widen that axis back to «no
     * bound», so the axis is filled with an impossible id instead. Narrowing that matches nothing is
     * a scope somebody can see and correct; narrowing that quietly became «everything» is the failure
     * this whole unit exists to prevent.
     */
    private function validated(Request $request, string $project): ReportScope
    {
        $request->validate([
            'scope' => ['nullable', 'array'],
            'scope.providers' => ['nullable', 'array'],
            'scope.objectives' => ['nullable', 'array'],
            'scope.objectives.*' => [Rule::in(CampaignObjective::values())],
            'scope.paths' => ['nullable', 'array'],
            'scope.paths.*' => [Rule::in(MarketingPath::values())],
            'scope.from' => ['nullable', 'date'],
            'scope.to' => ['nullable', 'date'],
        ]);

        $submitted = ReportScope::fromReport((array) $request->input('scope', []));

        $keep = function (array $asked, array $available): array {
            if ($asked === []) {
                return [];
            }

            $kept = array_values(array_intersect($asked, $available));

            return $kept === [] ? [ReportScope::IMPOSSIBLE] : $kept;
        };

        $campaignIds = UnifiedCampaign::query()->where('project_id', $project)
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return ReportScope::fromReport([
            'objective_filter' => $submitted->objectiveFilter,
            'project_ids' => $submitted->projectIds === [] ? [] : [$project],
            'providers' => $submitted->providers,
            'account_ids' => $keep($submitted->accountIds, array_values(array_unique(array_merge(DailyMetric::query()
                ->where('project_id', $project)->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))->whereNotNull('external_account_id')
                ->distinct()->pluck('external_account_id')->map(fn ($id): string => (string) $id)->all(), BoundAccountVisibility::activeAccountIds($project))))),
            'campaign_ids' => $keep($submitted->campaignIds, $campaignIds),
            'ad_set_ids' => $keep($submitted->adSetIds, ExternalAdSet::query()
                ->where('project_id', $project)->pluck('id')->map(fn ($id): string => (string) $id)->all()),
            'ad_ids' => $keep($submitted->adIds, ExternalAd::query()
                ->where('project_id', $project)->pluck('id')->map(fn ($id): string => (string) $id)->all()),
            'creative_ids' => $keep($submitted->creativeIds, ExternalCreative::query()
                ->where('project_id', $project)->tap(fn ($q) => BoundAccountVisibility::applyThroughCampaign($q, 'external_creatives.external_campaign_id', 'external_creatives.project_id'))->pluck('id')->map(fn ($id): string => (string) $id)->all()),
            'objectives' => $submitted->objectives,
            'paths' => $submitted->paths,
            'metrics' => $submitted->metrics,
            'from' => $submitted->from,
            'to' => $submitted->to,
        ]);
    }
}
