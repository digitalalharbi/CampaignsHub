<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Domains\Platform\Services\OperationalReadiness;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * DEVELOPMENT-ONLY live environment health. Hard-blocked in production. Exposes no secrets/tokens — only
 * service up/down + coarse metadata. Consumed by /dev/status and scripts/dev-*.sh.
 */
final class DevStatusController
{
    public function show(): JsonResponse
    {
        abort_if(app()->environment('production'), 404);

        return ApiResponse::success($this->snapshot(), 'dev status');
    }

    /**
     * GET /api/v1/admin/status — the same checks, for the platform owner (ADMIN-003).
     *
     * Reuses `snapshot()` rather than growing a second status page. There is no production block
     * here because the `platform` middleware already restricts it to `is_platform_admin`, and an
     * owner who cannot see whether the queue is running in PRODUCTION has the check exactly where it
     * is least useful.
     */
    public function platform(): JsonResponse
    {
        return ApiResponse::success($this->snapshot(), 'platform status');
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'backend' => ['state' => 'running'],
            'database' => $this->db(),
            'redis' => $this->redis(),
            'reports_queue' => $this->reportsQueue(),
            'queue_worker' => $this->queueWorker(),
            'scheduler' => $this->scheduler(),
            'storage' => $this->storage(),
            'chromium_renderer' => $this->chromium(),
            'last_migration' => $this->lastMigration(),
            'branch' => $this->git('rev-parse --abbrev-ref HEAD'),
            'commit' => $this->git('rev-parse --short HEAD'),
            // DEVSTATUS-001: the requirement board, parsed from the traceability matrix so it can never
            // drift from the document that governs the work.
            'requirements' => $this->requirements(),
        ];
    }

    private function db(): array
    {
        try {
            DB::select('select 1');

            return ['state' => 'running', 'connection' => config('database.default')];
        } catch (Throwable $e) {
            return ['state' => 'stopped', 'error' => class_basename($e)];
        }
    }

    private function redis(): array
    {
        try {
            Redis::connection()->ping();

            return ['state' => 'running'];
        } catch (Throwable $e) {
            return ['state' => 'stopped', 'error' => class_basename($e)];
        }
    }

    private function reportsQueue(): array
    {
        try {
            $depth = (int) Redis::connection()->llen('queues:reports');

            return ['state' => 'running', 'pending' => $depth];
        } catch (Throwable $e) {
            return ['state' => 'stopped', 'error' => class_basename($e)];
        }
    }

    /** Derived from a heartbeat the worker refreshes via the dev:queue-ping job. */
    /**
     * Both processes are read from {@see OperationalReadiness} — one heartbeat, not two (PROD-001).
     *
     * These used to read `dev:queue:heartbeat` and `dev:scheduler:heartbeat`, which are written only
     * outside production. This endpoint is mounted at `/admin/status` for the platform OPERATOR, so
     * in the one environment where it matters it reported both processes «stopped» whether or not
     * they were running — a monitoring screen that is wrong by construction is worse than none, since
     * somebody will eventually stop believing it on the day it is right.
     *
     * The dev keys are still honoured as a fallback so a developer's `/dev/status` keeps working
     * against a machine that has not run the new scheduler entry yet.
     */
    private function queueWorker(): array
    {
        return $this->process(
            app(OperationalReadiness::class)->status()['processes']['queue'],
            'dev:queue:heartbeat',
            'no recent processed job',
        );
    }

    private function scheduler(): array
    {
        return $this->process(
            app(OperationalReadiness::class)->status()['processes']['scheduler'],
            'dev:scheduler:heartbeat',
        );
    }

    /**
     * @param  array<string,mixed>  $heartbeat
     * @return array<string,mixed>
     */
    private function process(array $heartbeat, string $legacyKey, ?string $stoppedNote = null): array
    {
        if ($heartbeat['state'] !== 'never_seen') {
            return [
                'state' => $heartbeat['state'] === 'up' ? 'running' : 'degraded',
                'age_seconds' => ($heartbeat['minutes_since'] ?? 0) * 60,
            ];
        }

        $legacy = Cache::get($legacyKey);

        if ($legacy === null) {
            return ['state' => 'stopped'] + ($stoppedNote === null ? [] : ['note' => $stoppedNote]);
        }

        $age = (int) now()->diffInSeconds($legacy);

        return ['state' => $age < 120 ? 'running' : 'degraded', 'age_seconds' => $age];
    }

    private function storage(): array
    {
        try {
            Storage::disk('local')->put('dev-status-probe.txt', (string) now());
            Storage::disk('local')->delete('dev-status-probe.txt');

            return ['state' => 'running'];
        } catch (Throwable $e) {
            return ['state' => 'stopped', 'error' => class_basename($e)];
        }
    }

    private function chromium(): array
    {
        $enabled = (bool) config('reports.chromium.enabled', false);
        $script = (string) config('reports.chromium.script', '');
        $ok = $enabled && $script !== '' && is_file($script);

        return ['state' => $ok ? 'running' : ($enabled ? 'degraded' : 'awaiting_credentials')];
    }

    private function lastMigration(): ?string
    {
        try {
            $row = DB::table('migrations')->orderByDesc('id')->first();

            return $row?->migration;
        } catch (Throwable) {
            return null;
        }
    }

    private function git(string $args): ?string
    {
        $out = @shell_exec('cd '.escapeshellarg(base_path()).' && git '.$args.' 2>/dev/null');

        return $out ? trim($out) : null;
    }

    /**
     * DEVSTATUS-001 — a live requirement board read straight out of
     * docs/REQUIREMENTS_TRACEABILITY_MATRIX.md.
     *
     * Parsing the matrix rather than keeping a second list is deliberate: a hand-maintained copy would
     * drift, and a board that disagrees with the governing document is worse than no board.
     *
     * @return array<string,mixed>
     */
    private function requirements(): array
    {
        $path = base_path('../docs/REQUIREMENTS_TRACEABILITY_MATRIX.md');
        if (! is_file($path)) {
            return ['available' => false, 'reason' => 'Matrix file not found.'];
        }

        $counts = [];
        $open = [];

        foreach (file($path) ?: [] as $line) {
            if (! preg_match('/^\|\s*([A-Z][A-Z0-9-]{2,})\s*\|/', $line, $idMatch)) {
                continue;
            }
            if (! preg_match('/\*\*([A-Z_]+)\*\*/', $line, $statusMatch)) {
                // A row whose status is not bolded is the header or a VERIFIED row written plainly.
                if (! str_contains($line, 'VERIFIED')) {
                    continue;
                }
                $statusMatch = [1 => 'VERIFIED'];
            }

            $id = $idMatch[1];
            $status = $statusMatch[1];
            $counts[$status] = ($counts[$status] ?? 0) + 1;

            if ($status !== 'VERIFIED') {
                $cells = array_map('trim', explode('|', trim($line, "| \n")));
                $open[] = [
                    'id' => $id,
                    'status' => $status,
                    'title' => $cells[2] ?? '',
                ];
            }
        }

        arsort($counts);

        return [
            'available' => true,
            'counts' => $counts,
            'total' => array_sum($counts),
            // The board's whole purpose: what is still open, in matrix order.
            'open' => array_slice($open, 0, 40),
        ];
    }
}
