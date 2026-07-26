<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Print pipeline endpoints. `issue` (authenticated, gated) mints a short-lived one-time token bound to
 * a report + print options; `data` (token-only, no session) serves the canonical snapshot for headless
 * Chromium to render the React print route. The readiness gate runs BEFORE a token is issued, so a
 * failing report can never reach the renderer.
 */
final class ReportPrintController extends Controller
{
    private const TTL = 300; // seconds — headless render is fast; keep the window tight.

    /** Mint a print token for a validated report (used by the Chromium renderer). */
    public function issue(Request $request, string $project, string $report, ExportReadinessGate $gate): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.export'), 403);
        $model = Report::query()->findOrFail($report);
        $gate->ensureReady($model);

        $opts = $request->validate([
            'type' => ['nullable', 'in:presentation,document'],
            'theme' => ['nullable', 'in:light,dark'],
        ]);

        $token = Str::random(48);
        Cache::put($this->key($token), [
            'report_id' => (string) $model->id,
            'type' => $opts['type'] ?? 'presentation',
            'theme' => $opts['theme'] ?? 'light',
        ], self::TTL);

        return ApiResponse::success(['token' => $token, 'expires_in' => self::TTL], 'Print token issued.');
    }

    /** Serve the snapshot for a print token. Token-only auth; single-use is enforced by the caller flow. */
    public function data(string $token): JsonResponse
    {
        $ctx = Cache::get($this->key($token));
        abort_if($ctx === null, 404, 'Print token invalid or expired.');

        $report = Report::withoutGlobalScopes()->find($ctx['report_id']);
        abort_if($report === null || $report->status !== 'completed', 404, 'Report not available.');

        return ApiResponse::success([
            'report_id' => (string) $report->id,
            'name' => $report->name,
            'type' => $ctx['type'],
            'theme' => $ctx['theme'],
            'currency' => $report->currency,
            'is_demo' => (bool) $report->is_demo,
            'checksum' => $report->data['checksum'] ?? null,
            'data_version' => $report->data['data_version'] ?? null,
            'data' => $report->data ?? [],
        ], 'Print data.');
    }

    private function key(string $token): string
    {
        return 'report-print:'.hash('sha256', $token);
    }
}
