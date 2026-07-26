<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportAnnotation;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manage a report's findings/recommendations approval lifecycle. Only APPROVED items reach a client;
 * the transition is permission-gated (reports.approve) and audited. Approving/rejecting regenerates the
 * report so the client snapshot reflects the decision.
 */
final class ReportAnnotationController extends Controller
{
    private const TRANSITIONS = ['reviewed', 'approved', 'hidden', 'rejected', 'draft'];

    public function index(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);
        $this->report($report);

        $items = ReportAnnotation::query()->where('report_id', $report)->orderByDesc('priority')->get();

        return ApiResponse::success(['annotations' => $items], 'Annotations retrieved.');
    }

    public function updateStatus(Request $request, string $project, string $report, string $annotation, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.approve'), 403);
        $model = $this->report($report);
        $ann = ReportAnnotation::query()->where('report_id', $report)->where('id', $annotation)->firstOrFail();

        $status = $request->validate(['status' => ['required', Rule::in(self::TRANSITIONS)]])['status'];
        $before = $ann->status;
        $uid = $request->user()->id;

        $fields = ['status' => $status, 'version' => $ann->version + 1];
        match ($status) {
            'reviewed' => $fields += ['reviewed_by' => $uid, 'reviewed_at' => now()],
            'approved' => $fields += ['approved_by' => $uid, 'approved_at' => now(), 'reviewed_by' => $ann->reviewed_by ?? $uid, 'reviewed_at' => $ann->reviewed_at ?? now()],
            'rejected' => $fields += ['rejected_by' => $uid, 'rejected_at' => now()],
            default => null,
        };
        $ann->forceFill($fields)->save();

        $audit->log(action: "report.annotation.{$status}", entityType: ReportAnnotation::class, entityId: (string) $ann->id, before: ['status' => $before], after: ['status' => $status]);

        // Regenerate so the client-facing snapshot reflects the new approval state.
        $model->update(['status' => 'processing']);
        GenerateReportJob::dispatch((string) $model->id);

        return ApiResponse::success($ann->fresh(), 'Annotation updated; report regenerating.');
    }

    private function report(string $id): Report
    {
        abort_if(($m = Report::query()->find($id)) === null, 404, 'Report not found.');

        return $m;
    }
}
