<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Services\PlatformObjectiveMap;
use App\Domains\Reports\Services\ClientEntityBoundary;
use App\Domains\Reports\Services\ReportObjectiveLens;
use PHPUnit\Framework\TestCase;

/**
 * OBJECTIVE-GAPS-001 — the objective→result gaps the Production facts run found (2026-11-13).
 *
 * Each case is one row of that run: a platform word nothing translated, a «spend without results»
 * finding that counted orders on campaigns that never bought orders, and a report lens that ignored
 * campaigns whose objective was real but whose source column still read `unset`.
 */
final class ObjectiveGapsTest extends TestCase
{
    /**
     * Snap Marketing API, Campaigns → Campaign Objective V2: `objective_v2_type` is one of
     * AWARENESS_AND_ENGAGEMENT, SALES, TRAFFIC, APP_PROMOTION, LEADS, and its business-logic table
     * files the legacy BRAND_AWARENESS, VIDEO_VIEW and ENGAGEMENT objectives under the first.
     * Production: 32 Snapchat campaigns carried it and sat in the Unknown family.
     */
    public function test_snapchat_awareness_and_engagement_is_the_awareness_family(): void
    {
        $objective = (new PlatformObjectiveMap)->resolve('snapchat', 'AWARENESS_AND_ENGAGEMENT');

        $this->assertSame(CampaignObjective::Awareness, $objective);
        $this->assertSame(ObjectiveFamily::Awareness, $objective->family());
    }

    /**
     * OpenAI Ads developer docs, Bidding & Budgets → «Campaign Objectives & Billing»: the campaign
     * objective (`bidding_type`) is `impressions`, `clicks` or `conversions`, and a `clicks` campaign's
     * optimisation outcome is Clicks. Production: five ChatGPT Ads `clicks` campaigns, unclassified.
     */
    public function test_chatgpt_ads_clicks_is_a_traffic_objective(): void
    {
        $map = new PlatformObjectiveMap;

        $this->assertSame(CampaignObjective::Traffic, $map->resolve('openai_ads', 'clicks'));
        $this->assertSame(CampaignObjective::Traffic, $map->resolve('openai_ads', 'CLICKS'));
        $this->assertSame(ObjectiveFamily::Traffic, $map->resolve('openai_ads', 'clicks')->family());
    }

    /** The finding counts each campaign by ITS result: thousands of visits are not «no results». */
    public function test_spend_without_results_reads_each_campaigns_own_result(): void
    {
        $rows = [
            // Traffic with 1,400 visits and no orders — producing exactly what it was bought for.
            $this->row('traffic', 5000, 'landing_page_views', 1400.0, conversions: 0),
            // Sales with one order on 4,000 — the real finding.
            $this->row('sales', 4000, 'conversions', 1.0, conversions: 1, provider: 'tiktok'),
        ];

        $burning = ClientEntityBoundary::spendWithoutResults($rows);

        $this->assertNotNull($burning);
        $this->assertSame(4000.0, $burning['spend']);
        $this->assertSame(['tiktok'], $burning['providers']);
    }

    /** Unknown objective, unreported result and a paused campaign are never «spend without results». */
    public function test_spend_without_results_skips_unknown_unreported_and_inactive(): void
    {
        $rows = [
            $this->row('unknown', 9000, null, null, conversions: 0),
            $this->row('leads', 9000, 'leads', null, conversions: 0, missing: 'not_reported'),
            $this->row('sales', 9000, 'conversions', 0.0, conversions: 0, status: 'paused'),
        ];

        $this->assertNull(ClientEntityBoundary::spendWithoutResults($rows));
    }

    /**
     * A campaign the importer seeded with a real objective (`objective_source = unset`) still votes.
     *
     * Production: 17 TikTok WEB_CONVERSIONS campaigns carry `conversions` with source `unset`. Only
     * the column DEFAULT (`other`) is the «nobody classified it» placeholder the rule exists to skip.
     */
    public function test_the_report_lens_counts_a_real_objective_whose_source_is_unset(): void
    {
        $lens = ReportObjectiveLens::infer([
            ['spend' => 900, 'objective' => 'traffic', 'objective_source' => 'unset', 'revenue' => 0, 'conversions' => 0],
            ['spend' => 300, 'objective' => 'other', 'objective_source' => 'unset', 'revenue' => 0, 'conversions' => 0],
        ]);

        $this->assertSame('traffic', $lens->value());
    }

    /** The placeholder alone still falls back to the outcome heuristic, exactly as before. */
    public function test_the_report_lens_still_skips_the_unclassified_placeholder(): void
    {
        $lens = ReportObjectiveLens::infer([
            ['spend' => 300, 'objective' => 'other', 'objective_source' => 'unset', 'revenue' => 500, 'conversions' => 3],
        ]);

        $this->assertSame('sales', $lens->value());
    }

    /** @return array<string, mixed> */
    private function row(string $family, float $spend, ?string $key, ?float $result, float $conversions, string $provider = 'snapchat', string $status = 'active', ?string $missing = null): array
    {
        return [
            'spend' => $spend, 'conversions' => $conversions, 'provider' => $provider, 'status' => $status,
            'result_family' => $family, 'result_key' => $key, 'result' => $result,
            'result_missing_reason' => $missing ?? ($key === null ? 'objective_unknown' : null),
        ];
    }
}
