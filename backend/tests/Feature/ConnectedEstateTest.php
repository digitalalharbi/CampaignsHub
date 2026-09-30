<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Support\IntegrationTruth;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §17 — the estate is what was CHOSEN, per client, and nothing else.
 *
 * The page these defend replaced a provider grid sitting above every account any authorisation had
 * ever discovered. The failure mode being pinned is not cosmetic: a discovered account appearing
 * under a client's name is this product claiming a stranger's spend belongs to that client.
 */
final class ConnectedEstateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
    }

    /**
     * DISCOVERED != SELECTED, on the surface a customer reads.
     *
     * Seventeen accounts behind one authorisation, one chosen. The client's row says one, and the
     * other sixteen are a NUMBER on the page rather than sixteen rows under somebody's name.
     */
    public function test_only_chosen_accounts_appear_under_a_client(): void
    {
        $connection = $this->connection();
        $chosen = $this->account($connection, 'act_chosen');
        foreach (range(1, 16) as $i) {
            $this->account($connection, 'act_other_'.$i);
        }

        $project = $this->project('Razah');
        $this->bind($project, $chosen);

        $body = $this->estate();

        $this->assertCount(1, $body['projects']);
        $this->assertSame('Razah', $body['projects'][0]['name']);
        $this->assertSame(1, $body['projects'][0]['accounts']);
        $this->assertSame(16, $body['unselected_accounts']);
    }

    /**
     * Deselecting removes the account from the client's row and puts it back among the unchosen.
     *
     * The binding row SURVIVES — historical attribution depends on it (RebindingRefilesTheCampaign) —
     * so «is it selected» is `is_active` and never «does a row exist». Reading it as existence is how
     * a deselected account keeps appearing under a client it no longer feeds.
     */
    public function test_a_deselected_account_leaves_the_clients_row_and_can_return(): void
    {
        $connection = $this->connection();
        $kept = $this->account($connection, 'act_kept');
        $dropped = $this->account($connection, 'act_dropped');
        $project = $this->project('Razah');
        $this->bind($project, $kept);
        $this->bind($project, $dropped);

        $this->assertSame(2, $this->estate()['projects'][0]['accounts']);

        $binding = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $dropped->id)->firstOrFail();
        $binding->forceFill(['is_active' => false])->save();

        $body = $this->estate();
        $this->assertSame(1, $body['projects'][0]['accounts']);
        $this->assertSame(1, $body['unselected_accounts']);

        $binding->forceFill(['is_active' => true])->save();
        $this->assertSame(2, $this->estate()['projects'][0]['accounts']);
    }

    /** Project A's row is built from project A's bindings. Project B's account is not in it. */
    public function test_a_project_cannot_see_another_projects_account(): void
    {
        $connection = $this->connection();
        $a = $this->project('A');
        $b = $this->project('B');
        $this->bind($a, $this->account($connection, 'act_a'));
        $this->bind($b, $this->account($connection, 'act_b1'));
        $this->bind($b, $this->account($connection, 'act_b2'));

        $rows = collect($this->estate()['projects'])->keyBy('name');

        $this->assertSame(1, $rows['A']['accounts']);
        $this->assertSame(2, $rows['B']['accounts']);
    }

    /** Another tenant's estate is not this tenant's, however the bindings are shaped. */
    public function test_another_tenants_estate_is_invisible(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $theirs = $this->connection($other);
        $this->bind($this->project('Theirs', $other), $this->account($theirs, 'act_theirs', $other), $other);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $body = $this->estate();

        $this->assertSame([], $body['projects']);
        $this->assertSame(0, $body['unselected_accounts']);
    }

    /** The two truths travel per provider, so a refused grant is visible on the client's own row. */
    public function test_a_refused_grant_shows_on_the_clients_row_as_reauth(): void
    {
        $connection = $this->connection();
        $account = $this->account($connection);
        $this->bind($this->project('Razah'), $account);
        $this->syncRun($account, SyncRunStatus::Failed, Carbon::now()->subMinutes(10));
        $connection->forceFill(['insights_denied_at' => Carbon::now()])->save();

        $provider = $this->estate()['projects'][0]['providers'][0];

        $this->assertSame(IntegrationTruth::REAUTH_REQUIRED, $provider['connection_state']);
        $this->assertSame(IntegrationTruth::FAILED, $provider['sync_state']);
        $this->assertSame('reauth', $provider['health']);
    }

    /** A working client reads as complete, with its real counts and its own currency. */
    public function test_a_working_client_reports_its_real_counts(): void
    {
        $connection = $this->connection();
        $account = $this->account($connection);
        $account->forceFill(['last_synced_at' => Carbon::now()->subMinutes(5), 'currency' => 'SAR'])->save();
        $project = $this->project('Razah');
        $this->bind($project, $account);
        $this->syncRun($account, SyncRunStatus::Success, Carbon::now()->subMinutes(6));

        $this->campaign($project, $account, 'ACTIVE');
        $this->campaign($project, $account, 'ACTIVE');
        $this->campaign($project, $account, 'PAUSED');

        $row = $this->estate()['projects'][0];

        $this->assertSame('complete', $row['health']);
        $this->assertSame(3, $row['campaigns']);
        $this->assertSame(2, $row['active_campaigns']);
        $this->assertSame(['SAR'], $row['providers'][0]['currencies']);
    }

    /** Worst first: the page opens on the client that needs somebody. */
    public function test_the_client_that_needs_attention_is_listed_first(): void
    {
        $healthy = $this->connection();
        $healthyAccount = $this->account($healthy, 'act_ok');
        $healthyAccount->forceFill(['last_synced_at' => Carbon::now()->subMinutes(5)])->save();
        $this->bind($this->project('AAA fine'), $healthyAccount);
        $this->syncRun($healthyAccount, SyncRunStatus::Success, Carbon::now()->subMinutes(6));

        $broken = $this->connection();
        $broken->forceFill(['insights_denied_at' => Carbon::now()])->save();
        $this->bind($this->project('ZZZ broken'), $this->account($broken, 'act_bad'));

        $names = array_column($this->estate()['projects'], 'name');

        $this->assertSame(['ZZZ broken', 'AAA fine'], $names);
    }

    /** Expanding a client's row asks the inventory that already exists, scoped to that client. */
    public function test_the_inventory_can_be_asked_for_one_projects_accounts(): void
    {
        $connection = $this->connection();
        $mine = $this->account($connection, 'act_mine');
        $theirs = $this->account($connection, 'act_theirs');
        $project = $this->project('Razah');
        $this->bind($project, $mine);
        $this->bind($this->project('Other'), $theirs);

        $rows = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/accounts?project='.$project->id)
            ->assertOk()
            ->json('data.accounts');

        $this->assertSame(['act_mine'], array_column($rows, 'reference'));
    }

    /** @return array<string,mixed> */
    private function estate(): array
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/integrations/estate')
            ->assertOk()
            ->json('data');
    }

    private function connection(?Tenant $tenant = null): ProviderConnection
    {
        $tenant ??= $this->tenant;

        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'credential_id' => $credential->id,
            'provider' => 'meta',
            'connection_name' => 'Meta',
            'scope' => 'project_only',
            'status' => 'connected',
            'scopes' => ['ads_read'],
        ]);
    }

    private function account(ProviderConnection $connection, string $externalId = 'act_1', ?Tenant $tenant = null): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'provider_connection_id' => $connection->id,
            'provider' => 'meta',
            'external_id' => $externalId,
            'name' => 'Account '.$externalId,
            'account_type' => 'ad_account',
            'status' => 'active',
        ]);
    }

    private function project(string $name, ?Tenant $tenant = null): Project
    {
        $tenant ??= $this->tenant;

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => 'c-'.uniqid(), 'mode' => 'managed',
        ]);

        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => $name, 'status' => 'active',
        ]);
    }

    private function bind(Project $project, ExternalAccount $account, ?Tenant $tenant = null): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'project_id' => $project->id,
            'client_workspace_id' => $project->client_workspace_id,
            'external_account_id' => $account->id,
            'provider' => 'meta',
            'purpose' => 'advertising',
            'is_active' => true,
        ]);
    }

    private function campaign(Project $project, ExternalAccount $account, string $status): void
    {
        ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'client_workspace_id' => $project->client_workspace_id,
            'external_account_id' => $account->id,
            'provider' => 'meta',
            'external_id' => 'c-'.uniqid(),
            'name' => 'Campaign',
            'status' => $status,
        ]);
    }

    private function syncRun(ExternalAccount $account, SyncRunStatus $status, Carbon $startedAt): void
    {
        MetricSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'external_account_id' => $account->id,
            'provider' => 'meta',
            'status' => $status->value,
            'window_start' => $startedAt->copy()->subDays(7)->toDateString(),
            'window_end' => $startedAt->copy()->toDateString(),
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addMinute(),
            'metrics_upserted' => $status === SyncRunStatus::Success ? 5 : 0,
        ]);
    }
}
