<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Reports\Jobs\GenerateReportExportJob;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\ExportFailureReason;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportCreativeMedia;
use App\Domains\Reports\Services\ReportDeliveryAudienceGuard;
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
use Illuminate\Validation\Rule;

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

    public function store(Request $request, AuditLogger $audit, string $project): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $data = $request->validate([
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
            'scope' => ['nullable', 'array'],
        ]);

        $level = DetailLevel::fromInput($data['detail_level'] ?? null);

        $periodMode = $data['period_mode'] ?? (isset($data['period_start'], $data['period_end']) ? 'fixed' : 'rolling');
        $data['config'] = (array) ($data['config'] ?? []) + ['period_mode' => $periodMode];

        $periodKind = in_array($data['period_kind'] ?? null, ReportPeriod::KINDS, true) ? (string) $data['period_kind'] : 'custom';
        if ($periodKind !== 'custom') {
            $window = ReportPeriod::resolve($periodKind, Carbon::parse($data['period_start'] ?? Carbon::today()), Carbon::parse($data['period_end'] ?? Carbon::today()));
            $data['period_start'] = $window['from']->toDateString();
            $data['period_end'] = $window['to']->toDateString();
        }

        $report = Report::create([
            'name' => $data['name'],
            'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            'type' => $data['type'],
            // Defaults to the FULL report, never the summary: a report that silently trimmed itself
            // would drop pages its reader was expecting and give no sign that it had.
            'form' => $level?->form() ?? $data['form'] ?? 'detailed',
            'audience' => $data['audience'] ?? 'client',
            'mode' => $data['mode'] ?? 'snapshot',
            'campaign_objective' => $data['campaign_objective'] ?? null,
            'status' => 'processing',
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
                ? ReportColumns::normaliseConfig(self::withDetailLevel(self::withBrandingChoice($data['config'] ?? null, $data['branding_identity'] ?? null), $level))
                : array_merge((array) ReportColumns::normaliseConfig(self::withDetailLevel(self::withBrandingChoice($data['config'] ?? null, $data['branding_identity'] ?? null), $level)), ['period_kind' => $periodKind]),
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
            'scope' => ($scope = ReportScope::fromArray(ReportScopeHierarchy::verifyFlat($project, (array) ($data['scope'] ?? []))))->isUnbounded() ? null : $scope->toArray(),
            'created_by' => $request->user()->id,
        ]);

        GenerateReportJob::dispatch((string) $report->id);
        $audit->log(action: 'report.created', entityType: Report::class, entityId: (string) $report->id, after: ['type' => $report->type, 'form' => $report->form]);

        return ApiResponse::success($this->shape($report), 'Report queued for generation.', status: 201);
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

    /** Save builder edits (name / slide config / notes / mode / objective). Does not re-generate. */
    public function update(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $model = $this->find($report);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'mode' => ['sometimes', Rule::in(['live', 'snapshot'])],
            'config' => ['sometimes', 'array'],
        ]);
        if (array_key_exists('config', $data)) {
            // REPORT-TABLES-OWNER-COLUMNS-001 — the same cut on an edit as on creation.
            $data['config'] = ReportColumns::normaliseConfig(is_array($data['config']) ? $data['config'] : null);
        }
        $model->update($data);

        return ApiResponse::success($this->shape($model->fresh(), withData: true), 'Report updated.');
    }

    public function regenerate(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $model = $this->find($report);
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

    public function send(Request $request, AuditLogger $audit, string $project, string $report, ReportDeliveryAudienceGuard $guard): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);
        $model = $this->find($report);
        $emails = $request->validate(['recipients' => ['required', 'array', 'min:1'], 'recipients.*' => ['email']])['recipients'];
        // Never deliver an internal report to a client/external recipient.
        $guard->assertDeliverable($model, $emails);
        foreach ($emails as $email) {
            $model->recipients()->create(['email' => $email, 'last_sent_at' => now(), 'is_demo' => $model->is_demo]);
        }
        $model->update(['last_sent_at' => now()]);
        $audit->log(action: 'report.sent', entityType: Report::class, entityId: (string) $model->id, after: ['recipients' => count($emails)]);

        return ApiResponse::success(['sent_to' => count($emails), 'at' => $model->last_sent_at], 'Report sent.');
    }

    public function destroy(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $this->find($report)->delete();

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
            $out['data'] = app(ReportCreativeMedia::class)->refresh($r->data ?? []);
            /*
             * REPORT-TABLES-OWNER-COLUMNS-001 — the saved page honours the report's own choice.
             *
             * The link, the print route and the files receive `report_columns` through
             * `ReportSectionSurfaces::apply`; the operator's own page reads the stored data
             * directly, so the choice is attached here from the same reader. Absent means every
             * column, which is what every saved report rendered before the choice existed.
             */
            if (is_array($out['data']) && ($chosen = ReportColumns::forReport($r)->toArray()) !== []) {
                $out['data']['report_columns'] = $chosen;
            }
        }

        return $out;
    }
}
