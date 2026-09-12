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
        /*
         * Each set is what its objective is JUDGED on — METRIC-NAMES-001, and it must agree with
         * `metricCatalog.ts` on the client. A report that leads with different metrics from the
         * dashboard the reader just left is a report they have to reconcile by hand.
         *
         * Sales gained the basket step: the path from a basket to an order is the thing a merchant
         * acts on, and it was collected but never shown. Leads and app installs now name their own
         * count and their own cost rather than borrowing «النتائج» and «تكلفة النتيجة», which are
         * true of both and specific to neither.
         */
        'sales' => ['spend', 'add_to_cart', 'purchases', 'revenue', 'cpa', 'roas'],
        'awareness' => ['impressions', 'reach', 'frequency', 'cpm', 'video_views', 'ctr'],
        'traffic' => ['clicks', 'landing_page_views', 'ctr', 'cpc', 'spend'],
        'leads' => ['leads', 'cpl', 'conversion_rate', 'ctr', 'spend'],
        'app_installs' => ['installs', 'cpi', 'registrations', 'spend', 'ctr'],
        'video' => ['video_views', 'impressions', 'cpm', 'ctr', 'clicks'],
        /*
         * Several objectives in one scope — operational figures only (§14.6).
         *
         * This set was a copy of the sales set, so a month mixing a brand campaign and a sales
         * campaign led with ROAS and a cost per result computed across both: one objective's money
         * divided by another objective's events. The arithmetic works and the number means nothing,
         * which is worse than a gap, because nothing on the page tells the reader not to act on it.
         *
         * Spend, impressions, clicks and CTR mean the same thing whatever each campaign was for. The
         * per-path figures are not lost — `objective_performance` states them path by path, and that
         * section is in every template.
         */
        'custom' => ['spend', 'impressions', 'clicks', 'ctr', 'cpc', 'reach'],
    ];

    // One rich slide per platform by default (KPIs + charts + top creative + notes + recommendations).
    // top_creatives / platform_notes / platform_screenshot remain available slide types the user can
    // add from the builder, but are NOT emitted automatically — standalone they render sparse.
    private const PER_PLATFORM_SLIDES = ['platform_performance'];

    /** @param list<string> $platforms providers present in the data */
    /**
     * REPORT-DEPTH-001 — the two shapes a report is read in.
     *
     * «A. Summary / Executive — concise KPI dashboard, trend, platform distribution, strongest
     * results, key budget/spend, top creatives, a few concise findings only. B. Full / Detailed —
     * complete KPIs, deeper trend charts, platform/objective breakdown, funnel, budget, comparisons,
     * ALL promoted contents.»
     *
     * The deck had ONE shape: the report going to a client's inbox and the one an operator reads
     * before a call carried the same fourteen sections. «Reduce prose aggressively» cannot be
     * answered by a template with one setting.
     *
     * Depth belongs to the report rather than to a reader's toggle, so a summary is a summary
     * wherever it is opened — in the deck, in the print document, in a scheduled email.
     *
     * The parameter is the report's own `form` column — `executive_summary` or `detailed` — which
     * the product has recorded since reports shipped and which nothing read. Unknown and unspecified
     * both mean DETAILED: every report that exists was generated as the full deck whatever it was
     * created as, and re-reading those as summaries would silently delete sections from decks people
     * already send.
     */
    public function defaultConfig(string $objective, array $platforms, string $form = 'detailed'): array
    {
        $objective = array_key_exists($objective, self::METRIC_SETS) ? $objective : 'custom';
        $ordered = $this->orderPlatforms($platforms);
        $summary = $form === 'executive_summary';

        $slides = [
            ['id' => 'cover', 'type' => 'cover', 'order' => 1, 'visible' => true],
            /*
             * Recommendations are what an operator DOES next; an executive summary states what
             * happened. A summary deck keeps the second and drops the first, which is also the
             * «reduce prose aggressively» half of the requirement — this is the most text-heavy
             * section in the template.
             */
            ...($summary ? [] : [['id' => 'recommendations', 'type' => 'recommendations', 'order' => 2, 'visible' => true]]),
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
            /*
             * Kept in the SUMMARY too — REPORT-OBJECTIVE-003/004, and `ObjectivePerformanceTest`
             * says so in its own words: «it survives into the five-page summary a client is sent,
             * which is the version that gets forwarded and quoted with no per-platform pages behind
             * it to argue with».
             *
             * Dropping it from the executive form was the first thing I wrote, and it would have
             * reversed a decided product rule: the blended cost per order is exactly the figure a
             * forwarded summary gets quoted on, and this section is what stops it being read as the
             * price of a sale.
             */
            ['id' => 'objective_performance', 'type' => 'objective_performance', 'order' => 4, 'visible' => true],
        ];
        $order = 5; // 1–4 are the fixed opening: cover, recommendations, summary, objective split.
        /*
         * The per-platform slides are the section that grows without a ceiling — six connected
         * platforms is six slides — and they are the operator's view of money the distribution chart
         * already shows an executive. A summary carries the comparison instead.
         */
        foreach ($summary ? [] : $ordered as $platform) {
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
        /*
         * A funnel is drawn wherever the money was meant to move somebody THROUGH something.
         *
         * The list used to be the three objectives that existed when it was written, which meant an
         * app-install report and a mixed-objective report — both of which have a real click →
         * arrival → result path — silently lost the section. Naming the two that genuinely have no
         * funnel is the smaller and more durable list: an objective added later gets one by default
         * rather than being forgotten.
         */
        if (! $summary && ! in_array($objective, ['awareness', 'video'], true)) {
            $slides[] = ['id' => 'funnel', 'type' => 'funnel', 'order' => $order++, 'visible' => true];
        }
        $slides[] = ['id' => 'budget', 'type' => 'budget', 'order' => $order++, 'visible' => true];
        /*
         * REPORT-AD-PREVIEW-001 — the ads themselves, near the end and before the interpretation.
         *
         * The deck carried ad-level rows and their media in `ads` and rendered none of them: the part
         * a client actually recognises — the picture that ran — was a rank number on a coloured
         * square. It sits after the money and before the observations, because it is EVIDENCE for
         * what the observations are about to claim, and a reader who meets the conclusions first has
         * already decided.
         *
         * A report whose generator found no ad-level rows still gets the section: it says why, which
         * is a smaller and truer statement than a deck that quietly has no ads in it.
         */
        $slides[] = ['id' => 'ads', 'type' => 'ads', 'order' => $order++, 'visible' => true];
        /*
         * The closing sequence §14.10 asks for, in its order: trends and comparisons → notes and
         * alerts → recommendations → data quality and freshness.
         *
         * `comparison` and `observations` are new (§14.7). They sit here rather than near the top
         * because they INTERPRET what the reader has just been shown; put first, they would be
         * conclusions about figures nobody had seen yet.
         */
        /* The period comparison IS «what changed», which is half of what an executive came for. */
        $slides[] = ['id' => 'comparison', 'type' => 'comparison', 'order' => $order++, 'visible' => true];
        $slides[] = ['id' => 'observations', 'type' => 'observations', 'order' => $order++, 'visible' => true];
        // Client-facing action plan — rendered only when there are approved recommendations.
        /*
         * «Next steps» and the data-quality appendix are the operator's two most text-heavy closings.
         * A summary ends on the observations — «a few concise findings only» — and the quality
         * appendix is already withheld from a client audience by CLIENT-DIAGNOSTIC-SEPARATION-001,
         * so keeping it in a summary would put it in front of exactly the reader it was taken from.
         */
        if (! $summary) {
            $slides[] = ['id' => 'next_steps', 'type' => 'next_steps', 'order' => $order++, 'visible' => true];
        }
        /*
         * Data quality LAST, and always present.
         *
         * It is the slide that says how much weight the rest of the deck can carry, and a report
         * that omits it when everything is healthy teaches its reader that its absence means
         * nothing — so when it does appear, they have no baseline to read it against.
         */
        if (! $summary) {
            $slides[] = ['id' => 'data_quality', 'type' => 'data_quality', 'order' => $order++, 'visible' => true];
        }

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
