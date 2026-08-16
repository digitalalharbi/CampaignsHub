<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ORCH-100 §14 — a worker re-proves its scope from the database, and does not trust the payload.
 *
 * ## The window between enqueue and run
 *
 * A queued job carries an account id and a date window. Everything else about it — whether that
 * account still belongs to a project, whether the customer detached it, whether the connection was
 * revoked — was true when the job was created and may not be true when it runs. Queues are not
 * instantaneous and retries can be hours late.
 *
 * So the sweep filtering on assignment is necessary and not sufficient. The sweep decides what to
 * enqueue; the worker has to decide, again, whether the work is still authorised. Otherwise
 * detaching an account stops the NEXT sweep from queueing it and does nothing about the three jobs
 * already in the queue, which then fetch a customer's data after they asked us to stop.
 *
 * That is the case these tests hold: enqueue while assigned, detach, run — and nothing is fetched.
 */
final class SyncScopeReverificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);
    }

    // ── Metrics ───────────────────────────────────────────────────────────────────────────────

    /**
     * **The defect, pinned.** Detached between enqueue and run: nothing is fetched.
     *
     * Before the fix the job re-read the account, found it, and synced — because being assigned was
     * checked when the job was CREATED and never again.
     */
    public function test_a_metrics_job_for_a_detached_account_does_no_work(): void
    {
        $account = $this->assignedAccount();

        $job = new SyncAccountMetricsJob((string) $account->id, '2026-08-01', '2026-08-02', ['source' => 'test']);

        // The customer detaches while the job is queued.
        ProjectIntegrationBinding::withoutGlobalScopes()->update(['is_active' => false]);

        app()->call([$job, 'handle']);

        $this->assertSame(
            0,
            MetricSyncRun::withoutGlobalScopes()->count(),
            'ORCH-100 §14: the worker synced an account the customer had already detached, because '
                .'the assignment was checked when the job was queued and never re-proved.',
        );
    }

    /** An account still assigned when the job runs is synced, so the guard is not a blanket refusal. */
    public function test_a_metrics_job_for_an_assigned_account_still_runs(): void
    {
        $account = $this->assignedAccount();

        $job = new SyncAccountMetricsJob((string) $account->id, '2026-08-01', '2026-08-02', ['source' => 'test']);
        app()->call([$job, 'handle']);

        $this->assertGreaterThan(0, MetricSyncRun::withoutGlobalScopes()->count());
    }

    /** A revoked connection is not a source of data either, however the job got queued. */
    public function test_a_metrics_job_behind_a_revoked_connection_does_no_work(): void
    {
        $account = $this->assignedAccount();

        ProviderConnection::withoutGlobalScopes()
            ->whereKey($account->provider_connection_id)
            ->update(['status' => 'revoked']);

        $job = new SyncAccountMetricsJob((string) $account->id, '2026-08-01', '2026-08-02', ['source' => 'test']);
        app()->call([$job, 'handle']);

        $this->assertSame(0, MetricSyncRun::withoutGlobalScopes()->count());
    }

    // ── Structure ─────────────────────────────────────────────────────────────────────────────

    /** The structure job answers to the same rule. */
    public function test_a_structure_job_for_a_detached_account_does_no_work(): void
    {
        $account = $this->assignedAccount();

        $job = new SyncAccountStructureJob((string) $account->id, ['source' => 'test']);

        ProjectIntegrationBinding::withoutGlobalScopes()->update(['is_active' => false]);

        app()->call([$job, 'handle']);

        $this->assertSame(0, IntegrationSyncRun::withoutGlobalScopes()->count());
    }

    /** And still runs when the assignment stands. */
    public function test_a_structure_job_for_an_assigned_account_still_runs(): void
    {
        $account = $this->assignedAccount();

        $job = new SyncAccountStructureJob((string) $account->id, ['source' => 'test']);
        app()->call([$job, 'handle']);

        $this->assertGreaterThan(0, IntegrationSyncRun::withoutGlobalScopes()->count());
    }

    /**
     * An account that was never assigned at all is refused too.
     *
     * The 309 discovered Snapchat accounts are exactly this: real rows, real connection, and no
     * instruction from anybody to fetch their data.
     */
    public function test_a_job_for_a_never_assigned_account_does_no_work(): void
    {
        $account = $this->discoveredAccount();

        app()->call([new SyncAccountStructureJob((string) $account->id, ['source' => 'test']), 'handle']);
        app()->call([new SyncAccountMetricsJob((string) $account->id, '2026-08-01', '2026-08-02', []), 'handle']);

        $this->assertSame(0, IntegrationSyncRun::withoutGlobalScopes()->count());
        $this->assertSame(0, MetricSyncRun::withoutGlobalScopes()->count());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function discoveredAccount(): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'provider' => 'sandbox', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id,
            'provider' => 'sandbox', 'connection_name' => 'sandbox',
            'scope' => 'project_only', 'status' => 'connected',
        ]);

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => 'sandbox',
            'account_type' => 'ad_account',
            'external_id' => 'sandbox-act-1',
            'name' => 'Sandbox',
            'status' => 'active',
            'discovered_at' => now(),
        ]);
    }

    private function assignedAccount(): ExternalAccount
    {
        $account = $this->discoveredAccount();

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => 'sandbox',
            'purpose' => 'advertising',
            'is_active' => true,
        ]);

        return $account;
    }
}
