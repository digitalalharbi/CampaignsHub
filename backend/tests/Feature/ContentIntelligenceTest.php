<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Metrics\Services\ContentIntelligence;
use Tests\TestCase;

/**
 * ANALYTICS-DIFFERENTIATION-001 — the content reading, held to the same rules as every other one.
 *
 * The claims under test are the ones that separate a reading from a leaderboard: both ends measured
 * on the same metric, a format spoken for only when enough of it ran, a missing figure never read as
 * a zero, and the verdict never decided by whichever format was funded most.
 */
final class ContentIntelligenceTest extends TestCase
{
    private function subject(): ContentIntelligence
    {
        return new ContentIntelligence(app(CreativeMetrics::class));
    }

    /**
     * A creative row as `CreativeMetrics::forCreatives()` returns one — only the keys these
     * assertions turn on, because `aggregate()` treats an absent key as «not reported», which is
     * exactly the state most of these cases are about.
     *
     * @return array<string, mixed>
     */
    private function figures(float $spend, float $conversions, float $revenue = 0.0, ?float $clicks = null): array
    {
        return [
            'spend' => $spend,
            'conversions' => $conversions,
            'revenue' => $revenue,
            'clicks' => $clicks,
            'impressions' => 10000.0,
            'active_days' => 7,
        ];
    }

    public function test_it_names_the_format_that_earns_its_money_and_the_one_that_does_not(): void
    {
        // Video: 1000 spend / 50 results = 20 CPA. Image: 1000 / 10 = 100 CPA.
        $reading = $this->subject()->byFormat(
            [
                ['id' => 'v1', 'format' => 'video'],
                ['id' => 'v2', 'format' => 'video'],
                ['id' => 'i1', 'format' => 'image'],
                ['id' => 'i2', 'format' => 'image'],
            ],
            [
                'v1' => $this->figures(500, 25),
                'v2' => $this->figures(500, 25),
                'i1' => $this->figures(500, 5),
                'i2' => $this->figures(500, 5),
            ],
            'Conversions',
        );

        $this->assertNull($reading['refusal']);
        $this->assertSame('video', $reading['best']);
        $this->assertSame('image', $reading['worst']);
        $this->assertTrue($reading['lower_is_better'], 'a cost metric was ranked as though more were better');
    }

    /** Both ends on ONE metric — the rule that makes it a comparison rather than two facts. */
    public function test_every_format_is_read_on_the_same_metric(): void
    {
        $reading = $this->subject()->byFormat(
            [
                ['id' => 'v1', 'format' => 'video'], ['id' => 'v2', 'format' => 'video'],
                ['id' => 'i1', 'format' => 'image'], ['id' => 'i2', 'format' => 'image'],
            ],
            [
                'v1' => $this->figures(500, 25), 'v2' => $this->figures(500, 25),
                'i1' => $this->figures(500, 5), 'i2' => $this->figures(500, 5),
            ],
            'Conversions',
        );

        $this->assertNotNull($reading['metric']);
        $this->assertCount(2, $reading['formats']);
        foreach ($reading['formats'] as $row) {
            $this->assertIsFloat($row['value']);
        }
    }

    /**
     * The verdict is never spend or impressions.
     *
     * Ranking formats by what was spent on them makes the biggest buy the winner by construction —
     * a tautology printed as a finding.
     */
    public function test_it_never_decides_the_comparison_on_how_much_was_bought(): void
    {
        $reading = $this->subject()->byFormat(
            [
                ['id' => 'v1', 'format' => 'video'], ['id' => 'v2', 'format' => 'video'],
                ['id' => 'i1', 'format' => 'image'], ['id' => 'i2', 'format' => 'image'],
            ],
            [
                'v1' => $this->figures(5000, 10), 'v2' => $this->figures(5000, 10),
                'i1' => $this->figures(100, 10), 'i2' => $this->figures(100, 10),
            ],
            'Conversions',
        );

        $this->assertNotSame('spend', $reading['metric']);
        $this->assertNotSame('impressions', $reading['metric']);
        // Image bought the same 20 results for a fortieth of the money, so image leads.
        $this->assertSame('image', $reading['best']);
    }

