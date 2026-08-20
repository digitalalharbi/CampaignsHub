<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricDefinition;
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\ValueObjects\Money;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\MetricDefinitionSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * C3 metrics layer: idempotent upsert, currency conversion, aggregation correctness + derived KPIs,
 * per-project isolation, and the read API (auth + RBAC). Everything runs on PostgreSQL.
 */
final class MetricsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $owner = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $owner->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($owner);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->projectA = Project::create(['client_workspace_id' => $ws->id, 'name' => 'A', 'status' => 'active']);
        $this->projectB = Project::create(['client_workspace_id' => $ws->id, 'name' => 'B', 'status' => 'active']);

        app(TenantContext::class)->forget();
    }

    /** Deterministic UUID from a label so external_account_id/external_campaign_id are valid uuids. */
    private function uid(string $label): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, "metrics-test:{$label}");
    }

    private function metric(string $projectId, string $key, float $value, string $date, array $over = []): NormalizedMetric
    {
        return new NormalizedMetric(
            tenantId: $this->tenant->id,
            projectId: $projectId,
            externalAccountId: $this->uid($over['acct'] ?? 'acc-1'),
            externalCampaignId: $this->uid($over['camp'] ?? 'ext-1'),
            provider: $over['provider'] ?? 'meta',
            metricKey: $key,
            metricDate: Carbon::parse($date),
            value: $value,
            unifiedCampaignId: $over['unified'] ?? null,
        );
    }

    public function test_upsert_is_idempotent_and_updates_in_place(): void
    {
        $upsert = app(UpsertDailyMetrics::class);
        $upsert->handle([$this->metric($this->projectA->id, 'spend', 100, '2026-06-01')]);
        $upsert->handle([$this->metric($this->projectA->id, 'spend', 250, '2026-06-01')]); // same natural key

        $rows = DailyMetric::withoutGlobalScopes()->where('metric_key', 'spend')->get();
        $this->assertCount(1, $rows); // no duplicate
        $this->assertEquals(250.0, (float) $rows->first()->value); // updated, not inserted
    }

    public function test_money_and_currency_conversion(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-06-01']);

        $converted = app(CurrencyConverter::class)->convert(Money::of(100, 'USD'), 'SAR', Carbon::parse('2026-06-10'));
        $this->assertSame('SAR', $converted->currency);
        $this->assertEqualsWithDelta(375.0, $converted->amount, 0.001);

        // Same-currency is a no-op; inverse pair is derived.
        $this->assertSame(1.0, app(CurrencyConverter::class)->rateFor('SAR', 'SAR', Carbon::parse('2026-06-10')));
    }

    public function test_aggregator_totals_with_derived_kpis(): void
    {
        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'impressions', 1000, '2026-06-01'),
            $this->metric($this->projectA->id, 'clicks', 50, '2026-06-01'),
            $this->metric($this->projectA->id, 'conversions', 10, '2026-06-01'),
            $this->metric($this->projectA->id, 'spend', 100, '2026-06-01'),
            $this->metric($this->projectA->id, 'revenue', 400, '2026-06-01'),
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->projectA->id);
        $t = app(MetricsAggregator::class)->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));

        $this->assertEquals(100.0, $t['spend']);
        $this->assertEquals(4.0, $t['roas']);   // 400 / 100
        $this->assertEquals(10.0, $t['cpa']);    // 100 / 10
        $this->assertEquals(0.05, $t['ctr']);    // 50 / 1000
        $this->assertEquals(2.0, $t['cpc']);     // 100 / 50
        $this->assertEquals(100.0, $t['cpm']);   // 100 / 1000 * 1000
    }

    public function test_metrics_are_isolated_per_project(): void
    {
        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'spend', 100, '2026-06-01'),
            $this->metric($this->projectB->id, 'spend', 999, '2026-06-01', ['camp' => 'ext-b']),
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->projectA->id);
        $a = app(MetricsAggregator::class)->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));
        $this->assertEquals(100.0, $a['spend']); // project B's 999 is not visible
    }

    public function test_platform_breakdown_has_share(): void
    {
        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'spend', 300, '2026-06-01', ['provider' => 'meta', 'camp' => 'm1']),
            $this->metric($this->projectA->id, 'spend', 100, '2026-06-01', ['provider' => 'google', 'camp' => 'g1']),
        ]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->projectA->id);
        $rows = app(MetricsAggregator::class)->byProvider(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));

        $this->assertSame('meta', $rows[0]['provider']); // ordered by spend desc
        $this->assertEqualsWithDelta(0.75, $rows[0]['spend_share'], 0.001);
    }

    public function test_expanded_objective_metrics_and_objective_filter(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->projectA->id);
        $camp = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'name' => 'Sales', 'objective' => 'sales', 'status' => 'active']);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'leads', 20, '2026-06-01', ['unified' => $camp->id]),
            $this->metric($this->projectA->id, 'video_views', 500, '2026-06-01', ['unified' => $camp->id]),
            $this->metric($this->projectA->id, 'reach', 800, '2026-06-01', ['unified' => $camp->id]),
            $this->metric($this->projectA->id, 'impressions', 1600, '2026-06-01', ['unified' => $camp->id]),
            $this->metric($this->projectA->id, 'spend', 100, '2026-06-01', ['unified' => $camp->id]),
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->projectA->id);
        $t = app(MetricsAggregator::class)->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));
        $this->assertEquals(20.0, $t['leads']);          // new base metric aggregates
        $this->assertEquals(500.0, $t['video_views']);
        $this->assertEquals(5.0, $t['cpl']);             // 100 / 20 (new derived)
        $this->assertEquals(2.0, $t['frequency']);       // 1600 / 800 (new derived)

        // Objective filter (backend-supported): sales returns the campaign's spend; awareness returns nothing.
        $sales = app(MetricsAggregator::class)->forObjectives(['sales'])->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));
        $this->assertEquals(100.0, $sales['spend']);
        $aware = app(MetricsAggregator::class)->forObjectives(['awareness'])->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));
        $this->assertEquals(0.0, $aware['spend']);
    }

    public function test_platform_filter_scopes_metrics_backend_side(): void
    {
        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'spend', 300, '2026-06-01', ['provider' => 'meta', 'camp' => 'm1']),
            $this->metric($this->projectA->id, 'spend', 100, '2026-06-01', ['provider' => 'google_ads', 'camp' => 'g1']),
        ]);

        // Aggregator: forProviders() limits every figure to the selected platform(s).
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->projectA->id);
        $metaOnly = app(MetricsAggregator::class)->forProviders(['meta'])->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));
        $this->assertEquals(300.0, $metaOnly['spend']);
        $all = app(MetricsAggregator::class)->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));
        $this->assertEquals(400.0, $all['spend']);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        // API: ?provider=meta filters the platform breakdown AND the summary — backend-supported, not React-only.
        $platforms = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/platforms?from=2026-06-01&to=2026-06-02&provider=meta")
            ->assertOk()->json('data');
        $this->assertCount(1, $platforms);
        $this->assertSame('meta', $platforms[0]['provider']);

        $summary = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/summary?from=2026-06-01&to=2026-06-02&provider=meta")
            ->assertOk()->json('data');
        $this->assertEquals(300.0, $summary['current']['spend']);
    }

    public function test_summary_api_requires_permission_and_returns_shape(): void
    {
        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'spend', 100, '2026-06-01'),
            $this->metric($this->projectA->id, 'revenue', 400, '2026-06-01'),
        ]);

        $ok = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/summary?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('data');
        $this->assertEquals(4.0, $ok['current']['roas']);
        $this->assertArrayHasKey('previous', $ok);
        $this->assertArrayHasKey('delta', $ok);

        // A user without campaigns.view is forbidden.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'None', 'slug' => 'none']);
        $role->givePermissionTo('projects.view', 'projects.view.all');
        $nobody = User::create(['name' => 'N', 'email' => 'n@a.test', 'password' => 'secret123']);
        $this->grantMembership($nobody, $this->tenant);
        $nobody->assignRole($role);
        app(TenantContext::class)->forget();

        $this->actingAs($nobody, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/summary")
            ->assertForbidden();
    }

    public function test_funnel_and_budget_and_freshness_apis_ok(): void
    {
        $campaign = null;
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $campaign = UnifiedCampaign::create([
            'project_id' => $this->projectA->id, 'name' => 'C1', 'status' => 'active',
            'total_budget' => 1000, 'budget_currency' => 'SAR',
        ]);
        app(TenantContext::class)->forget();

        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'impressions', 1000, '2026-06-01', ['unified' => $campaign->id]),
            $this->metric($this->projectA->id, 'clicks', 100, '2026-06-01', ['unified' => $campaign->id]),
            $this->metric($this->projectA->id, 'conversions', 10, '2026-06-01', ['unified' => $campaign->id]),
            $this->metric($this->projectA->id, 'spend', 200, '2026-06-01', ['unified' => $campaign->id]),
        ]);

        $base = "/api/v1/projects/{$this->projectA->id}/metrics";
        $this->actingAs($this->owner, 'sanctum')->getJson("{$base}/funnel?from=2026-06-01&to=2026-06-02")->assertOk();
        $this->actingAs($this->owner, 'sanctum')->getJson("{$base}/campaigns?from=2026-06-01&to=2026-06-02")
            ->assertOk()->assertJsonFragment(['campaign_name' => 'C1']);
        $this->actingAs($this->owner, 'sanctum')->getJson("{$base}/budget?from=2026-06-01&to=2026-06-02")
            ->assertOk()->assertJsonFragment(['budget' => 1000]);
        $this->actingAs($this->owner, 'sanctum')->getJson("{$base}/freshness?from=2026-06-01&to=2026-06-02")->assertOk();
    }

    /**
     * FUNNEL-NULL-001 — a stage nobody reported is null; a stage measured at zero is zero.
     *
     * Every stage was `COALESCE(SUM(…), 0)`, so «this platform does not count basket adds» and «nobody
     * added anything to a basket» arrived at the client identically, on the chart they read first. The
     * two must be told apart, and the way to prove they are is to put BOTH in one window: `add_to_cart`
     * is seeded at a genuine 0 and must stay 0, while `landing_page_views` and `checkout` are never
     * sent at all and must be null.
     *
     * The rate is measured against the nearest stage that WAS reported — the same `from_stage` rule
     * `CreativeFunnel` follows — so a funnel with an unreported middle never divides by a step that is
     * not on the screen.
     */
    public function test_a_funnel_stage_nobody_reported_is_null_and_a_measured_zero_is_still_zero(): void
    {
        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'impressions', 1000, '2026-06-01'),
            $this->metric($this->projectA->id, 'clicks', 100, '2026-06-01'),
            // Reported, and genuinely zero: nobody added to a basket, and the platform said so.
            $this->metric($this->projectA->id, 'add_to_cart', 0, '2026-06-01'),
            $this->metric($this->projectA->id, 'purchases', 10, '2026-06-01'),
            $this->metric($this->projectA->id, 'spend', 200, '2026-06-01'),
            // `landing_page_views` and `checkout` are deliberately absent — never sent, not measured.
        ]);

        $stages = collect($this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/funnel?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('data'))->keyBy('stage');

        // Never sent — and this is the assertion the old COALESCE fails.
        foreach (['landing_page_views', 'checkout'] as $silent) {
            $this->assertNull($stages[$silent]['count'], "{$silent} was never sent — null, not 0");
            $this->assertFalse($stages[$silent]['reported'], "{$silent} was never sent and must not claim to be reported");
            $this->assertNull($stages[$silent]['step_rate'], "{$silent} has no count, so it has no rate");
            $this->assertNull($stages[$silent]['cost_per']);
            $this->assertNull($stages[$silent]['from_stage']);
        }

        // Sent, and zero. The fix must not turn a real measurement into silence.
        $this->assertTrue($stages['add_to_cart']['reported']);
        $this->assertSame(0.0, (float) $stages['add_to_cart']['count']);
        // Measured against clicks, skipping the landing-page step the platform never sent.
        $this->assertSame('clicks', $stages['add_to_cart']['from_stage']);
        $this->assertSame(0.0, (float) $stages['add_to_cart']['step_rate']);
        $this->assertSame(1.0, (float) $stages['add_to_cart']['drop_off']);
        // 0 add-to-carts cost nothing per add-to-cart; a division by zero is null, not a figure.
        $this->assertNull($stages['add_to_cart']['cost_per']);

        // And the stage below the zero still measures against the zero, so its rate is null rather
        // than a fabricated ratio — «10 purchases from 0 basket adds» is not a conversion rate.
        $this->assertSame('add_to_cart', $stages['purchases']['from_stage']);
        $this->assertNull($stages['purchases']['step_rate']);
        $this->assertSame(20.0, (float) $stages['purchases']['cost_per']);

        // The first stage is reported and has nothing above it.
        $this->assertTrue($stages['impressions']['reported']);
        $this->assertNull($stages['impressions']['from_stage']);
        $this->assertSame(0.1, (float) $stages['clicks']['step_rate']);
    }

    /**
     * FUNNEL-PURCHASE-001 — the stage labelled «Purchase» counts purchases.
     *
     * The funnel's terminal stage read `conversions` while the label under it said Purchase, and
     * those are not the same figure: `conversions` is the sum of EVERY event a campaign was
     * optimised for — a lead, an install, a registration. On a lead-generation account the funnel
     * ended in a count of leads with «الشراء» printed beneath it.
     *
     * It hid because Snapchat maps both canonical keys from `conversion_purchases`, so the two were
     * literally the same number and the label was accidentally true. TikTok is the first platform to
     * map them apart (`complete_payment` vs `conversion`), which turns a dormant mislabelling into a
     * figure a client would act on.
     *
     * So the fixture is a lead-gen week: 400 conversions, 3 purchases. If the stage still read
     * `conversions` the funnel would tell the client they sold four hundred things.
     */
    public function test_the_funnel_stage_labelled_purchase_counts_purchases_not_every_conversion(): void
    {
        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'impressions', 50000, '2026-06-01'),
            $this->metric($this->projectA->id, 'clicks', 900, '2026-06-01'),
            // A lead-generation buy: 400 results, of which 3 were actual sales.
            $this->metric($this->projectA->id, 'conversions', 400, '2026-06-01'),
            $this->metric($this->projectA->id, 'purchases', 3, '2026-06-01'),
            $this->metric($this->projectA->id, 'spend', 600, '2026-06-01'),
        ]);

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/funnel?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('data');

        $last = end($body);

        $this->assertSame('purchases', $last['stage']);
        $this->assertSame('Purchase', $last['label']);
        $this->assertSame(3.0, (float) $last['count'], 'the Purchase stage counted every conversion, not the sales');
        $this->assertNotEquals(400.0, (float) $last['count']);

        // `conversions` is not demoted — it is simply not the sale. The totals still report it.
        $summary = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/summary?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('data.current');

        $this->assertSame(400.0, (float) $summary['conversions'], '«النتائج» still means every result the platform reported');
        $this->assertSame(3.0, (float) $summary['purchases']);
    }

    /**
     * CAMPAIGN-020: comparing campaigns side by side must reuse the same derived-KPI formulas, must
     * expose each campaign's own objective (so the UI never blends KPIs across objectives), and must
     * fail closed when someone slips in a campaign from another project.
     */
    public function test_compare_returns_per_campaign_totals_series_and_platform_split(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $sales = UnifiedCampaign::create(['project_id' => $this->projectA->id, 'name' => 'Sales', 'objective' => 'sales', 'status' => 'active']);
        $reach = UnifiedCampaign::create(['project_id' => $this->projectA->id, 'name' => 'Reach', 'objective' => 'awareness', 'status' => 'active']);
        app(TenantContext::class)->forget();

        app(UpsertDailyMetrics::class)->handle([
            // Sales campaign: two days, two platforms.
            $this->metric($this->projectA->id, 'spend', 100, '2026-06-01', ['unified' => $sales->id, 'camp' => 's1']),
            $this->metric($this->projectA->id, 'conversions', 10, '2026-06-01', ['unified' => $sales->id, 'camp' => 's1']),
            $this->metric($this->projectA->id, 'revenue', 500, '2026-06-01', ['unified' => $sales->id, 'camp' => 's1']),
            $this->metric($this->projectA->id, 'spend', 60, '2026-06-02', ['unified' => $sales->id, 'camp' => 's2', 'provider' => 'tiktok']),
            // Awareness campaign: impressions only — no conversions, so its CPA must stay null, not 0.
            $this->metric($this->projectA->id, 'impressions', 20000, '2026-06-01', ['unified' => $reach->id, 'camp' => 'r1']),
            $this->metric($this->projectA->id, 'spend', 40, '2026-06-01', ['unified' => $reach->id, 'camp' => 'r1']),
        ]);

        $res = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/compare?from=2026-06-01&to=2026-06-02"
                ."&campaign_ids[]={$sales->id}&campaign_ids[]={$reach->id}")
            ->assertOk();

        $this->assertTrue($res->json('data.mixed_objectives'), 'sales vs awareness must be flagged as mixed');

        $rows = collect($res->json('data.campaigns'))->keyBy('campaign_id');
        $s = $rows[$sales->id];
        $this->assertSame('sales', $s['objective']);
        $this->assertEquals(160.0, $s['totals']['spend']);        // 100 + 60
        $this->assertEquals(16.0, $s['totals']['cpa']);           // 160 / 10 — derived from sums, not averaged
        $this->assertEquals(3.125, $s['totals']['roas']);         // 500 / 160
        $this->assertCount(2, $s['series']);                      // one point per day
        $this->assertSame(['meta', 'tiktok'], collect($s['platforms'])->pluck('provider')->sort()->values()->all());

        $r = $rows[$reach->id];
        $this->assertSame('awareness', $r['objective']);
        $this->assertNull($r['totals']['cpa'], 'no conversions must stay null, never 0');
        $this->assertEquals(2.0, $r['totals']['cpm']);            // 40 / 20000 * 1000
    }

    public function test_compare_refuses_campaigns_outside_the_active_project(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $mine = UnifiedCampaign::create(['project_id' => $this->projectA->id, 'name' => 'Mine', 'objective' => 'sales', 'status' => 'active']);
        $theirs = UnifiedCampaign::create(['project_id' => $this->projectB->id, 'name' => 'Theirs', 'objective' => 'sales', 'status' => 'active']);
        app(TenantContext::class)->forget();

        // Only one id survives the project scope, so there is nothing to compare — 422, never a silent
        // one-sided result and never another project's numbers.
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/compare?campaign_ids[]={$mine->id}&campaign_ids[]={$theirs->id}")
            ->assertStatus(422);

        // Fewer than two ids is a validation error, not an empty comparison.
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/compare?campaign_ids[]={$mine->id}")
            ->assertStatus(422);
    }

    /**
     * UX-DASH-001 — the campaign control on the dashboard narrows the dashboard.
     *
     * The two claims that matter are opposites and both are here. **With** `?campaign=` every figure
     * is that campaign's, on the same request the funnel and the pacing table make, so the page
     * cannot narrow its KPI row and leave its chart wide. **Without** it the page is not narrowed at
     * all — the aggregator's campaign bound is fail-closed by design (an empty set means «no
     * campaigns» for a shared link's ceiling), so passing the unset filter straight through would
     * have emptied the dashboard for everybody who had not picked one.
     */
    public function test_the_campaign_filter_narrows_every_figure_and_an_absent_one_narrows_nothing(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $sales = UnifiedCampaign::create(['project_id' => $this->projectA->id, 'name' => 'Sales', 'objective' => 'sales', 'status' => 'active']);
        $reach = UnifiedCampaign::create(['project_id' => $this->projectA->id, 'name' => 'Reach', 'objective' => 'awareness', 'status' => 'active']);
        app(TenantContext::class)->forget();

        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'spend', 100, '2026-06-01', ['unified' => $sales->id, 'camp' => 's1']),
            $this->metric($this->projectA->id, 'impressions', 5000, '2026-06-01', ['unified' => $sales->id, 'camp' => 's1']),
            $this->metric($this->projectA->id, 'spend', 40, '2026-06-01', ['unified' => $reach->id, 'camp' => 'r1']),
            $this->metric($this->projectA->id, 'impressions', 9000, '2026-06-01', ['unified' => $reach->id, 'camp' => 'r1']),
        ]);

        $base = "/api/v1/projects/{$this->projectA->id}/metrics";
        $window = 'from=2026-06-01&to=2026-06-02';

        // Unfiltered: both campaigns.
        $all = $this->actingAs($this->owner, 'sanctum')->getJson("{$base}/summary?{$window}")->assertOk();
        $this->assertEquals(140.0, $all->json('data.current.spend'));

        // Filtered: one campaign, and the same bound on the funnel — not just on the KPI row.
        $one = $this->actingAs($this->owner, 'sanctum')
            ->getJson("{$base}/summary?{$window}&campaign={$sales->id}")
            ->assertOk();
        $this->assertEquals(100.0, $one->json('data.current.spend'));
        $this->assertTrue($one->json('data.commerce') === null || $one->json('data.commerce.filtered_view'));

        $impressions = collect($this->actingAs($this->owner, 'sanctum')
            ->getJson("{$base}/funnel?{$window}&campaign={$sales->id}")
            ->assertOk()
            ->json('data'))
            ->firstWhere('stage', 'impressions');
        $this->assertEquals(5000, $impressions['count'], 'the funnel must narrow with the KPI row, not beside it');

        // And the campaign breakdown returns only the chosen campaign.
        $rows = $this->actingAs($this->owner, 'sanctum')
            ->getJson("{$base}/campaigns?{$window}&campaign={$sales->id}")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($sales->id, $rows[0]['campaign_id']);
    }

    /**
     * UX-METRICS-001 — the summary says which zeros are measurements.
     *
     * The defect is invisible in the arithmetic and obvious on the screen: `PIVOT` coalesces to 0,
     * so a platform that does not count landing-page views produces the same «0» as one that
     * counted none, and the KPI card reads as a failed campaign either way. `reported` is what lets
     * the card say «لم ترسله المنصة» for the first and «0» for the second.
     */
    public function test_the_summary_says_which_base_metrics_were_reported_at_all(): void
    {
        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'spend', 100, '2026-06-01'),
            $this->metric($this->projectA->id, 'impressions', 5000, '2026-06-01'),
            // Sent, and genuinely zero — a real measurement that must not be confused with silence.
            $this->metric($this->projectA->id, 'clicks', 0, '2026-06-01'),
        ]);

        $reported = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/summary?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('data.reported');

        $this->assertTrue($reported['spend']);
        $this->assertTrue($reported['impressions']);
        $this->assertTrue($reported['clicks'], 'a measured zero was reported');
        $this->assertFalse($reported['landing_page_views'], 'never sent — the card must not print a zero');
        $this->assertFalse($reported['reach']);

        // Derived ratios are computed, never sent: their own null already says «no denominator».
        $this->assertArrayNotHasKey('roas', $reported);
        $this->assertArrayNotHasKey('ctr', $reported);
    }

    // ---- NORM-001: the basis of the figures ---------------------------------------------------

    /**
     * A converted amount reports BOTH sides and the rate.
     *
     * The conversion has always happened and has never been visible: a USD spend appeared as a SAR
     * number with nothing saying it had been through an exchange rate. Somebody reconciling this
     * dashboard against the platform's own console would find two different figures for the same day
     * and no explanation on the page for why.
     */
    public function test_normalization_reports_the_conversion_that_produced_a_figure(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-06-01']);
        app(TenantContext::class)->forget();

        $usd = new NormalizedMetric(
            tenantId: $this->tenant->id,
            projectId: $this->projectA->id,
            externalAccountId: $this->uid('acc-1'),
            externalCampaignId: $this->uid('ext-usd'),
            provider: 'meta',
            metricKey: 'spend',
            metricDate: Carbon::parse('2026-06-01'),
            value: 375.0,
            originalCurrency: 'USD',
            projectCurrency: 'SAR',
            originalAmount: 100.0,
            convertedAmount: 375.0,
            exchangeRate: 3.75,
            originalTimezone: 'UTC',
            projectTimezone: 'Asia/Riyadh',
            attributionWindow: '7d_click_1d_view',
        );
        app(UpsertDailyMetrics::class)->handle([$usd]);

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/normalization?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('data');

        $this->assertSame('SAR', $body['project_currency']);
        $this->assertCount(1, $body['currencies']);
        $this->assertSame('USD', $body['currencies'][0]['from']);
        $this->assertSame('SAR', $body['currencies'][0]['to']);
        $this->assertTrue($body['currencies'][0]['converted']);
        $this->assertEqualsWithDelta(3.75, $body['currencies'][0]['rate_min'], 0.000001);

        // The day boundary is stated, because a day counted in Riyadh is not the day UTC reported.
        $this->assertSame('UTC', $body['timezones'][0]['from']);
        $this->assertSame('Asia/Riyadh', $body['timezones'][0]['to']);
        $this->assertTrue($body['timezones'][0]['shifted']);

        $this->assertSame('7d_click_1d_view', $body['attribution_windows'][0]['window']);

        // FX-001. Nothing was withheld here, and the endpoint says so rather than omitting the key —
        // an absent field and a zero are the same on the wire only until the field starts appearing.
        $this->assertSame(0, $body['currencies'][0]['withheld']);
    }

    /**
     * FX-001 — a figure the pipeline REFUSED to convert is reported, not quietly dropped.
     *
     * A row with no trustworthy rate carries a null `value`, so `SUM` skips it and every money total
     * on the page is short by that amount while looking complete. This count is the only thing
     * standing between an operator and a total they have no reason to distrust.
     */
    public function test_normalization_counts_the_figures_that_could_not_be_converted(): void
    {
        $withheld = new NormalizedMetric(
            tenantId: $this->tenant->id,
            projectId: $this->projectA->id,
            externalAccountId: $this->uid('acc-1'),
            externalCampaignId: $this->uid('ext-jpy'),
            provider: 'meta',
            metricKey: 'spend',
            metricDate: Carbon::parse('2026-06-01'),
            // No rate existed for JPY on this date, so the pipeline published no figure at all.
            value: null,
            originalCurrency: 'JPY',
            projectCurrency: 'SAR',
            originalAmount: 5000.0,
        );
        app(UpsertDailyMetrics::class)->handle([$withheld]);

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/normalization?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('data');

        $jpy = collect($body['currencies'])->firstWhere('from', 'JPY');

        $this->assertNotNull($jpy, 'a withheld row vanished from the basis entirely');
        $this->assertSame(1, $jpy['withheld']);
        $this->assertNull($jpy['rate_min'], 'a rate was reported for a conversion that never happened');
    }

    /**
     * Two attribution windows in one range are BOTH reported.
     *
     * Conversions counted under different windows are different measurements. Reporting only the
     * commonest would leave a total that silently mixes them, which is the failure this exists to
     * prevent — the arithmetic is fine and the conclusion is wrong.
     */
    public function test_normalization_reports_every_attribution_window_in_the_range(): void
    {
        $make = fn (string $window, string $camp) => new NormalizedMetric(
            tenantId: $this->tenant->id,
            projectId: $this->projectA->id,
            externalAccountId: $this->uid('acc-1'),
            externalCampaignId: $this->uid($camp),
            provider: 'meta',
            metricKey: 'conversions',
            metricDate: Carbon::parse('2026-06-01'),
            value: 5,
            attributionWindow: $window,
        );
        app(UpsertDailyMetrics::class)->handle([$make('7d_click_1d_view', 'a'), $make('1d_click', 'b')]);

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/normalization?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('data');

        $windows = array_column($body['attribution_windows'], 'window');
        sort($windows);
        $this->assertSame(['1d_click', '7d_click_1d_view'], $windows);
    }

    /**
     * A metric key nothing reads is named; one that IS read is not falsely accused.
     *
     * `add_to_cart` and `checkout` are absent from the aggregator's pivot but ARE funnel stages, so a
     * check written against the pivot alone would report two metrics as ignored while both are counted.
     * That would be a fabricated defect on a page whose whole job is to be trusted about provenance.
     */
    public function test_normalization_names_only_the_metric_keys_no_kpi_reads(): void
    {
        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'add_to_cart', 12, '2026-06-01', ['camp' => 'atc']),
            $this->metric($this->projectA->id, 'checkout', 6, '2026-06-01', ['camp' => 'chk']),
            $this->metric($this->projectA->id, 'some_platform_only_metric', 3, '2026-06-01', ['camp' => 'odd']),
        ]);

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/normalization?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('data');

        $this->assertSame(['some_platform_only_metric'], $body['unread_metric_keys']);
    }

    /**
     * Every metric the aggregator can emit has a catalogue entry.
     *
     * The catalogue existed and named fifteen of the thirty-one keys the engine produces, because the
     * seeder was written once and the aggregator grew afterwards. A half-catalogue is worse than none:
     * the gaps read as metrics the product does not have. This fails the moment a metric is added
     * without a definition, which is the only way the two stay in step.
     */
    public function test_every_metric_the_aggregator_emits_is_defined_in_the_catalogue(): void
    {
        $this->seed(MetricDefinitionSeeder::class);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->projectA->id);
        $emitted = array_keys(app(MetricsAggregator::class)->totals(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02')));
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        $defined = MetricDefinition::pluck('key')->all();

        /*
         * FX-WITHHELD-UI-001 — the money-truth annotations are subtracted, not catalogued.
         *
         * `spend_withheld_rows`, `spend_original` and their revenue twins describe `spend` and
         * `revenue` — whether anything was withheld for want of an FX rate, and what the platform
         * actually reported. They measure nothing themselves, so a `MetricDefinition` would offer
         * «withheld rows» as a KPI somebody could chart, which it is not.
         *
         * They are subtracted from a NAMED source rather than a literal list here, so adding another
         * annotation cannot silently widen the exemption.
         */
        $emitted = array_values(array_diff($emitted, MetricsAggregator::moneyTruthKeys()));

        $missing = array_values(array_diff($emitted, $defined));

        $this->assertSame([], $missing, 'Every metric the dashboard computes needs a definition: '.implode(', ', $missing));

        // Ratios must be marked non-additive, because that is what stops them being summed across days.
        foreach (['ctr', 'cpc', 'cpm', 'cpa', 'roas', 'conversion_rate'] as $ratio) {
            $this->assertFalse(
                (bool) MetricDefinition::where('key', $ratio)->value('is_additive'),
                "{$ratio} must be non-additive — summing it across days does not give the period's value",
            );
        }
    }

    /**
     * The objectives reported are this project's, never the installation's.
     *
     * Found in live review, not by a test: the page said «no data in this period» in every section and
     * then confidently named an objective. The subquery behind it read `daily_metrics` through the
     * query builder, which carries no global scopes, so it answered with every objective in the
     * database. On a project that HAD data it would not have contradicted itself — it would simply have
     * printed another tenant's campaigns as this one's, with nothing to mark them.
     */
    public function test_normalization_objectives_do_not_leak_across_projects(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $mine = UnifiedCampaign::create(['project_id' => $this->projectA->id, 'name' => 'Mine', 'objective' => 'sales', 'status' => 'active']);
        $theirs = UnifiedCampaign::create(['project_id' => $this->projectB->id, 'name' => 'Theirs', 'objective' => 'awareness', 'status' => 'active']);
        app(TenantContext::class)->forget();

        app(UpsertDailyMetrics::class)->handle([
            $this->metric($this->projectA->id, 'spend', 100, '2026-06-01', ['unified' => $mine->id, 'camp' => 'a']),
            $this->metric($this->projectB->id, 'spend', 999, '2026-06-01', ['unified' => $theirs->id, 'camp' => 'b']),
        ]);

        $body = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/normalization?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('data');

        $objectives = array_column($body['objectives']['present'], 'objective');
        $this->assertSame(['sales'], $objectives, 'project B’s awareness campaign must not appear here');
        $this->assertFalse($body['objectives']['mixed'], 'one project, one objective — not a mixed range');
    }

    /** The currency in `meta` comes from the data. An empty range claims no currency at all. */
    public function test_metrics_meta_currency_is_read_from_the_rows_not_assumed(): void
    {
        $empty = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/summary?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('meta');

        $this->assertNull($empty['currency'], 'a period with no money rows must not announce a currency');

        app(UpsertDailyMetrics::class)->handle([
            new NormalizedMetric(
                tenantId: $this->tenant->id,
                projectId: $this->projectA->id,
                externalAccountId: $this->uid('acc-1'),
                externalCampaignId: $this->uid('ext-aed'),
                provider: 'meta',
                metricKey: 'spend',
                metricDate: Carbon::parse('2026-06-01'),
                value: 50,
                originalCurrency: 'AED',
                projectCurrency: 'AED',
            ),
        ]);

        $meta = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/metrics/summary?from=2026-06-01&to=2026-06-02")
            ->assertOk()
            ->json('meta');

        $this->assertSame('AED', $meta['currency'], 'the response must report the project’s real currency, not SAR');
    }
}
