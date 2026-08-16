<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

    /** @param array<string,mixed> $meta */
    public function __construct(
        private readonly string $accountId,
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
