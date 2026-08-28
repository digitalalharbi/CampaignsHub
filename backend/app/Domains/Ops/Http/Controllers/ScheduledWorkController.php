<?php

declare(strict_types=1);

namespace App\Domains\Ops\Http\Controllers;

use App\Domains\Ops\Services\ScheduledWorkStatus;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — the automation's own status, for the platform owner.
 *
 * Read-only, and platform-scoped rather than tenant-scoped, because the scheduler is not a tenant's:
 * one run of `integrations:sync` serves every workspace, and a per-tenant view of it would either leak
 * the shape of the installation or invent a per-tenant answer that does not exist.
 *
 * No «run now» button. This requirement is about work that runs on schedulers rather than on manual
 * buttons; adding one here would answer an operational worry by reintroducing the thing the
 * requirement exists to remove.
 */
final class ScheduledWorkController extends Controller
{
    public function __invoke(ScheduledWorkStatus $status): JsonResponse
    {
        $rows = $status->all();

        return ApiResponse::success([
            'scheduled' => $rows,
            /*
             * Counted here rather than by the reader, so every surface says the same number — and
             * `never_observed` is counted SEPARATELY from failing. They call for different actions:
             * one is «go and look», the other is «we cannot see».
             */
            'summary' => [
                'total' => count($rows),
                'failing' => count(array_filter($rows, static fn (array $r): bool => $r['last_outcome'] === 'failed')),
                'overdue' => count(array_filter($rows, static fn (array $r): bool => $r['overdue'] === true)),
                'never_observed' => count(array_filter($rows, static fn (array $r): bool => $r['state'] === 'never_observed')),
            ],
        ], 'Scheduled work status.');
    }
}
