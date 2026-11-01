<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SYNC-GATE-001 — only an explicitly selected, authorised, active project–account binding may sync.
 *
 * The scenarios are the ones a large agency actually has: an organisation of hundreds of accounts of
 * which a couple are chosen, the same platform in several projects and several tenants, a binding
 * removed while its job is still queued, and a project that has been stopped. Each one is asserted
 * at the two places that decide: what the sweeps QUEUE and what a job DOES when it finally runs.
 */
final class SyncGateHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
    }

    // ── What the sweeps queue ────────────────────────────────────────────────────────────────────

    public function test_an_organisation_of_321_accounts_with_two_selected_queues_exactly_two_jobs_of_each_kind(): void
    {
        Queue::fake();
        Http::fake();

        $connection = $this->connection('snapchat', $this->tenant);
        $accounts = $this->accounts($connection, 321);
        $project = $this->project('P', $this->workspace('W'));
        $this->bind($accounts[10], $project);
        $this->bind($accounts[200], $project);

        $this->artisan('integrations:sync')->assertSuccessful();
        $this->artisan('integrations:sync-structure')->assertSuccessful();

        $expected = collect([$accounts[10]->id, $accounts[200]->id])->sort()->values()->all();
        $this->assertSame($expected, $this->pushed(SyncAccountMetricsJob::class));
        $this->assertSame($expected, $this->pushed(SyncAccountStructureJob::class));
        Http::assertNothingSent();
    }

    public function test_an_organisation_of_200_accounts_with_one_selected_queues_exactly_one_job_of_each_kind(): void
    {
        Queue::fake();

        $accounts = $this->accounts($this->connection('tiktok', $this->tenant), 200);
        $this->bind($accounts[199], $this->project('P', $this->workspace('W')));

        $this->artisan('integrations:sync')->assertSuccessful();
        $this->artisan('integrations:sync-structure')->assertSuccessful();

        $this->assertSame([$accounts[199]->id], $this->pushed(SyncAccountMetricsJob::class));
        $this->assertSame([$accounts[199]->id], $this->pushed(SyncAccountStructureJob::class));
    }

    /** The same platform in three projects of two workspaces and two tenants: each account, and only the bound ones, once. */
    public function test_the_same_platform_in_several_projects_and_tenants_queues_each_bound_account_once(): void
    {
        Queue::fake();

        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);

        $mine = $this->accounts($this->connection('meta', $this->tenant), 30);
        $theirs = $this->accounts($this->connection('meta', $otherTenant), 30);

        $w1 = $this->workspace('W1');
        $w2 = $this->workspace('W2');
        $bound = [$mine[0], $mine[1], $mine[2]];
        $this->bind($mine[0], $this->project('A', $w1));
        $this->bind($mine[1], $this->project('B', $w1));
        $this->bind($mine[2], $this->project('C', $w2));

        $theirProject = Project::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id,
            'client_workspace_id' => ClientWorkspace::withoutGlobalScopes()->create([
                'tenant_id' => $otherTenant->id, 'name' => 'T', 'slug' => 't-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
            ])->id,
            'name' => 'T', 'status' => 'active',
        ]);
        $this->bind($theirs[5], $theirProject);
        $bound[] = $theirs[5];

        $this->artisan('integrations:sync')->assertSuccessful();

        $this->assertSame(collect($bound)->map->id->sort()->values()->all(), $this->pushed(SyncAccountMetricsJob::class));
    }

    public function test_a_binding_switched_off_or_a_project_paused_archived_or_deleted_is_not_swept(): void
    {
        Queue::fake();

        $accounts = $this->accounts($this->connection('snapchat', $this->tenant), 6);
        $w = $this->workspace('W');

        $this->bind($accounts[0], $this->project('active', $w));
        $this->bind($accounts[1], $this->project('draft', $w, 'draft'));
        $this->bind($accounts[2], $this->project('paused', $w, 'paused'));
        $this->bind($accounts[3], $this->project('archived', $w, 'archived'));
        $deleted = $this->project('deleted', $w);
        $this->bind($accounts[4], $deleted);
        $deleted->delete();
        $this->bind($accounts[5], $this->project('switched off', $w), syncEnabled: false);

        $this->artisan('integrations:sync')->assertSuccessful();
        $this->artisan('integrations:sync-structure')->assertSuccessful();

        $expected = collect([$accounts[0]->id, $accounts[1]->id])->sort()->values()->all();
        $this->assertSame($expected, $this->pushed(SyncAccountMetricsJob::class));
        $this->assertSame($expected, $this->pushed(SyncAccountStructureJob::class));
    }

    // ── What a job does when it finally runs ─────────────────────────────────────────────────────

    /** Unbound while the job waits in the queue: it reaches no provider, on the first run or on any retry. */
    public function test_a_job_queued_before_the_unbinding_reaches_no_provider_on_any_attempt(): void
    {
        Http::fake();

        $account = $this->accounts($this->connection('snapchat', $this->tenant), 1)[0];
        $binding = $this->bind($account, $this->project('P', $this->workspace('W')));

        $metrics = new SyncAccountMetricsJob($account->id, '2026-10-25', '2026-11-01');
        $structure = new SyncAccountStructureJob($account->id);

        $binding->forceFill(['is_active' => false])->save();

        foreach ([1, 2, 3] as $attempt) {
            app()->call([$metrics, 'handle']);
            app()->call([$structure, 'handle']);
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('metric_sync_runs')->count());
        $this->assertSame(0, DB::table('daily_metrics')->count());
    }

    /** A project stopped while the job waits: same refusal. */
    public function test_a_job_for_a_project_paused_meanwhile_reaches_no_provider(): void
    {
        Http::fake();

        $account = $this->accounts($this->connection('snapchat', $this->tenant), 1)[0];
        $project = $this->project('P', $this->workspace('W'));
        $this->bind($account, $project);
        $job = new SyncAccountMetricsJob($account->id, '2026-10-25', '2026-11-01');

        $project->update(['status' => 'paused']);
        app()->call([$job, 'handle']);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('metric_sync_runs')->count());

        $project->update(['status' => 'active']);
        $this->assertTrue(app(AccountAssignment::class)->isActivelyAssigned($account->fresh()));
    }

    public function test_an_account_the_provider_reports_inactive_is_refused_by_the_job_gate_as_it_is_by_the_sweep(): void
    {
        $account = $this->accounts($this->connection('snapchat', $this->tenant), 1)[0];
        $this->bind($account, $this->project('P', $this->workspace('W')));
        $this->assertTrue(app(AccountAssignment::class)->isActivelyAssigned($account->fresh()));

        $account->forceFill(['status' => 'disabled'])->save();

        $this->assertFalse(app(AccountAssignment::class)->isActivelyAssigned($account->fresh()));
    }

    // ── Looking at the list creates nothing ──────────────────────────────────────────────────────

    public function test_browsing_the_available_accounts_queues_nothing_binds_nothing_and_calls_no_provider(): void
    {
        Queue::fake();
        Http::fake();

        $connection = $this->connection('snapchat', $this->tenant);
        $this->accounts($connection, 120);
        $bindings = ProjectIntegrationBinding::withoutGlobalScopes()->count();

        $get = fn (string $uri) => $this->actingAs($this->operator, 'sanctum')->withHeader('X-Tenant-ID', $this->tenant->id)->getJson($uri);

        $get("/api/v1/connections/{$connection->id}/accounts?per_page=25&page=2")->assertOk();
        $get("/api/v1/connections/{$connection->id}/accounts?q=Account%2012")->assertOk();
        $get("/api/v1/connections/{$connection->id}/hierarchy")->assertOk();
        $get('/api/v1/accounts')->assertOk();
        $get('/api/v1/integrations/hub')->assertOk();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame($bindings, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    public function test_an_account_the_credential_no_longer_lists_cannot_be_selected(): void
    {
        Queue::fake();

        $connection = $this->connection('snapchat', $this->tenant);
        [$listed, $lost] = $this->accounts($connection, 2);
        $lost->forceFill(['access_lost_at' => Carbon::now()])->save();
        $project = $this->project('P', $this->workspace('W'));

        $response = $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Project-Id', $project->id)
            ->postJson("/api/v1/projects/{$project->id}/integrations/bindings/batch", [
                'connection_id' => $connection->id,
                'external_account_ids' => [$listed->id, $lost->id],
                'purpose' => 'advertising',
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count(), 'a half-applied selection');
        Queue::assertNothingPushed();
    }

    /**
     * @param  class-string  $class
     * @return list<string> the account id of every pushed job of this class, sorted
     */
    private function pushed(string $class): array
    {
        return Queue::pushed($class)->map(fn ($job) => (string) $job->accountId)->sort()->values()->all();
    }

    private function connection(string $provider, Tenant $tenant): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $tenant->id, 'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);
    }

    /** @return list<ExternalAccount> */
    private function accounts(ProviderConnection $connection, int $count): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = ExternalAccount::withoutGlobalScopes()->create([
                'tenant_id' => $connection->tenant_id, 'provider_connection_id' => $connection->id, 'provider' => $connection->provider,
                'account_type' => 'ad_account', 'external_id' => "{$connection->provider}-{$connection->id}-{$i}", 'name' => "Account {$i}",
                'status' => 'active', 'discovered_at' => Carbon::now(),
            ]);
        }

        return $rows;
    }

    private function workspace(string $name): ClientWorkspace
    {
        return ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(string $name, ClientWorkspace $workspace, string $status = 'active'): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => $status,
        ]);
    }

    private function bind(ExternalAccount $account, Project $project, bool $syncEnabled = true): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $project->tenant_id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => $account->provider, 'purpose' => 'advertising',
            'is_active' => true, 'sync_enabled' => $syncEnabled, 'campaign_management_enabled' => true,
        ]);
    }
}
