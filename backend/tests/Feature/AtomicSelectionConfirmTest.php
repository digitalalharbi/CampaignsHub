<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * RUNTIME-100 §10 §13 — one decision, one transaction, all or nothing.
 *
 * ## What confirming used to be
 *
 * The wizard let somebody tick ten accounts and then did this:
 *
 * ```ts
 * for (const accountId of selected) await bindAccountToProject(projectId, accountId)
 * ```
 *
 * Ten requests. Each one is individually correct — tenant checked, workspace fenced, quota counted
 * under a lock — and the SEQUENCE is not a decision at all. A plan with room for eight leaves the
 * customer with eight connected accounts, two refusals, and a wizard that has already written half
 * of what they asked for. There is no undo, because from the server's point of view nothing failed:
 * eight bindings were created exactly as requested and the ninth was correctly refused.
 *
 * Partially applying somebody's choice is worse than refusing it. They chose ten accounts as one
 * thing; the product should either do that or say why it cannot.
 *
 * ## What the batch has to prove
 *
 * Not merely «it works». That every refusal reachable inside it leaves the database exactly as it
 * was — quota, ownership, a tampered id, an account from another connection — and that the first
 * sync is queued only once the write has actually committed.
 */
final class AtomicSelectionConfirmTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ClientWorkspace $workspace;

    private Project $project;

    private ProviderConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $this->workspace = $this->clientWorkspace('Client A');
        $this->project = $this->project('Retainer', $this->workspace);
        $this->connection = $this->connection('snapchat');

        app(TenantContext::class)->forget();
        Queue::fake();
    }

    // ── All or nothing ────────────────────────────────────────────────────────────────────────

    /**
     * **The defect, pinned.** Ten chosen, a cap of eight — and NOTHING is written.
     *
     * The per-account loop left eight bindings behind and refused the last two, which is the product
     * having made a decision the customer never made.
     */
    public function test_a_selection_that_exceeds_the_plan_writes_nothing_at_all(): void
    {
        $this->planWithAdAccountCap(8);

        $accounts = collect(range(1, 10))->map(fn (int $i) => $this->discovered("act-{$i}"));

        $response = $this->confirm($accounts->pluck('id')->all());

        $response->assertStatus(422)->assertJsonPath('meta.limit_reached', true);

        $this->assertSame(
            0,
            ProjectIntegrationBinding::withoutGlobalScopes()->count(),
            'RUNTIME-100 §10: a refusal part-way through left the earlier accounts connected, so the '
                .'customer got a selection they never made and no way to tell which half applied.',
        );
        Queue::assertNothingPushed();
    }

    /** A selection that fits is written whole, and every account lands on the chosen project. */
    public function test_a_selection_within_the_plan_is_written_whole(): void
    {
        $this->planWithAdAccountCap(8);

        $accounts = collect(range(1, 5))->map(fn (int $i) => $this->discovered("act-{$i}"));

        $this->confirm($accounts->pluck('id')->all())
            ->assertCreated()
            ->assertJsonPath('data.connected', 5);

        $this->assertSame(5, ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->count());
        $this->assertSame(
            [$this->project->id],
            ProjectIntegrationBinding::withoutGlobalScopes()->distinct()->pluck('project_id')->all(),
        );
    }

    /** Confirming the same selection twice is the same decision — no duplicates, no second slot. */
    public function test_confirming_twice_is_idempotent(): void
    {
        $this->planWithAdAccountCap(3);
        $ids = collect(range(1, 3))->map(fn (int $i) => $this->discovered("act-{$i}"))->pluck('id')->all();

        $this->confirm($ids)->assertCreated();
        $this->confirm($ids)->assertCreated()->assertJsonPath('data.connected', 3);

        $this->assertSame(3, ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->count());
    }

    // ── Everything is re-proved inside the transaction ────────────────────────────────────────

    /** An id belonging to another connection is not part of this decision, and voids the whole batch. */
    public function test_an_account_from_another_connection_voids_the_batch(): void
    {
        $mine = $this->discovered('act-mine');
        $other = $this->discovered('act-other', connection: $this->connection('meta'));

        $this->confirm([$mine->id, $other->id])->assertStatus(422);

        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    /** Another tenant's account is refused, and says nothing about existing. */
    public function test_another_tenants_account_voids_the_batch(): void
    {
        $mine = $this->discovered('act-mine');

        $otherTenant = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($otherTenant->id);
        $theirConnection = $this->connection('snapchat', $otherTenant);
        $theirs = $this->discovered('act-theirs', connection: $theirConnection, tenant: $otherTenant);
        app(TenantContext::class)->forget();

        $this->confirm([$mine->id, (string) $theirs->id])->assertStatus(422);

        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    /**
     * An account already feeding another project stops the batch rather than being moved.
     *
     * ORCH-100 §I holds: one active assignment per account. Detaching is how you move it, and the
     * refusal names where it currently lives.
     */
    public function test_an_account_assigned_elsewhere_voids_the_batch(): void
    {
        $elsewhere = $this->project('Other project', $this->workspace);
        $taken = $this->discovered('act-taken');
        $free = $this->discovered('act-free');

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'project_id' => $elsewhere->id,
            'external_account_id' => $taken->id,
            'provider' => 'snapchat',
            'purpose' => 'advertising',
            'is_active' => true,
        ]);

        $this->confirm([$free->id, $taken->id])->assertStatus(409);

        $this->assertSame(
            1,
            ProjectIntegrationBinding::withoutGlobalScopes()->count(),
            'the untouched account must not have been connected on the way to the refusal',
        );
    }

    /** An account belonging to another CLIENT of the same agency cannot cross into this project. */
    public function test_another_client_workspaces_account_voids_the_batch(): void
    {
        $otherClient = $this->clientWorkspace('Client B');

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $theirs = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $otherClient->id,
            'provider_connection_id' => $this->connection->id,
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act-client-b',
            'name' => 'Client B account',
            'status' => 'active',
            'discovered_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        $this->confirm([(string) $theirs->id])->assertStatus(403);

        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    // ── The first sync ────────────────────────────────────────────────────────────────────────

    /**
     * RUNTIME-100 §13 — the sync starts because the selection was confirmed, not because somebody
     * later found a button.
     */
    public function test_a_successful_confirmation_queues_the_first_sync_for_the_chosen_accounts_only(): void
    {
        $chosen = $this->discovered('act-chosen');
        $this->discovered('act-not-chosen');

        $this->confirm([$chosen->id])->assertCreated();

        Queue::assertPushed(
            SyncAccountStructureJob::class,
            fn (SyncAccountStructureJob $job) => $job->accountId === (string) $chosen->id,
        );
        Queue::assertPushed(SyncAccountStructureJob::class, 1);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @param  list<string>  $accountIds */
    private function confirm(array $accountIds): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Project-Id', $this->project->id)
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/bindings/batch", [
                'connection_id' => $this->connection->id,
                'external_account_ids' => $accountIds,
                'purpose' => 'advertising',
            ]);
    }

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

    private function connection(string $provider, ?Tenant $tenant = null): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider,
            'scope' => 'project_only',
            'status' => 'connected',
        ]);
    }

    private function discovered(
        string $externalId,
        ?ProviderConnection $connection = null,
        ?Tenant $tenant = null,
    ): ExternalAccount {
        $connection ??= $this->connection;

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'client_workspace_id' => null,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'discovered_at' => now(),
            'last_synced_at' => null,
        ]);
    }
}
