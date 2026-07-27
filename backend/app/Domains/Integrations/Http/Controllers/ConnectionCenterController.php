<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Integrations\Actions\EstablishSandboxConnection;
use App\Domains\Integrations\Connectors\ConnectionCenterService;
use App\Domains\Integrations\Connectors\ValueObjects\SyncWindow;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read API for the Connection Center. Gated by the existing integrations permissions. Every provider
 * is listed with its capabilities and HONEST state; syncs delegate to the connector (Sandbox works,
 * real providers report awaiting); history/errors/freshness are read from the existing MetricSyncRun.
 *
 * Routes are declared in routes/api/connections.php, which the orchestrator wires into routes/api.php.
 */
final class ConnectionCenterController extends Controller
{
    public function __construct(
        private readonly ConnectionCenterService $service,
        private readonly EstablishSandboxConnection $establishSandbox,
    ) {}

    /** List every registered connector with its capabilities + honest state (per project). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $data = [];
        foreach ($this->service->connectors() as $provider => $connector) {
            $data[] = $this->service->describe($connector, $this->service->latestConnection($provider));
        }

        return ApiResponse::success($data, 'Connection Center connectors retrieved.');
    }

    /** Trigger a sync for a provider. Sandbox performs a real demo sync; real providers no-op honestly. */
    public function sync(Request $request, string $provider): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $connector = $this->service->connector($provider);
        abort_if($connector === null, 404, 'Unknown connector.');

        $connection = $this->service->latestConnection($provider);

        // The Sandbox is fully functional: establish a real (demo) connection on first sync if needed.
        if ($connector->isSandbox() && $connection === null) {
            $connection = $this->establishSandbox->execute()['connection'];
        }

        $window = SyncWindow::lastDays($request->integer('days', 7) ?: 7);
        ['run' => $run, 'result' => $result, 'state' => $state] = $this->service->sync($connector, $connection, $window);

        return ApiResponse::success([
            'provider' => $provider,
            'state' => $state->value,
            'state_label' => $state->label(),
            'sync_run_id' => $run->id,
            'status' => $run->status,
            'records' => $result->count,
            'metrics_upserted' => $run->metrics_upserted,
            'message' => $result->message,
        ], $result->success ? 'Sync complete.' : 'Sync did not run: awaiting external dependency.');
    }

    /** Connection history, errors, and data freshness for a provider (project-scoped MetricSyncRun). */
    public function history(Request $request, string $provider): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $connector = $this->service->connector($provider);
        abort_if($connector === null, 404, 'Unknown connector.');

        $runs = MetricSyncRun::where('provider', $provider)
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->map(fn (MetricSyncRun $r): array => [
                'id' => $r->id,
                'status' => $r->status,
                'window_start' => optional($r->window_start)->toDateString(),
                'window_end' => optional($r->window_end)->toDateString(),
                'metrics_upserted' => $r->metrics_upserted,
                'attempts' => $r->attempts,
                'started_at' => optional($r->started_at)->toIso8601String(),
                'finished_at' => optional($r->finished_at)->toIso8601String(),
                'error' => $r->error,
            ])
            ->values();

        $latest = MetricSyncRun::where('provider', $provider)
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->first();

        return ApiResponse::success([
            'provider' => $provider,
            'runs' => $runs,
            'errors' => $runs->filter(fn (array $r): bool => $r['error'] !== null)->values(),
            'data_freshness' => [
                'last_run_at' => optional($latest?->finished_at)->toIso8601String(),
                'last_status' => $latest?->status,
                'metrics_upserted' => $latest?->metrics_upserted,
            ],
        ], 'Connection history retrieved.');
    }
}
