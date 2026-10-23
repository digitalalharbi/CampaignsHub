<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Agents\Engine\AgentToolbox;
use App\Domains\Agents\Support\AgentRoles;
use App\Domains\Campaigns\Launch\LaunchPlan;
use App\Domains\Campaigns\Models\UnifiedCampaign;
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
 * SMART-LAUNCH acceptance — one project, several ad accounts at different stages of measurement, taken from evidence to a paused,
 * approved launch, with the chat and the wizard editing the same plan. No real spend: the platform is faked and every campaign is
 * created paused.
 */
final class SmartLaunchJourneyTest extends TestCase
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

    public function test_from_measured_evidence_to_a_paused_approved_launch_with_chat_and_wizard_on_one_plan(): void
    {
        $this->configure('meta');
        $this->configure('snapchat');
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['id' => '5001'])->push(['id' => '5002'])]);

        $ready = $this->account('meta', 'act_ready');       // Purchase eligible
        $partial = $this->account('meta', 'act_partial');   // AddToCart eligible, Purchase only proven
        $bare = $this->account('snapchat', 'sc_bare');      // nothing reported
        $this->metric($ready, ['purchases' => 90, 'add_to_cart' => 300, 'page_views' => 4000]);
        $this->metric($partial, ['purchases' => 4, 'add_to_cart' => 140, 'page_views' => 2500]);

        // 1–5. The strategy per account, from evidence only.
        $sales = $this->actingAs($this->owner, 'sanctum')->getJson($this->url('strategy?goal=sales'))->assertOk()->json('data.accounts');
        $byId = collect($sales)->keyBy('account_id');
        $this->assertSame('sales', $byId[(string) $ready->id]['stage']);
        $this->assertSame('bridge', $byId[(string) $partial->id]['stage']);
        $this->assertSame('measurement_first', $byId[(string) $bare->id]['stage']);

        $leads = collect($this->actingAs($this->owner, 'sanctum')->getJson($this->url('strategy?goal=leads'))->assertOk()->json('data.accounts'))->keyBy('account_id');
        $this->assertSame('measurement_first', $leads[(string) $ready->id]['stage'], 'A lead goal needs a Lead event, which no account here reported.');

        // 8. The budget split: shares total 100, amounts total the budget.
        $split = $this->actingAs($this->owner, 'sanctum')->postJson($this->url('budget-split'), [
            'total' => 1000, 'currency' => 'SAR',
            'accounts' => [['external_account_id' => (string) $ready->id, 'share' => 60], ['external_account_id' => (string) $partial->id]],
        ])->assertOk()->json('data');
        $this->assertEquals([600, 400], array_column($split['slots'], 'amount'));
        $this->assertEquals(100, array_sum(array_column($split['slots'], 'share_pct')));

        // 7. The chat drafts the plan from the proposals…
        $draft = app(AgentToolbox::class)->call($this->project, $this->owner, 'campaign', AgentRoles::get('campaign')['tools'], 'draft_launch_plan', [
            'name' => 'Spring — Sales', 'business_goal' => 'sales', 'objective' => 'OUTCOME_SALES', 'objectives' => ['meta' => 'OUTCOME_SALES'],
            'accounts' => [['external_account_id' => (string) $ready->id, 'daily_budget' => 600], ['external_account_id' => (string) $partial->id, 'daily_budget' => 400]],
            'budget' => ['mode' => 'assisted', 'total' => 1000, 'currency' => 'SAR', 'shares' => [(string) $ready->id => 60, (string) $partial->id => 40]],
            'tracking' => ['url' => 'https://store.example/spring', 'utm_source' => 'meta', 'utm_campaign' => 'spring'],
        ]);
        $this->assertTrue($draft['ok'], $draft['content']);
        $planId = json_decode($draft['content'], true)['plan_id'];

        // …and the person continues in the wizard on the very same plan, switching it to a running launch.
        $plan = $this->actingAs($this->owner, 'sanctum')->getJson($this->url("plans/{$planId}"))->assertOk()->json('data');
        $this->assertSame('assisted', $plan['spec']['budget']['mode']);
        $spec = $plan['spec'];
        $spec['launch'] = false;
        $edited = $this->actingAs($this->owner, 'sanctum')->putJson($this->url("plans/{$planId}"), $spec)->assertOk()->json('data');
        $this->assertSame([], array_merge(...array_column($edited['review'], 'problems')));
        $this->assertSame($planId, $edited['id']);

        // 9–10. Explicit confirmation; created PAUSED on the faked platform; results per account and in the campaign list.
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url("plans/{$planId}/publish"), [])->assertStatus(422);
        $out = $this->actingAs($this->owner, 'sanctum')->postJson($this->url("plans/{$planId}/publish"), ['confirm' => true, 'spend_ack' => $edited['spend']['ack_token']])->assertOk()->json('data');

        $this->assertSame('published', $out['status']);
        $this->assertSame(['5001', '5002'], array_column(array_column($out['steps'], 'campaign'), 'external_id'));
        $this->assertSame([null, null], array_column($out['steps'], 'launched'), 'Created paused: nothing was run, nothing is spent.');
        $campaign = UnifiedCampaign::query()->findOrFail($out['unified_campaign_id']);
        $this->assertNotSame('active', (string) $campaign->status, 'The umbrella campaign is not running.');
        $this->assertSame(1, LaunchPlan::query()->where('status', 'published')->count());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data(), JSON_THROW_ON_ERROR).$request->url(), 'PAUSED'));
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
