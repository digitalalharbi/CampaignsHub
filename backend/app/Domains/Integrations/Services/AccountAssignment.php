<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * PROJECT-INTEGRATION-ASSIGNMENT-001 — the one place that answers «which project owns this account».
 *
 * ## Why this exists
 *
 * An OAuth connection is not a project connection, and a discovered account is not a connected one.
 * Consent gets us a catalogue of what the customer *could* attach; assignment is the separate,
 * deliberate act of saying which project a particular account feeds.
 *
 * Nothing enforced that separation. `AccountStructureSyncer::projectIdFor()` asked for the tenant's
 * project ordered by `created_at` and took the first — the OLDEST project, chosen because it is old.
 * So the first sync of any discovered account filed its campaigns into whichever project happened to
 * exist first, and every later sync then re-filed them there "correctly", because by then a campaign
 * existed to point at. One arbitrary choice, made once, silently became permanent.
 *
 * The first live Snapchat consent discovered **309 ad accounts**. Under the old rule a single sweep
 * would have filed all 309 into one project nobody picked.
 *
 * `ProjectIntegrationBinding` — the table that records the deliberate act — existed the whole time.
 * Nothing in the sync path read it. This class is that read, and it is deliberately the only one, so
 * the sweep, the structure syncer and the binding endpoint cannot drift apart on what "assigned"
 * means.
 */
final class AccountAssignment
{
    /**
     * The project an account is assigned to, or null when nobody has assigned it.
     *
     * Null is an answer, not a failure — and it must never be replaced by a guess. Callers refuse
     * rather than file, which is the whole point.
     */
    public function projectIdFor(ExternalAccount $account): ?string
    {
        $projectId = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->value('project_id');

        return is_string($projectId) && $projectId !== '' ? $projectId : null;
    }

    /**
     * Whether this account may be assigned to this project.
     *
     * Two fences, and they answer different questions:
     *
     * - **Tenant.** Different tenants are different companies. Always refused.
     * - **Client workspace.** Inside one agency, an account discovered *for* client A must not feed
     *   client B's project. A tenant check alone lets that through, because both are the same tenant
     *   — which is exactly the case this catches.
     *
     * An account with no client workspace is a TENANT-LEVEL connection: the operator deliberately
     * connected it without naming a client, so it may serve any project in their own tenant. That is
     * a real state and the live Snapchat connection is in it — refusing it would strand 309
     * discovered accounts with nowhere legitimate to go.
     */
    public function mayAssign(ExternalAccount $account, Project $project): bool
    {
        if ((string) $account->tenant_id !== (string) $project->tenant_id) {
            return false;
        }

        if ($account->client_workspace_id === null) {
            return true;
        }

        return (string) $account->client_workspace_id === (string) $project->client_workspace_id;
    }

    /**
     * Narrow a query to the accounts somebody has actually assigned.
     *
     * Used by both scheduled sweeps. Discovery alone puts a row in `external_accounts`, so a sweep
     * that does not ask this question pulls every catalogued account on every run — for the live
     * connection, 309 of them, against the provider's rate limit, into a project nobody chose.
     *
     * @param  Builder<ExternalAccount>  $query
     * @return Builder<ExternalAccount>
     */
    public function scopeToAssigned(Builder $query): Builder
    {
        return $query->whereExists(
            // `selectRaw('1')`, not `select(1)`: the latter is quoted as a COLUMN named "1", which
            // Postgres rejects outright — an EXISTS probe wants a literal.
            fn ($sub) => $sub->selectRaw('1')
                ->from('project_integration_bindings')
                ->whereColumn('project_integration_bindings.external_account_id', 'external_accounts.id')
                ->where('project_integration_bindings.is_active', true),
        );
    }

    /**
     * Whether this account is, RIGHT NOW, authorised to be fetched.
     *
     * Both the assignment and the connection have to hold. A worker asks this at the moment it runs
     * rather than trusting the sweep that queued it: queues are not instantaneous, retries can be
     * hours late, and the customer may have detached the account or revoked the authorisation in
     * between. Checking only at enqueue means detaching stops the next sweep and does nothing about
     * the jobs already queued.
     */
    public function isActivelyAssigned(ExternalAccount $account): bool
    {
        $projectId = $this->projectIdFor($account);

        if ($projectId === null) {
            return false;
        }

        /*
         * RUNTIME-100 §29 — the whole chain is re-proved, not just the binding.
         *
         * This asked two questions: is there an active binding, and is the connection still
         * connected. Both necessary; neither says the binding still describes a coherent world. A
         * queued job can outlive the project it names — deleted, archived, or moved to another
         * client — and a binding row whose project is gone is not an authorisation, it is a
         * leftover. Reading the project back is what turns «somebody once said yes» into «somebody's
         * yes still stands», and it closes the window between enqueue and run for every link in the
         * chain rather than for the first one.
         */
        $project = Project::withoutGlobalScopes()
            ->whereKey($projectId)
            ->first(['id', 'tenant_id', 'client_workspace_id']);

        if ($project === null || (string) $project->tenant_id !== (string) $account->tenant_id) {
            return false;
        }

        // The client-workspace fence, re-proved. A tenant-level connection may feed any of the
        // tenant's clients; one scoped to a client may only ever feed that client's projects.
        if ($account->client_workspace_id !== null
            && (string) $account->client_workspace_id !== (string) $project->client_workspace_id) {
            return false;
        }

        $status = ProviderConnection::withoutGlobalScopes()
            ->whereKey($account->provider_connection_id)
            ->value('status');

        return $status === 'connected';
    }

    /**
     * How many accounts a tenant has ASSIGNED — the figure a «connected ad accounts» cap is about.
     *
     * Counted on distinct accounts rather than bindings: one account deliberately shared across two
     * projects is one account the customer connected, and billing them twice for one advertiser
     * would be a charge for our own data model.
     *
     * Discovery is explicitly not counted. The customer authorised us to SEE 309 accounts; they have
     * connected however many they chose to assign, and a plan sold on connected accounts must mean
     * the second number.
     */
    public function assignedCountFor(string $tenantId): int
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            /*
             * AD accounts only — COMMERCE-QUOTA-001.
             *
             * Commerce shares this table now, and «connected ad accounts» is a cap sold on the six ad
             * platforms. Without this a merchant connecting their Salla store would silently spend an
             * advertising slot on a shop.
             */
            ->whereIn('external_account_id', ExternalAccount::withoutGlobalScopes()
                ->where('account_type', 'ad_account')
                ->select('id'))
            ->distinct()
            ->count('external_account_id');
    }

    /**
     * The accounts a project currently draws from — the only ones a project-wide sync may touch.
     *
     * RUNTIME-100 §29. «Sync this project» has to mean the accounts somebody assigned to it, not
     * every account the tenant's authorisations discovered. With 309 discovered and one assigned,
     * the difference between those two readings is 308 accounts of unrequested traffic.
     *
     * @return list<string>
     */
    public function activeAccountIdsForProject(string $projectId): array
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->where('is_active', true)
            ->distinct()
            ->pluck('external_account_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
