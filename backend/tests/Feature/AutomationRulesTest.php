<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Agents\Contracts\ProposalExecutor;
use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Agents\Models\AgentAutonomyGrant;
use App\Domains\Automation\Models\AutomationRule;
use App\Domains\Automation\Models\AutomationRuleAction;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Notifications\Models\AppNotification;
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
 * AUTO-RULES-001 — Owner-defined rules: what they read, what they match, and what each mode may do.
 *
 * «Loser» spent 600 over three days at ROAS 1.5; «Winner» spent 600 at ROAS 6; «Tiny» spent 20. A rule
 * «spend > 500 AND ROAS < 3 over 3 days» must match Loser only, and every action it takes goes through
 * the approval gateway in the rule's mode.
 */
final class AutomationRulesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private ExternalAccount $meta;

    /** @var array<string, ExternalCampaign> */
    private array $c = [];

    private object $executor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = $this->member('o@a.test', $role);
        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->meta = $this->account($this->project, 'meta', 'act_1');

        foreach (['Loser' => [600, 900], 'Winner' => [600, 3600], 'Tiny' => [20, 10]] as $name => [$spend, $revenue]) {
            $this->c[$name] = $this->campaign($this->project, $this->meta, $name, $spend, $revenue);
        }

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

    /** @param array<string, mixed> $over */
    private function rule(array $over = []): TestResponse
    {
        return $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules", array_replace_recursive([
            'name' => 'Cut losers', 'enabled' => true, 'mode' => 'recommend', 'level' => 'campaign', 'logic' => 'all',
            'conditions' => [
                ['metric' => 'spend', 'operator' => 'gt', 'value' => 500, 'window_days' => 3],
                ['metric' => 'roas', 'operator' => 'lt', 'value' => 3, 'window_days' => 3],
            ],
            'action' => ['type' => 'pause'], 'schedule' => ['frequency' => 'hourly', 'timezone' => 'Asia/Riyadh'], 'cooldown_hours' => 24,
        ], $over));
    }

    private function runRule(string $id): TestResponse
    {
        return $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules/{$id}/run");
    }

    public function test_a_dry_run_matches_only_what_the_conditions_say_and_changes_nothing(): void
    {
        $id = $this->rule()->assertCreated()->json('data.id');

        $res = $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules/{$id}/dry-run")->assertOk();
        $by = collect($res->json('data.results'))->keyBy('name');

        $this->assertTrue($by['Loser']['matched']);
        $this->assertSame('would_pause', $by['Loser']['status']);
        $this->assertEqualsWithDelta(1.5, $by['Loser']['metrics']['roas@3d'], 0.001);
        $this->assertFalse($by['Winner']['matched']);
        $this->assertSame(0, ActionProposal::withoutGlobalScopes()->count());
        $this->assertSame(0, AutomationRuleAction::withoutGlobalScopes()->count());
    }

    public function test_recommend_mode_files_a_pending_request_even_where_the_ladder_would_run_it(): void
    {
        AgentAutonomyGrant::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'action_class' => 'pause', 'granted_by' => $this->owner->id, 'granted_at' => Carbon::now()]);
        $id = $this->rule()->json('data.id');

        $this->runRule($id)->assertOk()->assertJsonPath('data.acted', 1);

        $proposal = ActionProposal::withoutGlobalScopes()->sole();
        $this->assertSame('pending', $proposal->status);
        $this->assertSame($this->c['Loser']->external_id, $proposal->target_external_id);
        $action = AutomationRuleAction::withoutGlobalScopes()->where('status', 'proposed')->sole();
        $this->assertSame(['status' => 'active'], $action->before);
        $this->assertSame(['status' => 'paused'], $action->after);
        $this->assertSame(0, $this->executor->calls);
    }

    public function test_auto_mode_waits_without_a_grant_and_executes_with_an_earned_one(): void
    {
        $id = $this->rule(['mode' => 'auto'])->json('data.id');
        $this->runRule($id);
        $this->assertSame('proposed', AutomationRuleAction::withoutGlobalScopes()->sole()->status);
        $this->assertSame(0, $this->executor->calls);

        // An earned record (12 approved pauses over 40 days) and the Owner's grant.
        for ($i = 0; $i < 12; $i++) {
            ActionProposal::withoutGlobalScopes()->forceCreate([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'agent_key' => 'campaign', 'provider' => 'meta',
                'external_account_id' => $this->meta->id, 'target_type' => 'campaign', 'target_external_id' => 'old_'.$i, 'action' => 'pause',
                'reason' => 'r', 'status' => 'executed', 'idempotency_key' => Str::random(40), 'decided_by' => $this->owner->id,
                'decided_at' => Carbon::now()->subDays(40 - $i), 'created_at' => Carbon::now()->subDays(40 - $i),
            ]);
        }
        AgentAutonomyGrant::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'action_class' => 'pause', 'granted_by' => $this->owner->id, 'granted_at' => Carbon::now()]);
        ActionProposal::withoutGlobalScopes()->where('status', 'pending')->update(['status' => 'rejected']);
        AutomationRuleAction::withoutGlobalScopes()->delete();   // past the cooldown, for this test

        $this->runRule($id);

        $this->assertSame('executed', AutomationRuleAction::withoutGlobalScopes()->sole()->status);
        $this->assertSame(1, $this->executor->calls);
    }

    public function test_monitor_mode_notifies_the_author_and_asks_for_nothing(): void
    {
        $id = $this->rule(['mode' => 'monitor'])->json('data.id');

        $this->runRule($id);

        $this->assertSame('notified', AutomationRuleAction::withoutGlobalScopes()->sole()->status);
        $this->assertSame(0, ActionProposal::withoutGlobalScopes()->count());
        $this->assertSame(1, AppNotification::withoutGlobalScopes()->where('user_id', $this->owner->id)->where('type', 'automation_rule')->count());
    }

    public function test_the_cooldown_keeps_the_same_object_from_being_acted_on_twice(): void
    {
        $id = $this->rule()->json('data.id');
        $this->runRule($id);
        $this->runRule($id);

        $this->assertSame(['proposed', 'skipped_cooldown'], AutomationRuleAction::withoutGlobalScopes()->orderBy('created_at')->pluck('status')->all());
    }

    public function test_an_opposite_budget_move_inside_the_cooldown_is_a_conflict(): void
    {
        $up = $this->rule(['name' => 'Up', 'action' => ['type' => 'budget_increase', 'percent' => 10]])->json('data.id');
        $down = $this->rule(['name' => 'Down', 'action' => ['type' => 'budget_decrease', 'percent' => 10]])->json('data.id');
        $this->runRule($up);
        ActionProposal::withoutGlobalScopes()->update(['status' => 'rejected']);   // no open request; the rule history still conflicts

        $this->runRule($down);

        $this->assertSame('skipped_conflict', AutomationRuleAction::withoutGlobalScopes()->where('rule_id', $down)->sole()->status);
    }

    public function test_a_budget_rule_names_the_before_and_after_and_the_gateway_still_caps_it(): void
    {
        $id = $this->rule(['action' => ['type' => 'budget_increase', 'percent' => 50]])->json('data.id');

        $this->runRule($id);

        $a = AutomationRuleAction::withoutGlobalScopes()->sole();
        $this->assertEquals(['daily_budget' => 100, 'currency' => 'USD'], $a->before);
        $this->assertSame(150, (int) $a->after['daily_budget']);
        $this->assertSame('blocked', $a->status, 'a 50% raise is above the gateway cap of 20%');
        $this->assertStringContainsString('increase_exceeds_cap', (string) $a->note);
    }

    public function test_unsupported_actions_and_missing_credentials_are_said_as_such(): void
    {
        $bid = $this->rule(['name' => 'Bid', 'action' => ['type' => 'bid_adjust']])->json('data.id');
        $this->runRule($bid);
        $this->assertSame('unsupported_action', AutomationRuleAction::withoutGlobalScopes()->where('rule_id', $bid)->sole()->status);

        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", null);
        }
        $pause = $this->rule(['name' => 'Pause'])->json('data.id');
        $this->runRule($pause);
        $this->assertSame('awaiting_credentials', AutomationRuleAction::withoutGlobalScopes()->where('rule_id', $pause)->sole()->status);
        $this->assertSame(0, ActionProposal::withoutGlobalScopes()->count());
    }

    public function test_below_the_minimum_spend_nothing_is_judged(): void
    {
        $id = $this->rule(['min_spend' => 1000])->json('data.id');

        $res = $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules/{$id}/dry-run")->assertOk();

        $this->assertSame([], collect($res->json('data.results'))->where('matched', true)->values()->all());
        $this->assertSame('skipped_insufficient_data', collect($res->json('data.results'))->firstWhere('name', 'Loser')['status']);
    }

    /** A platform that never sends revenue can never match a ROAS condition — and the history says «no figure». */
    public function test_a_metric_the_platform_never_reported_is_not_judged_and_says_so(): void
    {
        $snap = $this->account($this->project, 'snapchat', 'sn_1');
        $noRevenue = ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $snap->id, 'provider' => 'snapchat',
            'external_id' => 'sn-'.random_int(1000, 9999), 'name' => 'Awareness', 'status' => 'active', 'currency' => 'USD', 'daily_budget' => 100,
            'starts_at' => Carbon::now()->subDays(20),
        ]);
        foreach (['spend' => 200, 'impressions' => 40000, 'clicks' => 300] as $k => $v) {
            foreach ([0, 1, 2] as $d) {
                DailyMetric::withoutGlobalScopes()->create([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $snap->id,
                    'external_campaign_id' => $noRevenue->id, 'provider' => 'snapchat', 'metric_key' => $k, 'metric_date' => Carbon::now('Asia/Riyadh')->subDays($d)->toDateString(), 'value' => $v,
                ]);
            }
        }

        $id = $this->rule(['name' => 'Low ROAS', 'scope' => ['platforms' => ['snapchat']], 'conditions' => [['metric' => 'roas', 'operator' => 'lt', 'value' => 3, 'window_days' => 3]]])->json('data.id');
        $row = collect($this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules/{$id}/dry-run")->assertOk()->json('data.results'))->firstWhere('name', 'Awareness');

        $this->assertFalse($row['matched']);
        $this->assertSame('skipped_no_figure', $row['status'], 'no revenue was reported: the campaign was not judged, which is not the same as not matching');
        $this->assertNull($row['metrics']['roas@3d']);
    }

    /** An unmeasured condition does not hide a measured one that already failed: that is an honest miss. */
    public function test_a_measured_miss_is_still_just_not_matched(): void
    {
        $snap = $this->account($this->project, 'snapchat', 'sn_2');
        $c = ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $snap->id, 'provider' => 'snapchat',
            'external_id' => 'sn-'.random_int(1000, 9999), 'name' => 'Small', 'status' => 'active', 'currency' => 'USD', 'daily_budget' => 100,
            'starts_at' => Carbon::now()->subDays(20),
        ]);
        foreach ([0, 1, 2] as $d) {
            DailyMetric::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $snap->id,
                'external_campaign_id' => $c->id, 'provider' => 'snapchat', 'metric_key' => 'spend', 'metric_date' => Carbon::now('Asia/Riyadh')->subDays($d)->toDateString(), 'value' => 5,
            ]);
        }

        // Spend is 15 over three days (measured, fails «> 500»); ROAS has no figure (revenue never reported).
        $id = $this->rule(['name' => 'Both', 'scope' => ['platforms' => ['snapchat']]])->json('data.id');
        $row = collect($this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules/{$id}/dry-run")->assertOk()->json('data.results'))->firstWhere('name', 'Small');

        $this->assertSame('not_matched', $row['status']);
    }

    public function test_the_builder_learns_which_platforms_report_which_figures(): void
    {
        $this->account($this->project, 'tiktok', 'tt_1'); // bound, nothing synced yet
        $other = Project::create(['client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Other', 'status' => 'active']);
        $theirs = $this->account($other, 'google', 'g_1');
        $this->campaign($other, $theirs, 'Elsewhere', 100, 300);

        $data = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/automation/metric-support")->assertOk()->json('data');

        $this->assertSame(['has_data' => true, 'reported' => ['clicks', 'conversions', 'impressions', 'revenue', 'spend']], $data['providers']['meta']);
        $this->assertSame(['has_data' => false, 'reported' => []], $data['providers']['tiktok'], 'a platform with nothing synced is not known, not unsupported');
        $this->assertArrayNotHasKey('google', $data['providers'], 'another project\'s account never appears');

        $catalog = collect($this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/automation/catalog")->assertOk()->json('data.metrics'))->keyBy('key');
        $this->assertSame(['revenue', 'spend'], $catalog['roas']['needs']);
        $this->assertSame([], $catalog['age_days']['needs']);
    }

    public function test_the_scheduler_runs_due_enabled_rules_and_books_the_next_run(): void
    {
        $on = $this->rule()->json('data.id');
        $off = $this->rule(['name' => 'Off', 'enabled' => false])->json('data.id');
        AutomationRule::withoutGlobalScopes()->whereKey($on)->update(['next_run_at' => Carbon::now()->subMinute()]);

        $this->artisan('automation:evaluate')->assertSuccessful();

        $rule = AutomationRule::withoutGlobalScopes()->find($on);
        $this->assertNotNull($rule->last_run_at);
        $this->assertTrue($rule->next_run_at->isFuture());
        $this->assertNull(AutomationRule::withoutGlobalScopes()->find($off)->last_run_at);
    }

    public function test_a_rule_never_reaches_another_project(): void
    {
        $other = Project::create(['client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Other', 'status' => 'active']);
        $acct = $this->account($other, 'meta', 'act_2');
        $foreign = $this->campaign($other, $acct, 'Foreign loser', 600, 900);
        $id = $this->rule(['scope' => ['campaign_ids' => [$foreign->id]]])->json('data.id');

        $res = $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules/{$id}/dry-run")->assertOk();

        $this->assertSame([], $res->json('data.results'));
        $this->actingAs($this->owner)->getJson("/api/v1/projects/{$other->id}/automation/rules")->assertOk()->assertJsonCount(0, 'data.rules');
    }

    public function test_writing_rules_needs_the_change_s_own_permission_and_auto_needs_ai_manage(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'M', 'slug' => 'm']);
        $role->givePermissionTo(...Permission::whereNotIn('key', ['campaigns.pause', 'ai.manage'])->pluck('key')->all());
        $user = $this->member('m@a.test', $role);

        $this->actingAs($user)->postJson("/api/v1/projects/{$this->project->id}/automation/rules", [
            'name' => 'x', 'enabled' => true, 'mode' => 'recommend', 'level' => 'campaign', 'logic' => 'all',
            'conditions' => [['metric' => 'spend', 'operator' => 'gt', 'value' => 1, 'window_days' => 3]],
            'action' => ['type' => 'pause'], 'schedule' => ['frequency' => 'hourly'], 'cooldown_hours' => 24,
        ])->assertForbidden();

        $this->actingAs($user)->postJson("/api/v1/projects/{$this->project->id}/automation/rules", [
            'name' => 'x', 'enabled' => true, 'mode' => 'monitor', 'level' => 'campaign', 'logic' => 'all',
            'conditions' => [['metric' => 'spend', 'operator' => 'gt', 'value' => 1, 'window_days' => 3]],
            'action' => ['type' => 'pause'], 'schedule' => ['frequency' => 'hourly'], 'cooldown_hours' => 24,
        ])->assertCreated();
    }

    public function test_the_summary_and_the_change_log_say_what_happened(): void
    {
        $id = $this->rule()->json('data.id');
        $this->runRule($id);
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules/{$id}/toggle", ['enabled' => false])->assertOk();

        $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/automation/summary")
            ->assertOk()->assertJsonPath('data.all', 1)->assertJsonPath('data.turn_off', 1)->assertJsonPath('data.awaiting_approval', 1)->assertJsonPath('data.rules_disabled', 1);
        $log = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/automation/change-log")->assertOk()->json('data.changes');
        $this->assertEqualsCanonicalizing(['automation.rule.disabled', 'automation.rule.created'], array_column($log, 'action'));
        $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/automation/actions")->assertOk()->assertJsonPath('data.actions.0.status', 'proposed');
    }

    public function test_the_builder_lists_only_this_project_s_bound_objects(): void
    {
        $other = Project::create(['client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Other', 'status' => 'active']);
        $this->campaign($other, $this->account($other, 'meta', 'act_9'), 'Foreign', 1, 1);

        $data = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/automation/targets")->assertOk()->json('data');

        $this->assertSame(['Loser', 'Tiny', 'Winner'], array_column($data['campaigns'], 'name'));
        $this->assertSame(['act_1'], array_column($data['accounts'], 'name'));

        // A store bound to the project is not an advertising account a rule can act on.
        $store = $this->account($this->project, 'salla', 'store_1');
        $store->forceFill(['account_type' => 'store'])->save();
        $data = $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/automation/targets")->assertOk()->json('data');
        $this->assertSame(['act_1'], array_column($data['accounts'], 'name'));
    }

    public function test_a_condition_value_must_fit_its_metric(): void
    {
        $this->rule(['conditions' => [['metric' => 'delivery_status', 'operator' => 'gt', 'value' => 'active', 'window_days' => 1]]])->assertStatus(422);
        $this->rule(['action' => ['type' => 'budget_decrease']])->assertStatus(422);
    }

    private function member(string $email, Role $role): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function account(Project $project, string $provider, string $externalId): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDay()), connectionName: $provider.uniqid());
        $account = ExternalAccount::create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active', 'currency' => 'USD']);
        ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'ads', 'is_active' => true]);

        return $account;
    }

    private function campaign(Project $project, ExternalAccount $account, string $name, float $spend, float $revenue): ExternalCampaign
    {
        $c = ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id, 'provider' => 'meta',
            'external_id' => (string) random_int(100000, 999999), 'name' => $name, 'status' => 'active', 'currency' => 'USD', 'daily_budget' => 100,
            'starts_at' => Carbon::now()->subDays(20),
        ]);
        foreach ([0, 1, 2] as $d) {
            foreach (['spend' => $spend / 3, 'revenue' => $revenue / 3, 'impressions' => 10000, 'clicks' => 100, 'conversions' => 2] as $k => $v) {
                DailyMetric::withoutGlobalScopes()->create([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
                    'external_campaign_id' => $c->id, 'provider' => 'meta', 'metric_key' => $k, 'metric_date' => Carbon::now('Asia/Riyadh')->subDays($d)->toDateString(), 'value' => $v,
                ]);
            }
        }

        return $c;
    }
}
