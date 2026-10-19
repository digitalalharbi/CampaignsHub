<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * COMMERCE-ASSIGN-001 — a connected store can be given a project, and says so.
 *
 * Production's Salla store answered 409 `awaiting_assignment` to every sync because nothing in the
 * product could assign it. These hold the fix from the side that matters: who may assign what to
 * where, what happens to the data the moment it is assigned, and what is NOT chosen on a merchant's
 * behalf.
 */
final class CommerceStoreAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ClientWorkspace $clientA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o@ag.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $this->clientA = $this->workspace('Client A');
        foreach (['salla', 'zid'] as $platform) {
            foreach (PlatformCredentials::for($platform)->requires() as $key) {
                config()->set("commerce_platforms.platforms.{$platform}.{$key}", "test-{$key}");
            }
        }
    }

    // ── Assigning, moving, detaching ───────────────────────────────────────────────────────────

    public function test_a_store_is_assigned_to_a_project_and_its_first_read_is_queued(): void
    {
        Queue::fake();
        $project = $this->project('Project 1', $this->clientA);
        $store = $this->store('salla', '793298857');

        $this->actingAs($this->operator)
            ->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $project->id])
            ->assertOk()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.project_name', 'Project 1');

        $binding = ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->firstOrFail();
        $this->assertSame($project->id, $binding->project_id);
        $this->assertTrue($binding->is_active);
        $this->assertSame('ecommerce', $binding->purpose);

        // The card gets a run row at once, and the job carries it.
        $run = IntegrationSyncRun::withoutGlobalScopes()->where('provider_connection_id', $store->provider_connection_id)->firstOrFail();
        $this->assertSame('queued', $run->status);
        Queue::assertPushed(SyncStoreJob::class);
    }

    public function test_moving_a_store_leaves_exactly_one_active_binding(): void
    {
        Queue::fake();
        $a = $this->project('A', $this->clientA);
        $b = $this->project('B', $this->clientA);
        $store = $this->store('salla', 's-1');

        $this->actingAs($this->operator)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $a->id])->assertOk();
        $this->actingAs($this->operator)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $b->id])->assertOk();

        $active = ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->where('is_active', true)->pluck('project_id')->all();
        $this->assertSame([$b->id], $active);
    }

    public function test_a_store_can_be_detached_and_the_change_is_audited(): void
    {
        Queue::fake();
        $project = $this->project('A', $this->clientA);
        $store = $this->store('salla', 's-1');
        $this->actingAs($this->operator)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $project->id])->assertOk();

        $this->actingAs($this->operator)
            ->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => null])
            ->assertOk()
            ->assertJsonPath('data.project_id', null);

        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->where('is_active', true)->count());
        $actions = AuditLog::withoutGlobalScopes()->pluck('action')->all();
        $this->assertContains('commerce.store.assigned', $actions);
        $this->assertContains('commerce.store.unassigned', $actions);

        // …and a detached store is refused a sync, with the reason.
        $this->actingAs($this->operator)->postJson("/api/v1/commerce/stores/{$store->id}/sync")
            ->assertStatus(409)->assertJsonPath('meta.reason', 'awaiting_assignment');
    }

    public function test_assigning_needs_the_connect_permission(): void
    {
        $project = $this->project('A', $this->clientA);
        $store = $this->store('salla', 's-1');
        $viewer = User::create(['name' => 'V', 'email' => 'v@ag.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        $role->givePermissionTo('integrations.view');
        $viewer->assignRole($role);

        $this->actingAs($viewer)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $project->id])->assertForbidden();
    }

    // ── Isolation ──────────────────────────────────────────────────────────────────────────────

    public function test_another_tenants_store_is_not_found_and_cannot_be_assigned_or_synced(): void
    {
        Queue::fake();
        $project = $this->project('A', $this->clientA);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $theirs = $this->store('salla', 'theirs', $other);

        $this->actingAs($this->operator)->patchJson("/api/v1/commerce/stores/{$theirs->id}", ['project_id' => $project->id])->assertNotFound();
        $this->actingAs($this->operator)->postJson("/api/v1/commerce/stores/{$theirs->id}/sync")->assertNotFound();
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $theirs->id)->count());

        // And their store is not on our board.
        $ids = collect($this->actingAs($this->operator)->getJson('/api/v1/commerce/stores')->json('data'))
            ->flatMap(fn (array $p) => array_column($p['stores'], 'id'))->all();
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_another_tenants_project_cannot_receive_our_store(): void
    {
        $store = $this->store('salla', 's-1');
        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $theirProject = $this->project('Theirs', $this->workspace('X', $other), $other);

        $this->actingAs($this->operator)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $theirProject->id])->assertNotFound();
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->count());
    }

    public function test_a_store_of_one_client_cannot_be_given_to_anothers_project(): void
    {
        $clientB = $this->workspace('Client B');
        $store = $this->store('salla', 's-1', null, $this->clientA);
        $bProject = $this->project('B', $clientB);

        $this->actingAs($this->operator)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $bProject->id])->assertForbidden();
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->count());
    }

    // ── Auto-assign: one choice is not a decision ─────────────────────────────────────────────

    public function test_a_store_connected_to_a_workspace_with_one_project_is_assigned_to_it(): void
    {
        Queue::fake();
        $only = $this->project('Only', $this->clientA);
        $this->fakeSallaIdentity('Salla demo');

        $this->connectThroughCallback('salla');

        $store = ExternalAccount::withoutGlobalScopes()->where('account_type', 'store')->firstOrFail();
        $this->assertSame($only->id, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->where('is_active', true)->value('project_id'));
        Queue::assertPushed(SyncStoreJob::class);
    }

    public function test_with_two_projects_nothing_is_chosen_for_the_merchant(): void
    {
        Queue::fake();
        $this->project('One', $this->clientA);
        $this->project('Two', $this->clientA);
        $this->fakeSallaIdentity('Salla demo');

        $this->connectThroughCallback('salla');

        $store = ExternalAccount::withoutGlobalScopes()->where('account_type', 'store')->firstOrFail();
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->count());
        Queue::assertNothingPushed();

        // The board says what to do: no project yet.
        $card = collect($this->actingAs($this->operator)->getJson('/api/v1/commerce/stores')->json('data'))->firstWhere('key', 'salla');
        $this->assertNull($card['stores'][0]['project_id']);
    }

    // ── After assignment: the store fills in ───────────────────────────────────────────────────

    public function test_assigning_a_store_reads_it_and_the_card_shows_its_name_time_and_counts(): void
    {
        $project = $this->project('Project 1', $this->clientA);
        $store = $this->store('salla', '793298857', null, null, name: '');
        Http::fake([
            'api.salla.dev/*/store/info' => Http::response(['data' => ['id' => 793298857, 'name' => 'dev-rsz4qp7ahtacj1ae', 'domain' => 'https://demostore.salla.sa/dev', 'currency' => 'SAR']]),
            'api.salla.dev/*/products*' => Http::response(['data' => [['id' => 'p1', 'name' => 'قميص', 'price' => ['amount' => 100, 'currency' => 'SAR'], 'quantity' => 3]], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*/customers*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*/orders*' => Http::response(['data' => [[
                'id' => 'o1', 'reference_id' => 1042, 'status' => ['slug' => 'completed', 'name' => 'مكتمل'],
                'date' => ['date' => Carbon::now()->subDays(2)->format('Y-m-d H:i:s.000000'), 'timezone' => 'Asia/Riyadh'],
                'amounts' => ['total' => ['amount' => 100, 'currency' => 'SAR'], 'sub_total' => ['amount' => 100, 'currency' => 'SAR']],
                'items' => [],
            ]], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*/carts/abandoned*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
        ]);

        // The queue is `sync` in tests, so the first read runs inside the request that assigned.
        $this->actingAs($this->operator)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $project->id])->assertOk();

        $card = collect($this->actingAs($this->operator)->getJson('/api/v1/commerce/stores')->json('data'))->firstWhere('key', 'salla')['stores'][0];
        $this->assertSame('dev-rsz4qp7ahtacj1ae', $card['name']);
        $this->assertNotNull($card['last_synced_at']);
        $this->assertSame(1, $card['counts']['products']);
        $this->assertSame(1, $card['counts']['orders']);
        $this->assertNotNull($card['last_run']);
        $this->assertContains($card['last_run']['status'], ['success', 'partial_mapping']);

        // Filed under the project it was assigned to, and nowhere else.
        $this->assertSame($project->id, CommerceProduct::withoutGlobalScopes()->value('project_id'));
        $this->assertSame($project->id, CommerceOrder::withoutGlobalScopes()->value('project_id'));
    }

    public function test_zid_is_assigned_the_same_way(): void
    {
        Queue::fake();
        $project = $this->project('Z', $this->clientA);
        $store = $this->store('zid', 'zid-1');

        $this->actingAs($this->operator)
            ->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $project->id])
            ->assertOk()->assertJsonPath('data.project_id', $project->id);
        Queue::assertPushed(SyncStoreJob::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────

    private function workspace(string $name, ?Tenant $tenant = null): ClientWorkspace
    {
        return ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(string $name, ClientWorkspace $workspace, ?Tenant $tenant = null): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'client_workspace_id' => $workspace->id,
            'name' => $name, 'status' => 'active',
        ]);
    }

    private function store(string $provider, string $externalId, ?Tenant $tenant = null, ?ClientWorkspace $workspace = null, string $name = 'Store'): ExternalAccount
    {
        $tenant ??= $this->tenant;
        app(TenantContext::class)->setTenantId($tenant->id);
        $connection = app(TokenVault::class)->open(
            tenantId: $tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(14)), connectionName: $provider.'-'.$externalId,
        );

        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace?->id,
            'provider_connection_id' => $connection->getKey(), 'provider' => $provider,
            'account_type' => 'store', 'external_id' => $externalId, 'name' => $name, 'currency' => 'SAR', 'status' => 'active',
        ]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return $store;
    }

    private function fakeSallaIdentity(string $name): void
    {
        Http::fake([
            'accounts.salla.sa/oauth2/token' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 1209600]),
            'api.salla.dev/*/store/info' => Http::response(['data' => ['id' => 'store-9', 'name' => $name, 'domain' => 'demo.salla.sa', 'currency' => 'SAR']]),
        ]);
    }

    private function connectThroughCallback(string $provider): void
    {
        $start = $this->actingAs($this->operator)->postJson("/api/v1/integrations/commerce/{$provider}/oauth/start")->assertOk();
        parse_str((string) parse_url((string) $start->json('data.authorization_url'), PHP_URL_QUERY), $query);

        $this->get("/api/v1/oauth/commerce/{$provider}/callback?code=real-code&state={$query['state']}")->assertRedirect();
    }
}
