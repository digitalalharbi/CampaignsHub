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
final class CreativeSpendFromAdGrainTest extends TestCase
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
        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'meta', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    public function test_a_creative_with_no_rows_of_its_own_reports_what_its_ads_reported(): void
    {
        $creative = $this->creative('cr-meta');
        $this->adWithMetrics($creative, spend: 120.5, impressions: 9_000, clicks: 300);

        $figures = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        );

        $this->assertArrayHasKey((string) $creative->getKey(), $figures, 'the creative reported nothing at all');

        $row = $figures[(string) $creative->getKey()];
        $this->assertSame(120.5, (float) $row['spend']);
        $this->assertSame(9_000.0, (float) $row['impressions']);
        $this->assertSame(300.0, (float) $row['clicks']);
    }

    /** Several ads on one creative is what that creative cost, not a race between them. */
    public function test_ads_sharing_a_creative_are_summed(): void
    {
        $creative = $this->creative('cr-shared');
        $this->adWithMetrics($creative, spend: 100.0, impressions: 1_000, clicks: 10);
        $this->adWithMetrics($creative, spend: 40.0, impressions: 400, clicks: 4);

        $row = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        )[(string) $creative->getKey()];

        $this->assertSame(140.0, (float) $row['spend']);
        $this->assertSame(1_400.0, (float) $row['impressions']);
    }

    /**
     * A provider that reports the creative grain is more precise than a sum over the ads that used
     * it, so its own rows win outright — never a blend of the two.
     */
    public function test_a_creative_with_its_own_rows_is_not_blended_with_the_ad_grain(): void
    {
        $creative = $this->creative('cr-snap');
        $this->adWithMetrics($creative, spend: 999.0, impressions: 1, clicks: 1);

        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'spend' => 7.0,
            'impressions' => 70,
            'clicks' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        )[(string) $creative->getKey()];

        $this->assertSame(7.0, (float) $row['spend'], 'the native creative grain lost to a sum over its ads');
    }

    /** An ad nobody attributed to a creative cannot be attributed to one here either. */
    public function test_an_ad_with_no_creative_contributes_nothing(): void
    {
        $creative = $this->creative('cr-lonely');

        $orphan = ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_campaign_id' => $this->campaign->getKey(),
            'provider' => 'meta',
            'external_id' => 'ad-orphan',
            'name' => 'Unattributed',
            'status' => 'active',
            'source_type' => 'api',
        ]);
        $this->metricsFor($orphan, spend: 500.0, impressions: 5_000, clicks: 50);

        $figures = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        );

        $this->assertArrayNotHasKey(
            (string) $creative->getKey(),
            $figures,
            'an unattributed ad was counted against a creative it was never linked to',
        );
    }

    /**
     * ANALYTICS-PROVENANCE-001 on the creative tables — seeded spend never joins a real total.
     *
     * `MetricsAggregator` has excluded demo rows from operational scopes since that defect was found
     * on `daily_metrics`: «a seeded row added to them is not a rounding error — it is invented money
     * inside a real total». Both creative tables carry the same column and nothing read it, so a
     * content card summed the customer's spend together with the demo world's.
     */
    public function test_a_real_creative_does_not_acquire_seeded_spend(): void
    {
        $creative = $this->creative('cr-mixed');
        $this->adWithMetrics($creative, spend: 100.0, impressions: 1_000, clicks: 10);

        /* A seeded row on the SAME creative, exactly as a demo seeder would leave it. */
        $seeded = ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_campaign_id' => $this->campaign->getKey(),
            'creative_id' => $creative->getKey(),
            'provider' => 'meta',
            'external_id' => 'ad-demo',
            'name' => 'Demo ad',
            'status' => 'active',
            'source_type' => 'api',
            'is_demo' => true,
        ]);
        DB::table('entity_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'meta',
            'entity_type' => 'ad',
            'entity_id' => $seeded->getKey(),
            'external_entity_id' => 'ad-demo',
            'external_campaign_id' => $this->campaign->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'attribution_window' => 'default',
            'spend' => 5_000.0,
            'impressions' => 900_000,
            'clicks' => 9_000,
            'is_demo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        )[(string) $creative->getKey()];

        $this->assertSame(100.0, (float) $row['spend'], 'seeded spend was added to a real creative’s total');
        $this->assertSame(1_000.0, (float) $row['impressions']);
    }

    /**
     * And the demo world keeps working: a creative with ONLY seeded rows reports them, because there
     * is no real figure for them to corrupt and an empty demo is not a demo.
     */
    public function test_a_demo_only_creative_still_reports_its_seeded_rows(): void
    {
        $creative = $this->creative('cr-demo-only');

        $ad = ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_campaign_id' => $this->campaign->getKey(),
            'creative_id' => $creative->getKey(),
            'provider' => 'meta',
            'external_id' => 'ad-demo-only',
            'name' => 'Demo only',
            'status' => 'active',
            'source_type' => 'api',
            'is_demo' => true,
        ]);
        DB::table('entity_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'meta',
            'entity_type' => 'ad',
            'entity_id' => $ad->getKey(),
            'external_entity_id' => 'ad-demo-only',
            'external_campaign_id' => $this->campaign->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'attribution_window' => 'default',
            'spend' => 42.0,
            'impressions' => 420,
            'clicks' => 4,
            'is_demo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        )[(string) $creative->getKey()];

        $this->assertSame(42.0, (float) $row['spend'], 'the demo world stopped showing its own figures');
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

    private function adWithMetrics(ExternalCreative $creative, float $spend, float $impressions, float $clicks): void
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

        $this->metricsFor($ad, $spend, $impressions, $clicks);
    }

    private function metricsFor(ExternalAd $ad, float $spend, float $impressions, float $clicks): void
    {
        DB::table('entity_daily_metrics')->insert([
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
        ]);
    }
}
