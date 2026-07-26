<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Reports\Jobs\GenerateReportExportJob;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportDeliveryAudienceGuard;
use App\Domains\Reports\Services\ReportTemplateEngine;
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

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(self::TYPES)],
            'mode' => ['nullable', Rule::in(['live', 'snapshot'])],
            'campaign_objective' => ['nullable', Rule::in(['sales', 'awareness', 'traffic', 'leads', 'app_installs', 'video', 'custom'])],
            'audience' => ['nullable', Rule::in(['client', 'internal', 'executive'])],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'config' => ['nullable', 'array'],
        ]);

        $report = Report::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'audience' => $data['audience'] ?? 'client',
            'mode' => $data['mode'] ?? 'snapshot',
            'campaign_objective' => $data['campaign_objective'] ?? null,
            'status' => 'processing',
            'period_start' => $data['period_start'] ?? Carbon::today()->subDays(29),
            'period_end' => $data['period_end'] ?? Carbon::today(),
            'currency' => $data['currency'] ?? 'SAR',
            'config' => $data['config'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        GenerateReportJob::dispatch((string) $report->id);
        $audit->log(action: 'report.created', entityType: Report::class, entityId: (string) $report->id, after: ['type' => $report->type]);

        return ApiResponse::success($this->shape($report), 'Report queued for generation.', status: 201);
    }

    public function show(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $model = $this->find($report);

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
            'mode' => ['sometimes', Rule::in(['live', 'snapshot'])],
            'config' => ['sometimes', 'array'],
        ]);
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

    private function shape(Report $r, bool $withData = false): array
    {
        $out = [
            'id' => $r->id,
            'name' => $r->name,
            'type' => $r->type,
            'mode' => $r->mode,
            'campaign_objective' => $r->campaign_objective,
            'version' => $r->version,
            'status' => $r->status,
            'period' => ['from' => $r->period_start?->toDateString(), 'to' => $r->period_end?->toDateString()],
            'currency' => $r->currency,
            'config' => $r->config,
            'is_demo' => $r->is_demo,
            'generated_at' => $r->generated_at?->toIso8601String(),
            'last_sent_at' => $r->last_sent_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
            'error' => $r->error,
            'exports' => $r->relationLoaded('exports')
                ? $r->exports->map(fn (ReportExport $e) => [
                    'id' => $e->id, 'format' => $e->format, 'status' => $e->status,
                    'size' => $e->size, 'token' => $e->signed_token,
                ])->all()
                : [],
        ];
        if ($withData) {
            $out['data'] = $r->data;
        }

        return $out;
    }
}
