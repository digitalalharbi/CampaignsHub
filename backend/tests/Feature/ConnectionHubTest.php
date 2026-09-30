<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
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
 * INTEGRATION-DATASOURCE-WIZARD-001 §17 — the hub's row is the AUTHORISATION, and it says «1 of 17».
 *
 * Two Meta authorisations granted by two different people are two rows, because they expire, fail
 * and get reconnected independently — the provider card that merged them could not say which of an
 * agency's two Meta logins had gone stale.
 *
 * And the sentence on the row is chosen-of-discovered. A count of discovered accounts alone invites
 * the belief that all of them are being read, which is the belief this programme has had to correct
 * twice on real data.
 */
final class ConnectionHubTest extends TestCase
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

        $this->user = User::create(['name' => 'Owner One', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
    }

    /** The Production shape: seventeen reachable, one chosen, and the row says both numbers. */
    public function test_a_row_states_how_many_accounts_were_chosen_out_of_how_many_exist(): void
    {
        $connection = $this->connection();
        $chosen = $this->account($connection, 'act_chosen');
        foreach (range(1, 16) as $i) {
            $this->account($connection, 'act_other_'.$i);
        }
        $this->bind($this->project('Razah'), $chosen);

        $row = $this->hub()['connections'][0];

        $this->assertSame(1, $row['selected_accounts']);
        $this->assertSame(17, $row['discovered_accounts']);
    }

    /** An account the provider has withdrawn is not one this authorisation can still reach. */
    public function test_an_account_whose_access_was_withdrawn_is_not_counted_as_reachable(): void
    {
        $connection = $this->connection();
        $this->account($connection, 'act_a');
        $this->account($connection, 'act_gone')->forceFill(['access_lost_at' => Carbon::now()])->save();

        $this->assertSame(1, $this->hub()['connections'][0]['discovered_accounts']);
    }

    /** Two authorisations for one provider are two rows, each with its own state. */
    public function test_two_authorisations_for_one_provider_are_two_rows(): void
    {
        $ours = $this->connection();
        $theirs = $this->connection();
        $theirs->forceFill(['insights_denied_at' => Carbon::now()])->save();

        $rows = $this->hub()['connections'];

        $this->assertCount(2, $rows);
        $states = array_column($rows, 'connection_state');
        sort($states);
        $this->assertSame([IntegrationTruth::CONNECTED, IntegrationTruth::REAUTH_REQUIRED], $states);
        $this->assertSame([(string) $ours->id, (string) $theirs->id], array_column($rows, 'id'));
    }

    /**
     * The pair that broke Production: a refused grant AND an open run, both reported.
     *
     * No surface may rank one of these out of existence — that ranking is what left a card saying
     * «المزامنة جارية الآن» above an action area with no Reconnect button.
     */
    public function test_a_refused_grant_and_an_open_run_are_both_on_the_row(): void
    {
        $connection = $this->connection();
        $account = $this->account($connection);
        $this->bind($this->project('Razah'), $account);
        $this->syncRun($account, SyncRunStatus::Running, Carbon::now()->subMinutes(3));
        $connection->forceFill(['insights_denied_at' => Carbon::now()])->save();

        $row = $this->hub()['connections'][0];

        $this->assertSame(IntegrationTruth::REAUTH_REQUIRED, $row['connection_state']);
        $this->assertSame(IntegrationTruth::SYNCING, $row['sync_state']);
    }

    /** ACCOUNT-SCOPE-ISOLATION-001 — a deselected account's run is not this connection's data state. */
    public function test_a_deselected_accounts_run_does_not_describe_the_connection(): void
    {
        $connection = $this->connection();
        $chosen = $this->account($connection, 'act_chosen');
        $this->bind($this->project('Razah'), $chosen);
        $this->syncRun($chosen, SyncRunStatus::Success, Carbon::now()->subHour());

        $ignored = $this->account($connection, 'act_ignored');
        $this->syncRun($ignored, SyncRunStatus::Failed, Carbon::now()->subMinute());

        $this->assertSame(IntegrationTruth::SUCCEEDED, $this->hub()['connections'][0]['sync_state']);
    }

    /** The row names the clients this authorisation feeds — the answer to «who breaks if it lapses». */
    public function test_a_row_names_the_clients_it_feeds_and_only_through_active_bindings(): void
    {
        $connection = $this->connection();
        $a = $this->account($connection, 'act_a');
        $b = $this->account($connection, 'act_b');
        $this->bind($this->project('Razah'), $a);
        $binding = $this->bind($this->project('Dropped'), $b);
        $binding->forceFill(['is_active' => false])->save();

        $row = $this->hub()['connections'][0];

        $this->assertSame(['Razah'], array_column($row['projects'], 'name'));
        $this->assertSame(1, $row['selected_accounts']);
    }

    /** Another tenant's authorisations are not this tenant's, whatever the bindings look like. */
    public function test_another_tenants_connections_are_invisible(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $this->account($this->connection($other), 'act_theirs', $other);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->assertSame([], $this->hub()['connections']);
    }

    /** Who authorised it, from the identity this product actually holds — never an invented email. */
    public function test_the_row_says_who_authorised_it(): void
    {
        $connection = $this->connection();
        $connection->forceFill(['created_by' => $this->user->id])->save();

        $this->assertSame('Owner One', $this->hub()['connections'][0]['authorised_by']['name']);
        $this->assertNull($this->hub()['connections'][1]['authorised_by'] ?? null);
    }

    /** @return array<string,mixed> */
    private function hub(): array
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/integrations/hub')
            ->assertOk()
            ->json('data');
    }

    private function connection(?Tenant $tenant = null): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
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

    private function project(string $name): Project
    {
        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'c-'.uniqid(), 'mode' => 'managed',
        ]);

        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => $name, 'status' => 'active',
        ]);
    }

    private function bind(Project $project, ExternalAccount $account): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'client_workspace_id' => $project->client_workspace_id,
            'external_account_id' => $account->id,
            'provider' => 'meta',
            'purpose' => 'advertising',
            'is_active' => true,
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
            'finished_at' => $status === SyncRunStatus::Running ? null : $startedAt->copy()->addMinute(),
            'metrics_upserted' => $status === SyncRunStatus::Success ? 5 : 0,
        ]);
    }
}
