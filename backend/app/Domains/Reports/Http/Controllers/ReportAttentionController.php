<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Metrics\Services\ObjectivePerformance;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Reports\Models\ReportAttentionDecision;
use App\Domains\Reports\Services\Attention\AttentionAudience;
use App\Domains\Reports\Services\Attention\ObjectivePerformanceFigures;
use App\Domains\Reports\Services\Attention\ReportAttention;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — the operator's side of the attention section.
 *
 * `index` shows EVERY item for a window, each with the audience the action catalogue gives it and the
 * current decision. `decide` approves or hides one item for client reports, or clears the decision.
 * Approving is what can put an operator-internal item in front of a client, so it is gated on
 * `reports.approve` — the same permission that governs written recommendations — and audited.
 */
final class ReportAttentionController extends Controller
{
    public function index(Request $request, string $project, ReportAttention $attention): JsonResponse
    {
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $to = isset($input['to']) ? Carbon::parse($input['to']) : Carbon::yesterday();
        $from = isset($input['from']) ? Carbon::parse($input['from']) : $to->copy()->subDays(29);
        abort_if($from->gt($to), 422, 'The window ends before it starts.');

        $items = $attention->items(
            new ObjectivePerformanceFigures(new ObjectivePerformance(projectIds: [$project])),
            $from,
            $to,
            strtoupper((string) ($input['currency'] ?? ReportingCurrency::DEFAULT)),
        );

        return ApiResponse::success([
            'items' => AttentionAudience::forOperator($items, $attention->decisions((string) app(TenantContext::class)->tenantId(), $project)),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ], 'Attention items.');
    }

    public function decide(Request $request, string $project, string $item, AuditLogger $audit, ReportAttention $attention): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.approve'), 403);
        abort_unless(preg_match('/^[0-9a-f]{16}$/', $item) === 1, 404);

        $decision = $request->validate([
            'decision' => ['present', 'nullable', Rule::in(AttentionAudience::DECISIONS)],
        ])['decision'];

        $row = ReportAttentionDecision::query()->where('project_id', $project)->where('item_key', $item)->first();
        $before = $row?->decision;

        if ($decision === null) {
            $row?->delete();
        } else {
            $row ??= new ReportAttentionDecision(['project_id' => $project, 'item_key' => $item]);
            $row->forceFill([
                'decision' => $decision,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ])->save();
        }

        $audit->log(
            action: 'report.attention.'.($decision ?? 'cleared'),
            entityType: ReportAttentionDecision::class,
            entityId: $project.':'.$item,
            before: ['decision' => $before],
            after: ['decision' => $decision],
        );

        return ApiResponse::success(['item_key' => $item, 'decision' => $decision], 'Attention decision saved.');
    }
}
