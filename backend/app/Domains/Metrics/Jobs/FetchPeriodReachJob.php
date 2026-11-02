<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Jobs;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Services\PeriodReachFetcher;
use App\Domains\Metrics\Services\PeriodReachReader;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * REACH-PERIOD-001 — fetch one account's provider-deduplicated reach for one requested window.
 *
 * Queued, because it is asked for when a surface shows a window nobody has fetched yet: the card reads
 * «—» for that open and the provider's figure once it has answered. Unique per account and window, so
 * ten people opening the same dashboard ask the provider once.
 */
final class FetchPeriodReachJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 900;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return "period-reach:{$this->accountId}:{$this->from}:{$this->to}";
    }

    public function __construct(
        public readonly string $accountId,
        public readonly string $from,
        public readonly string $to,
    ) {}

    public function handle(PeriodReachFetcher $fetcher, TenantContext $tenant, AccountAssignment $assignment): void
    {
        $account = ExternalAccount::withoutGlobalScopes()->find($this->accountId);

        // Re-proved at run time, as the metrics sync does: a detached account is no longer ours to ask about.
        if ($account === null || ! $assignment->isActivelyAssigned($account)) {
            return;
        }

        $tenant->setTenantId($account->tenant_id);

        try {
            $fetcher->fetch($account, Carbon::parse($this->from), Carbon::parse($this->to));
        } catch (Throwable $e) {
            // Dispatch-side memory only: a refusal that retrying cannot fix is remembered for 7 days so no reader re-asks.
            if (str_contains($e->getMessage(), 'E9001') || stripos($e->getMessage(), 'too large') !== false) {
                Cache::put(PeriodReachReader::refusedKey($this->accountId, $this->from, $this->to), 1, Carbon::now()->addDays(7));
            }

            throw $e;
        }
    }
}
