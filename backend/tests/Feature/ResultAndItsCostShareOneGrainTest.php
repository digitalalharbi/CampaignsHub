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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CONTENT-RESULT-COST-ONE-GRAIN-001 — «Spend 38.36 · Orders 0 · Cost per result 12.79».
 *
 * ## The owner's screen, and why those three numbers cannot all be true
 *
 * The contract says orders ARE conversions and cost-per-result is spend ÷ conversions. So a cost per
 * result of 12.79 against 38.36 of spend names a denominator of about three, and the card beside it
 * says the denominator is zero. One row cannot have both. Nothing here is a rounding artefact or a
 * formatting choice: the two figures were read from DIFFERENT GRAINS and printed side by side as
 * though they described the same measurement.
 *
 * ## The rung it breaks at
 *
 * `CreativeMetrics::fillFromAds()` merges a creative's own rows with the rows of the ads that ran it,
 * and it makes the two decisions independently:
 *
 *  - a RAW column is taken from the ads only where the creative grain reported NOTHING. A measured
 *    zero is something, so `conversions = 0` on the creative grain stays, and the card shows 0.
 *  - a RATIO is taken from the creative grain if present, else from the ad grain — without ever
 *    asking where the denominator it will be read against came from. The creative grain has no
 *    `cpa`, so the ad grain's 12.79 is adopted, and its denominator is the AD grain's three
 *    conversions.
 *
 * Two defensible rules, each correct alone, producing a contradiction when they meet.
 *
 * ## The invariant
 *
 * A result and its cost must come from the same grain. Not «should usually»: if the displayed
 * denominator is the creative grain's, the ratio must be the creative grain's or absent. Zero
 * results with a positive cost per result is the shape this forbids, in every objective family —
 * Orders↔CPA, Leads↔CPL, Installs↔CPI, Engagements↔CPE, Video views↔CPV, LPV↔Cost/LPV.
 *
 * Unavailable is not zero, and zero is a real zero. «—» is the honest cell for a cost whose
 * denominator the displayed grain measured as none.
 */
final class ResultAndItsCostShareOneGrainTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Grain', 'slug' => 'gr-'.uniqid(), 'status' => 'active']);
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
            'provider' => 'snapchat', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snapchat', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Snapchat',
            'status' => 'active',
        ]);

        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'snapchat', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    /**
     * **The owner's row, reproduced to the cent.**
     *
     * The creative's own rows measured the spend and measured zero conversions. Its ad measured
     * three. Whatever the card decides to show, it may not show one grain's result beside the other
     * grain's cost.
     */
    public function test_the_owners_contradiction_cannot_be_produced(): void
    {
        $creative = $this->creative('cr-owner');
        $this->creativeRow($creative, [
            'spend' => 38.36, 'impressions' => 12_000, 'clicks' => 140, 'conversions' => 0,
        ]);
        $this->adWithMetrics($creative, spend: 38.36, impressions: 12_000, clicks: 140, extra: ['conversions' => 3]);

        $row = $this->figures($creative);

        $orders = $row['conversions'] ?? null;
        $costPerResult = $row['cpa'] ?? null;

        $this->assertSame(38.36, round((float) $row['spend'], 2));

        /*
         * The whole defect in one assertion: a cost per result may not be positive while the result
         * printed beside it is zero.
         */
        if ((float) $orders === 0.0) {
            $this->assertNull(
                $costPerResult,
                'Orders read 0 and cost per result read '.var_export($costPerResult, true)
                .' — a positive cost with a zero denominator is the owner’s contradiction.',
            );
        }

        /*
         * And the other direction: where a cost IS stated, its denominator must be the number on the
         * card. 38.36 ÷ 12.79 is three, so a card stating that cost must be stating three results.
         */
        if ($costPerResult !== null) {
            $implied = (float) $row['spend'] / (float) $costPerResult;
            $this->assertEqualsWithDelta(
                (float) $orders,
                $implied,
                0.01,
                'the displayed cost per result implies a different number of results than the card shows',
            );
        }
    }

    /**
     * The same rule stated as provenance rather than as arithmetic.
     *
     * `ratio_inputs` carries the numerator and denominator a ratio was computed from, so an aggregate
     * over several creatives pools like with like. The denominator recorded there must be the figure
     * the card displays — if they can disagree, every total built on them inherits the contradiction.
     */
    public function test_the_ratios_denominator_is_the_figure_the_card_shows(): void
    {
        $creative = $this->creative('cr-inputs');
        $this->creativeRow($creative, [
            'spend' => 38.36, 'impressions' => 12_000, 'clicks' => 140, 'conversions' => 0,
        ]);
        $this->adWithMetrics($creative, spend: 38.36, impressions: 12_000, clicks: 140, extra: ['conversions' => 3]);

        $row = $this->figures($creative);
        [$numerator, $denominator] = $row['ratio_inputs']['cpa'] ?? [null, null];

        /*
         * Both nulls is the correct answer for this row — the displayed denominator is zero, so
         * there is no ratio and nothing for an aggregate to pool. Asserting it rather than skipping
         * is what stops a future change quietly recording the ad grain's three here while the card
         * still says none.
         */
        $this->assertNull($denominator, 'a denominator was recorded for a ratio the card cannot show');
        $this->assertNull($numerator);
        $this->assertNull($row['cpa'] ?? null);
    }

    /**
     * Every objective family the CREATIVE grain can measure, not just the one the owner opened.
     *
     * Each pair is a result and the cost of that result. A zero measured on the displayed grain must
     * take its cost with it, whatever the ad grain measured — otherwise the same defect simply moves
     * to whichever objective the next client runs.
     *
     * `leads` and `installs` are absent here on purpose and covered below instead: they are
     * AD-GRAIN-ONLY columns — `creative_daily_metrics` has no such field — so a creative-grain zero
     * for them is not a state this product can reach, and a test that wrote one would be asserting
     * against a fixture rather than against the system.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function creativeGrainPairs(): array
    {
        return [
            'orders and CPA' => ['conversions', 'cpa'],
            'engagements and CPE' => ['engagements', 'cpe'],
            'video views and CPV' => ['video_views', 'cost_per_view'],
            'landing-page views and cost per LPV' => ['landing_page_views', 'cost_per_lpv'],
        ];
    }

    #[DataProvider('creativeGrainPairs')]
    public function test_a_zero_result_never_carries_a_positive_cost(string $resultKey, string $costKey): void
    {
        $creative = $this->creative('cr-'.$costKey);
        $this->creativeRow($creative, [
            'spend' => 38.36, 'impressions' => 12_000, 'clicks' => 140, $resultKey => 0,
        ]);
        $this->adWithMetrics($creative, spend: 38.36, impressions: 12_000, clicks: 140, extra: [$resultKey => 3]);

        $row = $this->figures($creative);

        $this->assertSame(0.0, (float) ($row[$resultKey] ?? -1), 'the creative grain’s measured zero was overwritten');
        $this->assertNull(
            $row[$costKey] ?? null,
            "{$resultKey} read 0 while {$costKey} was positive — the denominator came from the other grain",
        );
    }

    /**
     * The ad-grain-only families, where the zero can only be the AD grain's.
     *
     * `leads` and `installs` have no creative-grain column, so result and cost are both the ad
     * grain's by construction. That makes the cross-grain mismatch impossible here and the OTHER
     * half of the invariant testable: a zero denominator must produce no cost, from whichever grain
     * measured it.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function adGrainPairs(): array
    {
        return [
            'leads and CPL' => ['leads', 'cpl'],
            'installs and CPI' => ['installs', 'cpi'],
        ];
    }

    #[DataProvider('adGrainPairs')]
    public function test_an_ad_grain_result_of_zero_carries_no_cost(string $resultKey, string $costKey): void
    {
        $creative = $this->creative('cr-'.$costKey);
        $this->adWithMetrics($creative, spend: 38.36, impressions: 12_000, clicks: 140, extra: [$resultKey => 0]);

        $row = $this->figures($creative);

        $this->assertSame(0.0, (float) ($row[$resultKey] ?? -1));
        $this->assertNull($row[$costKey] ?? null, "{$costKey} was positive against zero {$resultKey}");
    }

    /**
     * And the rule does not break the case it must not break.
     *
     * A creative whose own rows report NOTHING still reads its ads — result and cost together, from
     * the one grain that measured them. Narrowing the merge to «never fill from ads» would fix the
     * contradiction by removing figures customers are entitled to, which is not a fix.
     */
    public function test_a_creative_with_no_rows_of_its_own_still_reads_result_and_cost_from_its_ads(): void
    {
        $creative = $this->creative('cr-ads-only');
        $this->adWithMetrics($creative, spend: 60.0, impressions: 9_000, clicks: 120, extra: ['conversions' => 4]);

        $row = $this->figures($creative);

        $this->assertSame(4.0, (float) $row['conversions'], 'the ad grain’s result was dropped');
        $this->assertNotNull($row['cpa'], 'the ad grain’s cost was dropped with it');
        $this->assertEqualsWithDelta(15.0, (float) $row['cpa'], 0.01);
    }

    /** A real measurement on both grains still belongs to the creative, cost included. */
    public function test_a_creative_that_measured_its_own_result_keeps_its_own_cost(): void
    {
        $creative = $this->creative('cr-both');
        $this->creativeRow($creative, [
            'spend' => 50.0, 'impressions' => 10_000, 'clicks' => 200, 'conversions' => 5,
        ]);
        $this->adWithMetrics($creative, spend: 50.0, impressions: 10_000, clicks: 200, extra: ['conversions' => 25]);

        $row = $this->figures($creative);

        $this->assertSame(5.0, (float) $row['conversions']);
        $this->assertEqualsWithDelta(10.0, (float) $row['cpa'], 0.01, 'the cost was taken against the ad grain’s 25');
    }

    /**
     * The totals inherit the rule, rather than quietly restoring the contradiction.
     *
     * A KPI strip over a scope is where a per-row fix is most easily undone: pool one creative's
     * zero-denominator ratio as a zero and the group's cost per result drifts away from the group's
     * result, which is the owner's row again with more creatives behind it. The row that can state
     * no truthful ratio contributes NOTHING to the pair — not a zero — so the total's denominator is
     * the results the total actually counted.
     */
    public function test_a_group_total_never_pools_a_ratio_the_row_could_not_state(): void
    {
        // The owner's shape: creative-grain zero, ad-grain three. Contributes no ratio.
        $contradiction = $this->creative('cr-group-a');
        $this->creativeRow($contradiction, [
            'spend' => 38.36, 'impressions' => 12_000, 'clicks' => 140, 'conversions' => 0,
        ]);
        $this->adWithMetrics($contradiction, spend: 38.36, impressions: 12_000, clicks: 140, extra: ['conversions' => 3]);

        // A clean creative: its own rows measured both halves — 50 ÷ 5 = 10.
        $clean = $this->creative('cr-group-b');
        $this->creativeRow($clean, [
            'spend' => 50.0, 'impressions' => 10_000, 'clicks' => 200, 'conversions' => 5,
        ]);

        $metrics = app(CreativeMetrics::class);
        $rows = $metrics->forCreatives(
            [(string) $contradiction->getKey(), (string) $clean->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        );

        $total = $metrics->aggregate(array_values($rows));

        $this->assertNotNull($total);
        $this->assertSame(5.0, (float) $total['conversions'], 'the group counted a different number of results');

        /*
         * 88.36 of spend across the two, but only 50 of it bought results anything could measure, so
         * the pooled cost is 50 ÷ 5. Pooling 88.36 ÷ 5 would charge the clean creative for the other
         * one's unmeasurable spend; pooling 88.36 ÷ 8 would invent three results the card never
         * shows.
         */
        $this->assertEqualsWithDelta(10.0, (float) $total['cpa'], 0.01, 'the group pooled across grains');
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function figures(ExternalCreative $creative): array
    {
        return app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::today()->subDays(7),
            Carbon::today(),
        )[(string) $creative->getKey()];
    }

    /** @param array<string, float|int|null> $figures */
    private function creativeRow(ExternalCreative $creative, array $figures): void
    {
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'metric_date' => Carbon::today()->subDay()->toDateString(),
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
            'provider' => 'snapchat',
            'external_creative_id' => $externalId,
            'name' => $externalId,
            'format' => 'image',
            'status' => 'active',
            'source_type' => 'api',
        ]);
    }

    /** @param array<string, float|int> $extra */
    private function adWithMetrics(ExternalCreative $creative, float $spend, float $impressions, float $clicks, array $extra = []): void
    {
        $ad = ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'external_campaign_id' => $this->campaign->getKey(),
            'creative_id' => $creative->getKey(),
            'provider' => 'snapchat',
            'external_id' => 'ad-'.Str::random(6),
            'name' => 'Ad',
            'status' => 'active',
            'source_type' => 'api',
        ]);

        DB::table('entity_daily_metrics')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'snapchat',
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
