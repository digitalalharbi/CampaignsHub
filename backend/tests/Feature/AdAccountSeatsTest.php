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
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Seats\AdAccountSeat;
use App\Domains\Subscriptions\Seats\AdAccountSeats;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * PRICING-SEATS-001 — ad-account seats, through the real binding endpoint.
 *
 * Solo: 5 seats, 1 replacement per 30 days. The same real account is never charged twice (another connection, another project,
 * re-consent); detaching does not free a seat for a DIFFERENT account at once; the replacement allowance is enforced and resets
 * after 30 days; history is kept; a plan without an allowance (the launch catalogue) behaves as before.
 */
final class AdAccountSeatsTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ClientWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Advertiser', 'slug' => 'adv-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->workspace = $this->clientWorkspace('House');
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o@adv.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
        app(SubscriptionService::class)->assignPlan($this->tenant, SubscriptionPlan::where('code', 'solo')->firstOrFail());
    }

    private function detachAll(): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->update(['is_active' => false]);
    }

    public function test_the_same_real_account_through_another_connection_or_project_costs_one_seat(): void
    {
        $p1 = $this->project('P1');
        $p2 = $this->project('P2');
        $first = $this->discovered($this->connection('meta'), 'act-1');
        // Re-consent created a second connection that sees the same account: same provider, same id.
        $again = $this->discovered($this->connection('meta'), 'act-1');

        $this->bindApi($p1, $first)->assertCreated();
        $this->detachAll();
        $this->bindApi($p2, $again)->assertCreated();

        $this->assertSame(1, app(SubscriptionService::class)->usage($this->tenant, 'ad_accounts'));
        $this->assertSame(1, AdAccountSeat::query()->count());
        $this->assertSame(0, DB::table('ad_account_seat_events')->where('type', 'replaced')->count(), 'coming back is never a replacement');
    }

    public function test_the_cap_holds_at_five(): void
    {
        $p = $this->project('P');
        $c = $this->connection('meta');
        foreach (range(1, 5) as $i) {
            $this->bindApi($p, $this->discovered($c, "act-{$i}"))->assertCreated();
        }

        $this->bindApi($p, $this->discovered($c, 'act-6'))->assertStatus(422)->assertJsonPath('meta.metric', 'ad_accounts');
    }

    public function test_a_released_seat_takes_a_different_account_only_through_the_replacement_allowance(): void
    {
        $p = $this->project('P');
        $c = $this->connection('meta');
        foreach (range(1, 5) as $i) {
            $this->bindApi($p, $this->discovered($c, "act-{$i}"))->assertCreated();
        }
        ProjectIntegrationBinding::withoutGlobalScopes()->whereIn('external_account_id',
            ExternalAccount::withoutGlobalScopes()->whereIn('external_id', ['act-4', 'act-5'])->pluck('id'))->update(['is_active' => false]);

        // Two seats released; Solo allows one replacement per 30 days.
        $this->bindApi($p, $this->discovered($c, 'act-new-1'))->assertCreated();
        $this->bindApi($p, $this->discovered($c, 'act-new-2'))->assertStatus(422)->assertJsonPath('meta.metric', 'ad_account_replacements');

        // The released account itself may come back at any time — it is the same seat.
        $this->bindApi($p, ExternalAccount::withoutGlobalScopes()->where('external_id', 'act-5')->firstOrFail())->assertCreated();

        // Five held again (act-1..3, act-new-1, act-5): a sixth is over the cap, replacement or not.
        $this->bindApi($p, $this->discovered($c, 'act-new-3'))->assertStatus(422)->assertJsonPath('meta.metric', 'ad_accounts');

        // After 30 days the allowance has returned: release one and a different account may take its seat.
        Carbon::setTestNow(now()->addDays(31));
        ProjectIntegrationBinding::withoutGlobalScopes()->whereIn('external_account_id',
            ExternalAccount::withoutGlobalScopes()->where('external_id', 'act-1')->pluck('id'))->update(['is_active' => false]);
        $this->bindApi($p, ExternalAccount::withoutGlobalScopes()->where('external_id', 'act-new-3')->firstOrFail())->assertCreated();
        Carbon::setTestNow();

        $types = DB::table('ad_account_seat_events')->pluck('type')->countBy()->all();
        $this->assertSame(2, $types['replaced'] ?? 0);
        $this->assertSame(1, $types['reactivated'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $types['released'] ?? 0, 'history of what was released is kept');
    }

    public function test_releasing_is_never_limited_and_keeps_its_history(): void
    {
        $p = $this->project('P');
        $this->bindApi($p, $this->discovered($this->connection('meta'), 'act-1'))->assertCreated();
        $this->detachAll();

        app(AdAccountSeats::class)->reconcile($this->tenant);

        $seat = AdAccountSeat::query()->sole();
        $this->assertSame('released', $seat->status);
        $this->assertSame(0, app(SubscriptionService::class)->usage($this->tenant, 'ad_accounts'));
        $this->assertSame(['claimed', 'released'], DB::table('ad_account_seat_events')->orderBy('id')->pluck('type')->all());
    }

    public function test_a_plan_without_a_replacement_allowance_keeps_the_old_behaviour(): void
    {
        app(SubscriptionService::class)->assignPlan($this->tenant, SubscriptionPlan::where('code', 'growth')->firstOrFail());
        $p = $this->project('P');
        $c = $this->connection('meta');
        foreach (range(1, 3) as $i) {
            $this->bindApi($p, $this->discovered($c, "act-{$i}"))->assertCreated();
        }
        $this->detachAll();
        foreach (range(4, 8) as $i) {
            $this->bindApi($p, $this->discovered($c, "act-{$i}"))->assertCreated();
        }

        $this->assertSame(5, app(SubscriptionService::class)->usage($this->tenant, 'ad_accounts'));
    }

    public function test_the_seat_summary_says_when_the_next_replacement_is_available(): void
    {
        $p = $this->project('P');
        $c = $this->connection('meta');
        foreach (range(1, 5) as $i) {
            $this->bindApi($p, $this->discovered($c, "act-{$i}"))->assertCreated();
        }
        ProjectIntegrationBinding::withoutGlobalScopes()->whereIn('external_account_id',
            ExternalAccount::withoutGlobalScopes()->where('external_id', 'act-1')->pluck('id'))->update(['is_active' => false]);
        $this->bindApi($p, $this->discovered($c, 'act-new'))->assertCreated();

        $summary = app(AdAccountSeats::class)->summary($this->tenant);
        $this->assertSame(5, $summary['active']);
        $this->assertSame(1, $summary['replacements_used']);
        $this->assertSame(1, $summary['replacements_allowed']);
        $this->assertNotNull($summary['next_replacement_at']);
    }

    private function clientWorkspace(string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(string $name, ?ClientWorkspace $workspace = null): Project
    {
        return Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => ($workspace ?? $this->workspace)->id,
            'name' => $name,
            'status' => 'active',
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

    private function discovered(
        ProviderConnection $connection,
        string $externalId,
        ?ClientWorkspace $workspace = null,
        ?Tenant $tenant = null,
    ): ExternalAccount {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'client_workspace_id' => $workspace?->id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            // As discovery now writes it: catalogued, never synced.
            'discovered_at' => now(),
            'last_synced_at' => null,
        ]);
    }

    private function bindApi(Project $project, ExternalAccount $account): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Project-Id', $project->id)
            ->postJson('/api/v1/projects/'.$project->id.'/integrations/bindings', [
                'external_account_id' => $account->id,
                'purpose' => 'advertising',
            ]);
    }
}
