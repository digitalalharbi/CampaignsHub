<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONTENT-SPEND-ALWAYS-001 — the owner's «Spend missing on promoted creatives», traced to its rung.
 *
 * ## Not the React cell
 *
 * `AccountMetricsSyncer` fetches creative-level insights behind `instanceof ReportsCreativeInsights`
 * and Snapchat is the only implementor, so `creative_daily_metrics` — the one table the content
 * library read — is never written for Meta, Google, TikTok, LinkedIn or X. The number was not lost in
 * rendering or in aggregation. It was never ingested at that grain.
 *
 * Meanwhile Meta DOES implement `ReportsEntityGrains`, so the same account's ad rows carry spend,
 * impressions and clicks in `entity_daily_metrics` right now, keyed by the ad that ran the creative,
 * underneath a card that says «—».
 *
 * ## What is pinned here
 *
 * That a creative with no rows of its own reports what its ads reported; that a creative WITH its own
 * rows keeps them rather than being blended with a second opinion; that several ads sharing one
 * creative sum rather than one of them winning; and that a metric the ad grain does not carry stays
 * ABSENT instead of becoming a fabricated zero.
 */
/**
 * Content Production Recovery — a creative with BOTH grains (C=34 and D=79 on the live account).
 *
 * `content:census` run 35117219746 found 90 Snapchat creatives whose own rows and whose ads both carry
 * figures. The card read only the creative's own rows, so where those rows lacked Spend, revenue or
 * landing-page views the card lost them while the ads held them. These cases hold the three rules the
 * fix stands on — never sum the grains, a ratio from one grain, and only from a grain covering the same
 * days — each with figures chosen so the wrong rule produces a DIFFERENT number, not the same one.
 */
