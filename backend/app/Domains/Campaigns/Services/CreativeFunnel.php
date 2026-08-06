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
}
