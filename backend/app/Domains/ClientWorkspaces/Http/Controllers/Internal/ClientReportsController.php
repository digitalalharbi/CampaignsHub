<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Projects\Concerns\ProjectScope;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Client Reports tab — this client's reports ONLY, delegating to the existing report engine (Report model,
 * GenerateReportJob, ShareService) with NO parallel engine. Cross-client access via a guessed UUID is
 * impossible: every report is resolved against the client's own project ids. Audience rules are the
 * engine's (internal reports can't be shared externally; client PDFs go through the Chromium path).
 */
final class ClientReportsController
{
    private const TYPES = ['executive', 'project', 'campaign', 'platform', 'platform_comparison', 'weekly', 'monthly', 'custom'];

    public function __construct(
        private readonly ClientAccess $access,
        private readonly TenantContext $tenant,
        private readonly ShareService $shares,
    ) {}

    /** GET /app/clients/{client}/reports — reports across this client's projects only. */
    public function index(Request $request, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.view_reports', $c);
        $projectIds = $this->projectIds((string) $c->id);

        // Bypass the (inactive) ProjectScope and constrain explicitly to the client's projects.
        $reports = Report::withoutGlobalScope(ProjectScope::class)
            ->whereIn('project_id', $projectIds ?: ['00000000-0000-0000-0000-000000000000'])
            ->with('exports')->withCount('recipients')->latest()->get()
            ->map(fn (Report $r) => $this->shape($r));

        return response()->json(['data' => ['reports' => $reports]]);
    }

    /** POST /app/clients/{client}/reports — create a report for one of this client's projects. */
    public function store(Request $request, AuditLogger $audit, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.create_reports', $c);

        $projectIds = $this->projectIds((string) $c->id);
        $data = $request->validate([
            'project_id' => ['required', 'string', Rule::in($projectIds)],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(self::TYPES)],
            'audience' => ['nullable', Rule::in(['client', 'internal', 'executive'])],
            'campaign_objective' => ['nullable', Rule::in(['sales', 'awareness', 'traffic', 'leads', 'app_installs', 'video', 'custom'])],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
        ]);

        // Set the project context so BelongsToProject stamps the right project_id, then reuse the engine.
        $ctx = app(ProjectContext::class);
        $ctx->setProjectId($data['project_id']);
        try {
            $report = Report::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'audience' => $data['audience'] ?? 'client',
                'mode' => 'snapshot',
                'campaign_objective' => $data['campaign_objective'] ?? null,
                'status' => 'processing',
                'period_start' => $data['period_start'] ?? Carbon::today()->subDays(29),
                'period_end' => $data['period_end'] ?? Carbon::today(),
                'currency' => $c->default_currency ?? 'SAR',
                'created_by' => $request->user()->id,
            ]);
        } finally {
            $ctx->forget();
        }

        GenerateReportJob::dispatch((string) $report->id);
        $audit->log(action: 'client.report_created', entityType: 'client_workspace', entityId: (string) $c->id, after: ['report_id' => (string) $report->id, 'type' => $report->type]);

        return response()->json(['data' => $this->shape($report->refresh())], 201);
    }

    /** POST /app/clients/{client}/reports/{report}/share — secure client link (internal reports blocked). */
    public function share(Request $request, AuditLogger $audit, string $client, string $report): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.share_reports', $c);
        $model = $this->resolveReport((string) $c->id, $report);

        abort_unless($model->status === 'completed', 409, 'Generate the report before sharing.');
        abort_if(($model->audience ?? 'client') === 'internal', 422, 'This report must be a client version before sharing.');

        $opts = $request->validate([
            'password' => ['nullable', 'string', 'min:4', 'max:64'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'allow_download' => ['boolean'],
            'hide_spend' => ['boolean'],
            'hide_revenue' => ['boolean'],
            'hide_campaign_names' => ['boolean'],
        ]);

        [$share, $raw] = $this->shares->create($model, $opts, $request->user()->id);
        $audit->log(action: 'client.report_shared', entityType: 'client_workspace', entityId: (string) $c->id, after: ['report_id' => (string) $model->id]);

        return response()->json(['data' => ['id' => $share->id, 'url' => "/reports/share/{$raw}", 'token' => $raw]], 201);
    }

    /** POST /app/clients/{client}/reports/{report}/shares/{share}/revoke */
    public function revoke(Request $request, AuditLogger $audit, string $client, string $report, string $share): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.share_reports', $c);
        $model = $this->resolveReport((string) $c->id, $report);
        $link = ReportShare::where('report_id', $model->id)->where('id', $share)->firstOrFail();
        $link->update(['revoked_at' => Carbon::now()]);
        $audit->log(action: 'client.report_share_revoked', entityType: 'client_workspace', entityId: (string) $c->id, after: ['share_id' => (string) $link->id]);

        return response()->json(['data' => ['revoked' => true]]);
    }

    /** @return list<string> */
    private function projectIds(string $clientId): array
    {
        return Project::where('tenant_id', (string) $this->tenant->tenantId())
            ->where('client_workspace_id', $clientId)->pluck('id')->map(fn ($v) => (string) $v)->all();
    }

    private function resolveReport(string $clientId, string $reportId): Report
    {
        $projectIds = $this->projectIds($clientId);

        return Report::withoutGlobalScope(ProjectScope::class)
            ->whereIn('project_id', $projectIds ?: ['00000000-0000-0000-0000-000000000000'])
            ->where('id', $reportId)->firstOrFail(); // cross-client / cross-tenant → 404
    }

    /** @return array<string,mixed> */
    private function shape(Report $r): array
    {
        return [
            'id' => $r->id,
            'project_id' => $r->project_id,
            'name' => $r->name,
            'type' => $r->type,
            'audience' => $r->audience ?? 'client',
            'status' => $r->status,
            'shareable' => $r->status === 'completed' && ($r->audience ?? 'client') !== 'internal',
            'formats' => $r->relationLoaded('exports') ? $r->exports->pluck('format')->unique()->values()->all() : [],
            'generated_at' => optional($r->generated_at)->toIso8601String(),
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];
    }
}
