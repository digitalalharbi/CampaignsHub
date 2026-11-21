<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Exceptions\TransientSyncFailure;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * INTEG-SYNC-ENGINE-001 — a metrics job the queue gave up on, on the run ledger.
 *
 * The run keeps one of §8's six words (`failed`); being a dead letter is a separate fact in its own columns, so no screen that reads
 * a status has a seventh word to learn. A dead letter blocks nothing: the next sweep still asks for the account.
 *
 * The category is a word from a closed list and the error goes through `ProviderErrorText`, so a token quoted in a failing URL does
 * not reach the ledger.
 */
final class SyncDeadLetters
{
    public const REDRIVE_QUEUED = 'queued';

    public const REDRIVE_ALREADY = 'already_redriven';

    public const REDRIVE_BINDING_GONE = 'binding_gone';

    public function __construct(
        private readonly AccountAssignment $assignment,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Mark the run this job left behind, or open one when it died before it opened any.
     *
     * @param  array<string,mixed>  $meta  the job's
     */
    public function record(string $accountId, string $from, string $to, array $meta, ?Throwable $exception): void
    {
        $account = ExternalAccount::withoutGlobalScopes()->find($accountId);
        $projectId = $account === null ? null : $this->assignment->projectIdFor($account);

        // No longer anyone's to re-drive: the account was removed or unlinked, which is a decision and not a failure.
        if ($account === null || $projectId === null) {
            return;
        }

        $this->tenant->setTenantId($account->tenant_id);

        $category = $this->categoryOf($exception);
        $reason = 'The sync did not finish: '.(ProviderErrorText::forStorage($exception?->getMessage()) ?? 'the worker stopped it (timeout, memory, or a restart).');

        $run = MetricSyncRun::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->whereDate('window_end', $to)
            ->whereIn('status', [SyncRunStatus::Running->value, SyncRunStatus::Failed->value])
            ->whereNull('dead_lettered_at')
            ->when(is_string($meta['queued_at'] ?? null), static fn ($q) => $q->where('started_at', '>=', Carbon::parse($meta['queued_at'])))
            ->orderByDesc('started_at')
            ->first();

        if ($run === null) {
            $run = new MetricSyncRun;
            $run->forceFill([
                'tenant_id' => $account->tenant_id,
                'project_id' => $projectId,
                'connection_id' => $account->provider_connection_id,
                'external_account_id' => $account->getKey(),
                'provider' => $account->provider,
                'status' => SyncRunStatus::Failed->value,
                'window_start' => $from,
                'window_end' => $to,
                'attempts' => 0,
                'queued_at' => is_string($meta['queued_at'] ?? null) ? Carbon::parse($meta['queued_at']) : null,
                'finished_at' => Carbon::now(),
                'error' => $reason,
                'meta' => array_intersect_key($meta, ['source' => 1, 'manual' => 1, 'triggered_by' => 1]),
            ]);
        } elseif ($run->status === SyncRunStatus::Running->value) {
            $run->forceFill(['status' => SyncRunStatus::Failed->value, 'finished_at' => Carbon::now(), 'error' => $reason]);
        }

        $run->forceFill(['dead_lettered_at' => Carbon::now(), 'dead_letter_category' => $category])->save();
    }

    /** One re-drive per dead letter. The job proves the binding again when it runs; this refuses before anything is queued. */
    public function redrive(MetricSyncRun $run, ?int $userId): string
    {
        if ($run->redriven_at !== null) {
            return self::REDRIVE_ALREADY;
        }

        $account = ExternalAccount::withoutGlobalScopes()->find($run->external_account_id);

        if ($account === null
            || ! $this->assignment->isActivelyAssigned($account)
            || $this->assignment->projectIdFor($account) !== (string) $run->project_id) {
            return self::REDRIVE_BINDING_GONE;
        }

        $claimed = MetricSyncRun::withoutGlobalScopes()
            ->whereKey($run->getKey())
            ->whereNotNull('dead_lettered_at')
            ->whereNull('redriven_at')
            ->update(['redriven_at' => Carbon::now(), 'redriven_by' => $userId]);

        if ($claimed === 0) {
            return self::REDRIVE_ALREADY;
        }

        SyncAccountMetricsJob::dispatch(
            (string) $account->getKey(),
            (string) $run->window_start?->toDateString(),
            (string) $run->window_end?->toDateString(),
            ['source' => 'redrive', 'manual' => true, 'triggered_by' => $userId, 'redrive_of' => (string) $run->getKey()],
        );

        return self::REDRIVE_QUEUED;
    }

    private function categoryOf(?Throwable $exception): string
    {
        return match (true) {
            $exception instanceof TransientSyncFailure => $exception->category,
            $exception instanceof TimeoutExceededException => 'worker_timeout',
            $exception instanceof MaxAttemptsExceededException => 'retries_exhausted',
            $exception === null => 'worker_lost',
            default => 'job_exception',
        };
    }
}
