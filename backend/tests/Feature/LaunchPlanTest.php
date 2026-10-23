<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LAUNCH-001 — a plan is published through the same write services as the control tab, step by step, with
 * memory: nothing is sent before an explicit confirmation, a partial launch stays partial, and a retry never
 * creates a second campaign on a platform that already holds one.
 */
final class LaunchPlanTest extends TestCase
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

    public function test_a_meta_plan_is_published_campaign_then_ad_set_then_ad_then_run_and_everything_is_mirrored(): void
    {
        $this->configure('meta');
        $account = $this->account('meta', 'act_9');
        $creative = $this->creative('meta', '2384001');
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['id' => '5001'])->push(['id' => '6001'])->push(['id' => '7001'])->push(['success' => true])]);

        $plan = $this->actingAs($this->owner, 'sanctum')->postJson($this->url('plans'), $this->spec($account, $creative, launch: true))
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.review.0.problems', [])->json('data');

        $this->actingAs($this->owner, 'sanctum')->postJson($this->url("plans/{$plan['id']}/publish"), [])->assertStatus(422);
        Http::assertNothingSent();

        $out = $this->actingAs($this->owner, 'sanctum')->postJson($this->url("plans/{$plan['id']}/publish"), ['confirm' => true])->assertOk()->json('data');
        $this->assertSame('published', $out['status']);
        $step = $out['steps'][0];
        $this->assertSame(['done', '5001'], [$step['campaign']['status'], $step['campaign']['external_id']]);
        $this->assertSame(['done', '6001'], [$step['ad_set']['status'], $step['ad_set']['external_id']]);
        $this->assertSame(['done', '7001'], [$step['ad']['status'], $step['ad']['external_id']]);
        $this->assertTrue($step['launched']);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/act_9/campaigns') && $r['status'] === 'PAUSED' && $r['objective'] === 'OUTCOME_SALES');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/act_9/adsets') && $r['campaign_id'] === '5001' && $r['targeting']['geo_locations']['countries'] === ['SA']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/act_9/ads') && $r['adset_id'] === '6001');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/5001') && $r['status'] === 'ACTIVE');

        $unified = UnifiedCampaign::query()->where('name', 'Ramadan — Sales')->sole();
        $this->assertSame($plan['id'], $unified->meta['launch_plan_id']);
        $this->assertSame('active', ExternalCampaign::query()->where('external_id', '5001')->sole()->status);
        $this->assertSame((string) $unified->id, (string) ExternalAdSet::query()->where('external_id', '6001')->sole()->unified_campaign_id);
        $this->assertSame((string) $creative->id, (string) ExternalAd::query()->where('external_id', '7001')->sole()->creative_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'campaign.launch_published')->count());

        // Publishing again sends nothing: the plan is a record now, not a request.
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url("plans/{$plan['id']}/publish"), ['confirm' => true])->assertOk();
        Http::assertSentCount(4);
        $this->actingAs($this->owner, 'sanctum')->putJson($this->url("plans/{$plan['id']}"), $this->spec($account, $creative))->assertStatus(409);
    }

    public function test_a_refused_ad_set_leaves_a_partial_launch_and_a_retry_resumes_without_a_second_campaign(): void
    {
        $this->configure('meta');
        $account = $this->account('meta', 'act_1');
        $creative = $this->creative('meta', '2384002');
        // One fake for both rounds: Http::fake merges stubs and the first match wins, so a second fake would never answer.
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['id' => '5001'])->push(['error' => ['message' => '(#100) Bad targeting', 'code' => 100]], 400)->push(['id' => '6001'])->push(['id' => '7001'])]);
        $plan = $this->actingAs($this->owner, 'sanctum')->postJson($this->url('plans'), $this->spec($account, $creative))->assertCreated()->json('data');

        $out = $this->actingAs($this->owner, 'sanctum')->postJson($this->url("plans/{$plan['id']}/publish"), ['confirm' => true])->assertStatus(207)->json('data');
        $this->assertSame('partial', $out['status']);
        $this->assertSame('5001', $out['steps'][0]['campaign']['external_id']);
        $this->assertSame('failed', $out['steps'][0]['ad_set']['status']);
        $this->assertStringContainsString('Bad targeting', $out['steps'][0]['ad_set']['error']);
        $this->assertSame('pending', $out['steps'][0]['ad']['status']);
        $this->assertSame(1, ExternalCampaign::query()->count());

        $out = $this->actingAs($this->owner, 'sanctum')->postJson($this->url("plans/{$plan['id']}/publish"), ['confirm' => true])->assertOk()->json('data');
        $this->assertSame('published', $out['status']);
        $this->assertSame('5001', $out['steps'][0]['campaign']['external_id'], 'the campaign already on the platform was kept');
        $this->assertSame('6001', $out['steps'][0]['ad_set']['external_id']);
        $this->assertSame('7001', $out['steps'][0]['ad']['external_id']);
        Http::assertSentCount(4);
        $this->assertSame(1, collect(Http::recorded())->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/act_1/campaigns'))->count(), 'the campaign was created exactly once across both rounds');
        $this->assertSame(1, ExternalCampaign::query()->count(), 'a retry never creates a second campaign');
        $this->assertSame(1, UnifiedCampaign::query()->count());
    }

    public function test_an_account_awaiting_credentials_is_named_in_the_review_and_nothing_is_sent(): void
    {
        $account = $this->account('meta', 'act_2');   // no app credentials configured on this installation
        Http::fake();
        $plan = $this->actingAs($this->owner, 'sanctum')->postJson($this->url('plans'), $this->spec($account, null, adSet: false))->assertCreated()->json('data');
        $this->assertSame(['awaiting_credentials'], $plan['review'][0]['problems']);

        $out = $this->actingAs($this->owner, 'sanctum')->postJson($this->url("plans/{$plan['id']}/publish"), ['confirm' => true])->assertStatus(422)->json('data');
        $this->assertSame('failed', $out['status']);
        $this->assertSame('awaiting_credentials', $out['steps'][0]['campaign']['refusal']);
        Http::assertNothingSent();
        $this->assertSame(0, ExternalCampaign::query()->count());
    }

    public function test_running_after_creation_needs_the_launch_permission_before_any_call_is_made(): void
    {
        $this->configure('meta');
        $account = $this->account('meta', 'act_3');
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'M', 'slug' => 'm']);
        $role->givePermissionTo(...Permission::where('key', '!=', 'campaigns.launch')->pluck('key')->all());
        $maker = User::create(['name' => 'M', 'email' => 'm@l.test', 'password' => 'secret123']);
        $this->grantMembership($maker, $this->tenant);
        $maker->assignRole($role);
        Http::fake();

        $plan = $this->actingAs($maker, 'sanctum')->postJson($this->url('plans'), $this->spec($account, null, adSet: false, launch: true))->assertCreated()->json('data');
        $this->assertContains('launch:no_permission', $plan['review'][0]['problems']);
        $this->actingAs($maker, 'sanctum')->postJson($this->url("plans/{$plan['id']}/publish"), ['confirm' => true])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_plan_is_reachable_only_under_its_own_project(): void
    {
        $this->configure('meta');
        $account = $this->account('meta', 'act_4');
        $plan = $this->actingAs($this->owner, 'sanctum')->postJson($this->url('plans'), $this->spec($account, null, adSet: false))->assertCreated()->json('data');
        $other = Project::create(['client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Q', 'status' => 'active']);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$other->id}/launch/plans/{$plan['id']}")->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->getJson($this->url("plans/{$plan['id']}"))->assertOk();
    }

    public function test_options_name_each_platforms_vocabulary_and_the_projects_accounts(): void
    {
        $this->configure('meta');
        $this->account('meta', 'act_5');
        $out = $this->actingAs($this->owner, 'sanctum')->getJson($this->url('options'))->assertOk()->json('data');
        $this->assertSame('meta', $out['accounts'][0]['provider']);
        $this->assertSame('available', $out['accounts'][0]['state']);
        $this->assertContains('OUTCOME_SALES', $out['providers'][0]['objectives']);
        $this->assertSame('creative', $out['providers'][0]['ad_fields']);
        $this->assertTrue($out['launch_permitted']);
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
