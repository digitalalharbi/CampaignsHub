<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Launch\LaunchPlan;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LAUNCH-SPEND-001 — a launch that RUNS campaigns needs the person's own proof that they saw what it commits to, one publisher at
 * a time may send a plan, and «automated» is not a mode anyone can launch with until an authorisation exists.
 */
final class LaunchSafetyTest extends TestCase
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

    private function draft(bool $launch): array
    {
        $account = $this->account('meta', 'act_9');
        $creative = $this->creative('meta', '2384001');

        return $this->actingAs($this->owner, 'sanctum')->postJson($this->url('plans'), $this->spec($account, $creative, launch: $launch))->assertCreated()->json('data');
    }

    private function publish(array $plan, array $extra = [])
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson($this->url("plans/{$plan['id']}/publish"), ['confirm' => true] + $extra);
    }

    public function test_a_plan_that_runs_campaigns_cannot_be_published_without_the_spend_approval_and_nothing_is_sent(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);
        $plan = $this->draft(launch: true);

        $this->assertTrue($plan['spend']['ack_required']);
        $this->assertEquals(['SAR' => 200], $plan['spend']['daily_by_currency']);
        $this->publish($plan)->assertStatus(422);
        $this->publish($plan, ['spend_ack' => 'not-the-token'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_the_approval_is_bound_to_the_numbers_it_was_given_for(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);
        $plan = $this->draft(launch: true);
        $stale = $plan['spend']['ack_token'];

        // The budget is raised after the person looked: yesterday's approval must not launch today's number.
        $spec = $plan['spec'];
        $spec['accounts'][0]['daily_budget'] = 9000;
        $changed = $this->actingAs($this->owner, 'sanctum')->putJson($this->url("plans/{$plan['id']}"), $spec)->assertOk()->json('data');

        $this->assertNotSame($stale, $changed['spend']['ack_token']);
        $this->publish($changed, ['spend_ack' => $stale])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_plan_created_paused_spends_nothing_and_asks_for_no_approval(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['id' => '5001'])->push(['id' => '6001'])->push(['id' => '7001'])]);
        $plan = $this->draft(launch: false);

        $this->assertFalse($plan['spend']['ack_required']);
        $this->publish($plan)->assertOk();
    }

    public function test_a_second_publisher_is_refused_while_the_first_is_running_and_nothing_is_sent_twice(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);
        $plan = $this->draft(launch: false);
        LaunchPlan::query()->whereKey($plan['id'])->update(['status' => LaunchPlan::PUBLISHING, 'updated_at' => Carbon::now()]);

        $this->publish($plan)->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_a_claim_left_by_a_run_that_died_can_be_resumed(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['id' => '5001'])->push(['id' => '6001'])->push(['id' => '7001'])]);
        $plan = $this->draft(launch: false);
        LaunchPlan::query()->whereKey($plan['id'])->update(['status' => LaunchPlan::PUBLISHING, 'updated_at' => Carbon::now()->subMinutes(30)]);

        $this->publish($plan)->assertOk();
    }

    public function test_automated_mode_is_named_in_the_review_and_refused_at_publish_before_any_call(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);
        $account = $this->account('meta', 'act_9');
        $creative = $this->creative('meta', '2384001');
        $spec = $this->spec($account, $creative) + ['budget' => ['mode' => 'automated', 'total' => 200, 'currency' => 'SAR']];

        $plan = $this->actingAs($this->owner, 'sanctum')->postJson($this->url('plans'), $spec)->assertCreated()->json('data');

        $this->assertContains('automation_requires_authorisation', $plan['review'][0]['problems']);
        $this->publish($plan, ['spend_ack' => $plan['spend']['ack_token']])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_the_budget_split_endpoint_computes_to_the_cent_in_each_accounts_currency_and_saves_nothing(): void
    {
        $this->configure('meta');
        $a = $this->account('meta', 'act_1');
        $b = $this->account('meta', 'act_2');

        $out = $this->actingAs($this->owner, 'sanctum')->postJson($this->url('budget-split'), [
            'total' => 100, 'currency' => 'SAR',
            'accounts' => [['external_account_id' => (string) $a->id], ['external_account_id' => (string) $b->id]],
        ])->assertOk()->json('data');

        $this->assertEquals([50, 50], array_column($out['slots'], 'share_pct'));
        $this->assertEquals([50, 50], array_column($out['slots'], 'amount'));
        $this->assertTrue($out['ok']);
        $this->assertSame(0, LaunchPlan::query()->count());

        $this->actingAs($this->owner, 'sanctum')->postJson($this->url('budget-split'), [
            'total' => 100, 'currency' => 'SAR',
            'accounts' => [['external_account_id' => (string) $a->id, 'share' => 70], ['external_account_id' => (string) $b->id, 'share' => 20]],
        ])->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')->postJson($this->url('budget-split'), [
            'total' => 100, 'currency' => 'SAR', 'accounts' => [['external_account_id' => '00000000-0000-0000-0000-000000000000']],
        ])->assertStatus(422);
    }

    private function limit(float $amount, string $currency = 'SAR', string $scope = 'project', ?string $scopeId = null): void
    {
        SpendLimit::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'scope' => $scope, 'scope_id' => $scopeId,
            'amount' => $amount, 'currency' => $currency, 'starts_on' => Carbon::now()->subDay()->toDateString(), 'ends_on' => Carbon::now()->addDays(9)->toDateString(),
            'thresholds' => [50, 80, 100], 'active' => true,
        ]);
    }

    public function test_the_approval_shows_each_spend_limit_against_what_the_launch_commits_and_it_only_warns(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['id' => '5001'])->push(['id' => '6001'])->push(['id' => '7001'])->push(['success' => true])]);
        $this->limit(1000);                                 // 200 a day for ten days = 2000: over
        $this->limit(500000, 'SAR', 'platform', 'meta');    // plenty of room
        $this->limit(1000, 'USD');                          // another currency: never converted behind the reader's back
        $plan = $this->draft(launch: true);

        $checks = collect($plan['spend']['limit_checks'])->keyBy(fn ($c) => $c['scope'].'/'.$c['currency']);
        $this->assertSame('exceeds', $checks['project/SAR']['status']);
        $this->assertEquals(1000, $checks['project/SAR']['over_by']);
        $this->assertEquals(2000, $checks['project/SAR']['committed_until_period_end']);
        $this->assertSame('within', $checks['platform/SAR']['status']);
        $this->assertNull($checks['platform/SAR']['over_by']);
        $this->assertSame('not_comparable', $checks['project/USD']['status']);
        foreach ($plan['spend']['limit_checks'] as $c) {
            $this->assertSame('internal_monitoring', $c['enforcement']);
        }

        // A warning is not a gate: this product cannot stop a platform spending, so the approved launch still goes through.
        $this->publish($plan, ['spend_ack' => $plan['spend']['ack_token']])->assertOk();
    }

    public function test_a_plan_with_no_budget_in_a_limits_scope_raises_no_check_for_it(): void
    {
        $this->limit(1000, 'SAR', 'platform', 'tiktok');
        $plan = $this->draft(launch: true);

        $this->assertSame([], $plan['spend']['limit_checks']);
    }

    public function test_the_goal_and_the_budget_choices_survive_in_the_one_plan(): void
    {
        $account = $this->account('meta', 'act_9');
        $spec = $this->spec($account, null, adSet: false) + ['business_goal' => 'sales', 'budget' => ['mode' => 'assisted', 'total' => 200, 'currency' => 'SAR', 'shares' => [(string) $account->id => 100]]];

        $plan = $this->actingAs($this->owner, 'sanctum')->postJson($this->url('plans'), $spec)->assertCreated()->json('data');
        $again = $this->actingAs($this->owner, 'sanctum')->getJson($this->url("plans/{$plan['id']}"))->assertOk()->json('data');

        $this->assertSame('sales', $again['spec']['business_goal']);
        $this->assertSame('assisted', $again['spec']['budget']['mode']);
        $this->assertSame(200.0, (float) $again['spec']['budget']['total']);
    }

    private function spec(ExternalAccount $account, ?ExternalCreative $creative, bool $adSet = true, bool $launch = false): array
    {
        return [
            'name' => 'Ramadan — Sales', 'objective' => 'OUTCOME_SALES', 'launch' => $launch,
            'accounts' => [['external_account_id' => (string) $account->id, 'daily_budget' => 200]],
            'ad_set' => $adSet ? ['name' => 'KSA 25-44', 'optimization_goal' => ['meta' => 'OFFSITE_CONVERSIONS'], 'countries' => ['SA'], 'daily_budget' => 120] : null,
            'ad' => $adSet && $creative ? ['name' => 'Hero', 'creative_id' => ['meta' => (string) $creative->id]] : null,
            'tracking' => ['url' => 'https://store.example/sale', 'utm_source' => 'meta', 'utm_campaign' => 'ramadan'],
        ];
    }

    private function url(string $tail): string
    {
        return "/api/v1/projects/{$this->project->id}/launch/{$tail}";
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
