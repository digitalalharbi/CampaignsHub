<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Support\AdPlatforms;

/**
 * Builds a report's default slide layout + metric focus from the campaign objective and the set of
 * platforms actually present in the data. Slides are only created for connected platforms (never
 * empty platform slides). The config is versioned so old reports keep rendering as the schema evolves.
 */
final class ReportTemplateEngine
{
    public const VERSION = 1;

    /** Default platform ordering; unknown platforms fall to the end in encounter order. */
    /**
     * The product's platform order, read from the one place that decides it (PLATFORM-ORDER-001).
     *
     * This list was correct and was also a second copy — which is how the other five surfaces came to
     * disagree with it without anybody noticing.
     */
    private const PLATFORM_ORDER = AdPlatforms::ORDER;

    /** Objective → the KPIs that matter most (drives KPI emphasis + creative ranking). */
    private const METRIC_SETS = [
        'sales' => ['spend', 'revenue', 'conversions', 'roas', 'cpa', 'ctr'],
        'awareness' => ['impressions', 'reach', 'frequency', 'cpm', 'video_views', 'ctr'],
        'traffic' => ['clicks', 'landing_page_views', 'ctr', 'cpc', 'conversions'],
        'leads' => ['conversions', 'cpa', 'ctr', 'cpc', 'spend'],
        'app_installs' => ['conversions', 'cpa', 'spend', 'ctr'],
        'video' => ['video_views', 'impressions', 'cpm', 'ctr', 'clicks'],
        'custom' => ['spend', 'revenue', 'conversions', 'roas', 'cpa', 'ctr'],
    ];

    // One rich slide per platform by default (KPIs + charts + top creative + notes + recommendations).
    // top_creatives / platform_notes / platform_screenshot remain available slide types the user can
    // add from the builder, but are NOT emitted automatically — standalone they render sparse.
    private const PER_PLATFORM_SLIDES = ['platform_performance'];

    /** @param list<string> $platforms providers present in the data */
    public function defaultConfig(string $objective, array $platforms): array
    {
        $objective = array_key_exists($objective, self::METRIC_SETS) ? $objective : 'custom';
        $ordered = $this->orderPlatforms($platforms);

        $slides = [
            ['id' => 'cover', 'type' => 'cover', 'order' => 1, 'visible' => true],
            ['id' => 'recommendations', 'type' => 'recommendations', 'order' => 2, 'visible' => true],
            ['id' => 'executive_summary', 'type' => 'executive_summary', 'order' => 3, 'visible' => true],
            /*
             * Direct against Blended, immediately after the summary (REPORT-OBJECTIVE-003/004).
             *
             * It sits this high because it qualifies the figures the summary just showed. Placed
             * further down, a reader would already have taken the headline cost per order at face
             * value and would meet the distinction only after acting on it.
             *
             * It is in EVERY objective's template, including awareness. A brand report is exactly
             * where somebody asks «and what did that cost per sale?», and the honest answer is that
             * this money did not buy sales — which the section states, rather than leaving the
             * question to be answered by a blended figure elsewhere.
             */
            ['id' => 'objective_performance', 'type' => 'objective_performance', 'order' => 4, 'visible' => true],
        ];
        $order = 5; // 1–4 are the fixed opening: cover, recommendations, summary, objective split.
        foreach ($ordered as $platform) {
            foreach (self::PER_PLATFORM_SLIDES as $type) {
                $slides[] = [
                    'id' => "{$platform}-{$type}",
                    'type' => $type,
                    'platform' => $platform,
                    'order' => $order++,
                    'visible' => true,
                ];
            }
        }
        // Cross-platform closing slides.
        if (count($ordered) > 1) {
            $slides[] = ['id' => 'platform_comparison', 'type' => 'platform_comparison', 'order' => $order++, 'visible' => true];
        }
        if (in_array($objective, ['sales', 'traffic', 'leads'], true)) {
            $slides[] = ['id' => 'funnel', 'type' => 'funnel', 'order' => $order++, 'visible' => true];
        }
        $slides[] = ['id' => 'budget', 'type' => 'budget', 'order' => $order++, 'visible' => true];
        // Client-facing action plan — rendered only when there are approved recommendations.
        $slides[] = ['id' => 'next_steps', 'type' => 'next_steps', 'order' => $order++, 'visible' => true];

        return [
            'version' => self::VERSION,
            'objective' => $objective,
            'metric_set' => self::METRIC_SETS[$objective],
            'platform_order' => $ordered,
            'slides' => $slides,
        ];
    }

    /** @param list<string> $platforms */
    private function orderPlatforms(array $platforms): array
    {
        $unique = array_values(array_unique($platforms));
        usort($unique, function (string $a, string $b) {
            $ia = array_search($a, self::PLATFORM_ORDER, true);
            $ib = array_search($b, self::PLATFORM_ORDER, true);
            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;

            return $ia <=> $ib;
        });

        return $unique;
    }

    public function metricSet(string $objective): array
    {
        return self::METRIC_SETS[array_key_exists($objective, self::METRIC_SETS) ? $objective : 'custom'];
    }
}
