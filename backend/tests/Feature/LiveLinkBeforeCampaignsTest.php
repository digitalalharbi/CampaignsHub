<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * LIVE-LINK-BEFORE-CAMPAIGNS-001 / CREATIVE-DISPLAY-001 — the Owner's workflow: connect accounts, create the client's Live link, share it,
 * launch campaigns later, and have them appear on the SAME link.
 *
 *  - a link can be created over a bound account that has no campaign, ad or figure yet, and says «no data yet» rather than printing zeros;
 *  - the campaigns that sync afterwards are inside it without recreating it, and an account it was not made over never is;
 *  - the default creative policy lists a creative with a real figure in the period (paused included), and leaves out one with none and one
 *    active only in an older period — from the library AND from its own address.
 */
final class LiveLinkBeforeCampaignsTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    private ExternalAccount $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'lb-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $owner = User::create(['name' => 'O', 'email' => 'lb-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($owner, $this->tenant, Portal::Agency);
        $owner->assignRole($role);
        $this->actingAs($owner, 'sanctum');

        $workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'NASEL', 'status' => 'active']);

        $connection = app(TokenVault::class)->open(tenantId: (string) $this->tenant->id, provider: 'tiktok', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'tt');
        $make = fn (string $id): ExternalAccount => ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'tiktok', 'account_type' => 'ad_account',
            'external_id' => $id, 'name' => $id, 'currency' => 'SAR', 'status' => 'active', 'last_synced_at' => Carbon::now(),
        ]);
        $this->account = $make('nasel-ad');
        $this->stranger = $make('someone-elses-ad');
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->id, 'provider' => 'tiktok', 'purpose' => 'reporting', 'is_active' => true, 'is_primary' => true,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function createLink(array $extra = [])
    {
        return $this->postJson("/api/v1/projects/{$this->project->id}/reports/live", $extra + [
            'name' => 'NASEL Riwaq', 'account_ids' => [(string) $this->account->id],
            'metrics' => ['spend', 'impressions', 'clicks', 'leads'],
            'from' => Carbon::now()->subDays(6)->toDateString(), 'to' => Carbon::now()->toDateString(), 'period_kind' => 'custom', 'period_mode' => 'rolling',
        ]);
    }

    private function live(string $token)
    {
        return $this->getJson("/api/v1/reports/shared/{$token}/live");
    }

    private function campaign(ExternalAccount $account, string $name, float $spend): UnifiedCampaign
    {
        $campaign = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => $name, 'status' => 'active', 'objective' => 'leads']);
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign->id, 'provider' => 'tiktok', 'metric_key' => 'spend',
            'metric_date' => Carbon::now()->subDay()->toDateString(), 'value' => $spend,
        ]);

        return $campaign;
    }

    public function test_a_link_is_created_over_an_account_with_no_campaigns_and_says_no_data_yet(): void
    {
        $res = $this->createLink()->assertCreated();

        $payload = $this->live($res->json('data.token'))->assertOk()->json('data');
        $this->assertTrue($payload['no_data_yet'], 'an empty account must read «no data yet», not a wall of zeros');
    }

    public function test_campaigns_synced_later_appear_on_the_same_link_and_other_accounts_never_do(): void
    {
        $token = $this->createLink()->assertCreated()->json('data.token');

        $this->campaign($this->account, 'Launched later', 40.0);
        $this->campaign($this->stranger, 'Another client', 999.0);

        $payload = $this->live($token)->assertOk()->json('data');
        $this->assertFalse($payload['no_data_yet']);
        $this->assertEquals(40.0, $payload['totals']['spend'], 'the later campaign is inside the link; the other account is not');
    }

    public function test_the_live_builder_offers_a_bound_account_with_nothing_synced_and_labels_it(): void
    {
        $options = $this->getJson("/api/v1/projects/{$this->project->id}/reports/live/options")->assertOk()->json('data');

        $offered = collect($options['ad_accounts'])->keyBy('id');
        $this->assertTrue($offered->has((string) $this->account->id), 'the bound account was hidden from the Live link builder');
        $this->assertSame('no_data', $offered[(string) $this->account->id]['state']);
        $this->assertFalse($offered->has((string) $this->stranger->id), 'an account the project does not bind was offered');
        $this->assertContains('tiktok', $options['providers']);
    }

    public function test_the_operators_own_kpi_names_reach_the_client_link_and_unknown_keys_are_dropped(): void
    {
        $token = $this->createLink([
            'metrics' => ['spend', 'impressions', 'clicks', 'form_starts'],
            'kpi_labels' => ['form_starts' => '  بدأوا التعبئة  ', 'not_a_metric' => 'x', 'clicks' => ''],
        ])->assertCreated()->json('data.token');

        $labels = $this->live($token)->assertOk()->json('data.kpi_labels');
        $this->assertSame(['form_starts' => 'بدأوا التعبئة'], $labels);
    }

    public function test_the_campaign_breakdown_lists_every_objective_client_safe_and_spend_follows_the_kpi_choice(): void
    {
        $this->campaign($this->account, 'Riwaq — LeadForm', 7.16);
        $traffic = $this->campaign($this->account, 'Riwaq — LandingPage', 9.18);
        $traffic->forceFill(['objective' => 'traffic'])->save();
        $this->campaign($this->stranger, 'Another client', 999.0);
        // Structure synced, no figures yet — under the link's account, and under an account the project does not bind.
        $awaiting = $this->structureOnly($this->account, 'Riwaq — New flight');
        $this->structureOnly($this->stranger, 'Stranger structure');

        // Spend chosen as a KPI and no explicit hide_spend: spend shows.
        $token = $this->createLink(['metrics' => ['spend', 'impressions', 'clicks', 'native_leads'], 'campaign_breakdown' => true])->assertCreated()->json('data.token');
        $payload = $this->live($token)->assertOk()->json('data');

        $this->assertSame(['spend', 'impressions', 'clicks', 'native_leads'], $payload['metrics'], 'the chosen KPI order persists');
        $this->assertEquals(16.34, $payload['totals']['spend']);
        $rows = collect($payload['campaign_rows']);
        $this->assertSame(['Riwaq — LandingPage', 'Riwaq — LeadForm', 'Riwaq — New flight'], $rows->pluck('name')->all(), 'every objective and the campaign awaiting metrics; the other account never');
        $this->assertSame(['traffic', 'leads', 'awareness'], $rows->pluck('objective')->all());
        $this->assertSame(['reported', 'reported', 'no_data'], $rows->pluck('data_state')->all());
        $this->assertSame(['name', 'platform', 'status', 'objective', 'data_state', 'spend', 'impressions', 'clicks', 'native_leads', 'landing_leads'], array_keys($rows->first()), 'no id or targeting field reaches the client');
        $this->assertNull($rows->first()['native_leads'], 'no lead event configured is «not available», never zero');
        $this->assertNull($rows->last()['impressions'], 'a campaign awaiting metrics reads «not available», never zero');
        $this->assertEquals(16.34, $payload['totals']['spend'], 'the waiting campaign adds nothing to the totals');
        $this->assertStringNotContainsString((string) $awaiting->id, (string) json_encode($payload['campaign_rows']), 'no campaign id reaches the client');

        // Without the switch the key stays empty; without Spend among the KPIs spend stays hidden by default.
        $plain = $this->createLink(['metrics' => ['impressions', 'clicks']])->assertCreated()->json('data.token');
        $payload = $this->live($plain)->assertOk()->json('data');
        $this->assertSame([], $payload['campaign_rows']);
        $this->assertNull($payload['totals']['spend']);
    }

    private function structureOnly(ExternalAccount $account, string $name): UnifiedCampaign
    {
        $campaign = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => $name, 'status' => 'active', 'objective' => 'awareness']);
        ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'provider' => 'tiktok',
            'external_id' => 'ec-'.Str::random(6), 'name' => $name, 'status' => 'active', 'unified_campaign_id' => $campaign->id,
        ]);

        return $campaign;
    }

    public function test_an_account_the_project_does_not_bind_is_still_refused(): void
    {
        $this->createLink(['account_ids' => [(string) $this->stranger->id]])->assertStatus(422);
    }

    private function creative(ExternalAccount $account, string $name, ?string $day, float $spend = 5.0): ExternalCreative
    {
        $unified = UnifiedCampaign::withoutGlobalScopes()->where('project_id', $this->project->id)->first()
            ?? $this->campaign($account, 'Host', 1.0);
        $external = ExternalCampaign::withoutGlobalScopes()->firstOrCreate(
            ['project_id' => $this->project->id, 'external_account_id' => $account->id, 'external_id' => 'ec-1'],
            ['tenant_id' => $this->tenant->id, 'provider' => 'tiktok', 'name' => 'Host', 'status' => 'active', 'unified_campaign_id' => $unified->id],
        );
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'tiktok', 'external_campaign_id' => $external->id,
            'campaign_id' => $unified->id, 'external_creative_id' => 'cr-'.Str::random(6), 'name' => $name, 'format' => 'image', 'status' => 'paused',
        ]);
        if ($day !== null) {
            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'creative_id' => $creative->id,
                'campaign_id' => $unified->id, 'metric_date' => $day, 'spend' => $spend, 'impressions' => $spend > 0 ? 1 : 0, 'clicks' => 0, 'conversions' => 0,
                'revenue' => 0, 'video_views' => 0, 'video_completions' => 0, 'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $creative;
    }

    public function test_the_default_policy_lists_period_activity_and_hides_the_rest_even_by_direct_address(): void
    {
        $this->campaign($this->account, 'Host', 10.0);
        $active = $this->creative($this->account, 'Active in period', Carbon::now()->subDay()->toDateString());
        $idle = $this->creative($this->account, 'Never delivered', null);
        $old = $this->creative($this->account, 'Delivered last quarter', Carbon::now()->subDays(90)->toDateString());
        $zero = $this->creative($this->account, 'A row of zeros', Carbon::now()->subDay()->toDateString(), 0.0);

        $token = $this->createLink([
            'creatives' => ['creatives' => true],
            'creative_display' => ['policy' => 'activity', 'include_zero_activity' => false, 'auto_refresh' => true],
        ])->assertCreated()->json('data.token');

        $library = json_encode($this->getJson("/api/v1/reports/shared/{$token}/creatives")->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Active in period', $library);
        foreach (['Never delivered', 'Delivered last quarter', 'A row of zeros'] as $left) {
            $this->assertStringNotContainsString($left, $library, "«{$left}» had no real figure in the period");
        }

        $this->getJson("/api/v1/reports/shared/{$token}/creatives/{$active->id}")->assertOk();
        foreach ([$idle, $old, $zero] as $hidden) {
            $this->getJson("/api/v1/reports/shared/{$token}/creatives/{$hidden->id}")->assertNotFound();
        }
    }

    public function test_including_zero_activity_lists_every_creative_in_scope(): void
    {
        $this->campaign($this->account, 'Host', 10.0);
        $this->creative($this->account, 'Active in period', Carbon::now()->subDay()->toDateString());
        $this->creative($this->account, 'Never delivered', null);

        $token = $this->createLink([
            'creatives' => ['creatives' => true],
            'creative_display' => ['policy' => 'activity', 'include_zero_activity' => true],
        ])->assertCreated()->json('data.token');

        $library = json_encode($this->getJson("/api/v1/reports/shared/{$token}/creatives")->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Active in period', $library);
        $this->assertStringContainsString('Never delivered', $library);
    }

    public function test_selecting_creatives_without_naming_any_is_refused_with_its_reason(): void
    {
        $this->createLink(['creative_display' => ['policy' => 'selected']])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Choose at least one creative, or pick another ad display policy.']);
    }
}
