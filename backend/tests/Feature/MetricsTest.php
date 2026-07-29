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
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\ValueObjects\Money;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
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
        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
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
        $nobody = User::create(['tenant_id' => $this->tenant->id, 'name' => 'N', 'email' => 'n@a.test', 'password' => 'secret123']);
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
}
