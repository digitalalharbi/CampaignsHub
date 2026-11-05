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
use Illuminate\Support\Facades\Log;
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

    /**
     * Exponential between attempts: 60 s, then 300 s. (Attempts three is the last; see `handle`.)
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * PERF-SYNC-ISOLATION-001 — what a provider's refusal means for THIS job.
     *
     * The job is an optional enrichment: a card reads «—» until the provider answers, and a window
     * that is never answered stays «—», which is the truth. Failing the job 30 times a day for a
     * refusal that retrying cannot fix only fills the failed-jobs table and spends the quota again.
     *
     *   - `too_large` — the provider says the answer would exceed its limit (Snapchat E9001): the same
     *     window will be refused again, so it is NOT retried.
     *   - `transient` — a rate limit, throttle or timeout: retried with exponential backoff, at most
     *     `$tries` attempts in all.
     *   - null — anything else is a real defect and still fails loudly.
     */
    public static function classify(Throwable $e): ?string
    {
        $m = mb_strtolower($e->getMessage());

        if (str_contains($m, 'e9001') || str_contains($m, 'too large') || str_contains($m, 'larger than its limit')) {
            return 'too_large';
        }

        foreach (['rate limit', 'rate-limit', 'throttl', 'too many requests', 'http 429', 'timed out', 'timeout', 'temporarily unavailable'] as $needle) {
            if (str_contains($m, $needle)) {
                return 'transient';
            }
        }

        return null;
    }

    public function uniqueId(): string
    {
        return "period-reach:{$this->accountId}:{$this->from}:{$this->to}";
    }

    public function __construct(
        public readonly string $accountId,
        public readonly string $from,
        public readonly string $to,
    ) {
        // PERF-SYNC-ISOLATION-001 — provider syncs run on their own, serialised queue, never beside a user's request.
        $this->onQueue('sync');
    }

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
            $this->handleRefusal($e);
        }
    }

    /** What to do with a failed fetch: rethrow a defect, retry a transient with backoff, drop a too-large window. */
    public function handleRefusal(Throwable $e): void
    {
        $kind = self::classify($e);

        if ($kind === null) {
            throw $e;
        }

        $context = ['account' => $this->accountId, 'from' => $this->from, 'to' => $this->to, 'reason' => $e->getMessage()];

        if ($kind === 'too_large') {
            // Dispatch-side memory: a refusal retrying cannot fix is remembered for 7 days so no reader re-asks (REACH-STORM-001).
            Cache::put(PeriodReachReader::refusedKey($this->accountId, $this->from, $this->to), 1, Carbon::now()->addDays(7));
            Log::warning('period reach: the provider refused the window as too large; not retried', $context);

            return;
        }

        $attempt = max(1, $this->attempts());

        if ($attempt >= $this->tries) {
            Log::warning('period reach: still limited after the last attempt; the window stays not reported', $context);

            return;
        }

        // 60 s, 240 s — exponential, and always inside the supervisor's retry window.
        $this->release(60 * (4 ** ($attempt - 1)));
    }
}
