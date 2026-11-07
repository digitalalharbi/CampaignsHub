<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportVersion;
use App\Domains\Reports\Services\ReportVersions;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** REPORT-SNAPSHOT-PROTECT-001 — the copies a rebuild replaced, without their contents, and putting one back. */
final class ReportVersionController extends Controller
{
    public function index(string $project, string $report): JsonResponse
    {
        $model = Report::query()->findOrFail($report);

        return ApiResponse::success(
            ReportVersion::query()->where('report_id', $model->getKey())->orderByDesc('generated_at')->get(['id', 'generated_at', 'created_at'])
                ->map(static fn (ReportVersion $v): array => [
                    'id' => (string) $v->getKey(),
                    'generated_at' => $v->generated_at?->toIso8601String(),
                    'kept_at' => $v->created_at?->toIso8601String(),
                ])->all(),
            'Report versions.',
        );
    }

    public function restore(Request $request, AuditLogger $audit, ReportVersions $versions, string $project, string $report, string $version): JsonResponse
    {
        $model = Report::query()->findOrFail($report);
        $kept = ReportVersion::query()->where('report_id', $model->getKey())->findOrFail($version);

        if (! ($request->validate(['confirm' => ['nullable', 'boolean']])['confirm'] ?? false)) {
            return ApiResponse::error(__('api.report_restore_needs_confirmation'), ['code' => 'report_restore_needs_confirmation'], status: 409);
        }

        // A rebuild in flight replaces whatever is on the row when it commits; a copy restored now would be gone with nobody told.
        if ($model->status === 'processing') {
            return ApiResponse::error(__('api.report_is_rebuilding'), ['code' => 'report_is_rebuilding'], status: 409);
        }

        $from = $model->generated_at?->toIso8601String();
        DB::transaction(function () use ($versions, $model, $kept): void {
            $versions->keep($model);
            $model->update(['data' => $kept->data, 'generated_at' => $kept->generated_at, 'status' => 'completed', 'error' => null]);
        });
        $audit->log(
            action: 'report.version_restored',
            entityType: Report::class,
            entityId: (string) $model->getKey(),
            before: ['generated_at' => $from],
            after: ['generated_at' => $kept->generated_at?->toIso8601String()],
        );

        return ApiResponse::success(['generated_at' => $kept->generated_at?->toIso8601String()], 'Report version restored.');
    }
}
