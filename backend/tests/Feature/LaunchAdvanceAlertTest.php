<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Launch\LaunchPlan;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LAUNCH-ADVANCE-001 — a launched account that has since EARNED a better stage is told to a person, by evidence and never by elapsed
 * time, and nothing is changed by the telling.
 */
final class LaunchAdvanceAlertTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'L', 'slug' => 'l', 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@l.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    /** @param array<string,int|null> $figures */
    private function metric(ExternalAccount $account, array $figures, bool $demo = false, ?Project $project = null, int $daysAgo = 2): void
    {
        $row = new EntityDailyMetric(array_merge([
            'tenant_id' => $this->tenant->id, 'project_id' => ($project ?? $this->project)->id, 'external_account_id' => $account->id,
            'provider' => $account->provider, 'entity_type' => 'ad', 'entity_id' => (string) Str::uuid(),
            'external_entity_id' => 'x'.uniqid(), 'metric_date' => Carbon::now()->subDays($daysAgo)->toDateString(),
        ], $figures));
        $row->is_demo = $demo;
        $row->save();
    }

    private function url(string $tail): string
    {
        return "/api/v1/projects/{$this->project->id}/launch/{$tail}";
    }

    private function publishedSalesPlan(array $accounts, bool $withGoal = true, string $name = 'Spring — Sales'): LaunchPlan
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['id' => '5001'])->push(['id' => '5002'])->push(['id' => '5003'])]);
        $spec = [
            'name' => $name, 'objective' => 'OUTCOME_SALES', 'launch' => false,
            'accounts' => array_map(fn (ExternalAccount $a) => ['external_account_id' => (string) $a->id, 'daily_budget' => 100], $accounts),
            'tracking' => ['url' => 'https://store.example/spring'],
        ] + ($withGoal ? ['business_goal' => 'sales'] : []);
        $plan = $this->actingAs($this->owner, 'sanctum')->postJson($this->url('plans'), $spec)->assertCreated()->json('data');
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url("plans/{$plan['id']}/publish"), ['confirm' => true, 'spend_ack' => $plan['spend']['ack_token']])->assertOk();

        return LaunchPlan::query()->findOrFail($plan['id']);
    }

    private function rule(): AlertRule
    {
        return AlertRule::create(['tenant_id' => $this->tenant->id, 'type' => 'launch_advance', 'name' => 'Launch advance', 'cooldown_minutes' => 720, 'channels' => ['in_app'], 'severity' => 'info', 'active' => true]);
    }

    public function test_publishing_records_the_stage_each_account_was_on_and_only_for_a_plan_with_a_goal(): void
    {
        $this->configure('meta');
        $a = $this->account('meta', 'act_1');
        $this->metric($a, ['purchases' => 80]);

        $plan = $this->publishedSalesPlan([$a]);

        $this->assertSame('sales', $plan->spec['journey']['goal']);
        $this->assertSame([(string) $a->id => 'sales'], $plan->spec['journey']['stages']);
    }

    public function test_an_account_that_has_since_earned_a_better_stage_is_raised_once_in_both_languages_and_nothing_is_changed(): void
    {
        $this->configure('meta');
        $moved = $this->account('meta', 'act_moved');
        $still = $this->account('meta', 'act_still');
        $plan = $this->publishedSalesPlan([$moved, $still]);
        $this->assertSame(['measurement_first'], array_values(array_unique($plan->spec['journey']['stages'])));
        $before = $plan->fresh()->spec;

        // Evidence arrives for ONE account: Purchase becomes eligible.
        $this->metric($moved, ['purchases' => 90]);
        $rule = $this->rule();
        app(AlertEvaluator::class)->evaluateRule($rule);
        app(AlertEvaluator::class)->evaluateRule($rule);   // inside the cooldown: the same alert

        $events = AlertEvent::query()->where('rule_id', $rule->id)->get();
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('info', $event->severity);
        $this->assertStringContainsString('measurement_first', $event->context['message']);
        $this->assertStringContainsString('Purchase', $event->context['message']);
        $this->assertNotSame($event->context['message'], $event->context['message_ar']);
        $this->assertSame((string) $moved->id, $event->context['account_id']);
        $this->assertSame(['measurement_first', 'sales'], [$event->context['from'], $event->context['to']]);

        // Telling is not doing: the plan, its campaigns and its stages are exactly what they were.
        $this->assertSame($before, $plan->fresh()->spec);
        $this->assertSame(1, LaunchPlan::query()->count());
    }

    public function test_time_alone_never_raises_it_and_a_plan_without_a_goal_or_a_snapshot_is_silent(): void
    {
        $this->configure('meta');
        $a = $this->account('meta', 'act_1');
        $withGoal = $this->publishedSalesPlan([$a]);
        $withGoal->forceFill(['published_at' => Carbon::now()->subYears(2)])->save();
        $noGoal = $this->publishedSalesPlan([$a], withGoal: false, name: 'Autumn — Traffic');

        $rule = $this->rule();
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule), 'Long after, with no new evidence: nothing to say.');
        $this->assertArrayNotHasKey('journey', $noGoal->spec);
    }

    public function test_an_account_the_project_deselected_is_not_raised(): void
    {
        $this->configure('meta');
        $a = $this->account('meta', 'act_1');
        $this->publishedSalesPlan([$a]);
        $this->metric($a, ['purchases' => 90]);
        ProjectIntegrationBinding::query()->where('external_account_id', $a->id)->update(['is_active' => false]);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule()));
    }

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function creative(string $provider, string $externalId): ExternalCreative
    {
        return ExternalCreative::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => $provider,
            'external_creative_id' => $externalId, 'name' => 'Hero video', 'format' => 'video', 'status' => 'active',
        ]);
    }

    private function account(string $provider, string $externalId): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT-'.$provider, 'RT', Carbon::now()->addDay()),
            connectionName: $provider.' '.uniqid(),
        );
        $account = ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id,
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => $externalId,
            'name' => ucfirst($provider).' account', 'status' => 'active', 'currency' => 'SAR',
        ]);
        ProjectIntegrationBinding::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => $provider, 'purpose' => 'ads', 'is_active' => true,
        ]);

        return $account;
    }
}
