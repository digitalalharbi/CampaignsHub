<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Sections\ReportAutoComposer;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REPORT-AUTO-COMPOSE-001 — propose, pick and go back, for a report or for one of its links.
 *
 * Reading is `reports.view`; changing is `reports.manage`, checked here as well as on the route.
 */
final class ReportAutoComposeController extends Controller
{
    public function __construct(
        private readonly ReportAutoComposer $composer,
        private readonly ProjectAbilities $abilities,
    ) {}

    public function show(Request $request, string $project, string $report): JsonResponse
    {
        $this->authorise($request, $project, 'reports.view');
        [$model, $link] = $this->find($request, $report);

        return ApiResponse::success($this->composer->propose($model, $link), 'Automatic composition.');
    }

    public function store(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        $this->authorise($request, $project, 'reports.manage');
        [$model, $link] = $this->find($request, $report);
        $before = $this->auditable($model, $link);

        $result = $this->composer->apply($model, $link);

        $audit->log(
            action: 'report.auto_compose_applied',
            entityType: Report::class,
            entityId: (string) $model->getKey(),
            before: $before,
            after: $this->auditable($model->fresh() ?? $model, $link?->fresh()),
        );

        return ApiResponse::success($result, 'Automatic composition applied.');
    }

    public function destroy(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        $this->authorise($request, $project, 'reports.manage');
        [$model, $link] = $this->find($request, $report);
        $before = $this->auditable($model, $link);

        $result = $this->composer->revert($model, $link);

        $audit->log(
            action: 'report.auto_compose_reverted',
            entityType: Report::class,
            entityId: (string) $model->getKey(),
            before: $before,
            after: $this->auditable($model->fresh() ?? $model, $link?->fresh()),
        );

        return ApiResponse::success($result, 'Automatic composition removed.');
    }

    private function authorise(Request $request, string $project, string $capability): void
    {
        $user = $request->user();

        abort_unless($user !== null && $this->abilities->allows($user, $project, $capability), 403);
    }

    /** @return array{0: Report, 1: ReportShare|null} */
    private function find(Request $request, string $report): array
    {
        $request->validate(['share_id' => ['sometimes', 'nullable', 'uuid']]);
        $model = Report::query()->findOrFail($report);
        $shareId = $request->input('share_id');

        return [$model, $shareId === null ? null : ReportShare::query()->where('report_id', $model->getKey())->findOrFail($shareId)];
    }

    /** @return array<string, mixed> */
    private function auditable(Report $report, ?ReportShare $link): array
    {
        return $link !== null
            ? ['share_id' => (string) $link->getKey(), 'hidden' => (array) (($link->settings ?? [])['section_overrides'] ?? []), 'metrics' => (array) (($link->scope ?? [])['metrics'] ?? [])]
            : ['sections' => $report->sectionSettings()->toArray()['sections'], 'auto' => ((array) ($report->config ?? []))['auto_compose']['metrics'] ?? null];
    }
}
