<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * INTEG-PROJECT-SPACE-001 (Owner 2026-11-06) — «شاشة التكاملات هي مساحة المشروع المحدد».
 *
 * Stores and GA4 properties are part of a project's space like its ad accounts: asked for one project, the
 * store list carries that project's own and what nobody has taken yet, the property list carries that
 * project's own (INTEG-PROJECT-LINK-STORES-GA4-001: a property is chosen in the project's journey, not from
 * a catalogue) — never what another project reads. Unasked, both answer exactly as before.
 */
final class ProjectSpaceStoresAndPropertiesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private Project $a;

    private Project $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@ag.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $this->a = $this->project('Project A');
        $this->b = $this->project('Project B');

        foreach (PlatformCredentials::for('salla')->requires() as $key) {
            config()->set("commerce_platforms.platforms.salla.{$key}", "test-{$key}");
        }
    }

    public function test_the_stores_of_one_project_carry_nothing_of_another(): void
    {
        $connection = $this->connection('salla');
        $this->account($connection, 'store', 's-a', 'Alpha Store', $this->a, 'ecommerce');
        $this->account($connection, 'store', 's-b', 'Beta Store', $this->b, 'ecommerce');
        $this->account($connection, 'store', 's-free', 'Unclaimed Store', null, 'ecommerce');

        $forA = $this->actingAs($this->operator)->getJson('/api/v1/commerce/stores?project_id='.$this->a->id)->assertOk();
        $names = collect($forA->json('data'))->firstWhere('key', 'salla')['stores'];

        $this->assertEqualsCanonicalizing(['Alpha Store', 'Unclaimed Store'], array_column($names, 'name'));
        $this->assertStringNotContainsString('Beta Store', $forA->getContent());
        $this->assertStringNotContainsString('Project B', $forA->getContent());
        $this->assertStringNotContainsString((string) $this->b->id, $forA->getContent());

        // Unasked, the list is the one every older caller reads.
        $all = $this->actingAs($this->operator)->getJson('/api/v1/commerce/stores')->assertOk();
        $this->assertCount(3, collect($all->json('data'))->firstWhere('key', 'salla')['stores']);
    }

    public function test_the_properties_of_one_project_carry_nothing_of_another(): void
    {
        $connection = $this->connection('ga4');
        $this->account($connection, Ga4PropertyDiscovery::ACCOUNT_TYPE, '111', 'Alpha Site', $this->a, 'analytics');
        $this->account($connection, Ga4PropertyDiscovery::ACCOUNT_TYPE, '222', 'Beta Site', $this->b, 'analytics');
        $this->account($connection, Ga4PropertyDiscovery::ACCOUNT_TYPE, '333', 'Unchosen Site', null, 'analytics');

        $forA = $this->actingAs($this->operator)->getJson('/api/v1/measurement/properties?project_id='.$this->a->id)->assertOk();

        $this->assertStringContainsString('Alpha Site', $forA->getContent());
        // INTEG-PROJECT-LINK-STORES-GA4-001 — this read «and the ones nobody has taken yet». A property is chosen in
        // the project's own journey now, from what that sign-in can read, so a catalogue row nobody chose is not a
        // list to pick from any more: the space carries the property this project reads and nothing else.
        $this->assertStringNotContainsString('Unchosen Site', $forA->getContent());
        $this->assertSame(1, collect($forA->json('data'))->firstWhere('key', 'ga4')['selected_count'], 'another project\'s selection was counted');
        $this->assertStringNotContainsString('Beta Site', $forA->getContent());
        $this->assertStringNotContainsString((string) $this->b->id, $forA->getContent());

        $all = $this->actingAs($this->operator)->getJson('/api/v1/measurement/properties')->assertOk();
        $this->assertStringContainsString('Beta Site', $all->getContent());
    }

    public function test_a_project_of_another_tenant_or_none_is_not_found(): void
    {
        $rival = Tenant::create(['name' => 'Rival', 'slug' => 'rv-'.uniqid(), 'status' => 'active']);
        $theirs = $this->project('Their Project', $rival);

        foreach (['/api/v1/commerce/stores', '/api/v1/measurement/properties'] as $path) {
            $this->actingAs($this->operator)->getJson($path.'?project_id='.$theirs->id)->assertNotFound();
            $this->actingAs($this->operator)->getJson($path.'?project_id=00000000-0000-4000-8000-000000000000')->assertNotFound();
            $this->actingAs($this->operator)->getJson($path.'?project_id=nope')->assertStatus(422);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function project(string $name, ?Tenant $tenant = null): Project
    {
        $tenant ??= $this->tenant;
        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }

    private function connection(string $provider): ProviderConnection
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(14)), connectionName: $provider,
        );
    }

    private function account(ProviderConnection $connection, string $type, string $externalId, string $name, ?Project $project, string $purpose): ExternalAccount
    {
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project?->client_workspace_id,
            'provider_connection_id' => $connection->getKey(), 'provider' => $connection->provider,
            'account_type' => $type, 'external_id' => $externalId, 'name' => $name, 'currency' => 'SAR', 'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        if ($project !== null) {
            ProjectIntegrationBinding::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id,
                'project_id' => $project->id, 'external_account_id' => $account->id,
                'provider' => $connection->provider, 'purpose' => $purpose, 'is_active' => true,
            ]);
        }

        return $account;
    }
}
