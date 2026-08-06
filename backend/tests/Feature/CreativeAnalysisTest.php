<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeFatigue;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
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
 * §15 — a creative read honestly.
 *
 * Three claims, each of which is a way the product could quietly lie about content:
 *
 *   1. **A metric the platform never sent is not zero.** Snapchat reports video quartiles; a Google
 *      Search ad has none. Storing the second as 0 puts «completion rate 0%» beside 40,000
 *      impressions, which reads as a catastrophic video rather than as a metric nobody sends for text
 *      ads. The column is nullable, the sum is not coalesced, and the response says which figures
 *      were actually reported.
 *   2. **A creative is judged by the job it was bought for.** An awareness video has no CPA. Printing
 *      one is not an extra column; it is a terrible number attached to content never asked to sell,
 *      and it is what makes somebody switch off the top of their funnel.
 *   3. **Fatigue is a pattern, and «we cannot tell» is a verdict.** A single threshold condemns quiet
 *      weeks and clears genuine decay, and `stable` where `insufficient_data` is meant reads as «we
 *      looked and it is fine» when nobody has looked at all.
 */
final class CreativeAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $awarenessCampaign;

    private UnifiedCampaign $salesCampaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Creative Co', 'slug' => 'creative-co', 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->awarenessCampaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Brand', 'objective' => 'awareness', 'status' => 'active',
        ]);
        $this->salesCampaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale', 'objective' => 'sales', 'status' => 'active',
        ]);
    }

    private function creative(string $name, UnifiedCampaign $campaign, string $format = 'video'): ExternalCreative
    {
        return ExternalCreative::create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'campaign_id' => $campaign->getKey(),
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.Str::slug($name),
            'name' => $name,
            'format' => $format,
            'status' => 'active',
        ]);
    }

    /** @param array<string, float|null> $values */
    private function day(ExternalCreative $creative, string $date, array $values): void
    {
        DB::table('creative_daily_metrics')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'campaign_id' => $creative->campaign_id,
            'metric_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ], $values));
    }

    /**
     * A platform that reports no video data leaves NULL, and the response says so.
     *
     * The `reported` map exists because the frontend cannot infer this from the value: a genuine zero
     * and a missing metric are both falsy in JavaScript.
     */
    public function test_a_metric_the_platform_never_reported_is_null_and_flagged_as_unreported(): void
    {
        $textAd = $this->creative('Search text ad', $this->salesCampaign, 'text');
        $this->day($textAd, '2026-07-01', ['spend' => 100, 'impressions' => 40000, 'clicks' => 800, 'conversions' => 10, 'revenue' => 900]);

        $figures = app(CreativeMetrics::class)->forCreatives(
            [(string) $textAd->getKey()],
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        )[(string) $textAd->getKey()];

        $this->assertNull($figures['video_views'], 'a text ad reported a video view count');
        $this->assertNull($figures['video_p100']);
        $this->assertNull($figures['completion_rate'], 'a completion rate was computed with no views to complete');
        $this->assertFalse($figures['reported']['video_views']);
        $this->assertTrue($figures['reported']['impressions']);

        // …and the figures the platform DID send are real.
        $this->assertEqualsWithDelta(100.0, $figures['spend'], 0.01);
        $this->assertEqualsWithDelta(0.02, $figures['ctr'], 0.0001);
    }

    /** A video that genuinely got zero completions reports 0 — the opposite case, and it must differ. */
    public function test_a_real_zero_is_kept_apart_from_a_missing_metric(): void
    {
        $video = $this->creative('Skipped video', $this->awarenessCampaign);
        $this->day($video, '2026-07-01', [
            'spend' => 50, 'impressions' => 10000, 'clicks' => 20,
            'video_views' => 4000, 'video_p100' => 0,
        ]);

        $figures = app(CreativeMetrics::class)->forCreatives(
            [(string) $video->getKey()],
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        )[(string) $video->getKey()];

        $this->assertSame(0.0, $figures['video_p100']);
        $this->assertTrue($figures['reported']['video_p100']);
        $this->assertSame(0.0, $figures['completion_rate'], 'nobody finished it — that is a real 0');
    }

    /** A ratio with nothing to divide by is null, never 0 — «free orders» is not a thing. */
    public function test_a_ratio_with_no_denominator_is_null_rather_than_zero(): void
    {
        $creative = $this->creative('No orders', $this->salesCampaign, 'image');
        // `revenue => 0` is now stated rather than inherited. The column used to be NOT NULL DEFAULT
        // 0, so omitting it meant «zero»; since it can express «not reported», omitting it means
        // that instead — and the whole point of this case is a MEASURED zero on both sides.
        $this->day($creative, '2026-07-01', [
            'spend' => 900, 'impressions' => 20000, 'clicks' => 300, 'conversions' => 0, 'revenue' => 0,
        ]);

        $figures = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        )[(string) $creative->getKey()];

        $this->assertNull($figures['cpa'], 'a CPA was computed with no orders in the denominator');

        /*
         * ROAS is 0 here, and that is the honest answer — the difference is which side is missing.
         *
         * CPA divides BY the orders: with none, there is nothing to divide by and «what an order
         * cost» has no answer. ROAS divides the revenue by the spend: the spend is 900 and the
         * revenue really was 0, so «we got nothing back for 900» is a true statement about a real
         * outcome, not an artefact of a missing denominator.
         */
        $this->assertSame(0.0, $figures['roas']);
        $this->assertEqualsWithDelta(900.0, $figures['spend'], 0.01, 'the spend is real and must still show');
    }

    /**
     * The other half of the same distinction: a creative that reported NO revenue at all.
     *
     * An awareness image is not «a sales creative that earned nothing» — no purchase figure was ever
     * reported for it. Stored as NULL rather than 0, its ROAS is null and the surfaces say «not
     * provided»; the case above, where the platform reported a real 0, still reads 0.00×. Both rows
     * look identical in the database until you check nullability, which is why both are asserted.
     */
    public function test_a_creative_the_platform_reported_no_revenue_for_has_no_roas(): void
    {
        $creative = $this->creative('Reach only', $this->awarenessCampaign, 'image');
        $this->day($creative, '2026-07-01', ['spend' => 900, 'impressions' => 20000, 'clicks' => 300]);

        $figures = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        )[(string) $creative->getKey()];

        $this->assertNull($figures['revenue'], 'an unreported revenue became a measured zero');
        $this->assertNull($figures['roas'], 'an awareness creative was told it returned nothing');
        $this->assertFalse($figures['reported']['revenue']);
        $this->assertFalse($figures['reported']['orders'], '«orders» must answer for conversions');
    }

    /** Awareness content is judged on reach and attention; sales content on orders. */
    public function test_the_headline_metrics_follow_the_objective(): void
    {
        $metrics = app(CreativeMetrics::class);

        $awareness = $metrics->headline('awareness');
        $sales = $metrics->headline('sales');

        $this->assertContains('cpm', $awareness);
        $this->assertContains('completion_rate', $awareness);
        $this->assertNotContains('cpa', $awareness, 'an awareness creative was given a cost per order to answer for');

        $this->assertContains('cpa', $sales);
        $this->assertContains('roas', $sales);
    }

    /**
     * Two creatives doing different jobs are not ranked on one axis — and the refusal says why.
     *
     * §15.7: «لا تعلن الفائز بصورة عامة إذا كان المحتويان يخدمان هدفين مختلفين».
     */
    public function test_creatives_bought_for_different_jobs_are_not_declared_a_winner(): void
    {
        $metrics = app(CreativeMetrics::class);

        $mixed = $metrics->comparable('awareness', 'sales');
        $this->assertFalse($mixed['comparable']);
        $this->assertNotNull($mixed['reason']);
        $this->assertNotNull($mixed['reason_ar']);

        $same = $metrics->comparable('sales', 'conversions');
        $this->assertTrue($same['comparable'], 'two conversion-path creatives are comparable');
    }

    /** Not enough days, or no previous period, is `insufficient_data` — never `stable`. */
    public function test_a_creative_with_too_little_delivery_is_not_called_stable(): void
    {
        $fatigue = app(CreativeFatigue::class);

        $tooNew = $fatigue->assess(['active_days' => 3, 'impressions' => 20000, 'ctr' => 0.02], ['ctr' => 0.02]);
        $this->assertSame(CreativeFatigue::INSUFFICIENT, $tooNew['status']);
        $this->assertContains('active_days', $tooNew['missing']);

        $noHistory = $fatigue->assess(['active_days' => 30, 'impressions' => 90000, 'ctr' => 0.02], null);
        $this->assertSame(CreativeFatigue::INSUFFICIENT, $noHistory['status']);
        $this->assertContains('previous_period', $noHistory['missing']);
    }

    /**
     * Fatigue needs several signals moving together, and it names every one.
     *
     * A verdict a reader cannot audit is a verdict they have to take on trust, and this is exactly
     * the figure worth distrusting: it argues for switching off something somebody paid to make.
     */
    public function test_fatigue_is_declared_from_several_signals_and_names_them(): void
    {
        $verdict = app(CreativeFatigue::class)->assess(
            ['active_days' => 21, 'impressions' => 200000, 'frequency' => 4.8, 'ctr' => 0.009, 'cpa' => 130.0, 'conversion_rate' => 0.008, 'spend' => 5000, 'conversions' => 38],
            ['frequency' => 2.1, 'ctr' => 0.019, 'cpa' => 74.0, 'conversion_rate' => 0.016, 'spend' => 3000, 'conversions' => 40],
        );

        $this->assertSame(CreativeFatigue::FATIGUED, $verdict['status']);

        $keys = array_column($verdict['signals'], 'key');
        $this->assertContains('frequency', $keys);
        $this->assertContains('ctr', $keys);
        $this->assertContains('cpa', $keys);
        // Spend up, orders flat — the relationship a per-metric threshold misses entirely.
        $this->assertContains('spend_without_results', $keys);
        $this->assertNotEmpty($verdict['note_ar']);
    }

    /** A quiet week is not fatigue: movement under the noise floor is ignored. */
    public function test_ordinary_variance_is_not_called_fatigue(): void
    {
        $verdict = app(CreativeFatigue::class)->assess(
            ['active_days' => 21, 'impressions' => 200000, 'frequency' => 2.15, 'ctr' => 0.0195, 'cpa' => 76.0, 'spend' => 3100, 'conversions' => 41],
            ['frequency' => 2.10, 'ctr' => 0.0200, 'cpa' => 74.0, 'spend' => 3000, 'conversions' => 40],
        );

        $this->assertSame(CreativeFatigue::STABLE, $verdict['status']);
        $this->assertSame([], $verdict['signals']);
    }

    /** A creative that got better says so, rather than being lumped in with «stable». */
    public function test_an_improving_creative_is_recognised(): void
    {
        $verdict = app(CreativeFatigue::class)->assess(
            ['active_days' => 21, 'impressions' => 200000, 'ctr' => 0.031, 'cpa' => 48.0, 'conversion_rate' => 0.026, 'spend' => 3000, 'conversions' => 62],
            ['ctr' => 0.019, 'cpa' => 74.0, 'conversion_rate' => 0.016, 'spend' => 3000, 'conversions' => 40],
        );

        $this->assertSame(CreativeFatigue::IMPROVING, $verdict['status']);
    }

    /** Frequency is averaged across the window, not summed — a sum grows with the window and means nothing. */
    public function test_frequency_is_averaged_across_the_window(): void
    {
        $creative = $this->creative('Frequent', $this->awarenessCampaign);
        $this->day($creative, '2026-07-01', ['spend' => 10, 'impressions' => 1000, 'frequency' => 2.0]);
        $this->day($creative, '2026-07-02', ['spend' => 10, 'impressions' => 1000, 'frequency' => 4.0]);

        $figures = app(CreativeMetrics::class)->forCreatives(
            [(string) $creative->getKey()],
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        )[(string) $creative->getKey()];

        $this->assertEqualsWithDelta(3.0, $figures['frequency'], 0.01);
        $this->assertSame(2, $figures['active_days']);
    }
}
