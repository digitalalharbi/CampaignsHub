<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — the per-binding «Sync now» honours the operator's selection.
 *
 * `PUT /integrations/selection` deselects an account by setting its binding `is_active = false` and
 * keeping the row — the history stays readable, and re-selecting reactivates the same row. Every
 * sweep, job and webhook then refuses that account through `AccountAssignment::isActivelyAssigned()`.
 *
 * `POST bindings/{binding}/sync` — the button on the project's integrations page — did not ask. It
 * found the binding row, active or not, fetched the account's campaigns and imported them into the
 * CURRENT project. So the one account an operator had just deselected was the one account whose
 * campaigns a click could still file under the project, through the product's own interface, with
 * nothing on screen saying the selection had been overridden.
 *
 * The route now asks the same question every other fetch path asks, and refuses in the same words.
 */
final class BindingSyncHonoursTheSelectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $tenant);
        $this->user->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Project A', 'status' => 'active']);

        app(TenantContext::class)->forget();
    }

    public function test_sync_now_refuses_a_deselected_binding_and_files_nothing(): void
    {
        $bindingId = $this->bindTheSandboxAccount();

        // The operator deselected the account: the row stays, inactive — exactly what the selection
        // endpoint writes.
        ProjectIntegrationBinding::withoutGlobalScopes()->whereKey($bindingId)->update(['is_active' => false]);

        $before = ExternalCampaign::withoutGlobalScopes()->count();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/bindings/{$bindingId}/sync")
            ->assertStatus(409);

        $this->assertSame($before, ExternalCampaign::withoutGlobalScopes()->count(), 'a deselected account\'s campaigns were imported into the project');
        $this->assertDatabaseMissing('integration_sync_runs', ['binding_id' => $bindingId, 'status' => 'success']);
    }

    public function test_sync_now_still_syncs_a_selected_binding(): void
    {
        $bindingId = $this->bindTheSandboxAccount();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/bindings/{$bindingId}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'success');

        $this->assertGreaterThan(0, ExternalCampaign::withoutGlobalScopes()->where('project_id', $this->project->id)->count());
    }

    /**
     * INTEGRATIONS-CENTRE-001 §4 — the second press inside two minutes is refused, and told when the
     * first one was. The time named is the time of the REQUEST; `last_synced_at` is not it.
     */
    public function test_a_second_manual_sync_of_the_same_binding_within_two_minutes_is_refused_with_the_time_of_the_first(): void
    {
        $bindingId = $this->bindTheSandboxAccount();
        $url = "/api/v1/projects/{$this->project->id}/integrations/bindings/{$bindingId}/sync";

        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->actingAs($this->user, 'sanctum')->postJson($url)->assertOk();
        $runsAfterFirst = IntegrationSyncRun::withoutGlobalScopes()->where('binding_id', $bindingId)->count();

        Carbon::setTestNow('2026-11-02 10:00:45');
        $refused = $this->actingAs($this->user, 'sanctum')->postJson($url)
            ->assertStatus(429)
            ->assertHeader('Retry-After', '75')
            ->assertJsonPath('errors.last_requested_at', Carbon::parse('2026-11-02 10:00:00')->toIso8601String())
            ->assertJsonPath('errors.retry_after_seconds', 75);
        $this->assertStringContainsString('10:00:00', (string) $refused->json('message'));
        $this->assertSame($runsAfterFirst, IntegrationSyncRun::withoutGlobalScopes()->where('binding_id', $bindingId)->count(), 'the refused press opened a run');

        Carbon::setTestNow('2026-11-02 10:02:01');
        $this->actingAs($this->user, 'sanctum')->postJson($url)->assertOk();

        Carbon::setTestNow();
    }

    private function bindTheSandboxAccount(): string
    {
        $accounts = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/connect")
            ->assertCreated()
            ->json('data.accounts');

        $accountId = collect($accounts)->firstWhere('account_type', 'ad_account')['id'];

        return $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/bindings", [
                'external_account_id' => $accountId, 'purpose' => 'advertising',
            ])
            ->assertCreated()
            ->json('data.id');
    }
}
