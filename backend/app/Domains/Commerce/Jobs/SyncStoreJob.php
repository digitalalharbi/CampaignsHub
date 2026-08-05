<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Jobs;

use App\Domains\Commerce\Services\StoreSyncer;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * COMMERCE-001 — one queued job per store per window. Ids only, so a retry reads current state.
 */
final class SyncStoreJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * The window is part of the key, so a backfill of last quarter does not queue behind the hourly
     * sweep and a re-sync of the SAME window is refused as the duplicate it is.
     */
    public function uniqueId(): string
    {
        return "sync-store:{$this->storeId}:{$this->from}:{$this->to}";
    }

    /** @param array<string,mixed> $meta */
    public function __construct(
        private readonly string $storeId,
        private readonly string $from,
        private readonly string $to,
        private readonly array $meta = [],
    ) {}

    public function handle(StoreSyncer $syncer, TenantContext $tenant): void
    {
        $store = ExternalAccount::withoutGlobalScopes()->find($this->storeId);

        if ($store === null) {
            return; // deleted between enqueue and run
        }

        $tenant->setTenantId($store->tenant_id);

        $syncer->sync($store, Carbon::parse($this->from), Carbon::parse($this->to), $this->meta);
    }
}
