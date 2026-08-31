<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * COMMERCE-QUOTA-001 — a store is assigned like an ad account and billed like neither.
 *
 * ## The bill this stops
 *
 * Making commerce go through `ProjectIntegrationBinding` fixed one defect and created the conditions
 * for another. «Connected Ad Accounts» was counted as «every active binding», which was correct while
 * bindings could only name an ad account — and the moment a Salla store could hold one, connecting a
 * SHOP would silently spend a slot on a cap the customer is sold on the six ADVERTISING platforms.
 *
 * So the fix has two halves that pull in opposite directions and both have to hold:
 *
 *  - a store's data goes where somebody said it goes, through the same explicit binding;
 *  - a store costs nothing against a quota that is not about stores, and no store quota is invented
 *    here that the product does not sell.
 */
final class CommerceAssignmentAndQuotaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $clientA;

    private ClientWorkspace $clientB;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->clientA = $this->workspace('Client A');
        $this->clientB = $this->workspace('Client B');
        $this->projectA = $this->project('A', $this->clientA);
        $this->projectB = $this->project('B', $this->clientB);
    }

    // ── The quota ─────────────────────────────────────────────────────────────────────────────

    /**
     * **The defect, pinned.** Four ad accounts and two stores is FOUR connected ad accounts.
     *
     * The figure the plan is sold on counts advertising, and a shop is not advertising.
     */
    public function test_stores_do_not_consume_the_connected_ad_accounts_quota(): void
    {
        $this->plan();

        foreach ([['snapchat', 'snap-1'], ['snapchat', 'snap-2'], ['meta', 'meta-1'], ['google', 'g-1']] as [$provider, $id]) {
            $this->assign($this->adAccount($provider, $id), $this->projectA, $this->clientA);
        }

        $this->assertSame(4, $this->usedAdAccounts(), 'two Snapchat, one Meta and one Google is four');

        $this->assign($this->store('salla', 'store-salla'), $this->projectA, $this->clientA, 'ecommerce');
        $this->assign($this->store('zid', 'store-zid'), $this->projectA, $this->clientA, 'ecommerce');

        $this->assertSame(
            4,
            $this->usedAdAccounts(),
            'COMMERCE-QUOTA-001: connecting a shop spent an ADVERTISING slot, on a cap the customer '
                .'is sold on the six ad platforms.',
        );
    }

    /** And the same holds for the counter the wizard's «after confirming» line reads. */
    public function test_the_assignment_counter_also_ignores_stores(): void
    {
        $this->assign($this->adAccount('snapchat', 'snap-1'), $this->projectA, $this->clientA);
        $this->assign($this->store('salla', 'store-1'), $this->projectA, $this->clientA, 'ecommerce');

        $this->assertSame(1, app(AccountAssignment::class)->assignedCountFor($this->tenant->id));
    }

    /** Discovery alone still costs nothing — the rule that started all of this. */
    public function test_discovered_and_unassigned_costs_nothing(): void
    {
        $this->plan();
        $this->adAccount('snapchat', 'snap-unassigned');
        $this->store('salla', 'store-unassigned');

        $this->assertSame(0, $this->usedAdAccounts());
    }

    // ── The isolation ─────────────────────────────────────────────────────────────────────────

    /** A store assigned to client A files nothing into client B's project. */
    public function test_a_stores_orders_belong_to_the_project_it_was_assigned_to(): void
    {
        $store = $this->store('salla', 'store-1');
        $this->assign($store, $this->projectB, $this->clientB, 'ecommerce');

        $this->assertSame($this->projectB->id, app(AccountAssignment::class)->projectIdFor($store));
        $this->assertNotSame($this->projectA->id, app(AccountAssignment::class)->projectIdFor($store));
    }

    /** A store scoped to one client cannot be authorised against another client's project. */
    public function test_a_client_scoped_store_pointing_at_another_clients_project_is_refused(): void
    {
        $store = $this->store('salla', 'store-1');
        $store->forceFill(['client_workspace_id' => $this->clientA->id])->save();
        $this->assign($store, $this->projectB, $this->clientB, 'ecommerce');

        $this->assertFalse(app(AccountAssignment::class)->isActivelyAssigned($store->refresh()));
    }

    /** Detaching a store stops the queued work too, not only the next sweep. */
    public function test_a_detached_store_is_refused_at_execution(): void
    {
        $store = $this->store('salla', 'store-1');
        $this->assign($store, $this->projectA, $this->clientA, 'ecommerce');

        $job = new SyncStoreJob((string) $store->id, '2026-08-01', '2026-08-02', ['source' => 'test']);

        ProjectIntegrationBinding::withoutGlobalScopes()->update(['is_active' => false]);

        app()->call([$job, 'handle']);

        $this->assertSame(0, CommerceOrder::withoutGlobalScopes()->count());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function usedAdAccounts(): int
    {
        return app(SubscriptionService::class)->usage($this->tenant->refresh(), 'ad_accounts');
    }

    private function plan(): void
    {
        $plan = SubscriptionPlan::query()->firstOrFail();

        DB::table('subscriptions')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_interval' => 'monthly',
            'unit_amount' => 0,
            'currency' => 'USD',
            'seats' => 1,
            'current_period_start' => Carbon::now()->subDay(),
            'current_period_end' => Carbon::now()->addMonth(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function workspace(string $name): ClientWorkspace
    {
        return ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(string $name, ClientWorkspace $workspace): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function adAccount(string $provider, string $externalId): ExternalAccount
    {
        return $this->external($provider, 'ad_account', $externalId);
    }

    private function store(string $provider, string $externalId): ExternalAccount
    {
        return $this->external($provider, 'store', $externalId);
    }

    private function external(string $provider, string $type, string $externalId): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id,
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider.'-'.$externalId,
            'scope' => 'project_only',
            'status' => 'connected',
        ]);

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => $provider,
            'account_type' => $type,
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'discovered_at' => Carbon::now(),
            'last_synced_at' => null,
        ]);
    }

    private function assign(
        ExternalAccount $account,
        Project $project,
        ClientWorkspace $workspace,
        string $purpose = 'advertising',
    ): void {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'purpose' => $purpose,
            'is_active' => true,
        ]);
    }
}