    /**
     * The defect a browser found and no unit test had: a VOLUME decided the comparison.
     *
     * On the first account this ran against, video carried thirty creatives and carousel fifteen,
     * and the reading ranked them on `clicks` — announcing video the winner by 1,050 to 390, which
     * is very largely the arithmetic of twice as many ads running. Formats are groups of unequal
     * size, so only a figure that is already per-something can settle a comparison between them.
     */
    public function test_a_raw_count_never_decides_a_comparison_between_groups_of_unequal_size(): void
    {
        // Video: twice as many creatives, twice the clicks, and the IDENTICAL click-through rate.
        $video = [];
        for ($i = 0; $i < 4; $i++) {
            $video["v{$i}"] = ['spend' => null, 'clicks' => 100.0, 'impressions' => 10000.0, 'active_days' => 7];
        }

        $image = [];
        for ($i = 0; $i < 2; $i++) {
            $image["i{$i}"] = ['spend' => null, 'clicks' => 100.0, 'impressions' => 5000.0, 'active_days' => 7];
        }

        $creatives = [];
        foreach (array_keys($video) as $id) {
            $creatives[] = ['id' => $id, 'format' => 'video'];
        }
        foreach (array_keys($image) as $id) {
            $creatives[] = ['id' => $id, 'format' => 'image'];
        }

        $reading = $this->subject()->byFormat($creatives, $video + $image, null);

        $this->assertNotSame('clicks', $reading['metric'], 'a raw click count was used as the verdict');
        $this->assertNotSame('conversions', $reading['metric']);
        $this->assertNotSame('video_views', $reading['metric']);

        // Image is twice as efficient per impression, and must therefore lead despite half the clicks.
        if ($reading['refusal'] === null) {
            $this->assertSame('ctr', $reading['metric']);
            $this->assertSame('image', $reading['best']);
        }
    }

    /**
     * «A format withheld its spend» is not the same sentence as «nobody reports spend here».
     *
     * The second is an ordinary account whose provider does not break spend down to the creative
     * grain; telling that reader a format is withholding a figure sends them looking for a fault
     * that does not exist.
     */
    public function test_it_says_which_kind_of_silence_left_the_spend_share_absent(): void
    {
        $none = static fn (float $clicks): array => [
            'spend' => null, 'clicks' => $clicks, 'impressions' => 10000.0, 'active_days' => 7,
        ];

        $reading = $this->subject()->byFormat(
            [
                ['id' => 'v1', 'format' => 'video'], ['id' => 'v2', 'format' => 'video'],
                ['id' => 'i1', 'format' => 'image'], ['id' => 'i2', 'format' => 'image'],
            ],
            ['v1' => $none(300), 'v2' => $none(300), 'i1' => $none(100), 'i2' => $none(100)],
            null,
        );

        if ($reading['refusal'] === null) {
            $this->assertNull($reading['share_of_spend_not_on_the_leading_format']);
            $this->assertSame('no_spend_was_reported_at_this_grain', $reading['why_no_spend_share']);
        }
    }

    /** One asset is not a category, and must not be allowed to speak for one. */
    public function test_a_format_carried_by_a_single_asset_does_not_speak_for_that_format(): void
    {
        $reading = $this->subject()->byFormat(
            [
                ['id' => 'v1', 'format' => 'video'], ['id' => 'v2', 'format' => 'video'],
                ['id' => 'c1', 'format' => 'carousel'],
            ],
            [
                'v1' => $this->figures(500, 25), 'v2' => $this->figures(500, 25),
                'c1' => $this->figures(500, 1),
            ],
            'Conversions',
        );

        $this->assertSame('only_one_format_ran_enough_to_compare', $reading['refusal']);
        $this->assertSame(
            [['format' => 'carousel', 'creatives' => 1]],
            $reading['too_few_to_speak_for_their_format'],
            'the held-out format was dropped silently instead of being named',
        );
    }

