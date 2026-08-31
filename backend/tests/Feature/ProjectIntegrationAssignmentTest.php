<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PROJECT-INTEGRATION-ASSIGNMENT-001 — an OAuth connection is not a project connection.
 *
 * ## The live defect
 *
 * The first real Snapchat consent succeeded and discovery returned **309 ad accounts** into a
 * workspace with **no projects at all**. The product reported the integration as connected and
 * offered a sync button, with no path from «309 accounts discovered» to «this account feeds this
 * project».
 *
 * That is the visible half. The half underneath is worse.
 *
 * ## ASSIGN-PROJECT-001 — the assignment layer existed and the syncer ignored it
 *
 * `ProjectIntegrationBinding` and `ProjectIntegrationController::bind()` have been here all along.
 * `AccountStructureSyncer::projectIdFor()` never reads them. It looks for a campaign this account
 * already filed, and failing that:
 *
 * ```php
 * Project::withoutGlobalScopes()->where('tenant_id', …)->orderBy('created_at')->value('id')
 * ```
 *
 * — the tenant's **oldest project**, chosen because it is old. So the first sync of any discovered
 * account files its campaigns into whichever project happens to have been created first, and every
 * later sync then "correctly" re-files them there because a campaign now exists. One arbitrary
 * choice, made once, becomes the permanent home of another client's spend.
 *
 * With 309 discovered accounts and one project in the tenant, that is 309 accounts' campaigns in one
 * project. The binding endpoint would have been the answer, and nothing consulted it.
 *
 * ## SWEEP-UNASSIGNED-001 — and the sweep does not ask either
 *
 * `SyncAdPlatformsCommand` queues a metrics job for **every** `ad_account` row that is `active` on a
 * connected connection. No binding filter. Discovery alone is what puts a row there, so the nightly
 * sweep would pull all 309 accounts — data nobody asked for, against a provider's rate limit, into a
 * project nobody chose.
 *
 * ## The rule these tests hold
 *
 * Discovery is a catalogue. Assignment is consent. Nothing syncs, and nothing is filed, until
 * somebody has said which project an account belongs to.
 */