final class CreativeDualGrainTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Spend', 'slug' => 'sp-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Meta',
            'status' => 'active',
        ]);
        /*
         * The active project, as `ResolveProject` sets it on every real request. The demo policy asks
         * the project whether it holds live rows, so a test without a context would silently skip it.
         */
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'meta', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    /**
     * C — the creative's own rows report delivery and no Spend; its ads report Spend. The card had
     * indicators and a Spend cell reading «—».
     */
    public function test_spend_the_creative_rows_do_not_report_is_taken_from_its_ads(): void
    {
        $creative = $this->creative('cr-dual-spend');
        $this->creativeRow($creative, ['spend' => null, 'impressions' => 8_000, 'clicks' => 200, 'conversions' => 10]);
        $this->adWithMetrics($creative, spend: 100.0, impressions: 7_500, clicks: 180, extra: ['conversions' => 20]);

        $row = $this->figures($creative);

        $this->assertSame(100.0, (float) $row['spend'], 'the ads\' spend was not used where the creative reported none');
        $this->assertTrue($row['reported']['spend']);
        $this->assertContains('spend', $row['from_ads']);
    }

    /**
     * Never summed: every metric the creative's own rows DO report stays theirs. Adding the grains
     * would state 15,500 impressions for delivery that happened once.
     */
    public function test_a_metric_both_grains_report_is_never_summed_and_stays_the_creatives(): void
    {
        $creative = $this->creative('cr-dual-sum');
        $this->creativeRow($creative, ['spend' => null, 'impressions' => 8_000, 'clicks' => 200, 'conversions' => 10]);
        $this->adWithMetrics($creative, spend: 100.0, impressions: 7_500, clicks: 180, extra: ['conversions' => 20]);

        $row = $this->figures($creative);

        $this->assertSame(8_000.0, (float) $row['impressions']);
        $this->assertSame(200.0, (float) $row['clicks']);
        $this->assertSame(10.0, (float) $row['conversions']);
        $this->assertNotContains('impressions', $row['from_ads']);
    }

    /**
     * A ratio comes wholly from ONE grain. The ads' own CPA is 100 / 20 = 5. Dividing the ads' spend by
     * the creative's conversions would state 10 — a ratio of two different measurements.
     */
    public function test_a_ratio_is_never_recomputed_across_the_two_grains(): void
    {
        $creative = $this->creative('cr-dual-ratio');
        $this->creativeRow($creative, ['spend' => null, 'impressions' => 8_000, 'clicks' => 200, 'conversions' => 10]);
        $this->adWithMetrics($creative, spend: 100.0, impressions: 7_500, clicks: 180, extra: ['conversions' => 20, 'revenue' => 400.0]);

        $row = $this->figures($creative);

        $this->assertSame(5.0, (float) $row['cpa'], 'CPA was divided across grains');
        $this->assertSame(4.0, (float) $row['roas'], 'ROAS did not come from the ads\' own spend and revenue');
        $this->assertSame(20.0, (float) $row['aov'], 'AOV was divided across grains');
        // The creative grain's own ratio, computed from its own inputs, is kept.
        $this->assertSame(round(200 / 8_000, 4), (float) $row['ctr']);
    }

    /** D (sales) — revenue the creative's rows do not report, reported by its ads. */
    public function test_revenue_the_creative_rows_do_not_report_is_taken_from_its_ads(): void
    {
        $creative = $this->creative('cr-dual-revenue');
        $this->creativeRow($creative, ['spend' => 60.0, 'impressions' => 5_000, 'clicks' => 90, 'conversions' => 6]);
        $this->adWithMetrics($creative, spend: 60.0, impressions: 5_000, clicks: 90, extra: ['conversions' => 6, 'revenue' => 300.0]);

        $row = $this->figures($creative);

        $this->assertSame(60.0, (float) $row['spend']);
        $this->assertSame(300.0, (float) $row['revenue']);
        $this->assertContains('revenue', $row['from_ads']);
        $this->assertNotContains('spend', $row['from_ads']);
    }

    /** D (traffic) — landing-page views and their cost, from the ads, where the creative's rows are silent. */
    public function test_landing_page_views_the_creative_rows_do_not_report_are_taken_from_its_ads(): void
    {
        $creative = $this->creative('cr-dual-lpv');
        $this->creativeRow($creative, ['spend' => 50.0, 'impressions' => 4_000, 'clicks' => 120]);
        $this->adWithMetrics($creative, spend: 40.0, impressions: 3_900, clicks: 110, extra: ['landing_page_views' => 80]);

        $row = $this->figures($creative);

        $this->assertSame(80.0, (float) $row['landing_page_views']);
        $this->assertSame(0.5, (float) $row['cost_per_lpv'], 'cost per LPV must be the ads\' 40 / 80, not the creative\'s 50 / 80');
        $this->assertSame(50.0, (float) $row['spend'], 'the creative\'s own spend was replaced');
    }

    /**
     * Only from a grain covering the same days. Ads active on one day beside a creative grain active on
     * two would state one day's spend as the creative's whole — so nothing is taken.
     */
    public function test_an_ad_grain_covering_fewer_days_fills_nothing(): void
    {
        $creative = $this->creative('cr-dual-partial');
        $this->creativeRow($creative, ['spend' => null, 'impressions' => 8_000, 'clicks' => 200], Carbon::today()->subDay());
        $this->creativeRow($creative, ['spend' => null, 'impressions' => 6_000, 'clicks' => 150], Carbon::today()->subDays(2));
        $this->adWithMetrics($creative, spend: 100.0, impressions: 7_500, clicks: 180, extra: ['revenue' => 90.0]);

        $row = $this->figures($creative);

        $this->assertNull($row['spend']);
        $this->assertNull($row['revenue']);
        $this->assertSame([], $row['from_ads']);
    }

    /**
     * Money arrives with its whole provenance. The ads' spend is withheld in USD; the creative grain
     * states none. The card must be able to say «79.61 USD», which needs the rows, the original and
     * the currency together.
     */
    public function test_withheld_ad_spend_is_taken_with_its_original_and_currency(): void
    {
        $creative = $this->creative('cr-dual-withheld');
        $this->creativeRow($creative, ['spend' => null, 'impressions' => 8_000, 'clicks' => 200]);
        $this->adWithMetrics($creative, spend: 0.0, impressions: 7_500, clicks: 180, extra: [
            'spend' => null, 'spend_original' => 79.61, 'original_currency' => 'USD',
        ]);

        $row = $this->figures($creative);

        $this->assertNull($row['spend']);
        $this->assertSame(1, $row['spend_withheld_rows']);
        $this->assertSame(79.61, (float) $row['spend_original']);
        $this->assertSame('USD', $row['money_original_currency']);
        $this->assertSame(1, $row['money_original_currencies']);
        $this->assertTrue(app(CreativeMetrics::class)->statable($row, 'spend'));
    }

    /** One reader: the headline strip over the same creative states what the card now states. */
    public function test_the_headline_strip_reads_the_same_filled_figures(): void
    {
        $creative = $this->creative('cr-dual-strip');
        $this->creativeRow($creative, ['spend' => null, 'impressions' => 8_000, 'clicks' => 200]);
        $this->adWithMetrics($creative, spend: 100.0, impressions: 7_500, clicks: 180);

        $totals = app(CreativeMetrics::class)->totalsFor(
            [(string) $creative->getKey()], Carbon::today()->subDays(7), Carbon::today(),
        );

        $this->assertSame(100.0, (float) $totals['spend']);
        $this->assertSame(8_000.0, (float) $totals['impressions']);
    }

    /** @return array<string, mixed> */
    private function figures(ExternalCreative $creative): array
    {
        return app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        )[(string) $creative->getKey()];
    }

    /** @param array<string, float|int|null> $figures */
    private function creativeRow(ExternalCreative $creative, array $figures, ?Carbon $day = null): void
    {
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'metric_date' => ($day ?? Carbon::today()->subDay())->toDateString(),
            ...$figures,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function creative(string $externalId): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'meta',
            'external_creative_id' => $externalId,
            'name' => $externalId,
            'format' => 'image',
            'status' => 'active',
            'source_type' => 'api',
        ]);
    }

    private function adWithMetrics(ExternalCreative $creative, float $spend, float $impressions, float $clicks, array $extra = []): void
    {
        $ad = ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_campaign_id' => $this->campaign->getKey(),
            'creative_id' => $creative->getKey(),
            'provider' => 'meta',
            'external_id' => 'ad-'.Str::random(6),
            'name' => 'Ad',
            'status' => 'active',
            'source_type' => 'api',
        ]);

        $this->metricsFor($ad, $spend, $impressions, $clicks, $extra);
    }

    private function metricsFor(ExternalAd $ad, float $spend, float $impressions, float $clicks, array $extra = []): void
    {
        DB::table('entity_daily_metrics')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'meta',
            'entity_type' => 'ad',
            'entity_id' => $ad->getKey(),
            'external_entity_id' => (string) $ad->external_id,
            'external_campaign_id' => $this->campaign->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'attribution_window' => 'default',
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }
}
