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
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ACCOUNT-COUNTS-001 — «discovered», «selected» and «connected» are one definition, said the same way
 * on the hub card and in the wizard.
 *
 * Production, 2026-11-01: the wizard counted every row ever catalogued, the hub card did not count the
 * accounts the provider had withdrawn, and the wizard's «connected» was the TENANT's count across every
 * provider. One connection read three different numbers depending on where it was looked at.
 *
 * The definition: DISCOVERED is what the authorisation can reach now plus whatever is already selected
 * (so a selection is always part of what it was chosen from); SELECTED is an active binding to a project
 * on THIS connection.
 */
final class AccountCountsOneDefinitionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ProviderConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);

        $this->connection = $this->connection('snapchat');
        $other = $this->connection('tiktok');

        // This connection: two reachable and unselected, one withdrawn and unselected, one selected, one selected AND withdrawn.
        $this->account($this->connection, 'a1');
        $this->account($this->connection, 'a2');
        $this->account($this->connection, 'lost-unselected', lost: true);
        $this->bind($this->account($this->connection, 'selected'), $project, $workspace);
        $this->bind($this->account($this->connection, 'selected-lost', lost: true), $project, $workspace);

        // Another provider's selections in the same tenant must not appear in this connection's numbers.
        foreach (['t1', 't2', 't3'] as $id) {
            $this->bind($this->account($other, $id), $project, $workspace);
        }
    }

    public function test_the_hub_card_and_the_wizard_say_the_same_numbers(): void
    {
        $get = fn (string $uri) => $this->actingAs($this->operator, 'sanctum')->withHeader('X-Tenant-ID', $this->tenant->id)->getJson($uri);

        $wizard = $get("/api/v1/connections/{$this->connection->id}/hierarchy")->assertOk()->json('data');
        $hub = collect($get('/api/v1/integrations/hub')->assertOk()->json('data.connections') ?? $get('/api/v1/integrations/hub')->json('data'))
            ->firstWhere('id', (string) $this->connection->id);

        $this->assertNotNull($hub, 'the hub does not list the connection');

        // Reachable now (a1, a2, selected) plus the selected one the provider withdrew; the withdrawn unselected one is not offered.
        $this->assertSame(4, $wizard['discovered_count']);
        $this->assertSame(4, $hub['discovered_accounts']);

        // Selected on THIS connection only: not the tenant's five.
        $this->assertSame(2, $wizard['assigned_count']);
        $this->assertSame(2, $hub['selected_accounts']);
        $this->assertLessThanOrEqual($wizard['discovered_count'], $wizard['assigned_count']);
    }

    private function connection(string $provider): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id, 'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);
    }

    private function account(ProviderConnection $connection, string $id, bool $lost = false): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $connection->provider,
            'account_type' => 'ad_account', 'external_id' => "{$connection->provider}-{$id}", 'name' => $id, 'status' => 'active',
            'discovered_at' => Carbon::now(), 'access_lost_at' => $lost ? Carbon::now() : null,
        ]);
    }

    private function bind(ExternalAccount $account, Project $project, ClientWorkspace $workspace): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => $account->provider, 'purpose' => 'advertising', 'is_active' => true,
        ]);
    }
}
