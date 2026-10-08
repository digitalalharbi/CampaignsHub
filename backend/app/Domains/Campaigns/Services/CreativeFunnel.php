<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

/**
 * §15.6 — one creative's funnel, out of figures that have already been fetched.
 *
 * ## It computes nothing it did not receive
 *
 * There is no query in this class. It is handed the array `CreativeMetrics` produced for the window
 * on screen and reshapes it into stages, which is the only version of a funnel that cannot disagree
 * with the cards beside it. A funnel with its own SQL is the architectural defect §15.17 names, and
 * it is also the practical one: the reader would see «1,204 clicks» in the header and a «1,198
 * clicks» step underneath it, and neither number would be wrong.
 *
 * ## A stage the platform did not report is not a stage
 *
 * «لا تختلق مراحل غير مرسلة». Meta reports add-to-cart and checkout; Snapchat, on an awareness
 * objective, reports neither. Drawing the two missing steps at zero would say the creative sent
 * 40,000 people to a page and none of them added anything to a basket — a sentence about performance
 * that is really a sentence about what the platform sends. So an unreported stage is LEFT OUT, and
 * named in `missing` instead, because silence about it reads as «this funnel has four steps» when
 * the truth is «this platform tells us about four of the seven».
 *
 * The rate on each stage is against the previous stage that SURVIVED that filter, so a funnel whose
 * middle is unreported says «purchases per landing-page view» rather than dividing by a step that
 * is not on the screen.
 */
final class CreativeFunnel
{
    /**
     * The stages, in the order a person moves through them.
     *
     * `video_views` sits above `clicks` deliberately: a viewer who watched has not yet clicked, and
     * on an awareness creative the view IS the funnel. On an image creative the key is unreported and
     * the step simply is not there.
     */
    /**
     * The watch-through, in the order a viewer passes through it.
     *
     * `video_views` is the denominator the quartiles are shares of — a platform counts a «view» at
     * its own threshold and then counts who reached each quarter of what followed.
     */
    private const VIDEO_STAGES = [
        'video_views' => ['ar' => 'المشاهدات', 'en' => 'Video views'],
        'video_p25' => ['ar' => 'شاهدوا 25%', 'en' => 'Watched 25%'],
        'video_p50' => ['ar' => 'شاهدوا 50%', 'en' => 'Watched 50%'],
        'video_p75' => ['ar' => 'شاهدوا 75%', 'en' => 'Watched 75%'],
        'video_p100' => ['ar' => 'أكملوا المشاهدة', 'en' => 'Watched to the end'],
    ];

    private const STAGES = [
        'impressions' => ['ar' => 'الظهور', 'en' => 'Impressions'],
        'video_views' => ['ar' => 'المشاهدات', 'en' => 'Video views'],
        'clicks' => ['ar' => 'النقرات', 'en' => 'Clicks'],
        'landing_page_views' => ['ar' => 'زيارات صفحة الهبوط', 'en' => 'Landing page views'],
        'add_to_cart' => ['ar' => 'الإضافة إلى السلة', 'en' => 'Add to cart'],
        'checkout' => ['ar' => 'بدء الدفع', 'en' => 'Checkout'],
        'purchases' => ['ar' => 'الشراء', 'en' => 'Purchases'],
    ];

