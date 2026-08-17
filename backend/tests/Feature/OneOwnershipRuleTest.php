<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\StoreSyncer;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * RUNTIME-100 — ONE rule for who owns synced data, and no second answer anywhere.
 *
 * ## Three copies of one method, fixed one at a time
 *
 * `projectIdFor()` existed three times, each ending in some variation of «and otherwise, the tenant's
 * oldest project»:
 *
 * | Where | Fixed in | How it presented |
 * |---|---|---|
 * | `AccountStructureSyncer` | `fdff1fc` (ORCH-100) | 309 discovered accounts' campaigns into one project nobody chose |
 * | `AccountMetricsSyncer` | RUNTIME-100 PR B | one agency client's sync history visible in another's |
 * | `StoreSyncer` | RUNTIME-100 PR B | one client's Salla/Zid revenue in another client's funnel |
 *
 * Each fix was correct and each left the others standing, which is the entire lesson: a rule written
 * in three places is a rule that will be right in one of them. There is now a single source —
 * `AccountAssignment` — and this suite holds the INVARIANT rather than any one of its callers, so a
 * fourth copy cannot be introduced quietly.
 *
 * ## Including the fallbacks that looked harmless
 *
 * Both ad-platform syncers kept an «existing campaign wins» clause, justified as «a re-sync never
 * re-files work already placed». That reasoning is about DISPLAY and it was applied to WRITES: it
 * could only fire for an account the worker had already refused, while leaving a second route by
 * which data could enter a project nobody assigned it to. Removed. Nothing already filed is moved or
 * deleted — it simply stops receiving new writes, which is what detaching means.
 */
