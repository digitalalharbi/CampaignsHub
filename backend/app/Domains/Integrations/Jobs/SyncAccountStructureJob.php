<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * STRUCT-001 — one queued job per ad account. Ids only, never a serialized model, so a retry reads
 * current state; the tenant context is re-established here because a worker has no request to inherit.
 */
final class SyncAccountStructureJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * The time this job actually needs — INTEG-RUNTIME §10.
     *
     * ## The live failure
     *
     * On production this job was being KILLED. The account has 89 campaigns, and a structure sweep
     * reads campaigns, ad squads, ads and creatives for all of them, paged. Horizon's default is 120
     * seconds; the work is not. Three attempts, three kills, ninety seconds apart — and because a
     * killed process never reaches `finish()`, each left its `integration_sync_runs` row at
     * `running`, forever. Nothing was reported as failed, so nothing looked wrong, and the Campaigns
     * page stayed empty beside 1,056 stored metrics.
     *
     * Fifteen minutes is sized to the work rather than to a default: the sweep runs every six hours
     * and `$uniqueFor` already prevents it stacking on itself, so a long-running attempt costs one
     * worker slot and nothing else.
     */
    public int $timeout = 900;

    /**
     * Long enough that the scheduled sweep cannot stack on itself.
     *
     * Structure is swept every six hours; an hour of uniqueness swallows a duplicate raised by an
     * operator pressing sync at the wrong moment without ever blocking the next real sweep.
     */
    public int $uniqueFor = 3600;

    /**
     * Between-attempt backoff, deliberately longer than a request would tolerate.
     *
     * `PlatformHttp` already backs off inside a single call; this is the wait before the whole account
     * is attempted again, and a platform that just rate-limited us needs minutes, not seconds.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return "sync-structure:{$this->accountId}";
    }

    /**
     * A job that DIED still has to say so.
     *
     * `AccountStructureSyncer` catches every `Throwable` the provider can raise, so a status of
     * `running` cannot mean «the platform refused» — it means the PROCESS went away: a timeout, an
     * OOM, a redeploy mid-flight. Laravel calls this hook for exactly those, and without it the run
     * row is left open and the pipeline looks busy rather than broken.
     *
     * That is not a hypothetical. It is how three killed attempts on a live account went unreported
     * for hours while the Campaigns page stayed empty.
     */
    public function failed(?Throwable $exception): void
    {
        IntegrationSyncRun::withoutGlobalScopes()
            ->where('type', 'structure')
            ->where('status', SyncRunStatus::Running->value)
            ->whereIn('provider_connection_id', ExternalAccount::withoutGlobalScopes()
                ->whereKey($this->accountId)
                ->select('provider_connection_id'))
            ->update([
                'status' => SyncRunStatus::Failed->value,
                'finished_at' => now(),
                'error' => 'The sync did not finish: '
                    .($exception?->getMessage() ?? 'the worker stopped it (timeout, memory, or a restart).'),
            ]);
    }

    /** @param array<string,mixed> $meta */
    public function __construct(
        public readonly string $accountId,
        private readonly array $meta = [],
    ) {}

    public function handle(
        AccountStructureSyncer $syncer,
        TenantContext $tenant,
        AccountAssignment $assignment,
    ): void {
        $account = ExternalAccount::withoutGlobalScopes()->find($this->accountId);

        if ($account === null) {
            return; // deleted between enqueue and run — nothing to sync, nothing to record
        }

        // ORCH-100 §14 — the same re-proof as the metrics job: the assignment may have been
        // withdrawn, or the connection revoked, since this was queued.
        if (! $assignment->isActivelyAssigned($account)) {
            return;
        }

        $tenant->setTenantId($account->tenant_id);

        $syncer->sync($account, $this->meta);
    }
}
