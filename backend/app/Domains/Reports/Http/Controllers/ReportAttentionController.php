<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Metrics\Services\ObjectivePerformance;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportAttentionDecision;
use App\Domains\Reports\Services\Attention\AttentionAudience;
use App\Domains\Reports\Services\Attention\ObjectivePerformanceFigures;
use App\Domains\Reports\Services\Attention\ReportAttention;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — the operator's side of the attention section.
 *
 * `index` shows EVERY item for a report's own window, each with the audience the action catalogue
 * gives it and the current decision. `decide` approves or hides one item for THAT report and window,
 * or clears the decision — an approval never carries into another report or period.
 * Approving is what can put an operator-internal item in front of a client, so it is gated on
 * `reports.approve` — the same permission that governs written recommendations — and audited.
 */
final class ReportAttentionController extends Controller
{
    public function index(string $project, string $report, ReportAttention $attention): JsonResponse
    {
        $model = $this->report($project, $report);
        [$from, $to] = $this->window($model);

        $items = $attention->items(
            new ObjectivePerformanceFigures(new ObjectivePerformance(projectIds: [$project])),
            $from,
            $to,
            (string) $model->currency,
        );

        return ApiResponse::success([
            'items' => AttentionAudience::forOperator(
                $items,
                $attention->decisions((string) $model->tenant_id, (string) $model->id, $from->toDateString(), $to->toDateString()),
            ),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ], 'Attention items.');
    }

    public function decide(Request $request, string $project, string $report, string $item, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.approve'), 403);
        abort_unless(preg_match('/^[0-9a-f]{16}$/', $item) === 1, 404);
        $model = $this->report($project, $report);
        [$from, $to] = $this->window($model);

        $decision = $request->validate([
            'decision' => ['present', 'nullable', Rule::in(AttentionAudience::DECISIONS)],
        ])['decision'];

        $row = ReportAttentionDecision::query()
            ->where('report_id', $model->id)
            ->whereDate('period_from', $from->toDateString())
            ->whereDate('period_to', $to->toDateString())
            ->where('item_key', $item)
            ->first();
        $before = $row?->decision;

        if ($decision === null) {
            $row?->delete();
        } else {
            $row ??= new ReportAttentionDecision([
                'project_id' => $project, 'report_id' => $model->id,
                'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(), 'item_key' => $item,
            ]);
            $row->forceFill([
                'decision' => $decision,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ])->save();
        }

        $audit->log(
            action: 'report.attention.'.($decision ?? 'cleared'),
            entityType: ReportAttentionDecision::class,
            entityId: $model->id.':'.$from->toDateString().':'.$to->toDateString().':'.$item,
            before: ['decision' => $before],
            after: ['decision' => $decision],
        );

        return ApiResponse::success(['item_key' => $item, 'decision' => $decision], 'Attention decision saved.');
    }

    private function report(string $project, string $id): Report
    {
        $report = Report::query()->where('project_id', $project)->find($id);
        abort_if($report === null, 404, 'Report not found.');

        return $report;
    }

    /**
     * The report's own window — the one its snapshot and its link open on by default.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Report $report): array
    {
        abort_if($report->period_start === null || $report->period_end === null, 422, 'This report has no period to decide against.');

        return [Carbon::parse($report->period_start->toDateString()), Carbon::parse($report->period_end->toDateString())];
    }
}