final class OneOwnershipRuleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $clientA;

    private ClientWorkspace $clientB;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        // A first, deliberately: it is what every «oldest project» fallback would have chosen.
        $this->clientA = $this->workspace('Client A');
        $this->clientB = $this->workspace('Client B');
        $this->projectA = $this->project('A — retainer', $this->clientA, '2026-01-01');
        $this->projectB = $this->project('B — retainer', $this->clientB, '2026-06-01');
    }

    // ── The invariant ─────────────────────────────────────────────────────────────────────────

    /** Structure and metrics agree, because they ask the same question of the same table. */
    public function test_structure_and_metrics_file_into_the_same_project(): void
    {
        $account = $this->assignedAdAccount($this->projectB, $this->clientB);

        $structure = app(AccountStructureSyncer::class)->sync($account);
        $metrics = app(AccountMetricsSyncer::class)->sync(
            $account->refresh(),
            Carbon::now()->subDays(30),
            Carbon::now(),
        );

        $this->assertSame($this->projectB->id, $structure->project_id);
        $this->assertSame(
            $structure->project_id,
            $metrics->project_id,
            'two syncers reading two different rules is how one client\'s data reaches another\'s surfaces',
        );
    }

    /**
     * **The fallback, gone.** An account detached AFTER its campaigns were filed writes nothing more.
     *
     * This is the case the «existing campaign wins» clause existed for, and the case that made it
     * dangerous: the campaigns are already in project B, so the clause would happily keep writing
     * there for an account the customer has told us to stop reading.
     */
    public function test_a_detached_account_with_campaigns_already_filed_writes_nothing_further(): void
    {
        $account = $this->assignedAdAccount($this->projectB, $this->clientB);
        app(AccountStructureSyncer::class)->sync($account);

        $filedBefore = ExternalCampaign::withoutGlobalScopes()->count();
        $this->assertGreaterThan(0, $filedBefore, 'the fixture must actually file something first');

        ProjectIntegrationBinding::withoutGlobalScopes()->update(['is_active' => false]);

        $run = app(AccountStructureSyncer::class)->sync($account->refresh());

        $this->assertSame('awaiting_assignment', $run->status);
        $this->assertNull($run->project_id);
        $this->assertSame(
            $filedBefore,
            ExternalCampaign::withoutGlobalScopes()->count(),
            'detaching stops new writes; it does not delete or move what is already filed',
        );
    }

    /** And the metrics side answers identically. */
    public function test_a_detached_account_writes_no_further_metrics(): void
    {
        $account = $this->assignedAdAccount($this->projectB, $this->clientB);
        app(AccountStructureSyncer::class)->sync($account);
        app(AccountMetricsSyncer::class)->sync($account->refresh(), Carbon::now()->subDays(30), Carbon::now());

        $before = DailyMetric::withoutGlobalScopes()->count();
        ProjectIntegrationBinding::withoutGlobalScopes()->update(['is_active' => false]);

        $run = app(AccountMetricsSyncer::class)->sync($account->refresh(), Carbon::now()->subDays(30), Carbon::now());

        $this->assertSame('awaiting_assignment', $run->status);
        $this->assertSame($before, DailyMetric::withoutGlobalScopes()->count());
    }

    /** An account nobody ever assigned imports neither structure nor metrics. */
    public function test_an_unassigned_account_imports_nothing_at_all(): void
    {
        $account = $this->discovered('sandbox', 'ad_account', 'act-loose');

        $structure = app(AccountStructureSyncer::class)->sync($account);
        $metrics = app(AccountMetricsSyncer::class)->sync($account, Carbon::now()->subDays(30), Carbon::now());

        $this->assertSame('awaiting_assignment', $structure->status);
        $this->assertSame('awaiting_assignment', $metrics->status);
        $this->assertSame(0, ExternalCampaign::withoutGlobalScopes()->count());
        $this->assertSame(0, DailyMetric::withoutGlobalScopes()->count());
    }

    // ── Commerce, the third copy ──────────────────────────────────────────────────────────────

    /**
     * **The defect, pinned.** A store nobody assigned files nothing into anybody's project.
     *
     * Before the fix `StoreSyncer::projectIdFor()` returned the tenant's oldest project, so a Salla or
     * Zid store's orders and revenue landed in client A's funnel and stayed there — the next sweep
     * found orders already filed and «correctly» kept filing to the same place.
     */
    public function test_an_unassigned_store_files_nothing(): void
    {
        $store = $this->discovered('salla', 'store', 'store-1');

        $run = app(StoreSyncer::class)->sync($store, Carbon::now()->subDays(30), Carbon::now());

        $this->assertSame(
            'awaiting_assignment',
            $run->status,
            'COMMERCE-PROJECT-001: a store\'s revenue was filed into the tenant\'s oldest project, '
                .'which for an agency is another client\'s funnel.',
        );
        $this->assertNull($run->project_id);
        $this->assertNotSame($this->projectA->id, $run->project_id);
        $this->assertSame(0, CommerceOrder::withoutGlobalScopes()->count());
    }

    /** An assigned store files under the project it was assigned to — the same table, the same rule. */
    public function test_an_assigned_store_files_under_its_own_project(): void
    {
        $store = $this->discovered('salla', 'store', 'store-1');
        $this->bind($store, $this->projectB, $this->clientB, 'ecommerce');

        $run = app(StoreSyncer::class)->sync($store, Carbon::now()->subDays(30), Carbon::now());

        $this->assertSame($this->projectB->id, $run->project_id);
    }

    // ── The worker gate ───────────────────────────────────────────────────────────────────────

    /** A binding whose project has been deleted is a leftover, not an authorisation. */
    public function test_an_account_whose_project_no_longer_exists_is_not_authorised(): void
    {
        $account = $this->assignedAdAccount($this->projectB, $this->clientB);
        $this->assertTrue(app(AccountAssignment::class)->isActivelyAssigned($account));

        Project::withoutGlobalScopes()->whereKey($this->projectB->id)->forceDelete();

        $this->assertFalse(
            app(AccountAssignment::class)->isActivelyAssigned($account->refresh()),
            'RUNTIME-100 §29: a queued job can outlive the project it names, and a binding pointing '
                .'at a project that is gone must not still read as consent.',
        );
    }

    /**
     * A binding that crosses the client-workspace fence is refused at RUN time, not only at bind time.
     *
     * The fence is applied when the binding is created. This asks it again where it matters — a
     * client-scoped account can be moved between projects, and the row that authorised it was written
     * against a world that may have changed since.
     */
    public function test_a_client_scoped_account_pointing_at_another_clients_project_is_not_authorised(): void
    {
        $account = $this->assignedAdAccount($this->projectB, $this->clientB);

        // The account belongs to client A; its binding names client B's project.
        $account->forceFill(['client_workspace_id' => $this->clientA->id])->save();

        $this->assertFalse(app(AccountAssignment::class)->isActivelyAssigned($account->refresh()));
    }

    /** A tenant-level account — no client workspace — may still serve its tenant's projects. */
    public function test_a_tenant_level_account_remains_authorised(): void
    {
        $account = $this->assignedAdAccount($this->projectB, $this->clientB);
        $account->forceFill(['client_workspace_id' => null])->save();

        $this->assertTrue(app(AccountAssignment::class)->isActivelyAssigned($account->refresh()));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function workspace(string $name): ClientWorkspace
    {
        return ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(string $name, ClientWorkspace $workspace, string $createdAt): Project
    {
        $project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'name' => $name,
            'status' => 'active',
        ]);
        $project->forceFill(['created_at' => Carbon::parse($createdAt)])->save();

        return $project;
    }

    private function discovered(string $provider, string $accountType, string $externalId): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id,
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider,
            'scope' => 'project_only',
            'status' => 'connected',
        ]);

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => $provider,
            'account_type' => $accountType,
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'discovered_at' => Carbon::now(),
            'last_synced_at' => null,
        ]);
    }

    private function assignedAdAccount(Project $project, ClientWorkspace $workspace): ExternalAccount
    {
        $account = $this->discovered('sandbox', 'ad_account', 'sandbox-act-1');
        $account->forceFill(['client_workspace_id' => $workspace->id])->save();
        $this->bind($account, $project, $workspace);

        return $account->refresh();
    }

    private function bind(
        ExternalAccount $account,
        Project $project,
        ClientWorkspace $workspace,
        string $purpose = 'advertising',
    ): void {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'purpose' => $purpose,
            'is_active' => true,
        ]);
    }
}
