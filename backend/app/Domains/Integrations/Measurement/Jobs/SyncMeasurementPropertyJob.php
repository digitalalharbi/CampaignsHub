<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement\Jobs;

use App\Domains\Integrations\Measurement\Ga4NotSelected;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * GA4-INTEGRATION-001 — one queued job per property per window. Ids only, so a retry reads current state.
 */
final class SyncMeasurementPropertyJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /**
     * Backoff measured in minutes rather than seconds at the second attempt.
     *
     * The Data API's refusal of note is `RESOURCE_EXHAUSTED`, and a property's token allowance
     * refills on its own clock. Retrying hard spends the little that is left and refuses the
     * scheduled runs behind it.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [120, 900];
    }

    public function uniqueId(): string
    {
        return "sync-ga4-property:{$this->accountId}:{$this->days}";
    }

    public function __construct(
        private readonly string $accountId,
        private readonly int $days,
    ) {}

    public function handle(Ga4PropertySync $sync, TenantContext $tenant, AccountAssignment $assignment): void
    {
        $account = ExternalAccount::withoutGlobalScopes()->find($this->accountId);

        if ($account === null) {
            return; // deleted between enqueue and run
        }

        /*
         * The worker re-proves the selection, exactly as the store and ad-platform workers do.
         *
         * The sweep decided this property was bound when it queued the job. Queues are not
         * instantaneous and retries can be hours late, so by now somebody may have detached it.
         * Filtering only at enqueue means detaching stops the NEXT sweep and does nothing about the
         * jobs already queued — which then read a client's site traffic after they asked us to stop.
         */
        if (! $assignment->isActivelyAssigned($account)) {
            return;
        }

        $tenant->setTenantId($account->tenant_id);

        try {
            $sync->sync($account, $this->days);
        } catch (Ga4NotSelected) {
            /*
             * Detached in the instant between the check above and the read. Not a failure to retry:
             * the work is no longer authorised, and three attempts would each reach the same answer.
             */
        }
    }
}
