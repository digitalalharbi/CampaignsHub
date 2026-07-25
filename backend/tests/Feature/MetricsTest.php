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
}
