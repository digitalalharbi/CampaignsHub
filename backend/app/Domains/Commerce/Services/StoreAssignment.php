<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Accounts\Enums\AccountType;
use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Actions\ConfirmAccountSelection;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * COMMERCE-ASSIGN-001 — which project a connected store feeds, decided by a person or by the only choice.
 *
 * A store used to sit unassigned with no way to assign it, so «sync» answered 409 `awaiting_assignment`
 * for ever. The decision is the same one an ad account's is — a `ProjectIntegrationBinding`, purpose
 * `ecommerce`, through the same `ConfirmAccountSelection` — so the tenant and client-workspace fences,
 * the dormant-row revival and the quota rules are not restated here.
 *
 * What this adds is the three things a store needs that an ad account does not:
 *
 *  - **move and detach**: re-pointing a store deactivates the old binding in the same transaction;
 *    detaching deactivates and leaves history, so the store can come back to the same row;
 *  - **auto-assign when there is no choice**: exactly one active project that the store may serve is
 *    not a decision somebody has to make, and not guessing is the point of the rule — with two, the
 *    merchant is asked;
 *  - **the first read**, queued after the commit, so a store assigned from the page fills in by itself.
 */
final class StoreAssignment
{
    public function __construct(
        private readonly AccountAssignment $assignment,
        private readonly ConfirmAccountSelection $confirm,
        private readonly StoreSyncQueue $queue,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Point the store at a project, or detach it (null). Returns the project id now in force.
     */
    public function assign(ExternalAccount $store, ?Project $project): ?string
    {
        abort_unless($store->account_type === 'store', 404, 'Store not found.');

        if ($project === null) {
            $this->detach($store);

            return null;
        }

        // Both fences, before anything is written: another company is a 404 by now (the lookups are
        // tenant-scoped), another CLIENT of the same agency is refused here.
        if (! $this->assignment->mayAssign($store, $project)) {
            throw new HttpException(403, 'This store belongs to a different client workspace and cannot be assigned to this project.');
        }

        $before = $this->assignment->projectIdFor($store);
        $changed = $before !== (string) $project->getKey();

        $connection = ProviderConnection::withoutGlobalScopes()->findOrFail($store->provider_connection_id);

        DB::transaction(function () use ($store, $project, $connection): void {
            // Moving a store is one act: the old binding goes in the same transaction the new one
            // arrives in, so there is never a moment it feeds two projects or none.
            ProjectIntegrationBinding::withoutGlobalScopes()
                ->where('external_account_id', $store->getKey())
                ->where('is_active', true)
                ->where('project_id', '!=', $project->getKey())
                ->update(['is_active' => false, 'is_primary' => false]);

            $this->confirm->execute($connection, $project, [(string) $store->getKey()], 'ecommerce');
        });

        $this->audit->log(
            action: 'commerce.store.assigned',
            entityType: ExternalAccount::class,
            entityId: (string) $store->getKey(),
            before: ['project_id' => $before],
            after: ['project_id' => (string) $project->getKey()],
        );

        // The first read, once the binding is committed — a worker that ran earlier would find no
        // assignment and correctly refuse. Re-asserting the same project is not a reason to re-read.
        if ($changed) {
            DB::afterCommit(fn () => $this->queue->queue($store, StoreSyncQueue::BACKFILL_DAYS, 'assignment'));
        }

        return (string) $project->getKey();
    }

    /**
     * Assign a newly connected store when the workspace leaves exactly one project it may serve.
     *
     * Returns the project id, or null when there was nothing to choose or more than one choice.
     */
    public function autoAssign(ExternalAccount $store): ?string
    {
        if ($this->assignment->projectIdFor($store) !== null) {
            return null;
        }

        /*
         * SEC-3 — «exactly one project» is a choice only when the store says whose it is.
         *
         * A store with no client workspace is a TENANT-LEVEL connection: nobody named a client. Where the
         * tenant is an agency, or holds more than one client, the only project in the tenant may well be a
         * different client's, and filing the store there would put one client's orders under another's
         * reports. That case is parked as unassigned until a person chooses (PATCH /commerce/stores/{id}).
         * A store that names its client, or an advertiser with a single client, keeps the shortcut.
         */
        if ($store->client_workspace_id === null && $this->tenantHasSeveralWhoseStoreItMightBe((string) $store->tenant_id)) {
            return null;
        }

        $candidates = Project::withoutGlobalScopes()
            ->where('tenant_id', $store->tenant_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->when($store->client_workspace_id !== null, fn ($q) => $q->where('client_workspace_id', $store->client_workspace_id))
            ->limit(2)
            ->get();

        if ($candidates->count() !== 1) {
            return null;
        }

        /** @var Project $only */
        $only = $candidates->first();

        return $this->assign($store, $only);
    }

    private function tenantHasSeveralWhoseStoreItMightBe(string $tenantId): bool
    {
        $agency = Tenant::withoutGlobalScopes()->whereKey($tenantId)->value('account_type') === AccountType::Agency->value;

        return $agency || ClientWorkspace::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->limit(2)
            ->count() > 1;
    }

    private function detach(ExternalAccount $store): void
    {
        $before = $this->assignment->projectIdFor($store);

        ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $store->getKey())
            ->where('is_active', true)
            ->update(['is_active' => false, 'is_primary' => false]);

        if ($before !== null) {
            $this->audit->log(
                action: 'commerce.store.unassigned',
                entityType: ExternalAccount::class,
                entityId: (string) $store->getKey(),
                before: ['project_id' => $before],
                after: ['project_id' => null],
            );
        }
    }
}
