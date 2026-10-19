<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Agents\Engine\AgentToolbox;
use App\Domains\Agents\Support\AgentRoles;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Automation\Models\AutomationRule;
use App\Domains\Automation\Services\RuleEvaluator;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AUTO-RULES-AGENTS-009 — the agents work ON the Automated Rules: they read them, explain a run from the
 * rows the dashboard shows, and save a rule only as a draft a person switches on. Chat is the second door.
 */
final class AutomationAgentToolsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private Project $other;

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

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->other = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Q', 'status' => 'active']);

        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDay()), connectionName: 'meta'.uniqid());
        $account = ExternalAccount::create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta', 'account_type' => 'ad_account', 'external_id' => 'act_1', 'name' => 'act_1', 'status' => 'active', 'currency' => 'USD']);
        ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'ads', 'is_active' => true]);
        $campaign = ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'provider' => 'meta',
            'external_id' => '123456', 'name' => 'Spring', 'status' => 'active', 'currency' => 'USD', 'daily_budget' => 100, 'starts_at' => Carbon::now()->subDays(20),
        ]);
        foreach ([0, 1, 2] as $d) {
            foreach (['spend' => 200, 'revenue' => 300, 'impressions' => 10000, 'clicks' => 100, 'conversions' => 2] as $k => $v) {
                DailyMetric::withoutGlobalScopes()->create([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
                    'external_campaign_id' => $campaign->id, 'provider' => 'meta', 'metric_key' => $k, 'metric_date' => Carbon::now('Asia/Riyadh')->subDays($d)->toDateString(), 'value' => $v,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function tool(string $agent, string $tool, array $input = [], ?Project $project = null): array
    {
        $result = app(AgentToolbox::class)->call($project ?? $this->project, $this->owner, $agent, AgentRoles::get($agent)['tools'] ?? [], $tool, $input);

        return [json_decode($result['content'], true), $result['ok']];
    }

    /** @return array<string, mixed> */
    private function draftInput(array $over = []): array
    {
        return array_replace([
            'name' => 'Pause low ROAS',
            'conditions' => [['metric' => 'roas', 'operator' => 'lt', 'window_days' => 3, 'value' => 2], ['metric' => 'spend', 'operator' => 'gt', 'window_days' => 3, 'value' => 300]],
            'action' => ['type' => 'pause'],
        ], $over);
    }

    public function test_a_draft_is_saved_off_and_in_monitor_only_whatever_the_agent_asked(): void
    {
        [$out, $ok] = $this->tool('campaign', 'draft_automation_rule', $this->draftInput(['mode' => 'auto', 'enabled' => true]));

        $this->assertTrue($ok);
        $rule = AutomationRule::query()->findOrFail($out['rule_id']);
        $this->assertFalse($rule->enabled);
        $this->assertSame('monitor', $rule->mode);
        $this->assertNull($rule->next_run_at, 'a draft is never scheduled');
        $this->assertSame('agent', $rule->origin);
        $this->assertSame('campaign', $rule->drafted_by_agent);
        $this->assertSame((string) $this->project->id, (string) $rule->project_id);
        $this->assertTrue(AuditLog::query()->where('action', 'automation.rule.drafted_by_agent')->where('entity_id', $rule->id)->exists());
    }

    public function test_a_draft_passes_the_builders_own_validation(): void
    {
        [$out, $ok] = $this->tool('campaign', 'draft_automation_rule', $this->draftInput(['conditions' => [['metric' => 'vibes', 'operator' => 'gt', 'window_days' => 3, 'value' => 1]]]));
        $this->assertFalse($ok);
        $this->assertArrayHasKey('error', $out);

        [, $ok] = $this->tool('campaign', 'draft_automation_rule', $this->draftInput(['action' => ['type' => 'budget_decrease']]));
        $this->assertFalse($ok, 'a budget change needs a percentage');
        $this->assertSame(0, AutomationRule::query()->count());
    }

    public function test_only_the_campaign_agent_may_draft_and_reading_is_for_every_working_agent(): void
    {
        [$out, $ok] = $this->tool('analyst', 'draft_automation_rule', $this->draftInput());
        $this->assertFalse($ok);
        $this->assertStringContainsString('not available', $out['error']);

        foreach (['analyst', 'campaign', 'orchestrator'] as $agent) {
            $this->assertContains('list_automation_rules', AgentRoles::get($agent)['tools']);
            $this->assertContains('explain_rule_run', AgentRoles::get($agent)['tools']);
        }
        $this->assertSame(['campaign'], array_keys(array_filter(AgentRoles::all(), static fn (array $r): bool => in_array('draft_automation_rule', $r['tools'], true))));
    }

    public function test_listing_and_explaining_stay_inside_the_project(): void
    {
        [$draft] = $this->tool('campaign', 'draft_automation_rule', $this->draftInput());
        [$foreign] = $this->tool('campaign', 'draft_automation_rule', $this->draftInput(['name' => 'Elsewhere']), $this->other);

        [$list] = $this->tool('analyst', 'list_automation_rules');
        $this->assertSame([$draft['rule_id']], array_column($list['rules'], 'id'));
        $this->assertSame('agent', $list['rules'][0]['origin']);

        [$out, $ok] = $this->tool('analyst', 'explain_rule_run', ['rule_id' => $foreign['rule_id']]);
        $this->assertFalse($ok);
        $this->assertStringContainsString('not in this project', $out['error']);
    }

    public function test_an_explanation_carries_the_figures_the_rule_read(): void
    {
        [$draft] = $this->tool('campaign', 'draft_automation_rule', $this->draftInput());
        $rule = AutomationRule::query()->findOrFail($draft['rule_id']);

        [$before] = $this->tool('analyst', 'explain_rule_run', ['rule_id' => $rule->id]);
        $this->assertSame('This rule has not run yet.', $before['note']);

        // A dry run keeps its totals only, and the explanation says so rather than inventing targets.
        app(RuleEvaluator::class)->evaluate($rule, 'manual', true);
        [$dry] = $this->tool('analyst', 'explain_rule_run', ['rule_id' => $rule->id]);
        $this->assertTrue($dry['runs'][0]['dry_run']);
        $this->assertSame([], $dry['latest_run_targets']);
        $this->assertStringContainsString('dry run', $dry['note']);

        // A real run of a Monitor rule notifies and records each target with the figures it read.
        $this->travel(5)->minutes();
        app(RuleEvaluator::class)->evaluate($rule, 'manual', false);
        [$out, $ok] = $this->tool('analyst', 'explain_rule_run', ['rule_id' => $rule->id]);

        $this->assertTrue($ok);
        $this->assertCount(2, $out['runs']);
        $this->assertFalse($out['runs'][0]['dry_run']);
        $this->assertSame('notified', $out['latest_run_targets'][0]['status']);
        $this->assertSame('Spring', $out['latest_run_targets'][0]['target']);
        $this->assertEqualsWithDelta(600.0, (float) $out['latest_run_targets'][0]['metrics']['spend@3d'], 0.01);
    }

    public function test_a_person_switches_a_draft_on_and_it_still_changes_nothing_until_they_choose_a_mode(): void
    {
        [$draft] = $this->tool('campaign', 'draft_automation_rule', $this->draftInput());

        $this->actingAs($this->owner)
            ->postJson("/api/v1/projects/{$this->project->id}/automation/rules/{$draft['rule_id']}/toggle", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.mode', 'monitor')
            ->assertJsonPath('data.origin', 'agent');
    }
}
