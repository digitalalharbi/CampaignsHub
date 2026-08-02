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
