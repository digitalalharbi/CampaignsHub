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
 * INTEGRATIONS-VS-PROJECTS-IA-001 — a project shows what was LINKED to it, never what was discovered.
 *
 * ## The production screen this is written from
 *
 * «تكاملات المشروع» listed dozens of Snapchat accounts and offered sync buttons for them, on a
 * project that had been given one. The cause is a single line:
 *
 * ```php
 * $accounts = ExternalAccount::query()->get()->groupBy('provider');
 * ```
 *
 * `ExternalAccount` carries `BelongsToTenant`, not `BelongsToProject`. Tenant-scoped is correct for
 * that model — a discovered account genuinely belongs to the tenant and to no project until somebody
 * says otherwise — and reading it unqualified on a PROJECT screen turns the tenant's whole inventory
 * into that project's contents. With the live connection that is 309 accounts on a page about one.
 *
 * ## The rule the two surfaces divide on
 *
 * **Integrations** is where sources are managed: authorise, discover, choose, add, remove, reconnect.
 * Inventory belongs there, because choosing from it is the whole point.
 *
 * **A project** is the result of those choices. Its integrations screen answers «what feeds this
 * project, and is it working» — so it starts from `ProjectIntegrationBinding`, and an account nobody
 * assigned is not merely filtered out of the list, it is not part of the question.
 */
final class ProjectShowsOnlyAssignedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ClientWorkspace $workspace;

    private Project $projectA;

    private Project $projectB;

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

        $this->workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->projectA = $this->project('A');
        $this->projectB = $this->project('B');
        $this->connection = $this->connection('snapchat');

        app(TenantContext::class)->forget();
    }

    /**
     * **The defect, pinned, at the live scale.** 309 discovered, one assigned — the project shows one.
     */
    public function test_a_project_shows_only_the_account_assigned_to_it(): void
    {
        $accounts = $this->discoverMany(309);
        $this->assign($accounts[0], $this->projectA);

        $shown = $this->accountsOn($this->projectA);

        $this->assertCount(
            1,
            $shown,
            'INTEGRATIONS-VS-PROJECTS-IA-001: the project screen listed every account the TENANT had '
                .'ever discovered — 309 of them on a page about one.',
        );
        $this->assertSame((string) $accounts[0]->id, $shown[0]['id']);
    }

    /** And the other project shows none of them, because none was given to it. */
    public function test_a_project_with_no_assignment_shows_nothing(): void
    {
        $accounts = $this->discoverMany(309);
        $this->assign($accounts[0], $this->projectA);

        $this->assertSame([], $this->accountsOn($this->projectB));
    }

    /** Detaching removes it from the project's screen without deleting anything. */
    public function test_detaching_removes_it_from_the_project_screen(): void
    {
        $accounts = $this->discoverMany(3);
        $this->assign($accounts[0], $this->projectA);

        $this->assertCount(1, $this->accountsOn($this->projectA));

        ProjectIntegrationBinding::withoutGlobalScopes()->update(['is_active' => false]);

        $this->assertSame([], $this->accountsOn($this->projectA));
        $this->assertSame(3, ExternalAccount::withoutGlobalScopes()->count(), 'nothing is deleted');
    }

    /**
     * The row carries what the operator needs to act, by NAME.
     *
     * An identifier where a name belongs is the same defect as the organisation list showing UUIDs:
     * it claims the provider called it that.
     */
    public function test_each_row_names_the_account_and_its_parent(): void
    {
        $accounts = $this->discoverMany(2);
        $this->assign($accounts[0], $this->projectA);

        $row = $this->accountsOn($this->projectA)[0];

        $this->assertSame('Riyadh Retail 0', $row['name']);
        $this->assertSame('act-0', $row['external_id']);
        $this->assertSame('Acme Media', $row['parent_name']);
        $this->assertArrayHasKey('health', $row, 'the operator has to be able to see it is working');
        $this->assertArrayHasKey('next_sync_at', $row);
    }

    /** A store assigned to the project appears beside the ad accounts, as a linked source. */
    public function test_an_assigned_store_appears_too(): void
    {
        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->connection('salla')->id,
            'provider' => 'salla',
            'account_type' => 'store',
            'external_id' => 'store-1',
            'name' => 'المتجر',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
        $this->assign($store, $this->projectA, 'ecommerce');

        $providers = collect($this->accountsOn($this->projectA))->pluck('provider')->all();

        $this->assertSame(['salla'], $providers);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function accountsOn(Project $project): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Project-Id', $project->id)
            ->getJson("/api/v1/projects/{$project->id}/integrations/platforms")
            ->assertOk()
            ->json('data.accounts') ?? [];
    }

    private function project(string $name): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function connection(string $provider): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id,
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider.'-'.uniqid(),
            'scope' => 'project_only',
            'status' => 'connected',
        ]);
    }

    /**
     * The live shape: one authorisation, many accounts, none of them chosen yet.
     *
     * @return list<ExternalAccount>
     */
    private function discoverMany(int $count): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = ExternalAccount::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'provider_connection_id' => $this->connection->id,
                'provider' => 'snapchat',
                'account_type' => 'ad_account',
                'external_id' => "act-{$i}",
                'name' => "Riyadh Retail {$i}",
                'parent_external_id' => 'org-1',
                'parent_name' => 'Acme Media',
                'status' => 'active',
                'timezone' => 'Asia/Riyadh',
                'discovered_at' => Carbon::now(),
            ]);
        }

        return $rows;
    }

    private function assign(ExternalAccount $account, Project $project, string $purpose = 'advertising'): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'purpose' => $purpose,
            'is_active' => true,
        ]);
    }
}
