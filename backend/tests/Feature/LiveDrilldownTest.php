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
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ClientReportContentValidator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\ContentKey;
use App\Domains\Reports\Support\ReportBreakdowns;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-DRILLDOWN-001 — the platform and content drill-downs of a live link.
 *
 * Every assertion compares the drill-down against the surface it is opened from — the comparison row,
 * the roster card — rather than a literal, so a figure drifting on both sides cannot pass. Each
 * refusal is paired with the same request succeeding on a wider link first, so a 404 here is the rule
 * working and not the fixture being empty.
 *
 * Fixture: two platforms under one account each, plus a THIRD account on meta whose binding to this
 * project is deselected. Its spend must never reach a drill-down (BoundAccountVisibility).
 */
final class LiveDrilldownTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Report $report;

    private User $operator;

    /** @var list<string> */
    private array $campaigns = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'drilldown-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(),
            provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', now()->addDays(30)),
            connectionName: 'meta',
        );
        $account = fn (string $provider, string $ext): string => (string) ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => $ext, 'name' => $ext, 'status' => 'active', 'discovered_at' => now(),
        ])->getKey();

        $deselected = $account('meta', 'act-elsewhere');
        $fixture = [
            // label, provider, account, [spend, impressions, clicks, conversions, revenue]
            ['Meta Summer', 'meta', $account('meta', 'act-meta'), [300, 20000, 400, 12, 1500]],
            ['TikTok Launch', 'tiktok', $account('tiktok', 'act-tiktok'), [100, 30000, 300, 4, 200]],
            ['Meta Elsewhere', 'meta', $deselected, [9000, 90000, 900, 90, 90000]],
        ];

        foreach ($fixture as [$label, $provider, $accountId, [$spend, $impressions, $clicks, $conversions, $revenue]]) {
            $campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => $label, 'status' => 'active', 'objective' => 'sales']);
            $this->campaigns[] = $campaign->id;
            $external = ExternalCampaign::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                'external_account_id' => $accountId, 'unified_campaign_id' => $campaign->id,
                'provider' => $provider, 'external_id' => 'cmp-'.Str::random(6), 'name' => $label, 'status' => 'active',
            ]);
            $creative = ExternalCreative::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'campaign_id' => $campaign->id,
                'external_campaign_id' => $external->getKey(),
                'provider' => $provider, 'external_creative_id' => 'cr-'.Str::random(8),
                'name' => "{$label} creative", 'format' => 'image', 'status' => 'active',
            ]);
            foreach (['2026-07-10', '2026-07-11'] as $date) {
                foreach (['spend' => $spend, 'impressions' => $impressions, 'clicks' => $clicks, 'conversions' => $conversions, 'revenue' => $revenue] as $key => $value) {
                    DailyMetric::create([
                        'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'external_account_id' => $accountId,
                        'external_campaign_id' => $external->getKey(), 'unified_campaign_id' => $campaign->id,
                        'provider' => $provider, 'metric_key' => $key, 'metric_date' => $date, 'value' => $value / 2,
                    ]);
                }
                DB::table('creative_daily_metrics')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                    'creative_id' => $creative->id, 'metric_date' => $date, 'spend' => $spend / 2,
                    'impressions' => $impressions / 2, 'clicks' => $clicks / 2, 'conversions' => $conversions / 2, 'revenue' => $revenue / 2,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // The third account was bound here once and deselected: its rows are someone else's now.
        DB::table('project_integration_bindings')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $deselected, 'provider' => 'meta', 'purpose' => 'ads', 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'campaign_objective' => 'sales', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'data' => [],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** @param array<string, mixed> $overrides @return array{0: ReportShare, 1: string} */
    private function share(array $overrides = []): array
    {
        return app(ShareService::class)->create($this->report, [
            'mode' => 'live',
            'scope' => [
                'project_id' => $this->project->id, 'campaign_ids' => $this->campaigns,
                'providers' => ['meta', 'tiktok'], 'earliest' => '2026-07-01', 'latest' => '2026-07-31',
            ],
        ] + $overrides, null);
    }

    private const WINDOW = '?from=2026-07-01&to=2026-07-31';

    /** @return array<string, mixed> */
    private function live(string $raw): array
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}/live".self::WINDOW)->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function platform(string $raw, string $provider, string $query = ''): array
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}/live/platform/{$provider}".self::WINDOW.$query)->assertOk()->json('data');
    }

    private function operatorUrl(ReportShare $share, string $tail): string
    {
        return "/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/shares/{$share->id}/live/{$tail}".self::WINDOW;
    }

    public function test_the_platform_drilldown_reconciles_with_the_comparison_row_it_opens_from(): void
    {
        [, $raw] = $this->share();
        $row = collect($this->live($raw)['platforms'])->keyBy('provider');
        $meta = $this->platform($raw, 'meta');

        $this->assertSame('meta', $meta['provider']);
        $this->assertEqualsWithDelta($row['meta']['spend'], $meta['totals']['spend'], 0.0001, 'the drawer disagrees with its comparison row');
        $this->assertEqualsWithDelta($meta['totals']['spend'], collect($meta['timeseries'])->sum('spend'), 0.0001, 'the trend does not add back to the total');

        // Shares are ratios of sums over the same rows the comparison shows.
        $spendTotal = $row->sum('spend');
        $this->assertEqualsWithDelta($row['meta']['spend'] / $spendTotal, $meta['shares']['spend']['share'], 0.0001);
        $this->assertSame('revenue', $meta['shares']['outcome']['metric'], 'a sales report reads a platform on revenue');
        $this->assertEqualsWithDelta($row['meta']['revenue'] / $row->sum('revenue'), $meta['shares']['outcome']['share'], 0.0001);

        // Objective KPIs come from ObjectivePerformance's conversion path, never with its campaigns.
        $conversion = collect($meta['objectives'])->firstWhere('path', 'conversion');
        $this->assertNotNull($conversion, 'no objective block for a sales-path platform');
        $this->assertEqualsWithDelta($row['meta']['spend'], $conversion['metrics']['spend'], 0.0001);
        $this->assertArrayNotHasKey('campaigns', $conversion);

        // Its content, only its own.
        $this->assertNotEmpty($meta['ads'], 'no content on the platform drawer, so the provider check proves nothing');
        $this->assertSame(['meta'], collect($meta['ads'])->pluck('provider')->unique()->values()->all());
    }

    public function test_a_deselected_accounts_figures_never_reach_a_drilldown(): void
    {
        [, $raw] = $this->share();
        $meta = $this->platform($raw, 'meta');

        $this->assertEqualsWithDelta(300.0, $meta['totals']['spend'], 0.0001, 'the deselected account\'s 9,000 reached the platform drawer');
        $this->assertNotContains('Meta Elsewhere creative', collect($meta['ads'])->pluck('name')->all());
        $this->assertEqualsWithDelta(300.0, collect($meta['objectives'])->sum(fn ($b) => $b['metrics']['spend'] ?? 0), 0.0001, 'the objective block counted the deselected account');
    }

    public function test_no_drilldown_payload_carries_a_campaign_or_ad_set(): void
    {
        [, $raw] = $this->share();
        $meta = $this->platform($raw, 'meta', '&campaigns[]='.$this->campaigns[0]);
        $key = collect($this->live($raw)['ads_roster'])->firstWhere('provider', 'meta')['content_key'];
        $content = $this->getJson("/api/v1/reports/shared/{$raw}/live/content/{$key}".self::WINDOW)->assertOk()->json('data');

        foreach (['platform' => $meta, 'content' => $content] as $which => $payload) {
            $this->assertSame([], ClientReportContentValidator::campaignEntities($payload), "the {$which} drill-down carries a campaign entity");
            $this->assertStringNotContainsString('Meta Summer"', json_encode($payload), "the {$which} drill-down names a campaign");
            $this->assertStringNotContainsString($this->campaigns[0], json_encode($payload), "the {$which} drill-down carries a campaign id");
        }
    }

    /**
     * A shared link cannot fetch campaign-level data — not by address, not by parameter.
     */
    public function test_a_shared_link_cannot_reach_campaign_level_data(): void
    {
        [, $raw] = $this->share();
        $campaign = $this->campaigns[0];

        foreach ([
            "/api/v1/reports/shared/{$raw}/live/campaign/{$campaign}",
            "/api/v1/reports/shared/{$raw}/live/campaigns/{$campaign}",
            "/api/v1/reports/shared/{$raw}/live/platform/meta/campaigns",
            "/api/v1/reports/shared/{$raw}/live/platform/{$campaign}",
        ] as $url) {
            $this->getJson($url)->assertNotFound();
        }

        // A campaign narrowing is ignored, not honoured: the drawer answers for the whole platform.
        $whole = $this->platform($raw, 'meta');
        $narrowed = $this->platform($raw, 'meta', '&campaigns[]='.$this->campaigns[1]);
        $this->assertEqualsWithDelta($whole['totals']['spend'], $narrowed['totals']['spend'], 0.0001, 'a campaign parameter narrowed a client drill-down');

        $live = $this->live($raw);
        $this->assertSame([], $live['campaigns']);
    }

    public function test_the_platform_drilldown_stays_inside_the_links_scope(): void
    {
        [$share, $raw] = $this->share();
        $this->platform($raw, 'tiktok');

        $share->scope = ['providers' => ['meta']] + $share->scope;
        $share->save();
        $this->getJson("/api/v1/reports/shared/{$raw}/live/platform/tiktok".self::WINDOW)->assertNotFound();
        $this->getJson("/api/v1/reports/shared/{$raw}/live/platform/snapchat".self::WINDOW)->assertNotFound();

        // A platform with no figures in the ceiling answers the same 404 as one outside it.
        [, $wide] = $this->share();
        $this->getJson("/api/v1/reports/shared/{$wide}/live/platform/snapchat".self::WINDOW)->assertNotFound();
    }

    public function test_switched_off_sections_and_breakdowns_close_the_drilldowns(): void
    {
        [$share, $raw] = $this->share();
        $key = collect($this->live($raw)['ads_roster'])->firstWhere('provider', 'meta')['content_key'];
        $this->platform($raw, 'meta');

        $share->settings = ['breakdowns' => ['live' => [ReportBreakdowns::PLATFORM => false]]];
        $share->save();
        $this->getJson("/api/v1/reports/shared/{$raw}/live/platform/meta".self::WINDOW)->assertNotFound();
        $this->assertFalse($this->live($raw)['breakdowns'][ReportBreakdowns::PLATFORM]);
        $this->getJson("/api/v1/reports/shared/{$raw}/live/content/{$key}".self::WINDOW)->assertOk();

        $share->settings = ['sections' => ['platform_comparison' => false]];
        $share->save();
        $this->getJson("/api/v1/reports/shared/{$raw}/live/platform/meta".self::WINDOW)->assertNotFound();

        // An override can switch a breakdown off; it can never bring a hidden section back.
        $share->settings = ['sections' => ['creatives' => false], 'breakdowns' => ['live' => [ReportBreakdowns::CONTENT => true]]];
        $share->save();
        $this->getJson("/api/v1/reports/shared/{$raw}/live/content/{$key}".self::WINDOW)->assertNotFound();
        $this->assertSame([], $this->platform($raw, 'meta')['ads'], 'the drawer listed content on a link that does not show content');
    }

    public function test_the_registry_keeps_drilldowns_off_in_pdf_and_on_in_live(): void
    {
        foreach (ReportBreakdowns::keys() as $key) {
            $this->assertTrue(ReportBreakdowns::defaultFor($key, 'live'));
            $this->assertFalse(ReportBreakdowns::defaultFor($key, 'pdf'));
        }

        [$share] = $this->share();
        $this->assertSame([ReportBreakdowns::PLATFORM => false, ReportBreakdowns::CONTENT => false], ReportBreakdowns::forShare($share, 'pdf'));
    }

    /**
     * An executive summary is the short product: no drill-down unless the operator enables it on that link.
     */
    public function test_a_summary_link_offers_no_drilldown_unless_the_operator_enables_it(): void
    {
        [, $detailedRaw] = $this->share(['form' => 'detailed']);
        $key = collect($this->live($detailedRaw)['ads_roster'])->firstWhere('provider', 'meta')['content_key'];
        $this->platform($detailedRaw, 'meta');

        [$summary, $raw] = $this->share(['form' => 'executive_summary']);
        $summaryKey = ContentKey::for($summary, (string) ExternalCreative::withoutGlobalScopes()->where('name', 'Meta Summer creative')->value('id'));
        $this->assertNotSame($key, $summaryKey);

        $this->assertSame([ReportBreakdowns::PLATFORM => false, ReportBreakdowns::CONTENT => false], $this->live($raw)['breakdowns']);
        $this->getJson("/api/v1/reports/shared/{$raw}/live/platform/meta".self::WINDOW)->assertNotFound();
        $this->getJson("/api/v1/reports/shared/{$raw}/live/content/{$summaryKey}".self::WINDOW)->assertNotFound();
        $this->actingAs($this->operator, 'sanctum')->getJson($this->operatorUrl($summary, 'platform/meta'))->assertNotFound();
        $this->app['auth']->forgetGuards();

        $summary->settings = ['breakdowns' => ['live' => [ReportBreakdowns::PLATFORM => true, ReportBreakdowns::CONTENT => true]]];
        $summary->save();
        $this->assertTrue($this->live($raw)['breakdowns'][ReportBreakdowns::PLATFORM]);
        $this->platform($raw, 'meta');
        $this->getJson("/api/v1/reports/shared/{$raw}/live/content/{$summaryKey}".self::WINDOW)->assertOk();
    }

    public function test_hidden_money_stays_hidden_one_level_down(): void
    {
        [$share, $raw] = $this->share();
        $share->hide_spend = true;
        $share->hide_revenue = true;
        $share->save();

        $meta = $this->platform($raw, 'meta');

        $this->assertNull($meta['totals']['spend']);
        $this->assertNull($meta['totals']['revenue']);
        $this->assertNull($meta['shares']['spend'], 'a spend share of hidden spend discloses its distribution');
        $this->assertSame('conversions', $meta['shares']['outcome']['metric']);
        foreach ($meta['timeseries'] as $point) {
            $this->assertNull($point['spend'] ?? null);
        }
        foreach ($meta['objectives'] as $block) {
            foreach (['spend', 'revenue', 'roas', 'cpa', 'cpc', 'cpm'] as $money) {
                $this->assertNull($block['metrics'][$money] ?? null, "objective block published hidden {$money}");
            }
        }
    }

    /**
     * A spend withheld for want of a rate makes the objective block's money «—», not the converted subset.
     */
    public function test_withheld_spend_is_unavailable_in_the_objective_blocks(): void
    {
        [, $raw] = $this->share();
        $this->assertNotNull(collect($this->platform($raw, 'tiktok')['objectives'])->firstWhere('path', 'conversion')['metrics']['spend'], 'the block has no spend before, so this proves nothing');

        $row = DailyMetric::withoutGlobalScopes()->where('provider', 'tiktok')->where('metric_key', 'spend')->first();
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'project_id' => $row->project_id,
            'external_account_id' => $row->external_account_id, 'external_campaign_id' => $row->external_campaign_id,
            'unified_campaign_id' => $row->unified_campaign_id, 'provider' => 'tiktok', 'metric_key' => 'spend',
            'metric_date' => '2026-07-12', 'value' => null, 'original_amount' => 75, 'original_currency' => 'USD',
        ]);

        $tiktok = $this->platform($raw, 'tiktok');
        $this->assertGreaterThan(0, $tiktok['totals']['spend_withheld_rows']);
        $block = collect($tiktok['objectives'])->firstWhere('path', 'conversion');
        foreach (['spend', 'cpa', 'roas'] as $money) {
            $this->assertNull($block['metrics'][$money], "the block stated {$money} from a converted subset");
        }
        $this->assertNotNull($block['metrics']['orders'], 'a count does not depend on a rate');
    }

    public function test_snapshot_links_expired_links_and_passwords_gate_the_drilldown(): void
    {
        [, $snapshot] = app(ShareService::class)->create($this->report, ['mode' => 'snapshot'], null);
        $this->getJson("/api/v1/reports/shared/{$snapshot}/live/platform/meta".self::WINDOW)->assertStatus(409);

        [$locked, $raw] = $this->share(['password' => 'secret1']);
        $this->getJson("/api/v1/reports/shared/{$raw}/live/platform/meta".self::WINDOW)->assertStatus(401);
        $this->withHeader('X-Report-Password', 'secret1')->getJson("/api/v1/reports/shared/{$raw}/live/platform/meta".self::WINDOW)->assertOk();

        $locked->forceFill(['revoked_at' => now()])->save();
        $this->withHeader('X-Report-Password', 'secret1')->getJson("/api/v1/reports/shared/{$raw}/live/platform/meta".self::WINDOW)->assertNotFound();
    }

    public function test_the_operator_sees_exactly_what_the_recipient_sees(): void
    {
        [$share, $raw] = $this->share();
        $client = $this->platform($raw, 'meta');
        $key = collect($this->live($raw)['ads_roster'])->firstWhere('provider', 'meta')['content_key'];

        $operator = $this->actingAs($this->operator, 'sanctum')->getJson($this->operatorUrl($share, 'platform/meta'))->assertOk()->json('data');
        $this->assertEquals($client['totals'], $operator['totals']);
        $this->assertEquals($client['shares'], $operator['shares']);
        $this->assertSame(collect($client['ads'])->pluck('content_key')->all(), collect($operator['ads'])->pluck('content_key')->all());

        $this->actingAs($this->operator, 'sanctum')->getJson($this->operatorUrl($share, "content/{$key}"))->assertOk()
            ->assertJsonPath('data.content.content_key', $key);
    }

    public function test_the_operator_route_is_isolated_by_project_report_tenant_and_permission(): void
    {
        [$share] = $this->share();
        $this->actingAs($this->operator, 'sanctum')->getJson($this->operatorUrl($share, 'platform/meta'))->assertOk();

        // A share of ANOTHER report in the same project does not resolve under this report.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $other = Report::create(['project_id' => $this->project->id, 'name' => 'R2', 'type' => 'executive', 'status' => 'completed', 'currency' => 'SAR', 'data' => []]);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
        [$foreign] = app(ShareService::class)->create($other, ['mode' => 'live', 'scope' => $share->scope], null);
        $this->actingAs($this->operator, 'sanctum')->getJson($this->operatorUrl($foreign, 'platform/meta'))->assertNotFound();

        // Another tenant's operator reaches nothing.
        $stranger = Tenant::create(['name' => 'B', 'slug' => 'stranger-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($stranger->id);
        $role = Role::create(['tenant_id' => $stranger->id, 'name' => 'S', 'slug' => 's']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $outsider = User::create(['name' => 'S', 'email' => 's-'.uniqid().'@b.test', 'password' => 'secret123']);
        $this->grantMembership($outsider, $stranger);
        $outsider->assignRole($role);

        // Without reports.view on the project, the drill-down is refused.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $bare = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);
        $bare->givePermissionTo('projects.view', 'projects.view.all');
        $viewer = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($bare);
        app(TenantContext::class)->forget();

        $this->app['auth']->forgetGuards();
        $status = $this->actingAs($outsider, 'sanctum')->getJson($this->operatorUrl($share, 'platform/meta'))->status();
        $this->assertContains($status, [403, 404], 'another tenant\'s operator opened this link\'s drill-down');

        $this->app['auth']->forgetGuards();
        $this->actingAs($viewer, 'sanctum')->getJson($this->operatorUrl($share, 'platform/meta'))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson($this->operatorUrl($share, 'platform/meta'))->assertUnauthorized();
    }
}
