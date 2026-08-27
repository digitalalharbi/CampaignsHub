<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * METRICS-BACKBONE-001 — the wiring, which is the part that was missing.
 *
 * `entity_daily_metrics`, `UpsertEntityDailyMetrics`, `fetchEntityInsights()` and
 * `EntityMetricsAggregator` were all built, all tested, and **nothing called any of them**. The
 * table stayed empty in production, so the Ad Set and Ads tabs had nothing to render and the
 * drill-down stopped at the campaign.
 *
 * Every other test in this area proves a unit works in isolation. This one proves the SYNC writes
 * rows — which is the only thing that puts data in front of the owner.
 */
final class EntityMetricsAreActuallySyncedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_metrics_sync_writes_ad_squad_and_ad_rows(): void
    {
        $this->seed(MetricDefinitionSeeder::class);
        [$account] = $this->liveSnapchatAccount();

        $this->fakeSnapchatStats();

        app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'));

        $squads = DB::table('entity_daily_metrics')->where('entity_type', 'ad_set')->count();
        $ads = DB::table('entity_daily_metrics')->where('entity_type', 'ad')->count();

        $this->assertGreaterThan(0, $squads, 'The ad-squad grain is still empty — the sweep is not wired.');
        $this->assertGreaterThan(0, $ads, 'The ad grain is still empty — the sweep is not wired.');

        $row = DB::table('entity_daily_metrics')->where('entity_type', 'ad_set')->first();

        $this->assertEqualsWithDelta(90000, (float) $row->impressions, 0.01);
        $this->assertEqualsWithDelta(45000, (float) $row->reach, 0.01, 'Reach is reported, never approximated.');

        // Riyal account, a project reporting in the canonical USD, no rate on file: withheld rather
        // than mislabelled. The account is SAR because a USD one converts at par and would prove
        // nothing about withholding (MONEY-USD-001).
        $this->assertNull($row->spend);
        $this->assertEqualsWithDelta(412.5, (float) $row->spend_original, 0.01);
        $this->assertSame('SAR', $row->original_currency);
    }

    /**
     * A provider failure at these grains costs the new rows and nothing else.
     *
     * The campaign figures are ingested before this runs and must survive it — turning a healthy
     * metrics run red because an enrichment call was throttled trades a working pipeline for a
     * nicer screen.
     */
    public function test_a_failure_at_the_new_grains_does_not_break_the_run(): void
    {
        $this->seed(MetricDefinitionSeeder::class);
        [$account] = $this->liveSnapchatAccount();

        Http::fake([
            '*/campaigns/*/stats*' => Http::response(['error' => 'rate limited'], 429),
            '*/adsquads/*/stats*' => Http::response(['error' => 'rate limited'], 429),
            '*' => Http::response([], 200),
        ]);

        app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-02'));

        $this->assertSame(0, DB::table('entity_daily_metrics')->count());
        // The run itself completed; the enrichment failing is not the run failing.
        $this->assertGreaterThan(0, DB::table('metric_sync_runs')->count());
    }

    /** @return array{0: ExternalAccount, 1: Project} */
    private function liveSnapchatAccount(): array
    {
        // Without configured credentials the registry returns no connector and the run ends before
        // a single request — which is exactly how this test first failed.
        foreach (PlatformCredentials::for('snapchat')->requires() as $key) {
            config()->set("ad_platforms.platforms.snapchat.{$key}", "test-{$key}");
        }

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
            'default_currency' => 'SAR',
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Snap',
            'status' => 'active',
            'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'discovered_at' => Carbon::now(),
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => 'snapchat',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);

        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'snapchat',
            'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);

        $squad = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id,
            'external_campaign_id' => $campaign->id, 'provider' => 'snapchat',
            'external_id' => 'sq-1', 'name' => 'Riyadh 18-34', 'status' => 'active',
        ]);

        ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id,
            'external_ad_set_id' => $squad->id, 'external_campaign_id' => $campaign->id,
            'provider' => 'snapchat', 'external_id' => 'ad-1', 'name' => 'Swipe up', 'status' => 'active',
        ]);

        return [$account, $project];
    }

    private function fakeSnapchatStats(): void
    {
        $point = static fn (array $stats): array => [
            'start_time' => '2026-08-01T00:00:00.000-07:00', 'stats' => $stats,
        ];

        /*
         * Both grains come from the SAME campaign endpoint and differ only by `breakdown`, so the
         * fake has to read the query rather than the path — two URL patterns would both match and
         * the first would answer for both.
         */
        Http::fake(function ($request) use ($point) {
            $url = $request->url();

            if (! str_contains($url, '/stats')) {
                return Http::response([], 200);
            }

            if (str_contains($url, 'breakdown=adsquad')) {
                return Http::response(['timeseries_stats' => [
                    ['timeseries_stat' => ['breakdown_stats' => ['adsquad' => [
                        ['id' => 'sq-1', 'timeseries' => [$point([
                            'spend' => 412_500_000, 'impressions' => 90000, 'swipes' => 300, 'uniques' => 45000,
                        ])]],
                    ]]]],
                ]], 200);
            }

            if (str_contains($url, 'breakdown=ad')) {
                return Http::response(['timeseries_stats' => [
                    ['timeseries_stat' => ['breakdown_stats' => ['ad' => [
                        ['id' => 'ad-1', 'timeseries' => [$point([
                            'spend' => 100_000_000, 'impressions' => 20000, 'swipes' => 80,
                        ])]],
                    ]]]],
                ]], 200);
            }

            return Http::response([], 200);
        });
    }
}