    public function test_it_refuses_when_only_one_format_ran_at_all(): void
    {
        $reading = $this->subject()->byFormat(
            [['id' => 'v1', 'format' => 'video'], ['id' => 'v2', 'format' => 'video']],
            ['v1' => $this->figures(500, 25), 'v2' => $this->figures(500, 25)],
            'Conversions',
        );

        $this->assertSame('only_one_format_ran_enough_to_compare', $reading['refusal']);
        $this->assertNull($reading['best']);
    }

    public function test_it_refuses_when_nothing_reported_in_the_period(): void
    {
        $reading = $this->subject()->byFormat(
            [['id' => 'v1', 'format' => 'video'], ['id' => 'i1', 'format' => 'image']],
            ['v1' => null, 'i1' => null],
            'Conversions',
        );

        $this->assertSame('no_creative_reported_in_this_period', $reading['refusal']);
    }

    /**
     * A creative with no format recorded is «unlabelled» — never filed under image.
     *
     * The column defaults to `image` in the schema, so a provider that does not state a format would
     * otherwise pad the image group with assets nobody has established are images, and the reading
     * would compare video against a mixture.
     */
    public function test_a_creative_with_no_format_is_unlabelled_rather_than_an_image(): void
    {
        $reading = $this->subject()->byFormat(
            [
                ['id' => 'v1', 'format' => 'video'], ['id' => 'v2', 'format' => 'video'],
                ['id' => 'u1', 'format' => null], ['id' => 'u2', 'format' => ''],
            ],
            [
                'v1' => $this->figures(500, 25), 'v2' => $this->figures(500, 25),
                'u1' => $this->figures(500, 5), 'u2' => $this->figures(500, 5),
            ],
            'Conversions',
        );

        $this->assertNull($reading['refusal']);
        $this->assertContains('unlabelled', array_column($reading['formats'], 'format'));
        $this->assertNotContains('image', array_column($reading['formats'], 'format'));
    }

    /**
     * A share of spend computed over an incomplete denominator overstates itself.
     *
     * `spend` comes back null for money with no conversion rate (FX-001), and dividing by a total
     * that is missing one side's figure produces a percentage that looks like an answer.
     */
    public function test_the_spend_share_is_withheld_when_a_format_withheld_its_spend(): void
    {
        $withheld = $this->figures(0, 5);
        $withheld['spend'] = null;

        $reading = $this->subject()->byFormat(
            [
                ['id' => 'v1', 'format' => 'video'], ['id' => 'v2', 'format' => 'video'],
                ['id' => 'i1', 'format' => 'image'], ['id' => 'i2', 'format' => 'image'],
            ],
            [
                'v1' => $this->figures(500, 25), 'v2' => $this->figures(500, 25),
                'i1' => $withheld, 'i2' => $withheld,
            ],
            'Conversions',
        );

        if ($reading['refusal'] === null) {
            $this->assertNull(
                $reading['share_of_spend_not_on_the_leading_format'],
                'a spend share was computed over a total missing one format’s figure',
            );
        } else {
            $this->assertSame('no_metric_every_format_could_answer', $reading['refusal']);
        }
    }

    /** A metric one side cannot answer is not a metric that side scored zero on. */
    public function test_a_format_that_reported_nothing_for_a_metric_is_not_ranked_last_on_it(): void
    {
        // Video reports revenue; image does not report it at all. ROAS must not be the verdict.
        $noRevenue = $this->figures(500, 5);
        unset($noRevenue['revenue']);

        $reading = $this->subject()->byFormat(
            [
                ['id' => 'v1', 'format' => 'video'], ['id' => 'v2', 'format' => 'video'],
                ['id' => 'i1', 'format' => 'image'], ['id' => 'i2', 'format' => 'image'],
            ],
            [
                'v1' => $this->figures(500, 25, 5000), 'v2' => $this->figures(500, 25, 5000),
                'i1' => $noRevenue, 'i2' => $noRevenue,
            ],
            'Sales',
        );

        $this->assertNotSame('roas', $reading['metric'], 'a format that never reported revenue was ranked on return');
    }
}
