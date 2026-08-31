<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportScopeTemplate;
use App\Domains\Reports\Support\ReportScope;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
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
         * REPORT-SCOPE-SELECTION-001 — the builder's lists are BOUNDED, and every bound is stated.
         *
         * Campaigns had no limit at all: a project with four hundred of them sent four hundred rows
         * into a picker, which is the cardinality this requirement exists for. The other four axes
         * had a silent `limit(500)`, which is worse than no limit — a list that stops without saying
         * so tells an operator their ad set does not exist, and the report they build then quietly
         * omits it. The same rule the campaign filter already follows: fetch one past the cap so
         * «there are more» is a FACT, and say it.
         */
        [$campaigns, $campaignsMore] = $this->bounded(
            UnifiedCampaign::query()
                ->where('project_id', $project)
                ->orderBy('name')
                ->orderBy('id'),
            ['id', 'name', 'client_display_name', 'status', 'objective'],
            fn (UnifiedCampaign $c): array => [
                'id' => (string) $c->getKey(),
                'name' => (string) ($c->client_display_name ?: $c->name),
                'status' => $c->status,
                'objective' => $c->objective,
            ],
        );

        $providers = DailyMetric::query()
            ->where('project_id', $project)
            ->distinct()->orderBy('provider')->pluck('provider')->all();

        $accountIds = DailyMetric::query()
            ->where('project_id', $project)
            ->whereNotNull('external_account_id')
            ->distinct()->pluck('external_account_id')->all();

        $accounts = $accountIds === [] ? [] : ExternalAccount::query()
            ->whereIn('id', $accountIds)
            ->orderBy('name')
            ->get(['id', 'name', 'provider', 'external_id'])
            ->map(fn (ExternalAccount $a): array => [
                'id' => (string) $a->getKey(),
                'name' => $a->name ?: $a->external_id,
                'provider' => $a->provider,
            ])->all();

        [$adSets, $adSetsMore] = $this->bounded(
            ExternalAdSet::query()->where('project_id', $project)->orderBy('name')->orderBy('id'),
            ['id', 'name', 'provider', 'unified_campaign_id', 'status'],
            fn (ExternalAdSet $s): array => [
                'id' => (string) $s->getKey(),
                'name' => $s->name,
                'provider' => $s->provider,
                'campaign_id' => (string) $s->unified_campaign_id,
            ],
        );

        [$ads, $adsMore] = $this->bounded(
            ExternalAd::query()->where('project_id', $project)->orderBy('name')->orderBy('id'),
            ['id', 'name', 'provider', 'unified_campaign_id', 'status'],
            fn (ExternalAd $a): array => [
                'id' => (string) $a->getKey(),
                'name' => $a->name,
                'provider' => $a->provider,
                'campaign_id' => (string) $a->unified_campaign_id,
            ],
        );

        [$creatives, $creativesMore] = $this->bounded(
            ExternalCreative::query()->where('project_id', $project)->orderBy('name')->orderBy('id'),
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

        $scope = $this->validated($request, $project);

        $model->forceFill([
            'scope' => $scope->isUnbounded() ? null : $scope->toArray(),
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

    /** The scope on a report, with what each bound axis actually reaches. */
    public function show(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $model = Report::query()->where('project_id', $project)->findOrFail($report);
        $scope = ReportScope::fromArray($model->scope);

        return ApiResponse::success([
            'scope' => $scope->toArray(),
            'explain' => $scope->explain(),
            'bound_axes' => $scope->boundAxes(),
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

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:400'],
        ]);

        if ($request->has('scope')) {
            $data['scope'] = $this->validated($request, $project)->toArray();
        }

        $model->update($data);

        return ApiResponse::success($this->templateShape($model->fresh()), 'Scope template updated.');
    }

    public function destroyTemplate(Request $request, string $project, string $template): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $this->findTemplate($project, $template)->delete();

        return ApiResponse::success(null, 'Scope template removed.');
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

        $submitted = ReportScope::fromArray((array) $request->input('scope', []));

        $keep = function (array $asked, array $available): array {
            if ($asked === []) {
                return [];
            }

            $kept = array_values(array_intersect($asked, $available));

            return $kept === [] ? [ReportScope::IMPOSSIBLE] : $kept;
        };

        $campaignIds = UnifiedCampaign::query()->where('project_id', $project)
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return ReportScope::fromArray([
            'project_ids' => $submitted->projectIds === [] ? [] : [$project],
            'providers' => $submitted->providers,
            'account_ids' => $keep($submitted->accountIds, DailyMetric::query()
                ->where('project_id', $project)->whereNotNull('external_account_id')
                ->distinct()->pluck('external_account_id')->map(fn ($id): string => (string) $id)->all()),
            'campaign_ids' => $keep($submitted->campaignIds, $campaignIds),
            'ad_set_ids' => $keep($submitted->adSetIds, ExternalAdSet::query()
                ->where('project_id', $project)->pluck('id')->map(fn ($id): string => (string) $id)->all()),
            'ad_ids' => $keep($submitted->adIds, ExternalAd::query()
                ->where('project_id', $project)->pluck('id')->map(fn ($id): string => (string) $id)->all()),
            'creative_ids' => $keep($submitted->creativeIds, ExternalCreative::query()
                ->where('project_id', $project)->pluck('id')->map(fn ($id): string => (string) $id)->all()),
            'objectives' => $submitted->objectives,
            'paths' => $submitted->paths,
            'metrics' => $submitted->metrics,
            'from' => $submitted->from,
            'to' => $submitted->to,
        ]);
    }
}
