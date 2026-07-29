<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Jobs;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * SYNC-001 — one queued job per ad account per window. Queued rather than inline because a real
 * platform sync is slow and rate-limited, and because a failure must not take an HTTP request with it.
 *
 * The job carries ids only (never a serialized model) so a retry always reads current state, and it
 * re-establishes the tenant context itself — a queue worker has no request to inherit it from.
 */
final class SyncAccountMetricsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @param array<string,mixed> $meta */
    public function __construct(
        private readonly string $accountId,
        private readonly string $from,
        private readonly string $to,
        private readonly array $meta = [],
    ) {}

    public function handle(AccountMetricsSyncer $syncer, TenantContext $tenant): void
    {
        $account = ExternalAccount::withoutGlobalScopes()->find($this->accountId);
        if ($account === null) {
            return; // the account was deleted between enqueue and run — nothing to sync, nothing to record
        }

        $tenant->setTenantId($account->tenant_id);

        $syncer->sync($account, Carbon::parse($this->from), Carbon::parse($this->to), $this->meta);
    }
}
