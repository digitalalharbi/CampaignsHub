<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Agents\Contracts\ProposalExecutor;
use App\Domains\Agents\Models\ActionLog;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * AGENTS-APPROVAL-GATEWAY-001 — an agent can only ASK; a person decides; the rules live in code.
 *
 * Each case names one rule and asserts it from the outside: what the API answers and what was (not) sent
 * to a platform. `Http::fake()` with `assertNothingSent()` is the proof that no approval path reaches a
 * provider unless an executor was deliberately enabled.
 */
final class AgentApprovalGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $asker;

    private User $approver;

    private Project $project;

    private ExternalAccount $account;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->asker = $this->member('asker@ag.test', $role);
        $this->approver = $this->member('approver@ag.test', $role);

        $ws = ClientWorkspace::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $this->account = $this->account('meta', 'act_1');
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
        ]);

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->account->id,
            'provider' => 'meta', 'external_id' => 'cmp_1', 'name' => 'Spring', 'status' => 'active', 'daily_budget' => 100,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/agent-proposals{$suffix}";
    }

    /** @param array<string, mixed> $over */
    private function body(array $over = []): array
    {
        return $over + [
            'provider' => 'meta', 'external_account_id' => $this->account->id, 'target_type' => 'campaign',
            'target_external_id' => 'cmp_1', 'action' => 'set_daily_budget', 'payload' => ['daily_budget' => 110],
            'reason' => 'ROAS is twice the target',
        ];
    }

    private function propose(array $over = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->asker)->postJson($this->url(), $this->body($over));
    }

    // ── What may be asked ──────────────────────────────────────────────────────────────────────

    public function test_a_raise_inside_the_cap_waits_for_approval(): void
    {
        $this->propose()->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.agent', 'person');
        $this->assertSame(['proposed'], ActionLog::withoutGlobalScopes()->pluck('phase')->all());
    }

    public function test_a_raise_beyond_the_cap_is_stored_blocked_and_cannot_be_approved(): void
    {
        $id = $this->propose(['payload' => ['daily_budget' => 125]])
            ->assertCreated()->assertJsonPath('data.status', 'blocked')->assertJsonPath('data.violations.0', 'increase_exceeds_cap')->json('data.id');

        $this->actingAs($this->approver)->postJson($this->url("/{$id}/approve"))->assertStatus(409);
    }

    public function test_lowering_a_budget_is_never_capped(): void
    {
        $this->propose(['payload' => ['daily_budget' => 10]])->assertCreated()->assertJsonPath('data.status', 'pending');
    }

    public function test_a_project_ceiling_blocks_a_raise_above_it(): void
    {
        $this->project->forceFill(['meta' => ['agent_limits' => ['daily_budget_ceiling' => 105]]])->save();

        $this->propose()->assertCreated()->assertJsonPath('data.status', 'blocked')->assertJsonPath('data.violations.0', 'above_daily_ceiling');
    }

    public function test_the_vocabulary_is_closed_there_is_no_delete(): void
    {
        $this->propose(['action' => 'delete_campaign'])->assertStatus(422);

        // And a caller that bypasses the API (an agent calling the service) is blocked by the same rule.
        $row = app(ProposalService::class)->propose($this->project, 'campaign', null, $this->body(['action' => 'delete_campaign']));
        $this->assertSame('blocked', $row->status);
        $this->assertSame(['action_not_allowed'], $row->violations);
    }

    public function test_a_zero_budget_is_a_pause_and_must_be_asked_as_one(): void
    {
        $this->propose(['payload' => ['daily_budget' => 0]])->assertJsonPath('data.violations.0', 'budget_invalid');
    }

    public function test_an_account_not_bound_to_the_project_is_refused(): void
    {
        $other = $this->account('meta', 'act_unbound');

        $this->propose(['external_account_id' => $other->id])->assertJsonPath('data.status', 'blocked')->assertJsonPath('data.violations.0', 'account_not_bound_to_project');
    }

    public function test_another_tenants_account_is_refused(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $theirs = $this->account('meta', 'act_theirs', $other);

        $this->propose(['external_account_id' => $theirs->id])->assertJsonPath('data.status', 'blocked')->assertJsonPath('data.violations.0', 'account_not_in_tenant');
    }

    public function test_a_duplicate_of_an_open_request_is_the_same_request(): void
    {
        $a = $this->propose()->json('data.id');
        $b = $this->propose()->json('data.id');

        $this->assertSame($a, $b);
        $this->assertSame(1, ActionProposal::withoutGlobalScopes()->count());
    }

    // ── Who may decide, and what a decision does ───────────────────────────────────────────────

    public function test_nobody_approves_their_own_request(): void
    {
        $id = $this->propose()->json('data.id');

        $this->actingAs($this->asker)->postJson($this->url("/{$id}/approve"))->assertForbidden();
    }

    public function test_a_person_without_the_budget_permission_cannot_approve_a_budget_change(): void
    {
        $id = $this->propose()->json('data.id');
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Pauser', 'slug' => 'pauser']);
        $role->givePermissionTo('campaigns.pause', 'campaigns.view', 'ai.view');
        $pauser = $this->member('pauser@ag.test', $role);

        $this->actingAs($pauser)->postJson($this->url("/{$id}/approve"))->assertForbidden();
    }

    public function test_approving_without_an_enabled_executor_reaches_no_platform(): void
    {
        Http::fake();
        config()->set('agents.execute_providers', []);   // no provider enabled for execution
        $id = $this->propose()->json('data.id');

        $this->actingAs($this->approver)->postJson($this->url("/{$id}/approve"))
            ->assertOk()->assertJsonPath('data.status', 'blocked')->assertJsonPath('data.violations.0', 'execution_not_enabled_for_provider');

        Http::assertNothingSent();
        $this->assertSame(['proposed', 'approved', 'blocked'], ActionLog::withoutGlobalScopes()->orderBy('created_at')->pluck('phase')->all());
    }

    public function test_an_approved_proposal_is_executed_once_and_logged_with_the_platforms_answer(): void
    {
        $executor = new class implements ProposalExecutor
        {
            public int $calls = 0;

            public function supports(ActionProposal $p): bool
            {
                return $p->provider === 'meta';
            }

            public function execute(ActionProposal $p): array
            {
                $this->calls++;

                return ['request' => ['id' => $p->target_external_id, 'daily_budget' => $p->payload['daily_budget'], 'key' => $p->getKey()], 'response' => ['success' => true]];
            }
        };
        $this->app->instance('test-executor', $executor);
        config()->set('agents.executors', ['test-executor']);

        $id = $this->propose()->json('data.id');
        $this->actingAs($this->approver)->postJson($this->url("/{$id}/approve"), ['note' => 'ok'])->assertOk()->assertJsonPath('data.status', 'executed');

        $this->assertSame(1, $executor->calls);
        $log = ActionLog::withoutGlobalScopes()->where('phase', 'executed')->firstOrFail();
        $this->assertSame(['success' => true], $log->api_response);
        $this->assertSame($id, $log->api_request['key']);

        // Approving again cannot execute it twice.
        $this->actingAs($this->approver)->postJson($this->url("/{$id}/approve"))->assertStatus(409);
        $this->assertSame(1, $executor->calls);
    }

    public function test_the_rules_are_read_again_at_approval_because_the_world_moves(): void
    {
        $id = $this->propose()->json('data.id');   // 100 → 110: inside the cap
        $this->campaign->forceFill(['daily_budget' => 50])->save();   // someone lowered it by hand: 50 → 110 is a 120% raise

        $this->actingAs($this->approver)->postJson($this->url("/{$id}/approve"))
            ->assertOk()->assertJsonPath('data.status', 'blocked')->assertJsonPath('data.violations.0', 'increase_exceeds_cap');
    }

    public function test_a_rejection_is_recorded_and_final(): void
    {
        $id = $this->propose()->json('data.id');

        $this->actingAs($this->approver)->postJson($this->url("/{$id}/reject"), ['note' => 'not now'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->actingAs($this->approver)->postJson($this->url("/{$id}/approve"))->assertStatus(409);
    }

    public function test_an_expired_proposal_cannot_be_approved(): void
    {
        $id = $this->propose()->json('data.id');
        ActionProposal::withoutGlobalScopes()->whereKey($id)->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->actingAs($this->approver)->postJson($this->url("/{$id}/approve"))->assertStatus(409);
        $this->assertSame('expired', ActionProposal::withoutGlobalScopes()->find($id)->status);
    }

    public function test_another_tenants_proposal_is_not_found(): void
    {
        $id = $this->propose()->json('data.id');
        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'O', 'slug' => 'o2']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $stranger = $this->member('x@other.test', $role, $other);

        $this->actingAs($stranger)->postJson($this->url("/{$id}/approve"))->assertStatus(404);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────

    /** SECURITY CLUSTER 5 — the currency of an account that is not this project's is not read, let alone returned. */
    public function test_a_proposal_does_not_read_the_currency_of_an_account_outside_the_project(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        $foreign = $this->account('meta', 'act_foreign', $otherTenant);
        $unbound = $this->account('meta', 'act_unbound');

        foreach ([$foreign, $unbound] as $account) {
            $this->propose(['external_account_id' => $account->id])
                ->assertCreated()->assertJsonPath('data.status', 'blocked')->assertJsonPath('data.currency', null);
        }

        // The bound account's own currency is still recorded.
        $this->propose(['payload' => ['daily_budget' => 105]])->assertCreated()->assertJsonPath('data.currency', 'USD');
    }

    private function member(string $email, Role $role, ?Tenant $tenant = null): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $this->grantMembership($user, $tenant ?? $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function account(string $provider, string $externalId, ?Tenant $tenant = null): ExternalAccount
    {
        $tenant ??= $this->tenant;
        $credential = new IntegrationCredential(['tenant_id' => $tenant->id, 'provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => $provider, 'connection_name' => $provider.$externalId, 'scope' => 'project_only', 'status' => 'connected']);

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => $externalId, 'name' => $externalId, 'status' => 'active', 'currency' => 'USD',
        ]);
    }
}
