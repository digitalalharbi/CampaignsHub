<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SEC-3 — a project's store funnel shows only the stores the project SELECTS, and names no other project's.
 *
 * The ad half of the funnel already obeyed BoundAccountVisibility; the commerce half read `project_id` alone, so a
 * store detached from (or moved away from) a project went on feeding its revenue, orders, carts and customers.
 */
final class CommerceReadIsolationTest extends TestCase
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

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $ca = ClientWorkspace::create(['name' => 'Client A', 'slug' => 'ca-'.uniqid(), 'mode' => 'managed']);
        $cb = ClientWorkspace::create(['name' => 'Client B', 'slug' => 'cb-'.uniqid(), 'mode' => 'managed']);
        $this->a = Project::create(['client_workspace_id' => $ca->id, 'name' => 'A', 'status' => 'active']);
        $this->b = Project::create(['client_workspace_id' => $cb->id, 'name' => 'B', 'status' => 'active']);
        app(TenantContext::class)->forget();
    }

    public function test_a_store_bound_to_the_project_feeds_its_funnel(): void
    {
        $store = $this->store('s-1');
        $this->bind($store, $this->a);
        $this->order($store, $this->a, 'o1', 300);

        $this->assertSame(300.0, $this->revenue($this->funnel($this->a)));
    }

    public function test_orders_of_a_detached_store_leave_the_funnel_and_come_back_when_it_is_reselected(): void
    {
        $store = $this->store('s-1');
        $binding = $this->bind($store, $this->a);
        $this->order($store, $this->a, 'o1', 300);
        $this->cart($store, $this->a);

        $binding->forceFill(['is_active' => false])->save();

        $detached = $this->funnel($this->a);
        $this->assertNull($this->revenue($detached), 'a deselected store\'s revenue is not this project\'s figure');
        $this->assertSame(0, $detached->json('data.coverage.stores'));
        $this->assertNotContains('Salla', array_column($detached->json('data.coverage.stores_pending_first_sync') ?? [], 'name'));
        $this->assertSame(0.0, (float) (collect($detached->json('data.stages'))->keyBy('key')['add_to_cart']['value'] ?? 0), 'no order and no cart of a deselected store');

        $binding->forceFill(['is_active' => true])->save();
        $this->assertSame(300.0, $this->revenue($this->funnel($this->a)), 'history is kept, not deleted');
    }

    public function test_orders_of_a_store_moved_to_another_project_stay_out_of_the_old_one(): void
    {
        $store = $this->store('s-1');
        $this->bind($store, $this->a)->forceFill(['is_active' => false])->save();
        $this->bind($store, $this->b);
        $this->order($store, $this->a, 'o-old', 300);

        $this->assertNull($this->revenue($this->funnel($this->a)));
    }

    public function test_a_project_with_no_rows_yet_is_not_told_the_names_of_other_projects_stores(): void
    {
        $theirs = $this->store('theirs', 'Client B shop');
        $this->bind($theirs, $this->b);
        $mine = $this->store('mine', 'My shop');
        $this->bind($mine, $this->a);

        $pending = $this->funnel($this->a)->json('data.coverage.stores_pending_first_sync');

        $this->assertSame(['My shop'], array_column($pending, 'name'));
        $this->assertStringNotContainsString('Client B shop', (string) json_encode($this->funnel($this->a)->json()));
    }

    public function test_an_unassigned_store_is_named_to_no_project(): void
    {
        $this->store('orphan', 'Orphan shop');

        $this->assertSame([], $this->funnel($this->a)->json('data.coverage.stores_pending_first_sync'));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────

    private function funnel(Project $p): TestResponse
    {
        return $this->actingAs($this->operator)->getJson("/api/v1/projects/{$p->id}/commerce/funnel")->assertOk();
    }

    private function revenue(TestResponse $r): ?float
    {
        $v = collect($r->json('data.stages'))->keyBy('key')['revenue']['value'] ?? null;

        return $v === null ? null : (float) $v;
    }

    private function store(string $externalId, string $name = 'Salla'): ExternalAccount
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'salla',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'salla-'.$externalId,
        );
        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'salla',
            'account_type' => 'store', 'external_id' => $externalId, 'name' => $name, 'currency' => 'SAR', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $store;
    }

    private function bind(ExternalAccount $store, Project $project): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'client_workspace_id' => $project->client_workspace_id,
            'external_account_id' => $store->id, 'provider' => 'salla', 'purpose' => 'ecommerce', 'is_active' => true, 'is_primary' => true,
        ]);
    }

    private function order(ExternalAccount $store, Project $project, string $id, float $total): CommerceOrder
    {
        return CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $store->id,
            'provider' => 'salla', 'external_id' => $id, 'status' => 'completed', 'placed_at' => Carbon::now()->subDay(),
            'currency' => 'SAR', 'total' => $total, 'attribution_method' => 'none', 'attributed_at' => Carbon::now(),
        ]);
    }

    private function cart(ExternalAccount $store, Project $project): void
    {
        CommerceAbandonedCart::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $store->id,
            'provider' => 'salla', 'external_id' => 'c-1', 'abandoned_at' => Carbon::now()->subDay(), 'total' => 50,
        ]);
    }
}
