<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
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
            ->distinct()
            ->count('external_account_id');
    }
}
