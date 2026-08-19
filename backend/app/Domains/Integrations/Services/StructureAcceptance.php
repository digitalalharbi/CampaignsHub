<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Metrics\Enums\SyncRunStatus;
use Illuminate\Support\Collection;

/**
 * SNAP-STRUCTURE-RETRY-001 — what «the structure sweep worked» is allowed to mean.
 *
 * Separate from the command that queues and polls, because the criteria are the part worth testing
 * and a polling loop is not. Every rule here is a way the live sweep actually failed, or a way it
 * could have failed silently:
 *
 * - fewer runs than accounts   → the job was never queued; usually a unique-job lock left behind
 * - more runs than accounts    → the broker re-delivered work that was still running
 * - MaxAttemptsExceeded        → the same, said by the framework
 * - still `running`            → killed without its `failed()` hook, or stuck
 * - success with records = 0   → the syncer cannot produce this; a green tick over nothing
 * - no_data with a payload     → the provider answered and the mapping dropped it
 *
 * The last one is the whole of SNAP-BREAKDOWN-001 restated as a check: a run reported `no_data`
 * honestly against a body that carried a hundred dollars of spend, because it read the wrong key.
 * «Zero rows» and «zero rows in the response» are different claims, and only the retained payload
 * can tell them apart.
 */
final class StructureAcceptance
{
    /**
     * @param  Collection<int, IntegrationSyncRun>  $runs
     * @return list<string> empty when the sweep is acceptable
     */
    public function problems(Collection $runs, int $accounts, int $observeSeconds): array
    {
        $problems = [];

        if ($runs->count() < $accounts) {
            $problems[] = "Only {$runs->count()} of {$accounts} account(s) produced a structure run. "
                .'A job that was never queued is usually a unique-job lock left behind by a killed attempt.';
        }

        if ($runs->count() > $accounts) {
            $problems[] = "{$runs->count()} runs for {$accounts} account(s) — the job was re-delivered while still running.";
        }

        foreach ($runs as $run) {
            if ($run->error !== null && str_contains(strtolower((string) $run->error), 'attempted too many times')) {
                $problems[] = "Run {$run->id} reports MaxAttemptsExceeded: {$run->error} "
                    .'The job exhausted its attempts, which means it was re-queued while still running.';
            }

            switch ($run->status) {
                case SyncRunStatus::Running->value:
                    $problems[] = "Run {$run->id} was still «running» after {$observeSeconds}s. "
                        .'It was killed without its `failed()` hook, or it is stuck.';
                    break;

                case SyncRunStatus::Success->value:
                    if ((int) ($run->records ?? 0) === 0) {
                        $problems[] = "Run {$run->id} reports success with records=0, which the syncer cannot produce. "
                            .'A green tick over an empty result is the one thing this pipeline must never say.';
                    }
                    break;

                case SyncRunStatus::NoData->value:
                    $rows = $this->retainedRows($run);

                    $problems[] = $rows > 0
                        ? "Run {$run->id} reports no_data while its retained payload carries {$rows} row(s). "
                            .'The provider answered and the mapping dropped it — a defect, not a quiet account.'
                        : "Run {$run->id} reports no_data with an empty retained payload. Structure with genuinely "
                            .'nothing in it is possible, but not on an account with campaigns: read the body with '
                            .'`integrations:diagnose --payload` before accepting this.';
                    break;

                case SyncRunStatus::PartialMapping->value:
                    $problems[] = "Run {$run->id} finished «partial_mapping» with records={$run->records}: "
                        .($run->error ?? 'rows named a parent that was not discovered.');
                    break;

                default:
                    $problems[] = "Run {$run->id} finished «{$run->status}»: ".($run->error ?? 'no reason recorded.');
            }
        }

        return $problems;
    }

    private function retainedRows(IntegrationSyncRun $run): int
    {
        return (int) IntegrationRawPayload::withoutGlobalScopes()
            ->where('sync_run_id', $run->getKey())
            ->where('resource', 'structure')
            ->sum('normalised_rows');
    }
}
