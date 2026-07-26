<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Manage secure client links for a report (create/list/revoke + access logs). Requires reports.share. */
final class ReportShareController extends Controller
{
    public function __construct(private readonly ShareService $shares) {}

    public function index(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findReport($report);
        $shares = ReportShare::where('report_id', $model->id)->withCount('logs')->latest()->get()
            ->map(fn (ReportShare $s) => $this->shape($s));

        return ApiResponse::success($shares, 'Shares retrieved.');
    }

    public function store(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findReport($report);
        abort_unless($model->status === 'completed', 409, 'Generate the report before sharing.');
        // An internal report is never shareable externally — a client version must be created first.
        abort_if(
            ($model->audience ?? 'client') === 'internal',
            422, 'This report must be converted to a client version before sharing.',
        );

        $opts = $request->validate([
            'password' => ['nullable', 'string', 'min:4', 'max:64'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'allow_download' => ['boolean'],
            'hide_spend' => ['boolean'],
            'hide_revenue' => ['boolean'],
            'hide_campaign_names' => ['boolean'],
            'watermark' => ['boolean'],
        ]);

        [$share, $raw] = $this->shares->create($model, $opts, $request->user()->id);
        $audit->log(action: 'report.shared_link_created', entityType: ReportShare::class, entityId: (string) $share->id);

        return ApiResponse::success(
            $this->shape($share) + ['url' => "/reports/share/{$raw}", 'token' => $raw],
            'Secure link created. Copy it now — it is shown only once.',
            status: 201,
        );
    }

    public function revoke(Request $request, AuditLogger $audit, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $this->findReport($report);
        $model = ReportShare::findOrFail($share);
        $model->update(['revoked_at' => Carbon::now()]);
        $audit->log(action: 'report.shared_link_revoked', entityType: ReportShare::class, entityId: (string) $model->id);

        return ApiResponse::success($this->shape($model), 'Link revoked.');
    }

    public function logs(Request $request, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $this->findReport($report);
        $model = ReportShare::findOrFail($share);
        $logs = $model->logs()->latest()->limit(100)->get(['action', 'ip', 'user_agent', 'detail', 'created_at']);

        return ApiResponse::success($logs, 'Access logs.');
    }

    private function findReport(string $id): Report
    {
        $r = Report::query()->find($id);
        abort_if($r === null, 404, 'Report not found.');

        return $r;
    }

    private function shape(ReportShare $s): array
    {
        return [
            'id' => $s->id,
            'active' => $s->isActive(),
            'allow_download' => $s->allow_download,
            'hide_spend' => $s->hide_spend,
            'hide_revenue' => $s->hide_revenue,
            'hide_campaign_names' => $s->hide_campaign_names,
            'watermark' => $s->watermark,
            'password_protected' => $s->password_hash !== null,
            'view_count' => $s->view_count,
            'last_viewed_at' => $s->last_viewed_at?->toIso8601String(),
            'expires_at' => $s->expires_at?->toIso8601String(),
            'revoked_at' => $s->revoked_at?->toIso8601String(),
            'logs_count' => $s->logs_count ?? null,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
