<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Jobs;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
final class SyncAccountMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * How long a duplicate is refused for (INTEG-SYNC-001).
     *
     * The same account and window can be asked for from three places at once — the scheduler's sweep,
     * an operator pressing sync, and a webhook saying something changed. Each would open its own
     * `MetricSyncRun`, call a rate-limited API for identical data, and write identical rows. The
     * upsert makes the WRITE idempotent; this makes the WORK idempotent.
     *
     * An hour, because that is longer than any real sync and shorter than the gap between sweeps.
     */
    public int $uniqueFor = 3600;

    /**
     * Wait longer between attempts than a web request ever would.
     *
     * A platform that failed is usually rate-limited or briefly down, and retrying in a second is how
     * three attempts are spent inside the same bad minute. `PlatformHttp` already backs off inside a
     * single call; this is the backoff BETWEEN attempts at the whole window.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * One in-flight job per account per window.
     *
     * The window is part of the key on purpose: refusing a second job for a DIFFERENT window would
     * make a backfill wait behind the daily sweep, which is a correctness-shaped fix to a problem
     * nobody has.
     */
    public function uniqueId(): string
    {
        return "sync-metrics:{$this->accountId}:{$this->from}:{$this->to}";
    }

    /** @param array<string,mixed> $meta */
    public function __construct(
        private readonly string $accountId,
        private readonly string $from,
        private readonly string $to,
        private readonly array $meta = [],
    ) {}

    public function handle(
        AccountMetricsSyncer $syncer,
        TenantContext $tenant,
        AccountAssignment $assignment,
    ): void {
        $account = ExternalAccount::withoutGlobalScopes()->find($this->accountId);
        if ($account === null) {
            return; // the account was deleted between enqueue and run — nothing to sync, nothing to record
        }

        /*
         * ORCH-100 §14 — re-prove the scope, do not trust the payload.
         *
         * The sweep decided this account was assigned when it queued the job. Queues are not
         * instantaneous and retries can be hours late, so by now the customer may have detached the
         * account or the connection may have been revoked. Checking only at enqueue means detaching
         * stops the NEXT sweep and does nothing about the jobs already queued — which then fetch a
         * customer's data after they asked us to stop.
         *
         * Returning silently is right here: this is not a failure to record, it is work that is no
         * longer authorised. A run row would report a problem where there is only a decision.
         */
        if (! $assignment->isActivelyAssigned($account)) {
            return;
        }

        $tenant->setTenantId($account->tenant_id);

        $syncer->sync($account, Carbon::parse($this->from), Carbon::parse($this->to), $this->meta);
    }
}
