<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Catalogue\ProviderHierarchy;
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
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * ORCH-100 §3 §4 §38 — choosing among 309 accounts, by organisation, without drawing 309 of them.
 *
 * The live Snapchat authorisation discovered 309 ad accounts across several organisations, and the
 * product offered no way to pick from them. These tests hold the shape of the step that was missing:
 * organisations first, accounts under the chosen organisation, searchable and paged — and every one
 * of them still inventory until somebody confirms.
 *
 * The parent step is provider-specific on purpose. Snapchat publishes organisations and Google Ads
 * publishes managers, so those two get the step; the other four do not publish a parent our discovery
 * captures, and inventing one would be a step asking a customer to choose something that does not
 * exist.
 */
final class ConnectionWizardHierarchyTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o@ag.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
    }

    // ── The capability model ──────────────────────────────────────────────────────────────────

    /** Only the providers that really publish a parent get the step. */
    public function test_the_parent_step_exists_only_where_the_provider_has_one(): void
    {
        $this->assertTrue(ProviderHierarchy::hasParent('snapchat'), 'Snapchat publishes organisations');
        $this->assertTrue(ProviderHierarchy::hasParent('google'), 'Google Ads publishes managers');

        foreach (['meta', 'tiktok', 'x', 'linkedin'] as $provider) {
            $this->assertFalse(
                ProviderHierarchy::hasParent($provider),
                "{$provider}: discovery captures no parent, and a step we cannot populate is invented",
            );
        }
    }

    /** The wizard's steps are the provider's real ones, so nobody clicks past an empty screen. */
    public function test_the_steps_collapse_for_a_provider_without_a_parent(): void
    {
        $this->assertSame(
            ['authorize', 'parent', 'accounts', 'project', 'review', 'sync'],
            ProviderHierarchy::steps('snapchat', agency: false),
        );

        $this->assertSame(
            ['authorize', 'accounts', 'project', 'review', 'sync'],
            ProviderHierarchy::steps('meta', agency: false),
        );

        // An agency is asked which client the authorisation belongs to first — a security boundary.
        $this->assertSame('client_workspace', ProviderHierarchy::steps('snapchat', agency: true)[0]);
    }

    // ── The hierarchy ─────────────────────────────────────────────────────────────────────────

    /**
     * The live shape, in miniature: organisations, each with a count, and «available» stated as
     * available rather than as connected.
     */
    public function test_the_hierarchy_lists_organisations_with_their_account_counts(): void
    {
        $connection = $this->connection('snapchat');

        $this->accountsUnder($connection, 'org-1', 'Acme Media', 40);
        $this->accountsUnder($connection, 'org-2', 'Beta Group', 9);

        $response = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/connections/{$connection->id}/hierarchy")
            ->assertOk();

        $response->assertJsonPath('data.has_parent', true);
        $response->assertJsonPath('data.parent_label.label', 'Organization');
        $response->assertJsonPath('data.discovered_count', 49);
        $response->assertJsonPath('data.assigned_count', 0);

        $parents = collect($response->json('data.parents'))->keyBy('external_id');
        $this->assertSame('Acme Media', $parents['org-1']['name']);
        $this->assertSame(40, $parents['org-1']['account_count']);
        $this->assertSame(9, $parents['org-2']['account_count']);
    }

    /** A provider with no parent says so, and offers no parents to choose between. */
    public function test_a_provider_without_a_parent_returns_no_parents(): void
    {
        $connection = $this->connection('meta');
        $this->accountsUnder($connection, null, null, 3);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/connections/{$connection->id}/hierarchy")
            ->assertOk()
            ->assertJsonPath('data.has_parent', false)
            ->assertJsonCount(0, 'data.parents')
            ->assertJsonPath('data.discovered_count', 3);
    }

    // ── The account page ──────────────────────────────────────────────────────────────────────

    /**
     * ORCH-100 §38 — 309 accounts are never returned at once.
     *
     * The page is bounded by the request, not by the size of the authorisation.
     */
    public function test_accounts_are_paged_rather_than_returned_all_at_once(): void
    {
        $connection = $this->connection('snapchat');
        $this->accountsUnder($connection, 'org-1', 'Acme Media', 309);

        $response = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/connections/{$connection->id}/accounts?per_page=25")
            ->assertOk();

        $this->assertCount(25, $response->json('data.accounts'), 'one page, not the whole inventory');
        $response->assertJsonPath('data.meta.total', 309);
        $response->assertJsonPath('data.meta.last_page', 13);
    }

    /** Accounts are read under the chosen organisation, not across all of them. */
    public function test_accounts_are_narrowed_to_the_chosen_parent(): void
    {
        $connection = $this->connection('snapchat');
        $this->accountsUnder($connection, 'org-1', 'Acme Media', 5);
        $this->accountsUnder($connection, 'org-2', 'Beta Group', 7);

        $response = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/connections/{$connection->id}/accounts?parent=org-2&per_page=100")
            ->assertOk();

        $this->assertCount(7, $response->json('data.accounts'));

        foreach ($response->json('data.accounts') as $account) {
            $this->assertSame('org-2', $account['parent_external_id']);
        }
    }

    /** Search reaches both the name and the id, because people have only one of the two to hand. */
    public function test_search_matches_the_name_and_the_external_id(): void
    {
        $connection = $this->connection('snapchat');
        $this->accountsUnder($connection, 'org-1', 'Acme Media', 3);

        $needle = ExternalAccount::withoutGlobalScopes()->first();
        $needle->forceFill(['name' => 'Riyadh Retail Winter'])->save();

        $byName = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/connections/{$connection->id}/accounts?q=riyadh")->assertOk();
        $this->assertCount(1, $byName->json('data.accounts'));

        $byId = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/connections/{$connection->id}/accounts?q={$needle->external_id}")->assertOk();
        $this->assertCount(1, $byId->json('data.accounts'));
    }

    /** A discovered account says it is not connected, and does not claim a sync it never had. */
    public function test_a_discovered_account_reads_as_available_not_connected(): void
    {
        $connection = $this->connection('snapchat');
        $this->accountsUnder($connection, 'org-1', 'Acme Media', 1);

        $account = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/connections/{$connection->id}/accounts")
            ->assertOk()
            ->json('data.accounts.0');

        $this->assertFalse($account['assigned']);
        $this->assertNull($account['assigned_project_id']);
        $this->assertNull($account['last_synced_at'], 'discovery is not a sync');
    }

    /** Once connected, the same account says which project it feeds. */
    public function test_an_assigned_account_names_its_project(): void
    {
        $connection = $this->connection('snapchat');
        $this->accountsUnder($connection, 'org-1', 'Acme Media', 1);

        $project = $this->project('P');
        $account = ExternalAccount::withoutGlobalScopes()->first();

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $project->client_workspace_id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => 'snapchat',
            'purpose' => 'advertising',
            'is_active' => true,
        ]);

        $row = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/connections/{$connection->id}/accounts")
            ->assertOk()->json('data.accounts.0');

        $this->assertTrue($row['assigned']);
        $this->assertSame($project->id, $row['assigned_project_id']);
    }

    // ── Isolation ─────────────────────────────────────────────────────────────────────────────

    /** Another tenant's connection is not readable, and the refusal says nothing about whose it is. */
    public function test_another_tenants_connection_is_not_readable(): void
    {
        $theirs = Tenant::create(['name' => 'Theirs', 'slug' => 'th-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($theirs->id);
        $theirConnection = $this->connection('snapchat', $theirs);
        $this->accountsUnder($theirConnection, 'org-x', 'Theirs', 4, $theirs);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/connections/{$theirConnection->id}/hierarchy")
            ->assertNotFound();

        $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/connections/{$theirConnection->id}/accounts")
            ->assertNotFound();
    }

    /** Plan usage is answerable before anything is bound, which is what the review step needs. */
    public function test_plan_usage_is_readable_before_any_binding_exists(): void
    {
        $connection = $this->connection('snapchat');
        $this->accountsUnder($connection, 'org-1', 'Acme Media', 309);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/plan-usage')
            ->assertOk()
            ->assertJsonPath('data.ad_accounts.used', 0);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function project(string $name): Project
    {
        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        return Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => $name, 'status' => 'active',
        ]);
    }

    private function connection(string $provider, ?Tenant $tenant = null): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider,
            'scope' => 'project_only',
            'status' => 'connected',
        ]);
    }

    private function accountsUnder(
        ProviderConnection $connection,
        ?string $parentId,
        ?string $parentName,
        int $many,
        ?Tenant $tenant = null,
    ): void {
        foreach (range(1, $many) as $i) {
            ExternalAccount::withoutGlobalScopes()->create([
                'tenant_id' => ($tenant ?? $this->tenant)->id,
                'provider_connection_id' => $connection->id,
                'provider' => $connection->provider,
                'account_type' => 'ad_account',
                'external_id' => ($parentId ?? 'flat')."-act-{$i}",
                'parent_external_id' => $parentId,
                'parent_name' => $parentName,
                'name' => ($parentName ?? 'Account')." {$i}",
                'currency' => 'SAR',
                'timezone' => 'Asia/Riyadh',
                'status' => 'active',
                'discovered_at' => now(),
                'last_synced_at' => null,
            ]);
        }
    }
}
