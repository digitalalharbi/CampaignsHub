<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * OBJECTIVE-RESULT-001 — every campaign row carries the result its OBJECTIVE is judged on.
 *
 * Built from the Production diagnosis: a Snapchat TRAFFIC campaign with 2,881 landing page views,
 * 18,464 clicks and no conversions. Before this, the row carried `conversions = 0` and nothing else
 * a surface could call its result — so the campaign page said «لا نتائج في الفترة».
 */
final class MetricsAggregatorResultTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'res-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);

        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'snapchat',
        );
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat', 'account_type' => 'ad_account',
            'external_id' => 'snap-ad', 'name' => 'Snap', 'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    public function test_a_traffic_row_with_no_conversions_and_2881_visits_has_a_result_of_2881(): void
    {
        $coffee = $this->campaign('1KCOFFEE', 'traffic');
        $this->sync($coffee, ['spend' => 1440.5, 'clicks' => 18464, 'landing_page_views' => 2881, 'conversions' => 0, 'impressions' => 900000]);

        $row = $this->byCampaign()[(string) $coffee->id];

        $this->assertSame(0.0, (float) $row['conversions']);
        $this->assertSame('landing_page_views', $row['result_key']);
        $this->assertSame(2881.0, $row['result']);
        $this->assertSame('زيارات الصفحة', $row['result_label']['ar']);
        $this->assertSame(round(1440.5 / 2881, 2), $row['cost_per_result']);
        $this->assertNull($row['result_missing_reason']);

        // The alert sweep's batched read carries the same result.
        $figures = $this->figures([(string) $coffee->id])[(string) $coffee->id];
        $this->assertSame('landing_page_views', $figures['result_key']);
        $this->assertSame(2881.0, $figures['result']);
    }

    public function test_a_traffic_row_without_landing_page_views_reads_clicks_named_clicks(): void
    {
        $campaign = $this->campaign('Clicks only', 'traffic');
        $this->sync($campaign, ['spend' => 100, 'clicks' => 400, 'impressions' => 9000]);

        $row = $this->byCampaign()[(string) $campaign->id];

        $this->assertSame('clicks', $row['result_key']);
        $this->assertSame(400.0, $row['result']);
        $this->assertSame('النقرات', $row['result_label']['ar']);
    }

    public function test_a_sales_row_keeps_orders_as_its_result(): void
    {
        $campaign = $this->campaign('Sales', 'sales');
        $this->sync($campaign, ['spend' => 300, 'clicks' => 900, 'landing_page_views' => 600, 'conversions' => 12, 'revenue' => 2400]);

        $row = $this->byCampaign()[(string) $campaign->id];

        $this->assertSame('conversions', $row['result_key']);
        $this->assertSame(12.0, $row['result']);
        $this->assertSame((float) $row['cpa'], (float) $row['cost_per_result'], 'the sales cost per result IS the product CPA');
    }

    public function test_an_unclassified_campaign_has_no_result_and_says_the_objective_is_not_set(): void
    {
        $campaign = $this->campaign('Search', 'other');
        $this->sync($campaign, ['spend' => 80, 'clicks' => 50, 'conversions' => 3]);

        $row = $this->byCampaign()[(string) $campaign->id];

        $this->assertNull($row['result']);
        $this->assertSame('objective_unknown', $row['result_missing_reason']);
        $this->assertSame('الهدف غير محدد', $row['result_missing_label']['ar']);

        $figures = $this->figures([(string) $campaign->id])[(string) $campaign->id];
        $this->assertSame('objective_unknown', $figures['result_missing_reason']);
    }

    /** A metric the platform never sent is «no data», not a zero result. */
    public function test_an_app_campaign_whose_platform_never_sent_installs_is_no_data(): void
    {
        $campaign = $this->campaign('App', 'app_installs');
        $this->sync($campaign, ['spend' => 70, 'clicks' => 20]);

        $row = $this->byCampaign()[(string) $campaign->id];

        $this->assertNull($row['result']);
        $this->assertSame('not_reported', $row['result_missing_reason']);
    }

    /**
     * Owner decision: a platform that REPORTS landing page views is believed at 0 — the honest signal
     * of a page or pixel measuring nothing. Meta leaves the key out when nothing measured it, so a
     * Meta 0 is a real 0.
     */
    public function test_a_reported_zero_of_landing_page_views_shows_as_zero_visits(): void
    {
        $campaign = $this->campaign('Broken pixel', 'traffic');
        $this->sync($campaign, ['spend' => 200, 'clicks' => 900, 'landing_page_views' => 0], 'meta');

        $row = $this->byCampaign()[(string) $campaign->id];

        $this->assertSame('landing_page_views', $row['result_key']);
        $this->assertSame(0.0, $row['result']);
    }

    /** Snapchat answers every field it is asked for, so its 0 means «not tracked»: the result is clicks. */
    public function test_a_snapchat_zero_of_landing_page_views_is_not_a_measurement(): void
    {
        $campaign = $this->campaign('Snap no pixel', 'traffic');
        $this->sync($campaign, ['spend' => 200, 'clicks' => 900, 'landing_page_views' => 0], 'snapchat');

        $row = $this->byCampaign()[(string) $campaign->id];

        $this->assertSame('clicks', $row['result_key']);
        $this->assertSame(900.0, $row['result']);
    }

    /** Measured on one platform and not the other: one unit, clicks, and the reason said. */
    public function test_partly_measured_landing_page_views_read_as_clicks_with_the_reason(): void
    {
        $campaign = $this->campaign('Two platforms', 'traffic');
        $this->sync($campaign, ['spend' => 100, 'clicks' => 400, 'landing_page_views' => 250], 'snapchat');
        $this->sync($campaign, ['spend' => 100, 'clicks' => 300], 'meta');

        $row = $this->byCampaign()[(string) $campaign->id];

        $this->assertSame('clicks', $row['result_key']);
        $this->assertSame(700.0, $row['result']);
        $this->assertSame('النقرات: زيارات الصفحة غير مقاسة على كل المنصات', $row['result_note']['ar']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * OBJECTIVE-GAPS-001 (ROAS leak) — a return on spend belongs to the sales family only.
     *
     * Production: four Snapchat traffic campaigns carried revenue in the last seven days, and the
     * campaign table printed a ROAS for each — a figure a traffic buy was never asked to produce.
     * Null, not 0: «not applicable» is not «returned nothing».
     */
    public function test_a_traffic_campaign_with_revenue_has_no_roas_and_a_sales_one_keeps_it(): void
    {
        $traffic = $this->campaign('Traffic with revenue', 'traffic');
        $this->sync($traffic, ['spend' => 500, 'clicks' => 900, 'landing_page_views' => 300, 'conversions' => 4, 'revenue' => 1200]);
        $unclassified = $this->campaign('Unclassified with revenue', 'other');
        $this->sync($unclassified, ['spend' => 500, 'clicks' => 900, 'conversions' => 4, 'revenue' => 1200]);
        $sales = $this->campaign('Sales', 'conversions');
        $this->sync($sales, ['spend' => 500, 'clicks' => 900, 'conversions' => 4, 'revenue' => 1200]);

        $rows = $this->byCampaign();

        $this->assertNull($rows[(string) $traffic->id]['roas'], 'a traffic campaign carried a ROAS');
        $this->assertNull($rows[(string) $unclassified->id]['roas'], 'an unclassified campaign carried a ROAS');
        $this->assertSame(2.4, (float) $rows[(string) $sales->id]['roas']);
        // Revenue itself is still the platform's figure; only the ratio is withheld.
        $this->assertSame(1200.0, (float) $rows[(string) $traffic->id]['revenue']);

        $figures = $this->figures([(string) $traffic->id, (string) $sales->id]);
        $this->assertNull($figures[(string) $traffic->id]['roas'], 'the alert read carried a traffic ROAS');
        $this->assertSame(2.4, (float) $figures[(string) $sales->id]['roas']);
    }

    /** @return array<string, array<string,mixed>> */
    private function byCampaign(): array
    {
        $this->holdingTenant((string) $this->tenant->id);

        return collect(app(MetricsAggregator::class)
            ->forProjects([(string) $this->project->id])
            ->byCampaign(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31')))
            ->keyBy(fn (array $r): string => (string) $r['campaign_id'])
            ->all();
    }

    /** @param list<string> $ids @return array<string, array<string,mixed>> */
    private function figures(array $ids): array
    {
        $this->holdingTenant((string) $this->tenant->id);

        return app(MetricsAggregator::class)
            ->forProjects([(string) $this->project->id])
            ->forCampaigns($ids)
            ->figuresByCampaign(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));
    }

    private function campaign(string $name, string $objective): UnifiedCampaign
    {
        $this->holdingTenant((string) $this->tenant->id);

        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => $objective,
            'total_budget' => 1000, 'budget_currency' => 'SAR',
        ]);
    }

    /** @param array<string, float|int> $figures */
    private function sync(UnifiedCampaign $campaign, array $figures, string $provider = 'snapchat'): void
    {
        $this->holdingTenant((string) $this->tenant->id);

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(),
            'unified_campaign_id' => $campaign->id,
            'provider' => $provider, 'external_id' => 'ext-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);

        $rows = [];
        foreach ($figures as $key => $value) {
            $rows[] = new NormalizedMetric(
                tenantId: (string) $this->tenant->id,
                projectId: (string) $this->project->id,
                provider: $provider,
                externalAccountId: (string) $this->account->getKey(),
                externalCampaignId: (string) $external->id,
                unifiedCampaignId: (string) $campaign->id,
                metricDate: Carbon::parse('2026-07-10'),
                metricKey: $key,
                value: (float) $value,
                projectCurrency: 'SAR',
            );
        }

        app(UpsertDailyMetrics::class)->handle($rows);
    }
}
