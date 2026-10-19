<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Agents\Models\ClientAgentRule;
use App\Domains\Agents\Services\AgentReadTools;
use App\Domains\Agents\Support\ProposalRules;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AGENTS-CLIENT-RULES-001 / AGENTS-TOOLS-READ-001 — a client's goals, and the figures agents judge them by.
 *
 * The comparison is built from `MetricsAggregator`, so the numbers are the report's own; a campaign below
 * its minimum spend is «not judged», and a KPI that was not measured is «no figure» — never a zero.
 */
final class AgentClientRulesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        Sanctum::actingAs($this->owner);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    private function url(string $s = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/agent-rules{$s}";
    }

    private function campaign(string $name, float $spend, float $conversions): UnifiedCampaign
    {
        $c = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => $name, 'status' => 'active', 'objective' => 'sales']);
        foreach (['spend' => $spend, 'conversions' => $conversions, 'impressions' => 1000, 'clicks' => 50] as $k => $v) {
            DailyMetric::create([
                'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $c->id, 'provider' => 'meta',
                'metric_key' => $k, 'metric_date' => Carbon::yesterday()->toDateString(), 'value' => $v,
            ]);
        }

        return $c;
    }

    public function test_rules_and_limits_are_saved_as_one_set_and_read_back(): void
    {
        $this->putJson($this->url(), [
            'rules' => [
                ['kpi' => 'cpa', 'direction' => 'max', 'target_value' => 20, 'min_spend_to_judge' => 50, 'forbidden_words' => ['مجاني'], 'special_days' => [['date' => '2026-10-24', 'label' => 'مباراة']]],
                ['platform' => 'snapchat', 'kpi' => 'roas', 'direction' => 'min', 'target_value' => 3],
            ],
            'limits' => ['max_increase_percent' => 10, 'daily_budget_ceiling' => 500],
        ])->assertOk()->assertJsonCount(2, 'data.rules')->assertJsonPath('data.limits.max_increase_percent', 10)->assertJsonPath('data.limits.daily_budget_ceiling', 500);

        // The gateway reads the same limits.
        $this->assertSame(10.0, app(ProposalRules::class)->limitsFor($this->project->fresh())['max_increase_percent']);

        // Replacing the set removes what was left out.
        $this->putJson($this->url(), ['rules' => [['kpi' => 'cpa', 'direction' => 'max', 'target_value' => 25]]])->assertOk()->assertJsonCount(1, 'data.rules');
    }

    public function test_a_rule_names_a_known_kpi_and_a_direction(): void
    {
        $this->putJson($this->url(), ['rules' => [['kpi' => 'happiness', 'direction' => 'max', 'target_value' => 1]]])->assertStatus(422);
        $this->putJson($this->url(), ['rules' => [['kpi' => 'cpa', 'direction' => 'sideways', 'target_value' => 1]]])->assertStatus(422);
        $this->putJson($this->url(), ['rules' => [['kpi' => 'cpa', 'direction' => 'max', 'target_value' => 1], ['kpi' => 'cpa', 'direction' => 'max', 'target_value' => 2]]])->assertStatus(422);
    }

    public function test_campaigns_are_judged_against_the_rules_with_the_reports_own_figures(): void
    {
        $this->campaign('Cheap', 100, 10);      // CPA 10
        $this->campaign('Dear', 100, 2);        // CPA 50
        $this->campaign('Too new', 10, 1);      // CPA 10 but under the minimum spend
        $this->campaign('No results', 100, 0);  // CPA not measurable

        ClientAgentRule::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'kpi' => 'cpa', 'direction' => 'max', 'target_value' => 20, 'min_spend_to_judge' => 50]);

        $verdicts = collect($this->getJson($this->url('/compare'))->assertOk()->json('data.verdicts'))->pluck('verdict', 'campaign_name')->all();

        $this->assertSame('on_target', $verdicts['Cheap']);
        $this->assertSame('off_target', $verdicts['Dear']);
        $this->assertSame('not_judged', $verdicts['Too new']);
        $this->assertSame('no_figure', $verdicts['No results'], 'an unmeasured CPA is not a zero CPA');
    }

    public function test_the_read_tools_answer_with_their_window(): void
    {
        $this->campaign('Cheap', 100, 10);

        $out = app(AgentReadTools::class)->listCampaigns($this->project, 7);

        $this->assertSame(Carbon::yesterday()->toDateString(), $out['window']['to']);
        $this->assertSame('Cheap', $out['campaigns'][0]['campaign_name']);
        $this->assertEquals(10.0, $out['campaigns'][0]['cpa']);
        $this->assertArrayNotHasKey('reported', $out['campaigns'][0], 'internals stay out of what an agent reads');
    }

    public function test_reading_needs_ai_view_and_writing_needs_ai_manage(): void
    {
        $viewer = User::create(['name' => 'V', 'email' => 'v@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        // Everything an owner holds except the right to change the AI set-up.
        $role->givePermissionTo(...Permission::where('key', '!=', 'ai.manage')->pluck('key')->all());
        $viewer->assignRole($role);
        Sanctum::actingAs($viewer);

        $this->getJson($this->url())->assertOk();
        $this->putJson($this->url(), ['rules' => []])->assertForbidden();
    }
}
