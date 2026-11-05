<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Jobs;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Support\ProviderRateBudget;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Exceptions\TransientSyncFailure;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Metrics\Services\SyncCheckpoints;
use App\Domains\Metrics\Services\SyncDeadLetters;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

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

    /**
     * INTEG-SYNC-ENGINE-001 — three real failures, and room to be patient.
     *
     * `release()` spends an attempt like any other, so `tries = 3` would dead-letter a job for waiting out a rate budget.
     * `maxExceptions` is what counts failures; `tries` is now the ceiling on deliveries, which is also what ends a job that keeps
     * killing its worker (that never throws, so only `tries` can stop it). Three failures plus five deferrals.
     */
    public int $tries = 8;

    public int $maxExceptions = 3;

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

    /**
     * INTEG-SYNC-ENGINE-001 — one delivery at a time per account and window.
     *
     * `ShouldBeUnique` above stops a second DISPATCH. It does nothing about a second DELIVERY of a job already on the queue. A twin is
     * dropped rather than released, because the one in progress is doing the identical work. The lock expires, so a worker killed
     * mid-sync cannot lock the account out.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->dontRelease()
                ->expireAfter((int) config('integrations.locking.account_sync_seconds', 900)),
        ];
    }

    /**
     * INTEG-SYNC-ENGINE-001 — the queue has given up on this job: write that on the run ledger instead of letting it vanish.
     *
     * Reached after the third `TransientSyncFailure`, after anything else thrown three times, after `tries` deliveries, or on a timeout.
     */
    public function failed(?Throwable $exception): void
    {
        app(SyncDeadLetters::class)->record($this->accountId, $this->from, $this->to, $this->meta, $exception);
    }

    /** @param array<string,mixed> $meta */
    public function __construct(
        public readonly string $accountId,
        public readonly string $from,
        public readonly string $to,
        private array $meta = [],
    ) {
        // PERF-SYNC-ISOLATION-001 — provider syncs run on their own, serialised queue, never beside a user's request.
        $this->onQueue('sync');
        /*
         * Stamped HERE, in the constructor, because this is the only line in the pipeline that runs
         * at dispatch time. The run row is written by the worker, so by the time anything else could
         * record a time the queue wait has already happened and been lost. Every dispatcher gets it
         * without knowing about it, and a caller that has its own idea of when it asked keeps it.
         */
        $this->meta['queued_at'] ??= Carbon::now()->toIso8601String();
    }

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

        $budget = app(ProviderRateBudget::class);
        $checkpoints = app(SyncCheckpoints::class);
        $connectionId = (string) $account->provider_connection_id;

        /*
         * INTEG-SYNC-ENGINE-001 — a spent budget or a provider's «come back later» defers the job: it does not fail and it does not
         * spin, the worker moves on to the next account, and no run row is opened because no attempt was made.
         */
        $deferred = $budget->delayFor((string) $account->provider, $connectionId);
        if ($deferred !== null) {
            $this->release($deferred);

            return;
        }

        $from = Carbon::parse($this->from);
        $meta = $this->meta;
        $projectId = $assignment->projectIdFor($account);

        // Only the scheduler's window is ours to widen. A person who asked for a window gets that window.
        $resume = $projectId !== null && ($meta['source'] ?? null) === 'scheduler'
            ? $checkpoints->resumePoint($account, $projectId, $from)
            : null;

        if ($resume !== null) {
            $from = Carbon::parse($resume['resumed_from']);
            $meta['resume'] = $resume;
        }

        if ($this->attempts() > 1) {
            $meta['attempt'] = $this->attempts();
        }

        $run = $budget->enforcing(fn () => $syncer->sync($account, $from, Carbon::parse($this->to), $meta));

        // What this run cost the provider, measured, so a budget can be set from the ledger rather than guessed.
        if ($budget->requestsSeen($connectionId) > 0) {
            $run->forceFill(['meta' => [...(array) ($run->meta ?? []), 'provider_requests' => $budget->requestsSeen($connectionId)]])->save();
        }

        if ($run->status !== SyncRunStatus::Failed->value) {
            return;
        }

        $transient = $budget->failureKind($connectionId);

        // A refusal no retry can change (a dead token, a missing grant) is recorded on the run and left for the next sweep, as it was.
        if ($transient === null) {
            return;
        }

        if ($resume !== null && $projectId !== null) {
            $checkpoints->forgetResumeAttempt($account, $projectId);
        }

        $deferred = $budget->delayFor((string) $account->provider, $connectionId);
        if ($deferred !== null) {
            $this->release($deferred);

            return;
        }

        // Nothing would retry it inline (`QUEUE_CONNECTION=sync`, a direct call): the failed run is the whole record, as it was.
        if ($this->job === null || $this->job instanceof SyncJob) {
            return;
        }

        throw new TransientSyncFailure($transient);
    }
}