final class ProjectIntegrationAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    // ── The filing decision ───────────────────────────────────────────────────────────────────

    /**
     * **The defect, pinned.** An unassigned account must not be filed into a project at all.
     *
     * Before the fix this returned the oldest project and filed the structure there.
     */
    public function test_an_unassigned_account_is_not_filed_into_some_arbitrary_project(): void
    {
        // Two projects. The older one is the one the old code would have picked, silently.
        $older = $this->project('Retainer — Client A', createdAt: '2026-01-01');
        $newer = $this->project('Retainer — Client B', createdAt: '2026-06-01');

        $account = $this->discoveredAccount('sandbox', 'act-unassigned');

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame(
            'awaiting_assignment',
            $run->status,
            'ASSIGN-PROJECT-001: a discovered account with no binding was filed into the tenant\'s '
                .'oldest project, which is a choice made by creation order rather than by anybody.',
        );

        $this->assertNull($run->project_id, 'an unassigned run belongs to no project');

        $this->assertSame(0, ExternalCampaign::withoutGlobalScopes()->count(), 'nothing may be filed');
        $this->assertNotSame($older->id, $run->project_id);
        $this->assertNotSame($newer->id, $run->project_id);
    }

    /** And the refusal names the real cause, so the operator knows what to do rather than retrying. */
    public function test_the_refusal_says_the_account_needs_a_project(): void
    {
        $this->project('Only project');
        $run = app(AccountStructureSyncer::class)->sync($this->discoveredAccount('sandbox', 'act-1'));

        $this->assertStringContainsString('project', mb_strtolower((string) $run->error));
        $this->assertStringContainsString('assign', mb_strtolower((string) $run->error));
    }

    /** Bound to the NEWER project, filed into the newer project — creation order is irrelevant. */
    public function test_a_bound_account_is_filed_into_the_project_it_was_bound_to(): void
    {
        $this->project('Older, and not the answer', createdAt: '2026-01-01');
        $chosen = $this->project('The one actually chosen', createdAt: '2026-06-01');

        $account = $this->discoveredAccount('sandbox', 'sbx-act-1');
        $this->bind($account, $chosen);

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame($chosen->id, $run->project_id);
        $this->assertNotSame('awaiting_assignment', $run->status);
    }

    /** An inactive binding is not an assignment — detaching stops the filing. */
    public function test_a_deactivated_binding_no_longer_files_anything(): void
    {
        $project = $this->project('P');
        $account = $this->discoveredAccount('sandbox', 'act-1');
        $binding = $this->bind($account, $project);

        $binding->forceFill(['is_active' => false])->save();

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame('awaiting_assignment', $run->status);
    }

    // ── The sweep ─────────────────────────────────────────────────────────────────────────────

    /**
     * SWEEP-UNASSIGNED-001 — the scheduled sweep queues assigned accounts only.
     *
     * The fixture is the live shape: many discovered accounts, one of them assigned. Before the fix
     * all of them were queued, which for the real connection is 309 jobs pulling 309 accounts.
     */
    public function test_the_metrics_sweep_queues_only_accounts_assigned_to_a_project(): void
    {
        Queue::fake();

        $project = $this->project('P');
        $assigned = $this->discoveredAccount('snapchat', 'act-assigned');
        $this->bind($assigned, $project);

        foreach (range(1, 12) as $i) {
            $this->discoveredAccount('snapchat', "act-discovered-{$i}");
        }

        $this->artisan('integrations:sync')->assertSuccessful();

        Queue::assertPushed(SyncAccountMetricsJob::class, 1);
    }

    /** The structure sweep answers to the same rule. */
    public function test_the_structure_sweep_queues_only_accounts_assigned_to_a_project(): void
    {
        Queue::fake();

        $project = $this->project('P');
        $assigned = $this->discoveredAccount('snapchat', 'act-assigned');
        $this->bind($assigned, $project);

        foreach (range(1, 8) as $i) {
            $this->discoveredAccount('snapchat', "act-discovered-{$i}");
        }

        $this->artisan('integrations:sync-structure')->assertSuccessful();

        Queue::assertPushed(SyncAccountStructureJob::class, 1);
    }

    /** With nothing assigned anywhere, a sweep queues nothing at all rather than everything. */
    public function test_a_sweep_with_no_assignments_anywhere_queues_nothing(): void
    {
        Queue::fake();

        $this->project('P');
        foreach (range(1, 20) as $i) {
            $this->discoveredAccount('snapchat', "act-{$i}");
        }

        $this->artisan('integrations:sync')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ── What discovery preserves ──────────────────────────────────────────────────────────────

    /**
     * The live connection's evidence must survive everything here.
     *
     * Discovered accounts, their external ids, and the connection behind them are untouched by a
     * refused sync — the refusal is a decision not to file, not a decision to forget.
     */
    public function test_a_refused_sync_preserves_every_discovered_account_and_its_external_id(): void
    {
        $this->project('P');

        $ids = [];
        foreach (range(1, 15) as $i) {
            $ids[] = $this->discoveredAccount('sandbox', "act-{$i}")->external_id;
        }

        foreach (ExternalAccount::withoutGlobalScopes()->get() as $account) {
            app(AccountStructureSyncer::class)->sync($account);
        }

        $this->assertSame(15, ExternalAccount::withoutGlobalScopes()->count());
        $this->assertEqualsCanonicalizing(
            $ids,
            ExternalAccount::withoutGlobalScopes()->pluck('external_id')->all(),
            'external ids must never be rewritten by a sync decision',
        );
        $this->assertSame(1, ProviderConnection::withoutGlobalScopes()->count(), 'the OAuth connection stands');
    }

    // ── Isolation ─────────────────────────────────────────────────────────────────────────────

    /** Project A's assignment does not file anything into project B. */
    public function test_binding_to_one_project_files_nothing_into_another(): void
    {
        $a = $this->project('Project A');
        $b = $this->project('Project B');

        $account = $this->discoveredAccount('sandbox', 'sbx-act-1');
        $this->bind($account, $a);

        $run = app(AccountStructureSyncer::class)->sync($account);

        $this->assertSame($a->id, $run->project_id);
        $this->assertSame(
            0,
            ExternalCampaign::withoutGlobalScopes()->where('project_id', $b->id)->count(),
            'project B receives nothing it was not assigned',
        );
    }

    /**
     * An agency's two client workspaces do not share a discovery.
     *
     * The account belongs to client workspace A; the project belongs to client workspace B. Same
     * tenant, so a tenant check alone lets this through — which is exactly why the client workspace
     * is checked as well.
     */
    public function test_an_account_cannot_be_assigned_to_another_client_workspaces_project(): void
    {
        $workspaceA = $this->workspace('Client A');
        $workspaceB = $this->workspace('Client B');

        $projectB = $this->project('B project', workspace: $workspaceB);
        $accountA = $this->discoveredAccount('snapchat', 'act-a', workspace: $workspaceA);

        app(ProjectContext::class)->setProjectId($projectB->id);

        $this->assertFalse(
            app(AccountAssignment::class)
                ->mayAssign($accountA, $projectB),
            'a client workspace\'s discovered account must not reach another client\'s project',
        );
    }

    /** A tenant's account is invisible to another tenant's assignment, by the tenant scope. */
    public function test_another_tenants_account_is_not_assignable(): void
    {
        $ours = $this->project('Ours');

        $theirs = Tenant::create(['name' => 'Theirs', 'slug' => 'th-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($theirs->id);
        $theirAccount = $this->discoveredAccount('snapchat', 'act-theirs', tenant: $theirs);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->assertFalse(
            app(AccountAssignment::class)->mayAssign($theirAccount, $ours),
        );
    }

    // ── Re-authorisation ──────────────────────────────────────────────────────────────────────

    /**
     * Re-authorising must not duplicate a valid assignment.
     *
     * Discovery upserts on `(connection, external_id, account_type)`, so the account row is the same
     * row — and the binding that points at it therefore survives untouched. Worth pinning, because
     * the live connection will be re-authorised eventually and losing 309 assignments to a token
     * refresh would be the worst possible way to find that out.
     */
    public function test_re_running_discovery_keeps_one_account_and_one_binding(): void
    {
        $project = $this->project('P');
        $account = $this->discoveredAccount('snapchat', 'act-1');
        $this->bind($account, $project);

        // The same account, discovered again — the upsert key discovery uses.
        ExternalAccount::withoutGlobalScopes()->updateOrCreate(
            [
                'provider_connection_id' => $account->provider_connection_id,
                'external_id' => 'act-1',
                'account_type' => 'ad_account',
            ],
            ['tenant_id' => $this->tenant->id, 'provider' => 'snapchat', 'name' => 'Renamed', 'status' => 'active'],
        );

        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->where('external_id', 'act-1')->count());
        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->count());
        $this->assertSame($project->id, ProjectIntegrationBinding::withoutGlobalScopes()->first()->project_id);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function workspace(string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(string $name, ?string $createdAt = null, ?ClientWorkspace $workspace = null): Project
    {
        // Every project belongs to a client workspace — the column is NOT NULL, which is what makes
        // the agency isolation case below a real constraint rather than a hypothetical one.
        $workspace ??= $this->workspace('Workspace for '.$name);

        $project = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'name' => $name,
            'status' => 'active',
        ]);

        if ($createdAt !== null) {
            $project->forceFill(['created_at' => $createdAt])->save();
        }

        return $project->refresh();
    }

    private ?ProviderConnection $connection = null;

    private function discoveredAccount(
        string $provider,
        string $externalId,
        ?ClientWorkspace $workspace = null,
        ?Tenant $tenant = null,
    ): ExternalAccount {
        $tenantId = ($tenant ?? $this->tenant)->id;

        if ($this->connection === null || $tenant !== null) {
            $credential = new IntegrationCredential([
                'provider' => $provider, 'credential_scope' => 'project_only',
                'credential_type' => 'oauth', 'status' => 'active',
            ]);
            $credential->setPayload('token');
            $credential->save();

            $connection = ProviderConnection::create([
                'tenant_id' => $tenantId, 'credential_id' => $credential->id, 'provider' => $provider,
                'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
            ]);

            if ($tenant === null) {
                $this->connection = $connection;
            }
        } else {
            $connection = $this->connection;
        }

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'client_workspace_id' => $workspace?->id,
            'provider_connection_id' => $connection->id,
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
        ]);
    }

    private function bind(ExternalAccount $account, Project $project): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $project->client_workspace_id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'purpose' => 'advertising',
            'is_active' => true,
            'sync_enabled' => true,
            'campaign_management_enabled' => true,
        ]);
    }
}