    /**
     * @param  array<string, mixed>|null  $metrics  a row from `CreativeMetrics::forCreatives()`
     * @return array{
     *     stages: list<array<string, mixed>>,
     *     missing: list<array<string, string>>,
     *     source: string
     * }
     */
    public function build(?array $metrics): array
    {
        $reported = is_array($metrics['reported'] ?? null) ? $metrics['reported'] : [];
        $spend = is_numeric($metrics['spend'] ?? null) ? (float) $metrics['spend'] : null;

        $stages = [];
        $missing = [];
        $previousKey = null;
        $previousCount = null;

        foreach (self::STAGES as $key => $label) {
            /*
             * Reported, not merely present.
             *
             * `forCreatives()` returns every key it knows about, with `null` where the platform sent
             * nothing — so `isset()` would include all seven steps on every creative. The `reported`
             * map is the only thing that distinguishes «no basket adds» from «this platform does not
             * count basket adds», and the whole point of this class is not to collapse the two.
             */
            if (($reported[$key] ?? false) !== true) {
                $missing[] = ['key' => $key, 'label_ar' => $label['ar'], 'label_en' => $label['en']];

                continue;
            }

            $count = is_numeric($metrics[$key] ?? null) ? (float) $metrics[$key] : null;

            $stages[] = [
                'key' => $key,
                'label_ar' => $label['ar'],
                'label_en' => $label['en'],
                'count' => $count,
                'from_stage' => $previousKey,
                // Null when there is no step above, when either side is missing, or when the step
                // above is zero — «100% of nothing» is not a conversion rate.
                'rate_from_previous' => $previousCount !== null && $previousCount > 0.0 && $count !== null
                    ? $count / $previousCount
                    : null,
                // Cost per step, not a share of spend: the whole spend bought every step, so
                // apportioning it between them would invent an attribution nobody reported.
                'cost_per' => $spend !== null && $count !== null && $count > 0.0 ? $spend / $count : null,
                'source' => 'platform_reported',
            ];

            $previousKey = $key;
            $previousCount = $count;
        }

        return [
            'stages' => $stages,
            // Named rather than dropped: a reader who cannot see «add to cart» needs to know the
            // platform never sent it, or they will read its absence as a creative that sold nothing.
            'missing' => $missing,
            'source' => 'platform_reported',
        ];
    }

    /**
     * CONTENT-VIDEO-RETENTION-001 — where people stop watching, which every row already knew.
     *
     * `video_p25`, `video_p50`, `video_p75` and `video_p100` are written on every metric row, carry
     * labels in the catalogue, and were drawn nowhere. For a video creative «where do people stop
     * watching» is the question the asset is judged on, and the answer was four numbers in a table.
     *
     * ## It is a real funnel, which not every list of stages is
     *
     * The quartiles NEST: everybody who reached 50% reached 25% first, so a tapering shape is a true
     * claim about this data. That is what separates it from a snapshot of who is standing at which
     * stage, where the shape would assert a flow nobody measured — and it is why this gets a funnel
     * and the leads pipeline deliberately does not.
     *
     * Separate from `build()` rather than appended to it: the conversion funnel is one person's path
     * from seeing to buying, and the quartiles are a different question about the same impression.
     * Putting «50% watched» between «clicks» and «landing page views» would say somebody watched half
     * a video after clicking through it.
     *
     * Every refusal `build()` makes is made here, for the same reasons: an unreported quartile is a
     * HOLE and not a zero — filling it would draw a video everybody abandoned at the first quarter and
     * then finished anyway — and no rate is derived from a step of zero, because «100% of nothing» is
     * not a retention rate.
     *
     * @param  array<string, mixed>|null  $metrics  a row from `CreativeMetrics::forCreatives()`
     * @return array{stages: list<array<string, mixed>>, missing: list<array<string, string>>, source: string}
     */
    public function video(?array $metrics): array
    {
        $reported = is_array($metrics['reported'] ?? null) ? $metrics['reported'] : [];

        $stages = [];
        $missing = [];
        $previousCount = null;
        $previousKey = null;

        foreach (self::VIDEO_STAGES as $key => $label) {
            if (($reported[$key] ?? false) !== true) {
                $missing[] = ['key' => $key, 'label_ar' => $label['ar'], 'label_en' => $label['en']];

                continue;
            }

            $count = is_numeric($metrics[$key] ?? null) ? (float) $metrics[$key] : null;

            $stages[] = [
                'key' => $key,
                'label_ar' => $label['ar'],
                'label_en' => $label['en'],
                'count' => $count === null ? null : (int) $count,
                'from_stage' => $previousKey,
                'rate_from_previous' => $previousCount !== null && $previousCount > 0.0 && $count !== null
                    ? $count / $previousCount
                    : null,
                /*
                 * No cost per quartile. The spend bought the impression, not the moment somebody
                 * stopped watching, and a «cost per 50% view» is a figure no platform reports and no
                 * decision uses — the conversion funnel carries cost because its steps are outcomes.
                 */
                'cost_per' => null,
                'source' => 'platform_reported',
            ];

            $previousKey = $key;
            $previousCount = $count;
        }

        return [
            /* An image creative has no watch-through, and is given no empty chart to look at. */
            'stages' => $stages,
            'missing' => $stages === [] ? [] : $missing,
            'source' => 'platform_reported',
        ];
    }
}
