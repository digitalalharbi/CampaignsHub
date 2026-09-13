<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\CreativeRankingService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CLIENT-REPORT-MONEY-REDACTION-001 — `?? 0` on withheld money, and what it cost twice.
 *
 * `ReportAds::rankable()` built each ad row with `(float) ($row['metrics']['spend'] ?? 0)`. On
 * production that is not a corner: every Snapchat account is USD with no USD→SAR rate, so `spend` is
 * null by FX-001's design and the real amount is in `spend_original`. `?? 0` therefore did two
 * separate wrong things with one operator:
 *
 *   1. it printed «0» as the ad's spend on a client's report — the state the owner named explicitly,
 *      «NEVER convert unavailable Spend into 0»;
 *   2. it made the ad vanish. `CreativeRankingService` gates on `spend > 0` — reasonably, because a
 *      creative that never spent is not a «best» or a «worst» — and a coerced zero fails that gate.
 *      So the entire Snapchat half of an account was silently absent from the best and worst lists,
 *      and the section still read «الأفضل أداءً» over whatever remained.
 *
 * The second is the worse one, because nothing on the page says a row is missing. The first at least
 * prints something a reader can disbelieve.
 *
 * `didSpend()` asks the money contract instead: a withheld amount IS spend, and «no rate» is a fact
 * about the conversion, not about the campaign.
 */
final class ReportAdsMoneyTruthTest extends TestCase
{
    /** A Snapchat ad as production has it: nothing converted, the amount preserved beside it. */
    private function withheld(array $over = []): array
    {
        return array_merge([
            'id' => 'cr-1',
            'name' => 'Prayer Beads AD1',
            'spend' => null,
            'spend_original' => 412.5,
            'spend_withheld_rows' => 3,
            'money_original_currency' => 'USD',
            'money_original_currencies' => 1,
            'impressions' => 24_700,
            'clicks' => 94,
            'conversions' => 4,
            'roas' => 3.1,
            'cpa' => 12.0,
            'ctr' => 0.0038,
        ], $over);
    }

    #[Test]
    public function a_creative_whose_spend_was_only_ever_withheld_is_still_ranked(): void
    {
        $ranked = app(CreativeRankingService::class)->rank('sales', [$this->withheld()]);

        $this->assertCount(1, $ranked, 'an ad with 412.50 USD of real spend was dropped from the ranking as though it had spent nothing');
        $this->assertSame('cr-1', $ranked[0]['id']);
    }

    #[Test]
    public function a_creative_whose_spend_was_only_ever_withheld_can_still_be_a_worst_performer(): void
    {
        $worst = app(CreativeRankingService::class)->worst('sales', [
            $this->withheld(),
            $this->withheld(['id' => 'cr-2', 'name' => 'Rings AD4', 'roas' => 0.4, 'cpa' => 90.0]),
        ]);

        $this->assertNotSame([], $worst, 'every candidate was withheld, so the worst list came back empty');
        $this->assertSame('cr-2', $worst[0]['id'], 'the worse return did not sort to the worst position');
    }

    /** The gate still holds for a creative that genuinely spent nothing — it is not simply removed. */
    #[Test]
    public function a_creative_that_genuinely_never_spent_is_still_excluded(): void
    {
        $never = $this->withheld([
            'id' => 'cr-3',
            'spend' => null,
            'spend_original' => null,
            'spend_withheld_rows' => 0,
            'money_original_currency' => null,
            'money_original_currencies' => 0,
        ]);

        $this->assertSame([], app(CreativeRankingService::class)->rank('sales', [$never]));
    }

    /** A measured zero is measured, and is still not spending. */
    #[Test]
    public function a_creative_with_a_reported_zero_spend_is_excluded(): void
    {
        $zero = $this->withheld(['id' => 'cr-4', 'spend' => 0.0, 'spend_original' => null, 'spend_withheld_rows' => 0, 'money_original_currency' => null]);

        $this->assertSame([], app(CreativeRankingService::class)->rank('sales', [$zero]));
    }

    /** A converted figure is unaffected — the ordinary case must not change. */
    #[Test]
    public function a_converted_spend_ranks_exactly_as_before(): void
    {
        $ranked = app(CreativeRankingService::class)->rank('sales', [
            $this->withheld(['id' => 'a', 'spend' => 100.0, 'spend_original' => null, 'money_original_currency' => null, 'roas' => 2.0]),
            $this->withheld(['id' => 'b', 'spend' => 200.0, 'spend_original' => null, 'money_original_currency' => null, 'roas' => 9.0]),
        ]);

        $this->assertSame(['b', 'a'], array_column($ranked, 'id'));
    }
}
