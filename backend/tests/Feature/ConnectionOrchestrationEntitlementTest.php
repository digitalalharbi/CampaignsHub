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
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * INTEGRATIONS-CONNECTION-ORCHESTRATION-100 §I §J §K §Y §Z — what a «connected ad account» costs,
 * and what it is allowed to touch.
 *
 * ## Discovery is an inventory. Assignment is the purchase
 *
 * The first live Snapchat consent discovered **309 ad accounts**. Under a naïve reading that is 309
 * connected accounts, and on a plan sold with a cap of three the customer is instantly and
 * permanently over their limit — for a catalogue they have not chosen from. Consent to *see* an
 * inventory is not a decision to connect it.
 *
 * So the meter counts distinct accounts somebody has actively assigned to a project, and nothing
 * else. These tests hold that line from both directions: 309 discovered costs nothing, and the third
 * assignment on a cap of three is refused.
 *
 * ## And a cap that is only checked is not enforced
 *
 * `withinLimit()` then `create()` is two statements. Two confirmations racing for the last slot both
 * read «one left» and both write, and the customer ends up with one more than they bought — the
 * quiet kind of billing defect that is only ever found by the customer. The count is taken under a
 * row lock and the database carries a partial unique index as the backstop.
 */
final class ConnectionOrchestrationEntitlementTest extends TestCase
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

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->workspace = $this->clientWorkspace('House');

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o@ag.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
    }

    // ── What discovery costs ──────────────────────────────────────────────────────────────────

    /**
     * The live shape: 309 discovered, none assigned. The meter must read **zero**.
     *
     * Counting the inventory would put this customer over every cap in the catalogue the instant
     * they authorised us, for accounts they never picked.
     */
    public function test_three_hundred_and_nine_discovered_accounts_cost_nothing(): void
    {
        $connection = $this->connection('snapchat');

        foreach (range(1, 309) as $i) {
            $this->discovered($connection, "act-{$i}");
        }

        $this->assertSame(309, ExternalAccount::withoutGlobalScopes()->count(), 'the inventory is real');
        $this->assertSame(
            0,
            app(SubscriptionService::class)->usage($this->tenant, 'ad_accounts'),
            'ORCH-100 §J: discovery is not connection, and must never consume a plan slot',
        );
    }

    /** Assignment is what the customer bought, so assignment is what is counted. */
    public function test_an_assigned_account_is_what_the_meter_counts(): void
    {
        $project = $this->project('P');
        $account = $this->discovered($this->connection('snapchat'), 'act-1');

        $this->bindApi($project, $account)->assertCreated();

        $this->assertSame(1, app(SubscriptionService::class)->usage($this->tenant, 'ad_accounts'));
    }

    /** Detaching gives the slot back — the customer stopped using it. */
    public function test_detaching_returns_the_slot(): void
    {
        $project = $this->project('P');
        $account = $this->discovered($this->connection('snapchat'), 'act-1');
        $this->bindApi($project, $account)->assertCreated();

        ProjectIntegrationBinding::withoutGlobalScopes()->update(['is_active' => false]);

        $this->assertSame(0, app(SubscriptionService::class)->usage($this->tenant, 'ad_accounts'));
    }

    // ── The cap ───────────────────────────────────────────────────────────────────────────────

    /** The cap is enforced by the API, not merely displayed by the interface. */
    public function test_the_cap_refuses_the_account_past_the_limit(): void
    {
        $this->planWithAdAccountCap(2);

        $project = $this->project('P');
        $connection = $this->connection('snapchat');

        $this->bindApi($project, $this->discovered($connection, 'act-1'))->assertCreated();
        $this->bindApi($project, $this->discovered($connection, 'act-2'))->assertCreated();

        $this->bindApi($project, $this->discovered($connection, 'act-3'))
            ->assertStatus(422)
            ->assertJsonPath('meta.limit_reached', true)
            ->assertJsonPath('meta.metric', 'ad_accounts')
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('meta.remaining', 0);

        $this->assertSame(2, app(SubscriptionService::class)->usage($this->tenant, 'ad_accounts'));
    }

    /**
     * ORCH-100 §Y — two confirmations for one remaining slot: exactly one may win.
     *
     * Run as real concurrent transactions rather than sequential calls, because the defect being
     * guarded against only exists between the read and the write.
     */
    public function test_two_confirmations_racing_for_the_last_slot_cannot_both_succeed(): void
    {
        $this->planWithAdAccountCap(1);

        $project = $this->project('P');
        $connection = $this->connection('snapchat');
        $first = $this->discovered($connection, 'act-1');
        $second = $this->discovered($connection, 'act-2');

        $responses = [
            $this->bindApi($project, $first)->status(),
            $this->bindApi($project, $second)->status(),
        ];

        sort($responses);

        $this->assertSame([201, 422], $responses, 'one confirmation succeeds and one is refused');
        $this->assertSame(
            1,
            app(SubscriptionService::class)->usage($this->tenant, 'ad_accounts'),
            'the cap of one must hold whatever order the two requests arrive in',
        );
    }

    /** Confirming the same account twice is one decision, and costs one slot. */
    public function test_confirming_the_same_account_twice_is_idempotent(): void
    {
        $this->planWithAdAccountCap(1);

        $project = $this->project('P');
        $account = $this->discovered($this->connection('snapchat'), 'act-1');

        $this->bindApi($project, $account)->assertCreated();
        $this->bindApi($project, $account)->assertSuccessful();

        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->count());
        $this->assertSame(1, app(SubscriptionService::class)->usage($this->tenant, 'ad_accounts'));
    }

    /** The database refuses a second ACTIVE binding for the same pair, whatever the application does. */
    public function test_the_database_refuses_a_duplicate_active_binding(): void
    {
        $project = $this->project('P');
        $account = $this->discovered($this->connection('snapchat'), 'act-1');
        $this->bindApi($project, $account)->assertCreated();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('project_integration_bindings')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => 'snapchat',
            'purpose' => 'advertising',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Isolation ─────────────────────────────────────────────────────────────────────────────

    /** ORCH-100 §I — one account does not quietly feed two projects. */
    public function test_an_account_already_connected_elsewhere_is_refused(): void
    {
        $first = $this->project('First');
        $second = $this->project('Second');
        $account = $this->discovered($this->connection('snapchat'), 'act-1');

        $this->bindApi($first, $account)->assertCreated();

        $this->bindApi($second, $account)
            ->assertStatus(409)
            ->assertJsonPath('meta.assigned_project_id', $first->id);

        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->count());
    }

    /** ORCH-100 §F — an agency's client A cannot reach client B's project. */
    public function test_one_clients_account_cannot_be_assigned_to_another_clients_project(): void
    {
        $clientA = $this->clientWorkspace('Client A');
        $clientB = $this->clientWorkspace('Client B');

        $projectB = $this->project('B project', $clientB);

        $connection = $this->connection('snapchat');
        $accountA = $this->discovered($connection, 'act-a', $clientA);

        $this->bindApi($projectB, $accountA)->assertStatus(403);

        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    /** And another tenant's account is not even addressable. */
    public function test_another_tenants_account_cannot_be_assigned(): void
    {
        $ours = $this->project('Ours');

        $theirs = Tenant::create(['name' => 'Theirs', 'slug' => 'th-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($theirs->id);
        $theirConnection = $this->connection('snapchat', $theirs);
        $theirAccount = $this->discovered($theirConnection, 'act-theirs', null, $theirs);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->bindApi($ours, $theirAccount)->assertStatus(422);

        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    // ── Discovery honesty ─────────────────────────────────────────────────────────────────────

    /** ORCH-100 §E — a discovered account has never synced, and must not claim to have. */
    public function test_a_discovered_account_reports_no_sync_until_one_happens(): void
    {
        $account = $this->discovered($this->connection('snapchat'), 'act-1');

        $this->assertNotNull($account->discovered_at, 'discovery is recorded as discovery');
        $this->assertNull(
            $account->last_synced_at,
            'ORCH-100 §E: discovery stamped last_synced_at, so the product announced a sync that '
                .'had never run — for all 309 accounts at once',
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function planWithAdAccountCap(int $cap): void
    {
        $plan = SubscriptionPlan::query()->firstOrFail();
        $plan->forceFill(['limits' => [...($plan->limits ?? []), 'ad_accounts' => $cap]])->save();

        DB::table('subscriptions')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_interval' => 'monthly',
            'unit_amount' => 0,
            'currency' => 'USD',
            'seats' => 1,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
