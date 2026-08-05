<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UNIFIED-001 — one synced source behind every surface.
 *
 * The brief asks that the dashboard, the campaigns, the content, the analytics, the funnel, the
 * reports, the client links, the alerts and the budgets all be fed by the SAME synced data, «دون
 * مصادر منفصلة أو تكرار». The risk this suite exists for is not that a figure is computed wrongly —
 * it is that a figure is computed TWICE. Two implementations of ROAS agree on the day they are
 * written and drift the first time either is touched, and when they disagree the reader has no way to
 * tell which one lied. So these tests pin the singularity itself: the alert engine and the dashboard
 * must reach the same number because they run the same code, and the freshness badge on every surface
 * must be the same verdict for the same reason.
 */
final class UnifiedDataSourceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private UnifiedCampaign $campaign;

    private ExternalCampaign $external;

    private ExternalAccount $adAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'U', 'slug' => 'u-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o@u.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);

        $this->adAccount = $this->account('snapchat', 'ad_account');

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'حملة', 'status' => 'active', 'objective' => 'sales',
            'total_budget' => 1000, 'budget_currency' => 'SAR',
        ]);

        $this->external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->adAccount->getKey(),
            'unified_campaign_id' => $this->campaign->id,
            'provider' => 'snapchat', 'external_id' => 'ext-1', 'name' => 'حملة', 'status' => 'active',
        ]);

        app(TenantContext::class)->forget();
    }

    // ── One engine behind the alerts and the dashboard ───────────────────────────────────────

    /**
     * The alert engine reads the SAME ROAS the dashboard shows, because it runs the same code.
     *
     * The evaluator used to sum `daily_metrics` itself and divide revenue by spend inline. The
     * arithmetic matched {@see MetricsAggregator} the day it was written, and nothing held it there —
     * so an alert firing on a ROAS that appears nowhere on the product was one edit away.
     */
    public function test_the_alert_engine_and_the_metrics_engine_agree_on_roas(): void
    {
        $this->seedMetrics('2026-07-20', spend: 400, revenue: 1600); // ROAS 4
        $this->seedMetrics('2026-07-27', spend: 500, revenue: 500);  // ROAS 1 — a 75% fall

        $this->holdingTenant((string) $this->tenant->id);

        $engineRoas = app(MetricsAggregator::class)
            ->acrossProjects()
            ->forCampaign((string) $this->campaign->id)
            ->totals(Carbon::parse('2026-07-25'), Carbon::parse('2026-07-31'))['roas'];

        $rule = AlertRule::create([
            'tenant_id' => $this->tenant->id, 'name' => 'هبوط العائد', 'type' => 'roas_drop',
            'severity' => 'warning',
            'threshold' => ['pct' => 25, 'days' => 7], 'cooldown_minutes' => 60, 'active' => true,
        ]);

        app(AlertEvaluator::class)->evaluateRule($rule, Carbon::parse('2026-07-31'));

        $event = AlertEvent::withoutGlobalScopes()->where('type', 'roas_drop')->firstOrFail();

        $this->assertSame(1.0, (float) $engineRoas);
        $this->assertSame((float) $engineRoas, (float) $event->context['roas_current']);
        $this->assertSame(4.0, (float) $event->context['roas_previous']);

        app(TenantContext::class)->forget();
    }

    /** Budget alerts spend the same figure the budget pacing screen spends. */
    public function test_the_budget_alert_and_the_budget_screen_spend_the_same_figure(): void
    {
        $this->seedMetrics(Carbon::today()->subDays(2)->toDateString(), spend: 950, revenue: 100);

        $this->holdingTenant((string) $this->tenant->id);

        $rule = AlertRule::create([
            'tenant_id' => $this->tenant->id, 'name' => 'خطر الميزانية', 'type' => 'budget_risk',
            'severity' => 'warning',
            'threshold' => ['ratio' => 0.9], 'cooldown_minutes' => 60, 'active' => true,
        ]);

        app(AlertEvaluator::class)->evaluateRule($rule, Carbon::today());

        $event = AlertEvent::withoutGlobalScopes()->where('type', 'budget_risk')->firstOrFail();
        app(TenantContext::class)->forget();

        $pacing = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('metrics/budget?from='.Carbon::today()->subDays(7)->toDateString().'&to='.Carbon::today()->toDateString()))
            ->assertOk()->json('data.0');

        $this->assertSame(950.0, (float) $event->context['spend']);
        $this->assertSame((float) $event->context['spend'], (float) $pacing['spent']);
    }

    // ── One freshness verdict ────────────────────────────────────────────────────────────────

    /**
     * A store that has not been swept in a week makes the project stale, even while the ads are fresh.
     *
     * Every freshness readout in the product looked at `daily_metrics` alone. Once revenue, orders,
     * AOV and ROAS came off a shop, a badge that never asked the shop anything was vouching for the
     * figures it was least entitled to vouch for.
     */
    public function test_an_unswept_store_makes_the_project_stale_even_when_the_ads_are_fresh(): void
    {
        $this->seedMetrics(Carbon::today()->toDateString(), spend: 100, revenue: 300);
        $this->seedSuccessfulRun('snapchat', Carbon::now());

        $store = $this->seedStoreWithOrder(lastSyncedAt: Carbon::now()->subDays(7));

        $freshness = app(DataFreshnessService::class)->state(
            (string) $this->tenant->id,
            [(string) $this->project->id],
            Carbon::today(),
            Carbon::today(),
        );

        $states = collect($freshness['sources'])->pluck('state', 'provider');

        $this->assertSame('fresh', $states['snapchat']);
        $this->assertSame('stale', $states['salla']);
        $this->assertSame('stale', $freshness['state']);
        $this->assertSame((string) $store->getKey(), collect($freshness['sources'])->firstWhere('kind', 'store')['account_id']);
    }

    /**
     * The dashboard strip, the client link's footer and the client analytics header say the same thing.
     *
     * Not «similar things» — the same verdict, from one call. This is the assertion that would have
     * caught the state the product was actually in: three readouts, three cutoffs, one project reading
     * `fresh` in one place and `stale` in another on the same afternoon.
     */
    public function test_every_surface_reports_the_same_freshness_verdict(): void
    {
        $this->seedMetrics(Carbon::today()->subDays(5)->toDateString(), spend: 100, revenue: 300);
        $this->seedSuccessfulRun('snapchat', Carbon::now()->subDays(5));

        $service = app(DataFreshnessService::class)->state(
            (string) $this->tenant->id,
            [(string) $this->project->id],
            Carbon::today()->subDays(5),
            Carbon::today(),
        );

        $dashboard = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('metrics/freshness?from='.Carbon::today()->subDays(5)->toDateString().'&to='.Carbon::today()->toDateString()))
            ->assertOk();

        $this->assertSame($service['state'], $dashboard->json('meta.summary.state'));
        $this->assertSame($service['last_sync_at'], $dashboard->json('meta.summary.last_sync_at'));
        // And the source rows are the service's rows, not a second query's.
        $this->assertSame(
            collect($service['sources'])->pluck('state', 'provider')->all(),
            collect($dashboard->json('data'))->pluck('last_sync_status', 'provider')->all(),
        );
    }

    /**
     * A platform we have never read is NAMED, not omitted.
     *
     * It is the single most important row on a freshness list and the only one with no data to build a
     * row out of, so a list assembled from whatever the tables happen to hold drops exactly the row
     * that mattered — and a missing row reads on the page as a platform that was fine and quiet.
     */
    public function test_a_platform_that_was_never_read_is_named_rather_than_omitted(): void
    {
        $this->seedMetrics(Carbon::today()->toDateString(), spend: 100, revenue: 300);
        $this->seedSuccessfulRun('snapchat', Carbon::now());

        $sources = app(DataFreshnessService::class)->sources(
            (string) $this->tenant->id,
            [(string) $this->project->id],
            ['snapchat', 'tiktok'],
        );

        $states = collect($sources)->pluck('state', 'provider');

        $this->assertSame('fresh', $states['snapchat']);
        $this->assertSame('awaiting_credentials', $states['tiktok']);
    }

    /**
     * Figures on the table disprove «awaiting credentials», whatever the run log says.
     *
     * Runs are pruned by retention and rows predate the day their table started recording; data does
     * neither. The live review caught this calling a store with six orders and a sweep twenty minutes
     * old «awaiting credentials» — a badge saying we cannot read the shop, printed beside the shop's
     * revenue, on one page.
     */
    public function test_a_source_with_figures_is_never_reported_as_awaiting_credentials(): void
    {
        $store = $this->seedStoreWithOrder();
        // The run log loses its record — retention, or a table younger than the connection.
        IntegrationSyncRun::withoutGlobalScopes()->where('provider_connection_id', $store->provider_connection_id)->delete();

        $sources = app(DataFreshnessService::class)->sources(
            (string) $this->tenant->id,
            [(string) $this->project->id],
        );

        $this->assertSame('fresh', collect($sources)->firstWhere('kind', 'store')['state']);
    }

    /**
     * A store-only project is not «partial» for want of ad metrics nobody was going to write.
     *
     * Missing days count days with no `daily_metrics` row. With no ad platform connected there is
     * nothing to write one, so every day of the window read as a gap and the badge said `partial`
     * permanently — a standing warning about an absence that is just what the project is.
     */
    public function test_a_store_only_project_is_not_partial_for_want_of_ad_metrics(): void
    {
        $this->seedStoreWithOrder();

        $state = app(DataFreshnessService::class)->state(
            (string) $this->tenant->id,
            [(string) $this->project->id],
            Carbon::today()->subDays(29),
            Carbon::today(),
        );

        $this->assertSame(0, $state['missing_days']);
        $this->assertSame('fresh', $state['state']);
    }

    // ── The store reaches the dashboard, from the funnel's own service ────────────────────────

    /** The dashboard's store block is the funnel's figures, not a second count of the same orders. */
    public function test_the_dashboard_carries_the_stores_own_figures(): void
    {
        $this->seedMetrics(Carbon::today()->subDay()->toDateString(), spend: 200, revenue: 50);
        $this->seedStoreWithOrder(total: 800);

        $window = 'from='.Carbon::today()->subDays(3)->toDateString().'&to='.Carbon::today()->toDateString();

        $summary = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url("metrics/summary?{$window}"))->assertOk();
        $funnel = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url("commerce/funnel?{$window}"))->assertOk();

        $this->assertTrue($summary->json('data.commerce.available'));
        $this->assertSame(
            (float) $funnel->json('data.totals.revenue'),
            (float) $summary->json('data.commerce.revenue'),
        );
        $this->assertSame(
            (float) $funnel->json('data.derived.roas'),
            (float) $summary->json('data.commerce.roas'),
        );
        // The ad platforms' own `revenue` key is a different number and stays where it was.
        $this->assertSame(50.0, (float) $summary->json('data.current.revenue'));
    }

    /**
     * A platform filter does not narrow the store block, and the block says so rather than pretending.
     *
     * Spend narrows to the chosen platform; an order does not — a large share of them arrive with no
     * usable attribution at all. So the figures stay whole-shop and `filtered_view` is raised, which
     * the card renders as a sentence. Suppressing the block instead would have been worse than
     * useless: the dashboard opens on an objective filter, so the numbers would never have appeared.
     */
    public function test_a_platform_filter_does_not_narrow_the_store_block_and_the_block_says_so(): void
    {
        $this->seedMetrics(Carbon::today()->subDay()->toDateString(), spend: 200, revenue: 50);
        $this->seedStoreWithOrder(total: 800);

        $window = 'from='.Carbon::today()->subDays(3)->toDateString().'&to='.Carbon::today()->toDateString();

        $filtered = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url("metrics/summary?provider=snapchat&{$window}"))->assertOk();
        $unfiltered = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url("metrics/summary?{$window}"))->assertOk();

        $this->assertTrue($filtered->json('data.commerce.filtered_view'));
        $this->assertFalse($unfiltered->json('data.commerce.filtered_view'));
        // Same shop, same figure — the filter narrowed the ads and left the ledger alone.
        $this->assertSame(
            (float) $unfiltered->json('data.commerce.revenue'),
            (float) $filtered->json('data.commerce.revenue'),
        );
        $this->assertNotEmpty($filtered->json('data.commerce.unfiltered_note_ar'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function url(string $path): string
    {
        return "/api/v1/projects/{$this->project->id}/{$path}";
    }

    private function seedMetrics(string $date, float $spend, float $revenue): void
    {
        $this->holdingTenant((string) $this->tenant->id);

        app(UpsertDailyMetrics::class)->handle([
            $this->metric($date, 'spend', $spend),
            $this->metric($date, 'revenue', $revenue),
            $this->metric($date, 'conversions', 0),
        ]);

        app(TenantContext::class)->forget();
    }

    private function metric(string $date, string $key, float $value): NormalizedMetric
    {
        return new NormalizedMetric(
            tenantId: (string) $this->tenant->id,
            projectId: (string) $this->project->id,
            provider: 'snapchat',
            externalAccountId: (string) $this->adAccount->getKey(),
            externalCampaignId: (string) $this->external->getKey(),
            unifiedCampaignId: (string) $this->campaign->id,
            metricDate: Carbon::parse($date),
            metricKey: $key,
            value: $value,
            originalCurrency: 'SAR',
            projectCurrency: 'SAR',
            exchangeRate: 1.0,
            originalTimezone: 'UTC',
            projectTimezone: 'Asia/Riyadh',
            attributionWindow: '7d_click',
            sourceType: 'api',
            dataFreshnessAt: Carbon::parse($date)->endOfDay(),
        );
    }

    private function seedSuccessfulRun(string $provider, Carbon $finishedAt): void
    {
        MetricSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->adAccount->getKey(), 'provider' => $provider,
            'status' => 'success', 'window_start' => $finishedAt->toDateString(),
            'window_end' => $finishedAt->toDateString(), 'metrics_upserted' => 3, 'attempts' => 1,
            'started_at' => $finishedAt, 'finished_at' => $finishedAt,
        ]);
    }

    private function seedStoreWithOrder(float $total = 500, ?Carbon $lastSyncedAt = null): ExternalAccount
    {
        $this->holdingTenant((string) $this->tenant->id);

        $store = $this->account('salla', 'store');
        $store->forceFill(['last_synced_at' => $lastSyncedAt ?? Carbon::now()])->save();

        CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $store->getKey(), 'provider' => 'salla',
            'external_id' => 'o-'.uniqid(), 'status' => 'completed',
            'placed_at' => Carbon::now()->subHours(6), 'currency' => 'SAR', 'total' => $total,
            'attribution_method' => 'none', 'attributed_at' => Carbon::now(),
        ]);

        IntegrationSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'provider_connection_id' => $store->provider_connection_id,
            'type' => 'commerce', 'status' => 'success', 'records' => 1,
            'started_at' => $lastSyncedAt ?? Carbon::now(), 'finished_at' => $lastSyncedAt ?? Carbon::now(),
        ]);

        app(TenantContext::class)->forget();

        return $store;
    }

    private function account(string $provider, string $type): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider,
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => $type,
            'external_id' => "{$provider}-{$type}", 'name' => ucfirst($provider),
            'currency' => 'SAR', 'status' => 'active',
        ]);
    }
}
