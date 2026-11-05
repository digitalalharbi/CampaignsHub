<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Jobs\GenerateReportExportJob;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Sections\ReportSectionSurfaces;
use App\Domains\Reports\Services\ExportFailureReason;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportCreativeMedia;
use App\Domains\Reports\Services\ReportDeliveryAudienceGuard;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ReportSourceContainment;
use App\Domains\Reports\Services\ReportTemplateEngine;
use App\Domains\Reports\Support\DetailLevel;
use App\Domains\Reports\Support\ExportStaleness;
use App\Domains\Reports\Support\ReportColumns;
use App\Domains\Reports\Support\ReportPeriod;
use App\Domains\Reports\Support\ReportScope;
use App\Domains\Reports\Support\ReportScopeHierarchy;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Reports for the active project (project + tenant scoped). Generation and export run on the queue;
 * the row's status reflects processing → completed/failed. Downloads use a signed, expiring token.
 */
final class ReportController extends Controller
{
    private const TYPES = ['executive', 'project', 'campaign', 'platform', 'platform_comparison', 'weekly', 'monthly', 'custom'];

    /**
     * What the report IS — a decision-length summary, or the full account (REPORT-LINKS-13).
     *
     * A separate axis from `type` (what it is about) and from `mode` (whether a link recomputes), so
     * a summary and a detailed report of the SAME project are both expressible, and either can be
     * shared live or as a snapshot. Before this existed, «executive» sat inside `type` beside
     * `campaign` and `weekly`, so asking for a summary meant claiming to be a different kind of
     * report altogether.
     */
    private const FORMS = ['executive_summary', 'detailed'];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);

        $query = Report::query()->with('exports')->latest();
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'ilike', "%{$search}%");
        }
        $reports = $query->get()->map(fn (Report $r) => $this->shape($r));

        $counts = Report::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return ApiResponse::success([
            'reports' => $reports,
            'summary' => [
                'total' => (int) $counts->sum(),
                'completed' => (int) ($counts['completed'] ?? 0),
                'processing' => (int) ($counts['processing'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                // REPORT-DUPLICATE-001 — saved and not published; counted apart so «total» is never read as «published».
                'draft' => Report::query()->where('status', 'draft')->where('config->'.Report::DRAFT_MARK, true)->count(),
            ],
        ], 'Reports retrieved.');
    }

    /**
     * Record the operator's identity choice on the report, without recording an identity.
     *
     * `config['branding']` is the frozen-identity bag every surface already reads —
     * `PublicReportController::branding()`, `SharedLinkBranding::forShare()`, the print route and
     * the share card. `prefer` sits beside `name` and `logo_url` rather than replacing them: those
     * two mean «this exact identity was frozen», and `forShare()` tests for them by name, so a
     * preference cannot be mistaken for a frozen mark.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    private static function withBrandingChoice(?array $config, ?string $identity): ?array
    {
        if ($identity === null) {
            return $config;
        }

        $config ??= [];
        $branding = (array) ($config['branding'] ?? []);
        $branding['prefer'] = $identity;
        $config['branding'] = $branding;

        return $config;
    }

    /**
     * REPORT-IDENTITY-NAMES-001 — keep the typed names in `config.branding`, beside the identity choice.
     *
     * Blank clears the override (the stored names answer again). Only these two keys are touched, so a name edit never disturbs a
     * frozen identity, the preference, or anything else the report's config carries.
     *
     * @param  array<string, mixed>|null  $config
     * @param  array{prepared_for_name?: ?string, prepared_by_name?: ?string}  $names  only the keys the caller sent
     * @return array<string, mixed>|null
     */
    private static function withPreparedNames(?array $config, array $names): ?array
    {
        if ($names === []) {
            return $config;
        }
        $config ??= [];
        $branding = (array) ($config['branding'] ?? []);
        foreach (['prepared_for_name', 'prepared_by_name'] as $key) {
            if (! array_key_exists($key, $names)) {
                continue;
            }
            $value = trim((string) ($names[$key] ?? ''));
            if ($value === '') {
                unset($branding[$key]);
            } else {
                $branding[$key] = mb_substr($value, 0, 120);
            }
        }
        if ($branding === []) {
            unset($config['branding']);
        } else {
            $config['branding'] = $branding;
        }

        return $config === [] ? null : $config;
    }

    /**
     * The builder's fields — one rule set for creating, saving a draft, editing a draft, publishing it and previewing it.
     *
     * @return array<string, mixed>
     */
    private function builderInput(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            // REPORT-SETUP-001 — shown only if the Owner wrote one.
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::in(self::TYPES)],
            'mode' => ['nullable', Rule::in(['live', 'snapshot'])],
            'campaign_objective' => ['nullable', Rule::in(['sales', 'awareness', 'traffic', 'leads', 'app_installs', 'video', 'custom'])],
            'form' => ['nullable', Rule::in(self::FORMS)],
            // REPORT-DETAIL-LEVEL-001 — summary | standard | detailed; sets the form and the starting sections.
            'detail_level' => ['nullable', Rule::in(array_map(static fn (DetailLevel $l): string => $l->value, DetailLevel::cases()))],
            'section_settings' => ['nullable', 'array'],
            'audience' => ['nullable', Rule::in(['client', 'internal', 'executive'])],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            // REPORT-PERIOD-MODE-001 — fixed = exactly these dates; rolling = follows the reader's day. Absent: fixed when both dates were given.
            'period_mode' => ['nullable', Rule::in(ReportPeriod::MODES)],
            // REPORT-PERIOD-001 — daily | weekly | monthly | custom; the first three anchor on `period_end` (default today).
            'period_kind' => ['nullable', Rule::in(ReportPeriod::KINDS)],
            'config' => ['nullable', 'array'],
            /*
             * WHOSE identity this report carries — REPORT-CREATION-UX-001's last clause.
             *
             * A choice, not an identity: the name and the mark are resolved server-side by
             * `SharedLinkBranding`, because a caller that could post a `logo_url` could put any
             * agency's mark on any client's report. What the operator picks is which LAYER leads,
             * and the resolver answers what that layer actually holds.
             *
             * Absent means the hierarchy decides, which is what every report created before this
             * did — so nothing that already exists changes meaning.
             */
            'branding_identity' => ['nullable', Rule::in(['client', 'agency'])],
            // REPORT-IDENTITY-NAMES-001 — «prepared for» / «prepared by» typed for this report; blank = the stored names.
            'prepared_for_name' => ['nullable', 'string', 'max:120'],
            'prepared_by_name' => ['nullable', 'string', 'max:120'],
            'scope' => ['nullable', 'array'],
            // REPORT-DUPLICATE-001 — «حفظ كمسودة»: stored unpublished, nothing queued. And the report this one was started from.
            'draft' => ['nullable', 'boolean'],
            'source_report_id' => ['nullable', 'uuid'],
        ]);
    }

    /** What the editor owns outright in `config`: never carried over from the original, always what the editor sent. */
    private const EDITOR_OWNED_CONFIG = ['columns', 'detail_level', 'period_kind', 'period_mode', 'live', 'metrics', Report::DRAFT_MARK];

    /** The slide layout the generator authors for a report's form, objective and platforms ({@see ReportTemplateEngine::defaultConfig()}). */
    private const AUTHORED_LAYOUT = ['version', 'objective', 'metric_set', 'platform_order', 'slides'];

    /**
     * The report row the builder's fields describe — everything but its status and its author.
     *
     * `$from` is the report a copy was started from (or the draft being edited). Its config travels: the settings the editor
     * does not show (name switches, hidden slides) stay exactly as they were, so a copy is never more open than its original,
     * and everything the editor does show is what the editor sent. The one exception is the slide layout when the FORM was
     * changed in the editor: a layout is authored for a form, so a summary's layout on a detailed report (or the reverse)
     * would be the wrong document, and the generator authors it again. The scope is verified against the PROJECT here, so
     * every path that stores or publishes a report checks it again.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function builderAttributes(array $data, string $project, ?Report $from = null): array
    {
        if ($from !== null) {
            $carried = array_diff_key((array) ($from->config ?? []), array_flip(self::EDITOR_OWNED_CONFIG));
            $form = DetailLevel::fromInput($data['detail_level'] ?? null)?->form() ?? $data['form'] ?? 'detailed';
            if ($form !== $from->form) {
                $carried = array_diff_key($carried, array_flip(self::AUTHORED_LAYOUT));
            }
            if (array_key_exists('branding_identity', $data) && $data['branding_identity'] === null && is_array($carried['branding'] ?? null)) {
                // «تلقائي» chosen in the editor: the identity choice the copy started with is cleared, not kept silently.
                unset($carried['branding']['prefer']);
                if ($carried['branding'] === []) {
                    unset($carried['branding']);
                }
            }
            $data['config'] = (array) ($data['config'] ?? []) + $carried;
        }

        $level = DetailLevel::fromInput($data['detail_level'] ?? null);

        $periodMode = $data['period_mode'] ?? (isset($data['period_start'], $data['period_end']) ? 'fixed' : 'rolling');
        $data['config'] = (array) ($data['config'] ?? []) + ['period_mode' => $periodMode];

        $periodKind = in_array($data['period_kind'] ?? null, ReportPeriod::KINDS, true) ? (string) $data['period_kind'] : 'custom';
        if ($periodKind !== 'custom') {
            $window = ReportPeriod::resolve($periodKind, Carbon::parse($data['period_start'] ?? Carbon::today()), Carbon::parse($data['period_end'] ?? Carbon::today()));
            $data['period_start'] = $window['from']->toDateString();
            $data['period_end'] = $window['to']->toDateString();
        }

        return [
            'name' => $data['name'],
            'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            'type' => $data['type'],
            // Defaults to the FULL report, never the summary: a report that silently trimmed itself
            // would drop pages its reader was expecting and give no sign that it had.
            'form' => $level?->form() ?? $data['form'] ?? 'detailed',
            'audience' => $data['audience'] ?? 'client',
            'mode' => $data['mode'] ?? 'snapshot',
            'campaign_objective' => $data['campaign_objective'] ?? ReportScope::fromReport((array) ($data['scope'] ?? []))->objectiveTemplate(),
            'period_start' => $data['period_start'] ?? Carbon::today()->subDays(29),
            'period_end' => $data['period_end'] ?? Carbon::today(),
            /*
             * The canonical currency, not a choice. Every figure in a report comes from the
             * aggregator already normalised to it, and this column only LABELS them — so accepting a
             * currency here would have printed USD totals under whatever three letters were sent.
             */
            'currency' => ReportingCurrency::DEFAULT,
            // REPORT-TABLES-OWNER-COLUMNS-001 — `config['columns']` is cut to the known tables and keys on the way in.
            'config' => ($periodKind === 'custom')
                ? ReportColumns::normaliseConfig(self::withDetailLevel(self::withBrandingChoice(self::withPreparedNames($data['config'], array_intersect_key($data, array_flip(['prepared_for_name', 'prepared_by_name']))), $data['branding_identity'] ?? null), $level))
                : array_merge((array) ReportColumns::normaliseConfig(self::withDetailLevel(self::withBrandingChoice(self::withPreparedNames($data['config'], array_intersect_key($data, array_flip(['prepared_for_name', 'prepared_by_name']))), $data['branding_identity'] ?? null), $level)), ['period_kind' => $periodKind]),
            // The level's starting sections, with whatever the Owner switched explicitly laid over them.
            'section_settings' => $level === null && ! is_array($data['section_settings'] ?? null)
                ? null
                : ['sections' => array_merge(
                    $level?->sections() ?? [],
                    array_filter((array) (($data['section_settings']['sections'] ?? [])), 'is_bool'),
                )],
            /*
             * What the report covers, from the moment it is created (§14.5).
             *
             * Normalised through `ReportScope` rather than stored raw, so an unknown axis or a junk
             * value cannot reach the generator — and so a scope that bounds nothing is stored as
             * null rather than as an empty object nobody can distinguish from «not chosen yet».
             *
             * REPORT-SCOPE-HIERARCHY-001 — a NEW saved report names its ad accounts, exactly as a live
             * link does: the accounts, the campaigns inside them and the ads inside those are verified
             * against the project (422 on a foreign id) and an empty account list is refused, because
             * «no choice» used to mean every account the project binds — several clients' in one case.
             * Reports saved before this keep the scope they have; only creating or editing asks.
             */
            // SEC-3 — `project_ids` is not the caller's to choose: `verifyFlat` hands unknown keys back untouched, and a stored
            // project id lifted the generator's project scope onto another project's rows. The report's own project, or none.
            'scope' => ($scope = ReportScope::fromReport(self::pinnedToProject($project, ReportScopeHierarchy::verifyFlat($project, (array) ($data['scope'] ?? [])))))->isUnbounded() ? null : $scope->toArray(),
        ];
    }

    public function store(Request $request, AuditLogger $audit, string $project): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $data = $this->builderInput($request);
        $asDraft = (bool) ($data['draft'] ?? false);
        // The report a copy was started from: found inside THIS project and tenant, or not at all (404).
        $source = empty($data['source_report_id']) ? null : $this->find((string) $data['source_report_id']);
        if ($asDraft || $source !== null) {
            abort_unless($request->user()->hasPermission('reports.create'), 403);
        }

        $attributes = self::builderAttributes($data, $project, $source);
        if ($source !== null) {
            // The measurement template the original was built on, unless the scope the editor sent names one of its own.
            $attributes['campaign_objective'] ??= $source->campaign_objective;
            // What a report is measured with, never what it was shared with: no link, token, password, export or stored document.
            $attributes += array_filter([
                'campaign_id' => $source->campaign_id,
                'timezone' => $source->timezone,
                'attribution_window' => $source->attribution_window,
                'data_source' => $source->data_source,
                'is_demo' => $source->is_demo,
            ], static fn ($v): bool => $v !== null);
        }

        if ($asDraft) {
            $attributes['config'] = (array) ($attributes['config'] ?? []) + [Report::DRAFT_MARK => true];
        }
        $report = Report::create($attributes + [
            'status' => $asDraft ? 'draft' : 'processing',
            'created_by' => $request->user()->id,
        ]);

        if (! $asDraft) {
            GenerateReportJob::dispatch((string) $report->id);
            $audit->log(action: 'report.created', entityType: Report::class, entityId: (string) $report->id, after: ['type' => $report->type, 'form' => $report->form]);
        }
        if ($source !== null) {
            $audit->log(
                action: 'report.duplicated',
                entityType: Report::class,
                entityId: (string) $report->getKey(),
                before: ['source_report_id' => (string) $source->getKey()],
                after: ['report_id' => (string) $report->getKey(), 'status' => $report->status, 'share_id' => null],
            );
        } elseif ($asDraft) {
            $audit->log(action: 'report.draft_saved', entityType: Report::class, entityId: (string) $report->id, after: ['type' => $report->type, 'form' => $report->form]);
        }

        return ApiResponse::success($this->shape($report), $asDraft ? 'Draft saved.' : 'Report queued for generation.', status: 201);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private static function pinnedToProject(string $project, array $scope): array
    {
        $scope['project_ids'] = (array) ($scope['project_ids'] ?? []) === [] ? [] : [$project];

        return $scope;
    }

    public function show(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $model = $this->find($report)->load('exports');

        return ApiResponse::success($this->shape($model, withData: true), 'Report retrieved.');
    }

    /** Default slide layout for a campaign objective (drives the builder before a report exists). */
    public function template(Request $request, ReportTemplateEngine $engine): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $objective = $request->string('objective')->toString() ?: 'custom';
        $platforms = array_filter(explode(',', $request->string('platforms')->toString()));

        return ApiResponse::success($engine->defaultConfig($objective, array_values($platforms)), 'Template retrieved.');
    }

    /**
     * Save builder edits (name / description / slide config / names). Does not re-generate.
     *
     * REPORT-LIFECYCLE-MENU-001 — every ACTUAL change is audited (who, when, before, after); a save that changes nothing
     * writes nothing. `mode` is not an edit: Live and Snapshot is a deliberate, confirmed act on a link
     * ({@see ReportShareController::convert()}), so naming a different mode here is refused rather than applied silently.
     */
    public function update(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $model = $this->find($report);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'mode' => ['sometimes', Rule::in(['live', 'snapshot'])],
            'config' => ['sometimes', 'array'],
            // REPORT-IDENTITY-NAMES-001 — re-open and change the two names; blank returns to the stored names. Nothing else in config moves.
            'prepared_for_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'prepared_by_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $names = array_intersect_key($data, array_flip(['prepared_for_name', 'prepared_by_name']));
        unset($data['prepared_for_name'], $data['prepared_by_name']);
        if (array_key_exists('config', $data)) {
            // REPORT-TABLES-OWNER-COLUMNS-001 — the same cut on an edit as on creation.
            $data['config'] = ReportColumns::normaliseConfig(is_array($data['config']) ? $data['config'] : null);
        }
        if ($names !== []) {
            $base = array_key_exists('config', $data) ? (array) $data['config'] : (array) ($model->config ?? []);
            $data['config'] = self::withPreparedNames($base, $names);
        }
        if (array_key_exists('mode', $data)) {
            abort_if($data['mode'] !== $model->mode, 422, 'Changing between Live and Snapshot is a separate, confirmed action on the link.');
            unset($data['mode']);
        }

        $original = $model->only(['name', 'description', 'config']);
        $model->update($data);

        $before = [];
        $after = [];
        foreach (array_intersect_key($data, $original) as $key => $value) {
            if ($key === 'config') {
                // Top-level keys only: enough to say WHAT moved without storing a whole configuration twice.
                foreach (array_unique(array_merge(array_keys((array) $original['config']), array_keys((array) $model->config))) as $k) {
                    $was = ((array) $original['config'])[$k] ?? null;
                    $now = ((array) $model->config)[$k] ?? null;
                    if ($was !== $now) {
                        $before['config.'.$k] = $was;
                        $after['config.'.$k] = $now;
                    }
                }

                continue;
            }
            if ($original[$key] !== $model->{$key}) {
                $before[$key] = $original[$key];
                $after[$key] = $model->{$key};
            }
        }
        if ($after !== []) {
            $audit->log(action: 'report.updated', entityType: Report::class, entityId: (string) $model->getKey(), before: $before, after: $after);
        }

        return ApiResponse::success($this->shape($model->fresh(), withData: true), 'Report updated.');
    }

    /**
     * POST …/reports/{report}/duplicate — REPORT-DUPLICATE-001, as corrected by the Owner on 2026-11-04 (§8.3).
     *
     * «تكرار» starts a copy; it does not make one. Nothing is stored here: no report, no link, no token, no job. What comes
     * back is the editor's starting state — the original's settings with the suggested name «نسخة من …» — and the person
     * then chooses «حفظ كمسودة», «نشر التقرير» or «إلغاء» in the report editor ({@see store()}, {@see publish()}).
     *
     * The scope is verified for the PROJECT before it is handed to the editor (a foreign account is refused, 422), and it
     * is verified again when the draft is saved and when it is published. Nothing of the original's access travels: no
     * link, token, password, grant, export or stored document. The original is never written.
     */
    public function duplicate(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.create'), 403);
        $source = $this->find($report);
        $name = mb_substr((string) $source->name, 0, 150);

        return ApiResponse::success([
            'source' => ['id' => (string) $source->getKey(), 'name' => $source->name, 'status' => $source->status],
            'suggested_name' => ['ar' => 'نسخة من '.$name, 'en' => 'Copy of '.$name],
            'draft' => $this->editorState($source, $project, stored: false),
        ], 'Duplicate started. Nothing is saved until you save or publish it.');
    }

    /** GET …/reports/{report}/draft — a saved draft, as the editor's state, to carry on editing it. */
    public function draft(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $model = $this->draftOrRefuse($report);

        return ApiResponse::success(['draft' => $this->editorState($model, $project, stored: true)], 'Draft retrieved.');
    }

    /** PUT …/reports/{report}/draft — «حفظ كمسودة» on a draft that already exists. Stays a draft; the scope is verified again. */
    public function updateDraft(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.create'), 403);
        $model = $this->draftOrRefuse($report);
        // Validation and the scope check both answer before anything is written, so a refusal leaves the draft as it was.
        $attributes = self::builderAttributes($this->builderInput($request), $project, $model);
        $attributes['campaign_objective'] ??= $model->campaign_objective;
        $attributes['config'] = (array) ($attributes['config'] ?? []) + [Report::DRAFT_MARK => true];
        $model->update($attributes);
        $audit->log(action: 'report.draft_updated', entityType: Report::class, entityId: (string) $model->getKey(), after: ['name' => $model->name]);

        return ApiResponse::success($this->shape($model->fresh()), 'Draft saved.');
    }

    /**
     * POST …/reports/{report}/publish — «نشر التقرير»: the one way a draft becomes a report.
     *
     * The editor's fields are validated and the scope is verified against the project AGAIN, here, whatever was true when
     * the draft was saved. Only then does the row leave `draft` and the generation queue hear of it. A refusal writes
     * nothing, so the draft is neither lost nor half-published. No link is made: publishing a report needs none, and the
     * link an operator sends is made afterwards, by someone who may share, with its own new token.
     */
    public function publish(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.create'), 403);
        $model = $this->draftOrRefuse($report);
        $attributes = self::builderAttributes($this->builderInput($request), $project, $model);
        $attributes['campaign_objective'] ??= $model->campaign_objective;

        $model->fill($attributes)->publish();
        try {
            GenerateReportJob::dispatch((string) $model->getKey());
        } catch (Throwable $e) {
            // The queue did not take it: back to a draft, with the edits kept, rather than a report nobody is building.
            $model->forceFill(['status' => 'draft'])->saveQuietly();
            throw $e;
        }
        $audit->log(
            action: 'report.published',
            entityType: Report::class,
            entityId: (string) $model->getKey(),
            before: ['status' => 'draft'],
            after: ['status' => $model->status, 'type' => $model->type, 'form' => $model->form, 'share_id' => null],
        );

        return ApiResponse::success($this->shape($model->fresh()), 'Report queued for generation.');
    }

    /**
     * POST …/reports/draft-preview — the editor's «معاينة», before any report or link exists.
     *
     * The same engine a published report goes through, in the same order: the row the builder's fields describe, the
     * generator, then the resolver the preview page, the link and the PDF share ({@see ReportSectionSurfaces::previewPayload()}).
     * It runs inside a transaction that is always rolled back, so the row, and anything the generator wrote beside it, is gone
     * when the answer is sent: a preview stores nothing, queues nothing and needs no link.
     */
    public function previewDraft(Request $request, ReportGenerator $generator, string $project): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.create'), 403);
        $data = $this->builderInput($request);
        // The original the copy was started from, or the saved draft itself — either way a report of THIS project.
        $from = empty($data['source_report_id']) ? null : $this->find((string) $data['source_report_id']);
        $attributes = self::builderAttributes($data, $project, $from);

        DB::beginTransaction();
        try {
            $unsaved = Report::create($attributes + ['status' => 'draft', 'created_by' => $request->user()->id, 'is_demo' => $from !== null && $from->is_demo]);
            $generated = $generator->generate($unsaved);
            // The generator forgets the project when it is done; the resolver below reads inside it.
            app(ProjectContext::class)->setProjectId($project);
            $stored = app(ReportCreativeMedia::class)->refresh($generated);
            $payload = app(ReportSectionSurfaces::class)->previewPayload($unsaved, $stored);
            $platforms = array_values(array_unique(array_filter(array_map(
                static fn ($p): string => (string) (is_array($p) ? ($p['provider'] ?? '') : ''),
                (array) ($stored['platforms'] ?? []),
            ))));
        } finally {
            DB::rollBack();
            app(ProjectContext::class)->setProjectId($project);
        }

        return ApiResponse::success(['name' => $attributes['name'], 'data' => $payload, 'platforms' => $platforms], 'Draft preview built. Nothing was saved.');
    }

    private function draftOrRefuse(string $id): Report
    {
        $model = $this->find($id);
        abort_unless($model->isDraft(), 409, __('api.report_not_a_draft'));

        return $model;
    }

    /**
     * A report as the editor's fields: what «تكرار» starts from, and what a saved draft reopens with.
     *
     * Settings only. No stored document, no link and nothing of a link's access. A live link report keeps its sources on
     * the link (its ceiling), so they are read from there: the accounts, campaigns, ads and platforms, never its token,
     * password or expiry. A scope with no ad account is handed over without the ids that cannot be placed under one, so the
     * editor asks for the accounts instead of guessing them.
     *
     * @return array<string, mixed>
     */
    private function editorState(Report $r, string $project, bool $stored): array
    {
        $config = (array) ($r->config ?? []);
        $branding = (array) ($config['branding'] ?? []);
        // A live link report is known by its link as much as by its row: the builder marks the row, a converted link does not.
        $liveLink = $stored ? null : ReportShare::query()->where('report_id', $r->getKey())->whereNull('revoked_at')->where('mode', 'live')->whereNotNull('scope')->oldest()->first();
        $live = ($config['live'] ?? false) === true || $r->type === 'live' || $liveLink !== null;
        $level = DetailLevel::fromInput($config['detail_level'] ?? null) ?? ($r->form === 'executive_summary' ? DetailLevel::Summary : DetailLevel::Detailed);

        $scope = is_array($r->scope) && $r->scope !== [] ? $r->scope : null;
        if ($scope === null && $liveLink !== null) {
            $ceiling = (array) ($liveLink->scope ?? []);
            $scope = array_filter(array_intersect_key($ceiling, array_flip(['account_ids', 'campaign_ids', 'ad_ids', 'providers'])), static fn ($v): bool => is_array($v) && $v !== []) ?: null;
        }
        if ($scope !== null) {
            $scope = array_filter((array) ($scope['account_ids'] ?? [])) !== []
                ? ReportScopeHierarchy::verifyFlat($project, $scope)
                : array_diff_key($scope, array_flip(['campaign_ids', 'ad_ids']));
            $verified = ReportScope::fromReport(self::pinnedToProject($project, $scope));
            $scope = $verified->isUnbounded() ? null : $verified->toArray();
        }

        return [
            'id' => $stored ? (string) $r->getKey() : null,
            'name' => $stored ? $r->name : null,
            'description' => $r->description,
            'type' => in_array($r->type, self::TYPES, true) ? $r->type : 'custom',
            'mode' => $live && ! $stored ? 'live' : (in_array($r->mode, ['live', 'snapshot'], true) ? $r->mode : 'snapshot'),
            'form' => $level->form(),
            'detail_level' => $level->value,
            'audience' => $r->audience ?? 'client',
            'campaign_objective' => $r->campaign_objective,
            'period_start' => $r->period_start?->toDateString(),
            'period_end' => $r->period_end?->toDateString(),
            'period_mode' => in_array($config['period_mode'] ?? null, ReportPeriod::MODES, true) ? $config['period_mode'] : 'fixed',
            'period_kind' => in_array($config['period_kind'] ?? null, ReportPeriod::KINDS, true) ? $config['period_kind'] : 'custom',
            'branding_identity' => in_array($branding['prefer'] ?? null, ['client', 'agency'], true) ? $branding['prefer'] : null,
            'prepared_for_name' => isset($branding['prepared_for_name']) ? (string) $branding['prepared_for_name'] : null,
            'prepared_by_name' => isset($branding['prepared_by_name']) ? (string) $branding['prepared_by_name'] : null,
            'columns' => ReportColumns::fromArray((array) ($config['columns'] ?? []))->toArray(),
            'sections' => array_filter((array) ($r->section_settings['sections'] ?? []), 'is_bool'),
            'scope' => $scope,
        ];
    }

    /** REPORT-SNAPSHOT-PROTECT-001 — replacing a stored copy is asked for, attributed, and the copy is kept by the job. */
    public function regenerate(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $model = $this->find($report);
        // REPORT-DUPLICATE-001 — a draft is built by publishing it, which validates it first; a rebuild is not a way round that.
        if ($model->isDraft()) {
            return ApiResponse::error(__('api.report_is_draft'), ['code' => 'report_is_draft'], status: 409);
        }
        $confirmed = (bool) ($request->validate(['confirm' => ['nullable', 'boolean']])['confirm'] ?? false);
        if ($model->status === 'completed' && $model->hasStoredCopy() && ! $confirmed) {
            return ApiResponse::error(__('api.report_regenerate_needs_confirmation'), ['code' => 'report_regenerate_needs_confirmation'], status: 409);
        }
        $audit->log(
            action: 'report.regenerated',
            entityType: Report::class,
            entityId: (string) $model->getKey(),
            before: ['generated_at' => $model->generated_at?->toIso8601String()],
        );
        $model->update(['status' => 'processing', 'error' => null]);
        GenerateReportJob::dispatch((string) $model->id);

        return ApiResponse::success($this->shape($model), 'Report regenerating.');
    }

    /** Export readiness + any consistency issues, so the UI can show/block export with reasons. */
    public function validation(Request $request, string $project, string $report, ExportReadinessGate $gate): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);

        return ApiResponse::success($gate->evaluate($this->find($report)), 'Report validation evaluated.');
    }

    public function export(Request $request, string $project, string $report, ExportReadinessGate $gate): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);
        $model = $this->find($report);
        abort_unless($model->status === 'completed', 409, 'Report is not generated yet.');
        // Block export up-front (before queueing) when the snapshot is inconsistent.
        $gate->ensureReady($model);
        $format = $request->validate(['format' => ['required', Rule::in(['pdf', 'xlsx', 'csv'])]])['format'];

        $export = ReportExport::create([
            'report_id' => $model->id,
            'format' => $format,
            'status' => 'processing',
            'created_by' => $request->user()->id,
            'is_demo' => $model->is_demo,
        ]);
        GenerateReportExportJob::dispatch((string) $export->id);

        return ApiResponse::success(['id' => $export->id, 'format' => $format, 'status' => 'processing'], 'Export queued.', status: 202);
    }

    /**
     * REPORT-SEND-HONEST-001 — this hands nothing to a mail transport, so it refuses rather than claims.
     *
     * It used to answer «Report sent.», stamp `last_sent_at` on the report and on a new recipient row, and
     * write a `report.sent` audit row, with no message behind any of them. Permission, validation and the
     * audience guard still answer first, so a wrong request hears what is wrong with it. Stamps already on
     * existing rows are left alone. A real send belongs here only with proof it reached the transport.
     */
    public function send(Request $request, string $project, string $report, ReportDeliveryAudienceGuard $guard): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);
        $model = $this->find($report);
        $emails = $request->validate(['recipients' => ['required', 'array', 'min:1'], 'recipients.*' => ['email']])['recipients'];
        // Never deliver an internal report to a client/external recipient.
        $guard->assertDeliverable($model, $emails);

        return ApiResponse::error(__('api.report_send_unavailable'), ['code' => 'report_send_unavailable'], status: 501);
    }

    /**
     * Deleting a report deletes its links with it, and a client's link that stops answering is a question someone will ask.
     *
     * It was the one destructive report action with no audit row — sending and revoking a link were recorded, deleting the
     * whole report was not — so «who deleted the NASEL links, and when» had no authorised source to answer it. The row keeps
     * what identifies the report and each of its links (never a token), written in the same transaction as the delete.
     */
    public function destroy(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $model = $this->find($report);
        $shares = ReportShare::query()->where('report_id', $model->getKey())
            ->get(['id', 'mode', 'revoked_at', 'expires_at', 'view_count', 'created_by', 'created_at'])
            ->map(fn (ReportShare $s) => [
                'id' => (string) $s->getKey(),
                'mode' => $s->mode,
                'revoked_at' => $s->revoked_at?->toIso8601String(),
                'expires_at' => $s->expires_at?->toIso8601String(),
                'view_count' => (int) $s->view_count,
                'created_by' => $s->created_by,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->all();

        DB::transaction(function () use ($audit, $model, $shares): void {
            $audit->log(action: 'report.deleted', entityType: Report::class, entityId: (string) $model->getKey(), before: [
                'name' => $model->name,
                'type' => $model->type,
                'project_id' => (string) $model->project_id,
                'period_start' => $model->period_start?->toDateString(),
                'period_end' => $model->period_end?->toDateString(),
                'shares' => $shares,
            ]);
            $model->delete();
        });

        return ApiResponse::success(null, 'Report deleted.', status: 200);
    }

    private function find(string $id): Report
    {
        $model = Report::query()->find($id);
        abort_if($model === null, 404, 'Report not found.');

        return $model;
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    private static function withDetailLevel(?array $config, ?DetailLevel $level): ?array
    {
        if ($level === null) {
            return $config;
        }

        return array_merge($config ?? [], ['detail_level' => $level->value]);
    }

    private function shape(Report $r, bool $withData = false): array
    {
        $out = [
            'id' => $r->id,
            'name' => $r->name,
            'description' => $r->description,
            'type' => $r->type,
            // What the report IS, beside where its numbers come from — two independent facts, and a
            // list that shows only one of them cannot tell a five-page summary from a thirty-page
            // account of the same project.
            'form' => $r->form,
            'mode' => $r->mode,
            'campaign_objective' => $r->campaign_objective,
            'version' => $r->version,
            'status' => $r->status,
            // REPORT-DUPLICATE-001 — saved from the editor and not published; `draft` alone is also the column's default.
            'is_draft' => $r->isDraft(),
            'audience' => $r->audience,
            'period' => ['from' => $r->period_start?->toDateString(), 'to' => $r->period_end?->toDateString()],
            'currency' => $r->currency,
            'config' => $r->config,
            // What the report covers (§14.5) — a list that hides it cannot tell a whole-project
            // report from one narrowed to a single platform.
            'scope' => $r->scope,
            'is_demo' => $r->is_demo,
            'generated_at' => $r->generated_at?->toIso8601String(),
            'last_sent_at' => $r->last_sent_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
            'error' => $r->error,
            'exports' => $r->relationLoaded('exports')
                ? $r->exports->map(fn (ReportExport $e) => [
                    'id' => $e->id, 'format' => $e->format, 'status' => $e->status,
                    'size' => $e->size, 'token' => $e->signed_token,
                    // Renderer provenance — lets the UI (and E2E) prove the file is a current, valid
                    // Chromium export rather than a stale/legacy one.
                    'renderer' => $e->renderer, 'renderer_version' => $e->renderer_version,
                    'template_version' => $e->template_version, 'locale' => $e->locale,
                    'layout_mode' => $e->layout_mode, 'validation_status' => $e->validation_status,
                    /*
                     * WHY it failed, as a code the interface translates — never the stored message.
                     *
                     * The raw `error` carries renderer stderr and absolute paths, and this row is
                     * read by an operator who can act on «the renderer is off on this server» and
                     * can do nothing with a stack trace.
                     */
                    'failure_reason' => $e->status === 'failed'
                        ? ExportFailureReason::classify($e->error)
                        : null,
                    /*
                     * REPORT-EXPORT-STALE-DEADEND-001 — say it BEFORE somebody clicks.
                     *
                     * The download endpoint refuses a stale export with 409, and the list drew one
                     * as a plain link, so the sequence a customer met was: a chip that looks ready,
                     * one click, and the browser navigating away to a JSON error body. The same rule
                     * answers here, so the interface can offer «إعادة إنشاء» instead of a dead link.
                     */
                    'stale_reason' => ExportStaleness::reason($e),
                ])->all()
                : [],
        ];
        if ($withData) {
            /*
             * REPORT-CREATIVE-MEDIA-001 — the operator's own copy gets the same fresh media.
             *
             * The shared link, the PDF and this all render `ads_roster` through one component. If
             * only the client's copy resolved its pictures, the operator checking the report before
             * sending it would see «no cover» on the very rows the client is about to see correctly
             * — and would reasonably conclude the report was broken and not send it.
             */
            // REPORT-SOURCE-ISOLATION-001 — the builder and preview read the same contained document the link serves.
            $stored = app(ReportCreativeMedia::class)->refresh(app(ReportSourceContainment::class)->apply($r->data ?? [], $r));
            /*
             * REPORT-PARITY-FOUR-SURFACES-001 — the preview is the client's document, through the surfaces' own resolver.
             *
             * It was the stored internal snapshot with `report_sections` absent, which `sectionShown()` reads as «everything»: the
             * operator's preview drew trends the report had switched off, a main section narrowed away, a campaign roster and
             * unapproved recommendations — none of which the link, the PDF or the file carry. The same call the print route makes
             * answers here (`report_columns`, `report_views` and `report_sections` arrive with it).
             */
            $out['data'] = $stored !== []
                ? app(ReportSectionSurfaces::class)->previewPayload($r, $stored)
                // A report not generated yet has no figures to resolve sections against; it still carries the columns it was saved with.
                : (($chosen = ReportColumns::forReport($r)->toArray()) !== [] ? ['report_columns' => $chosen] : []);
            /*
             * What the operator's own pickers need from the report — its campaigns and platforms — served beside the body now that
             * the body is the client's: the link builder narrows a link to campaigns that exist in THIS report, and the roster a
             * client may not see is exactly what an operator choosing a ceiling must.
             */
            $out['scope_options'] = [
                'campaigns' => array_values(array_map(
                    static fn ($c): array => ['campaign_id' => (string) ($c['campaign_id'] ?? ''), 'campaign_name' => (string) ($c['campaign_name'] ?? '')],
                    array_filter((array) ($stored['campaigns'] ?? []), 'is_array'),
                )),
                'platforms' => array_values(array_unique(array_filter(array_map(
                    static fn ($p): string => (string) (is_array($p) ? ($p['provider'] ?? '') : ''),
                    (array) ($stored['platforms'] ?? []),
                )))),
            ];
        }

        return $out;
    }
}
