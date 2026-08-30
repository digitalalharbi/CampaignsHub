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
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §8 — «Manage accounts» is a diff, and it costs no authorisation.
 *
 * ## What a customer had to do before this
 *
 * `bindings/batch` is the first commit: a list of accounts, an empty one refused, the plan charged
 * for what is new, the first sync started. It has no concept of REMOVAL, because the wizard calling
 * it is answering «which accounts shall this project start with».
 *
 * Somebody who had connected six accounts and wanted five had two options: detach one binding at a
 * time out of a raw inventory, or disconnect the provider and authorise again. The second is what
 * people did, and it costs the connection, every binding under it and the sync history — to remove
 * one account.
 *
 * ## Three things this pins
 *
 * The desired set is the request and the diff is the server's: two operators managing the same
 * project cannot each undo the other's change, which is what «add these / remove those» would do.
 * Removal DEACTIVATES, because a binding is what makes a metric row this project's and a delete
 * orphans months of history. And nothing here asks the provider for a new consent.
 */
final class ManageAccountSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

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

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client A', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'name' => 'Retainer',
            'status' => 'active',
        ]);

        $this->connection = $this->connection('linkedin');

        app(TenantContext::class)->forget();
        Queue::fake();
    }

    /** Adding one account to a project that already has one leaves the first alone. */
    public function test_adding_an_account_keeps_the_ones_already_bound(): void
    {
        $kept = $this->discovered('act-kept');
        $added = $this->discovered('act-added');
        $this->bind($kept);

        $response = $this->apply([$kept->id, $added->id])->assertOk();

        $this->assertSame([(string) $added->id], $response->json('data.added'));
        $this->assertSame([(string) $kept->id], $response->json('data.unchanged'));
        $this->assertSame([], $response->json('data.removed'));
        $this->assertSame(2, $this->activeBindings());
    }

    /**
     * A removed account is DEACTIVATED, and its binding row survives.
     *
     * The binding is what says a metric row belongs to this project. Deleting it would orphan
     * months of history the project is still entitled to show, and «remove this account from the
     * report» is not a request to destroy last quarter.
     */
    public function test_a_removed_account_is_deactivated_rather_than_deleted(): void
    {
        $kept = $this->discovered('act-kept');
        $dropped = $this->discovered('act-dropped');
        $this->bind($kept);
        $this->bind($dropped);

        $response = $this->apply([$kept->id])->assertOk();

        $this->assertSame([(string) $dropped->id], $response->json('data.removed'));
        $this->assertSame(1, $this->activeBindings());
        $this->assertDatabaseHas('project_integration_bindings', [
            'external_account_id' => $dropped->id,
            'is_active' => false,
        ]);
    }

    /** The same desired set twice is the same decision — the second time changes nothing. */
    public function test_the_same_selection_applied_twice_changes_nothing_the_second_time(): void
    {
        $one = $this->discovered('act-one');
        $two = $this->discovered('act-two');

        $this->apply([$one->id, $two->id])->assertOk();
        $second = $this->apply([$one->id, $two->id])->assertOk();

        $this->assertSame([], $second->json('data.added'));
        $this->assertSame([], $second->json('data.removed'));
        $this->assertCount(2, $second->json('data.unchanged'));
        $this->assertSame(2, $this->activeBindings());
    }

    /**
     * An EMPTY set is an answer here, and `bindings/batch` still refuses one.
     *
     * They are different questions: «start with none» is a mistake, «keep none of them any more» is
     * a decision somebody is entitled to make from the manage screen.
     */
    public function test_an_empty_selection_removes_everything_here_and_is_still_refused_by_the_first_commit(): void
    {
        $account = $this->discovered('act-only');
        $this->bind($account);

        $this->apply([])->assertOk();
        $this->assertSame(0, $this->activeBindings());

        $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Project-Id', $this->project->id)
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/bindings/batch", [
                'connection_id' => $this->connection->id,
                'external_account_ids' => [],
                'purpose' => 'advertising',
            ])
            ->assertStatus(422);
    }

    /** The first sync starts for what was ADDED, and for nothing that was already there. */
    public function test_only_the_newly_added_accounts_start_a_sync(): void
    {
        $kept = $this->discovered('act-kept');
        $added = $this->discovered('act-added');
        $this->bind($kept);
        Queue::fake();

        $this->apply([$kept->id, $added->id])->assertOk();

        Queue::assertPushed(
            SyncAccountStructureJob::class,
            fn (SyncAccountStructureJob $job) => $job->accountId === (string) $added->id,
        );
        Queue::assertPushed(SyncAccountStructureJob::class, 1);
    }

    /**
     * A diff over one connection leaves another provider's bindings alone.
     *
     * The failure this prevents is quiet and total: managing LinkedIn would compute «these are all
     * the accounts this project should have» and unbind every Meta account under it.
     */
    public function test_managing_one_connection_does_not_touch_another_providers_bindings(): void
    {
        $linkedin = $this->discovered('act-linkedin');
        $meta = $this->discovered('act-meta', $this->connection('meta'));
        $this->bind($linkedin);
        $this->bind($meta);

        $this->apply([])->assertOk();

        $this->assertDatabaseHas('project_integration_bindings', [
            'external_account_id' => $meta->id,
            'is_active' => true,
        ]);
    }

    /** @param  list<string>  $accountIds */
    private function apply(array $accountIds): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Project-Id', $this->project->id)
            ->putJson("/api/v1/projects/{$this->project->id}/integrations/selection", [
                'connection_id' => $this->connection->id,
                'external_account_ids' => $accountIds,
                'purpose' => 'advertising',
            ]);
    }

    private function activeBindings(): int
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('project_id', $this->project->id)
            ->where('is_active', true)
            ->whereIn(
                'external_account_id',
                ExternalAccount::withoutGlobalScopes()
                    ->where('provider_connection_id', $this->connection->id)
                    ->select('id'),
            )
            ->count();
    }

    private function bind(ExternalAccount $account): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'purpose' => 'advertising',
            'is_active' => true,
            'is_primary' => false,
        ]);
    }

    private function discovered(string $externalId, ?ProviderConnection $connection = null): ExternalAccount
    {
        $connection ??= $this->connection;

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => null,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'discovered_at' => now(),
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
            'name' => $provider,
            'connection_name' => $provider,
            'status' => 'connected',
            'connected_at' => now(),
        ]);
    }
}
