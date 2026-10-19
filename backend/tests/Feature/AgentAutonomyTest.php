<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Agents\Contracts\ProposalExecutor;
use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Agents\Services\ProposalService;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * AGENTS-AUTONOMY-001 — autonomy is earned by a measured record, granted by an owner, limited to the two
 * classes that only reduce spend, and withdrawn by the record itself.
 */
final class AgentAutonomyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private ExternalAccount $account;

    private object $executor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'o', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $ws = ClientWorkspace::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta', 'connection_name' => 'm', 'scope' => 'project_only', 'status' => 'connected']);
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'act_1', 'name' => 'act_1', 'status' => 'active', 'currency' => 'USD',
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
        ]);
        ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->account->id,
            'provider' => 'meta', 'external_id' => 'cmp_1', 'name' => 'Spring', 'status' => 'active', 'daily_budget' => 100,
        ]);

        $this->executor = new class implements ProposalExecutor
        {
            public int $calls = 0;

            public function supports(ActionProposal $p): bool
            {
                return true;
            }

            public function execute(ActionProposal $p): array
            {
                $this->calls++;

                return ['request' => [], 'response' => ['success' => true]];
            }
        };
        $this->app->instance('test-executor', $this->executor);
        config()->set('agents.executors', ['test-executor']);
    }

    /** A person's past decisions: `$approved` of `$count` approved, the first `$daysAgo` days back. */
    private function history(string $action, int $count, int $approved, int $daysAgo = 40, ?float $budget = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            ActionProposal::withoutGlobalScopes()->forceCreate([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'agent_key' => 'campaign', 'provider' => 'meta',
                'external_account_id' => $this->account->id, 'target_type' => 'campaign', 'target_external_id' => 'cmp_old_'.$i,
                'action' => $action, 'payload' => $budget !== null ? ['daily_budget' => $budget] : null,
                'evidence' => $budget !== null ? ['current_daily_budget' => $budget * 2] : null, 'reason' => 'r',
                'status' => $i < $approved ? 'executed' : 'rejected', 'idempotency_key' => Str::random(40),
                'decided_by' => $this->owner->id, 'decided_at' => Carbon::now()->subDays($daysAgo - $i),
                'created_at' => Carbon::now()->subDays($daysAgo - $i),
            ]);
        }
    }

    private function grant(string $class, bool $enabled = true): TestResponse
    {
        return $this->actingAs($this->owner)->putJson("/api/v1/projects/{$this->project->id}/agent-autonomy", ['class' => $class, 'enabled' => $enabled]);
    }

    private function agentProposes(string $action, ?float $budget = null): ActionProposal
    {
        return app(ProposalService::class)->propose($this->project, 'campaign', null, [
            'provider' => 'meta', 'external_account_id' => $this->account->id, 'target_type' => 'campaign', 'target_external_id' => 'cmp_1',
            'action' => $action, 'payload' => $budget !== null ? ['daily_budget' => $budget] : [], 'reason' => 'CPA far above target',
        ]);
    }

    public function test_a_young_record_cannot_be_granted_and_says_what_it_lacks(): void
    {
        $this->history('pause', 4, 4, daysAgo: 10);

        $this->grant('pause')->assertStatus(422);
        $ladder = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/agent-autonomy")->assertOk()->json('data.classes.0');
        $this->assertFalse($ladder['eligible']);
        $this->assertSame(['history_days', 'decided_count'], $ladder['needs']);
    }

    public function test_an_earned_and_granted_pause_runs_without_waiting_in_the_owner_s_name(): void
    {
        $this->history('pause', 12, 12);
        $this->grant('pause')->assertOk()->assertJsonPath('data.classes.0.granted', true);

        $row = $this->agentProposes('pause');

        $this->assertSame('executed', $row->fresh()->status);
        $this->assertSame('auto: trust ladder', $row->fresh()->decision_note);
        $this->assertSame((int) $this->owner->id, (int) $row->fresh()->decided_by);
        $this->assertSame(1, $this->executor->calls);
    }

    public function test_a_person_s_own_request_still_waits(): void
    {
        $this->history('pause', 12, 12);
        $this->grant('pause')->assertOk();

        $row = app(ProposalService::class)->propose($this->project, 'person', $this->owner, [
            'provider' => 'meta', 'external_account_id' => $this->account->id, 'target_type' => 'campaign', 'target_external_id' => 'cmp_1',
            'action' => 'pause', 'reason' => 'r',
        ]);

        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_a_raise_never_runs_on_its_own_and_a_decrease_needs_its_own_grant(): void
    {
        $this->history('pause', 12, 12);
        $this->history('set_daily_budget', 12, 12, budget: 40);
        $this->grant('pause')->assertOk();

        $this->assertSame('pending', $this->agentProposes('set_daily_budget', 110)->fresh()->status);   // a raise
        $this->assertSame('pending', $this->agentProposes('set_daily_budget', 80)->fresh()->status);    // a decrease, not granted

        $this->grant('budget_decrease')->assertOk();
        $this->assertSame('executed', $this->agentProposes('set_daily_budget', 70)->fresh()->status);
        $this->assertSame('pending', $this->agentProposes('resume')->fresh()->status);   // resume has no class at all
    }

    public function test_a_recent_platform_failure_withdraws_eligibility_without_anyone_noticing(): void
    {
        $this->history('pause', 12, 12);
        $this->grant('pause')->assertOk();
        ActionProposal::withoutGlobalScopes()->where('target_external_id', 'cmp_old_11')->update(['status' => 'failed']);

        $this->assertSame('pending', $this->agentProposes('pause')->fresh()->status);
        $this->assertSame(0, $this->executor->calls);
    }

    public function test_a_revoked_grant_stops_the_next_run_and_keeps_its_history(): void
    {
        $this->history('pause', 12, 12);
        $this->grant('pause')->assertOk();
        $this->grant('pause', false)->assertOk()->assertJsonPath('data.classes.0.granted', false);

        $this->assertSame('pending', $this->agentProposes('pause')->fresh()->status);
        $this->assertDatabaseHas('agent_autonomy_grants', ['project_id' => $this->project->id, 'action_class' => 'pause']);
    }

    public function test_granting_needs_ai_manage_and_the_class_s_own_permission(): void
    {
        $this->history('pause', 12, 12);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'M', 'slug' => 'm']);
        $role->givePermissionTo(...Permission::where('key', '!=', 'campaigns.pause')->pluck('key')->all());
        $user = User::create(['name' => 'm', 'email' => 'm@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        $this->actingAs($user)->putJson("/api/v1/projects/{$this->project->id}/agent-autonomy", ['class' => 'pause', 'enabled' => true])->assertForbidden();
    }
}
