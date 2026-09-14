<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Metrics\Services\EntityMetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\DemoAdCreativeLinkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADSET-METRICS-TRUTH-001 — the ad-set rung has to carry figures, and they have to reconcile.
 *
 * ## What was actually wrong
 *
 * `entity_daily_metrics` is written at two grains, `ad` and `ad_set`. Against a real account
 * `AccountMetricsSyncer` writes both. Against the demo world it wrote only `ad`:
 *
 * ```
 * select entity_type, count(*) from entity_daily_metrics group by 1;
 *  ad | 168
 * ```
 *
 * Forty ad sets existed and not one of them had a metric row. `MetricsController::drivers()` answers
 * `by=ad_set` out of `EntityMetricsAggregator::byEntity(..., AD_SET, ...)`, so the ad-set drill in
 * Analytics could only ever come back empty — which in a browser is indistinguishable from a broken
 * dimension. The grain was never broken; it had nothing to read.
 *
 * ## Why the rows are a sum and not their own invention
 *
 * A provider's ad-set total and the ads underneath it reconcile. A fixture that gave the ad set an
 * independently-invented figure would render a product where drilling from an ad set into its ads
 * loses money, and would make the reconciliation assertion below unwritable — so the seeder sums the
 * ad rows it already wrote, and this test holds it to exactly that.
 *
 * ## Why the fixture is built here rather than seeded
 *
 * `DemoAdCreativeLinkSeeder` is reachable from `DatabaseSeeder` only under `local` and `demo`, so a
 * test cannot get at it through a full seed. It is called directly against the two facts its ad-set
 * step actually depends on — ad-grain rows, and ad sets behind the ids those rows carry.
 */
final class DemoAdSetGrainTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private ExternalAdSet $squad;

    /** A second ad set, so «summed the right ads» is distinguishable from «summed every ad». */
    private ExternalAdSet $otherSquad;

    private const DAYS = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Grain', 'slug' => 'grain-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $tenant->getKey());

        $client = ClientWorkspace::create([
            'tenant_id' => $tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: (string) $tenant->getKey(),
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat', 'account_type' => 'ad_account',
            'external_id' => 'act-1', 'name' => 'Snap', 'status' => 'active', 'discovered_at' => now(),
        ]);

        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'snapchat', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);

        $squad = fn (string $externalId, string $name): ExternalAdSet => ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_campaign_id' => $campaign->getKey(), 'provider' => 'snapchat',
            'external_id' => $externalId, 'name' => $name, 'status' => 'active', 'is_demo' => true,
        ]);

        $this->squad = $squad('sq-1', 'Riyadh · 18-34');
        $this->otherSquad = $squad('sq-2', 'Jeddah · 25-44');

        // Two ads under the first squad and one under the second, so the sums differ by construction.
        $this->adRows($tenant, $campaign, $this->squad, spend: 10.0, impressions: 1000.0);
        $this->adRows($tenant, $campaign, $this->squad, spend: 4.0, impressions: 250.0);
        $this->adRows($tenant, $campaign, $this->otherSquad, spend: 7.0, impressions: 700.0);
    }

    public function test_the_seeder_gives_every_ad_set_with_ads_the_grain_above_them(): void
    {
        $this->seed(DemoAdCreativeLinkSeeder::class);

        $carried = EntityDailyMetric::withoutGlobalScopes()
            ->where('entity_type', EntityDailyMetric::AD_SET)
            ->distinct()
            ->pluck('entity_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $this->assertContains((string) $this->squad->getKey(), $carried);
        $this->assertContains((string) $this->otherSquad->getKey(), $carried);
    }

    public function test_each_ad_set_day_is_the_sum_of_its_own_ads_that_day(): void
    {
        $this->seed(DemoAdCreativeLinkSeeder::class);

        $rows = DB::table('entity_daily_metrics')
            ->where('entity_type', EntityDailyMetric::AD_SET)
            ->where('entity_id', $this->squad->getKey())
            ->get();

        $this->assertCount(self::DAYS, $rows, 'The ad set did not get one row per day its ads have.');

        foreach ($rows as $row) {
            // 10 + 4, and NOT 21 — the other squad's ad must not land in this squad's total.
            $this->assertSame(14.0, (float) $row->spend);
            $this->assertSame(1250.0, (float) $row->impressions);
        }

        $other = DB::table('entity_daily_metrics')
            ->where('entity_type', EntityDailyMetric::AD_SET)
            ->where('entity_id', $this->otherSquad->getKey())
            ->first();

        $this->assertSame(7.0, (float) $other->spend);
    }

    public function test_the_ad_set_row_names_the_provider_entity_it_stands_for(): void
    {
        $this->seed(DemoAdCreativeLinkSeeder::class);

        $row = DB::table('entity_daily_metrics')
            ->where('entity_type', EntityDailyMetric::AD_SET)
            ->where('entity_id', $this->squad->getKey())
            ->first();

        // `external_entity_id` is the provider's own key. A row carrying the wrong one reconciles
        // against nothing at the provider, which is the whole point of holding it.
        $this->assertSame('sq-1', $row->external_entity_id);
        $this->assertTrue((bool) $row->is_demo, 'A seeded row outside `is_demo` would enter Production totals.');
    }

    public function test_the_aggregator_behind_the_ad_set_drill_answers(): void
    {
        $this->seed(DemoAdCreativeLinkSeeder::class);

        $rows = app(EntityMetricsAggregator::class)->byEntity(
            (string) $this->project->getKey(),
            EntityDailyMetric::AD_SET,
            Carbon::today()->subDays(30)->startOfDay(),
            Carbon::today()->endOfDay(),
        );

        $this->assertNotEmpty($rows, 'The aggregator behind by=ad_set returns nothing for a project that has ad-set rows.');

        $spend = array_sum(array_map(static fn (array $r): float => (float) ($r['spend'] ?? 0), $rows));
        $this->assertSame(63.0, $spend, 'The drill totals (10+4+7) × 3 days across both ad sets.');
    }

    public function test_a_second_run_adds_no_ad_set_rows(): void
    {
        $this->seed(DemoAdCreativeLinkSeeder::class);
        $before = DB::table('entity_daily_metrics')->where('entity_type', EntityDailyMetric::AD_SET)->count();

        $this->seed(DemoAdCreativeLinkSeeder::class);

        $this->assertSame(
            $before,
            DB::table('entity_daily_metrics')->where('entity_type', EntityDailyMetric::AD_SET)->count(),
            'A re-run duplicated the ad-set grain.',
        );
    }

    /**
     * One ad's own daily rows, written straight to the table.
     *
     * `entity_daily_metrics` holds no foreign keys, so the ad itself is not needed to state the two
     * facts the seeder reads: the grain, and the ad set the row belongs to.
     */
    private function adRows(Tenant $tenant, ExternalCampaign $campaign, ExternalAdSet $squad, float $spend, float $impressions): void
    {
        $adId = (string) Str::uuid();
        $rows = [];

        for ($day = 0; $day < self::DAYS; $day++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'provider' => 'snapchat',
                'entity_type' => EntityDailyMetric::AD,
                'entity_id' => $adId,
                'external_entity_id' => 'ad-'.$adId,
                'external_campaign_id' => $campaign->getKey(),
                'external_ad_set_id' => $squad->getKey(),
                'metric_date' => Carbon::today()->subDays($day)->toDateString(),
                'attribution_window' => 'default',
                'impressions' => $impressions,
                'clicks' => 20,
                'spend' => $spend,
                'conversions' => 2,
                'is_demo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('entity_daily_metrics')->insert($rows);
    }
}
