<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Launch\LaunchPlan;
use App\Domains\Campaigns\Launch\LaunchReadiness;
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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LAUNCH-READINESS-001 — what each ad account was SEEN to measure, read from its own synced results and nothing else.
 *
 * `unavailable` never passes as a pass, a demo row is never evidence, and another project's figures never leak in.
 */
final class LaunchReadinessTest extends TestCase
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

    private function readiness(array $accounts): array
    {
        $rows = array_map(fn (ExternalAccount $a) => ['id' => (string) $a->id, 'provider' => $a->provider], $accounts);

        return app(LaunchReadiness::class)->forAccounts((string) $this->tenant->id, (string) $this->project->id, $rows);
    }

    public function test_an_event_is_unavailable_proven_or_eligible_by_what_the_account_reported_and_the_account_tier_follows(): void
    {
        $ready = $this->account('meta', 'act_1');
        $partial = $this->account('meta', 'act_2');
        $silent = $this->account('meta', 'act_3');
        $zeros = $this->account('meta', 'act_4');
        $this->metric($ready, ['purchases' => 40, 'add_to_cart' => 90, 'leads' => null, 'page_views' => 900]);
        $this->metric($ready, ['purchases' => 25], daysAgo: 5);
        $this->metric($partial, ['purchases' => 3, 'add_to_cart' => 10]);
        $this->metric($zeros, ['purchases' => 0, 'leads' => 0, 'page_views' => 0]);

        $out = $this->readiness([$ready, $partial, $silent, $zeros]);

        $r = $out['accounts'][(string) $ready->id];
        $this->assertSame('eligible', $r['events']['purchases']['status']);
        $this->assertSame(65, $r['events']['purchases']['observed'], 'Two days are one window.');
        $this->assertSame('unavailable', $r['events']['leads']['status'], 'A figure that was never reported is not a zero measured.');
        $this->assertSame('sales_ready', $r['tier']);

        $p = $out['accounts'][(string) $partial->id];
        $this->assertSame('proven', $p['events']['purchases']['status']);
        $this->assertSame('partial', $p['tier']);

        foreach ([$silent, $zeros] as $account) {
            $a = $out['accounts'][(string) $account->id];
            $this->assertSame('none', $a['tier']);
            $this->assertSame('unavailable', $a['events']['purchases']['status']);
        }
    }

    public function test_the_pixel_is_never_claimed_because_no_official_api_here_can_read_it(): void
    {
        $account = $this->account('meta', 'act_1');
        $this->metric($account, ['purchases' => 500]);

        $pixel = $this->readiness([$account])['accounts'][(string) $account->id]['pixel'];

        $this->assertSame('unavailable', $pixel['status']);
        $this->assertSame('not_readable_from_platform_api', $pixel['reason']);
    }

    public function test_demo_rows_old_rows_and_another_projects_figures_are_not_evidence(): void
    {
        $account = $this->account('meta', 'act_1');
        $other = Project::create(['client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Q', 'status' => 'active']);
        $this->metric($account, ['purchases' => 900], demo: true);
        $this->metric($account, ['purchases' => 900], daysAgo: 45);
        $this->metric($account, ['purchases' => 900], project: $other);

        $out = $this->readiness([$account])['accounts'][(string) $account->id];

        $this->assertSame('none', $out['tier']);
        $this->assertSame(0, $out['events']['purchases']['observed']);
    }

    public function test_the_volume_that_makes_an_event_eligible_is_configuration_not_a_constant(): void
    {
        $account = $this->account('meta', 'act_1');
        $this->metric($account, ['purchases' => 10]);

        $this->assertSame('partial', $this->readiness([$account])['accounts'][(string) $account->id]['tier']);

        config()->set('launch.readiness.eligible_events', 10);
        $this->assertSame('sales_ready', $this->readiness([$account])['accounts'][(string) $account->id]['tier']);
    }

    public function test_the_launch_options_carry_each_accounts_readiness_and_the_project_signals(): void
    {
        $this->configure('meta');
        $account = $this->account('meta', 'act_1');
        $this->metric($account, ['purchases' => 60]);

        $out = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/launch/options")->assertOk()->json('data');

        $measurement = $this->account('ga4', 'prop_1');
        $out = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/launch/options")->assertOk()->json('data');
        $byId = collect($out['accounts'])->keyBy('id');
        $this->assertNull($byId[(string) $measurement->id]['readiness'], 'A measurement account launches nothing and is never scored.');
        $this->assertSame('sales_ready', $byId[(string) $account->id]['readiness']['tier']);
        $this->assertSame(30, $out['readiness']['window_days']);
        $this->assertFalse($out['readiness']['project']['store_linked']);
        $this->assertTrue($out['readiness']['project']['ga4_linked'], 'The GA4 property bound above is a project signal, not an ad account.');
        $this->assertSame([], $out['readiness']['project']['live_conversion_destinations']);
    }

    public function test_a_multi_account_project_gets_a_proposal_per_account_each_on_its_own_evidence(): void
    {
        $this->configure('meta');
        $this->configure('snapchat');
        $ready = $this->account('meta', 'act_1');
        $partial = $this->account('meta', 'act_2');
        $none = $this->account('snapchat', 'sc_1');
        $this->metric($ready, ['purchases' => 80]);
        $this->metric($partial, ['purchases' => 4, 'add_to_cart' => 120]);

        $out = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/launch/strategy?goal=sales")->assertOk()->json('data');

        $byId = collect($out['accounts'])->keyBy('account_id');
        $this->assertSame(['sales', 'OUTCOME_SALES', 'Purchase'], [$byId[(string) $ready->id]['stage'], $byId[(string) $ready->id]['objective'], $byId[(string) $ready->id]['optimization_event']]);
        $this->assertSame(['bridge', 'OUTCOME_SALES', 'AddToCart'], [$byId[(string) $partial->id]['stage'], $byId[(string) $partial->id]['objective'], $byId[(string) $partial->id]['optimization_event']]);
        $this->assertSame(['measurement_first', 'TRAFFIC', null], [$byId[(string) $none->id]['stage'], $byId[(string) $none->id]['objective'], $byId[(string) $none->id]['optimization_event']]);
        $this->assertSame(0, LaunchPlan::query()->count(), 'A proposal saves nothing.');

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/launch/strategy?goal=profit")->assertStatus(422);
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
