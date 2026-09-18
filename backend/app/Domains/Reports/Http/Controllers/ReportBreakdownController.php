<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Support\ReportBreakdowns;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * REPORT-DRILLDOWN-001 — the operator's switch for a report's optional drill-down sections.
 *
 * Only registered breakdowns, only booleans, only the PDF surface: an unknown key is refused rather
 * than stored, because a stored `campaign_drilldown: true` is a promise some later renderer might keep.
 */
final class ReportBreakdownController extends Controller
{
    public function update(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        $model = Report::query()->findOrFail($report);

        $data = $request->validate([
            'pdf' => ['required', 'array', 'min:1'],
            'pdf.*' => ['boolean'],
        ]);
        $unknown = array_diff(array_keys($data['pdf']), ReportBreakdowns::keys());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['pdf' => 'Unknown breakdown: '.implode(', ', $unknown)]);
        }

        $config = (array) ($model->config ?? []);
        $breakdowns = (array) ($config['breakdowns'] ?? []);
        $breakdowns['pdf'] = array_map(static fn ($v): bool => (bool) $v, $data['pdf']) + (array) ($breakdowns['pdf'] ?? []);
        $config['breakdowns'] = $breakdowns;
        $model->forceFill(['config' => $config])->save();

        $audit->log(action: 'report.breakdowns_updated', entityType: Report::class, entityId: (string) $model->id);

        return ApiResponse::success(['pdf' => ReportBreakdowns::forReport($model, 'pdf')], 'Breakdowns updated.');
    }
}
