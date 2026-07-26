<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * Builds a report's default slide layout + metric focus from the campaign objective and the set of
 * platforms actually present in the data. Slides are only created for connected platforms (never
 * empty platform slides). The config is versioned so old reports keep rendering as the schema evolves.
 */
final class ReportTemplateEngine
{
    public const VERSION = 1;

    /** Default platform ordering; unknown platforms fall to the end in encounter order. */
    private const PLATFORM_ORDER = ['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin'];

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
        ];
        $order = 4;
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
        return self::METRIC_SETS[$this->canonical($objective)];
    }

    /**
     * The full objective-driven report template — the single Backend source of truth for which KPIs
     * lead, which are secondary, which must be HIDDEN (never featured for this objective), the charts,
     * the funnel shape, and the metric a creative is ranked by. The Report Builder + generator read
     * this instead of scattering objective conditionals across React. Unknown objective → custom.
     *
     * @return array{objective:string, primary_kpi:string, secondary_kpis:list<string>, hidden_kpis:list<string>, charts:list<string>, funnel:list<string>|null, rank_metric:string, top_creative_metric:string}
     */
    public function objectiveTemplate(string $objective): array
    {
        return self::OBJECTIVE_TEMPLATES[$this->canonical($objective)];
    }

    /** @return list<string> the supported objective keys (aliases normalise onto these). */
    public function objectives(): array
    {
        return array_keys(self::OBJECTIVE_TEMPLATES);
    }

    /** Normalise objective aliases onto a supported template key. */
    private function canonical(string $objective): string
    {
        $objective = match ($objective) {
            'conversions' => 'sales',
            'reach' => 'awareness',
            'consideration' => 'engagement',
            'app_installs', 'installs' => 'app',
            'video_views' => 'video',
            'store_visits', 'store' => 'traffic',
            default => $objective,
        };

        return array_key_exists($objective, self::OBJECTIVE_TEMPLATES) ? $objective : 'custom';
    }

    /**
     * Per-objective report contract. `hidden_kpis` are the metrics that must NOT be presented as the
     * headline for this objective (e.g. ROAS/revenue on an Awareness report, or treating clicks as
     * purchases on a Traffic report) — the report layer drops them from the primary strip.
     */
    private const OBJECTIVE_TEMPLATES = [
        'sales' => [
            'objective' => 'sales', 'primary_kpi' => 'roas',
            'secondary_kpis' => ['revenue', 'conversions', 'cpa', 'conversion_rate', 'spend'],
            'hidden_kpis' => ['cpm', 'frequency', 'video_views'],
            'charts' => ['spend_vs_revenue', 'roas_trend', 'sales_funnel', 'platform_revenue_donut', 'top_campaigns', 'top_creatives', 'budget_pacing'],
            'funnel' => ['impressions', 'clicks', 'landing_page_views', 'add_to_cart', 'checkout', 'purchase'],
            'rank_metric' => 'roas', 'top_creative_metric' => 'roas',
        ],
        'awareness' => [
            'objective' => 'awareness', 'primary_kpi' => 'cpm',
            'secondary_kpis' => ['impressions', 'reach', 'frequency', 'video_views', 'view_rate', 'ctr'],
            'hidden_kpis' => ['roas', 'revenue', 'cpa', 'conversion_rate'],
            'charts' => ['reach_impressions_trend', 'platform_spend_donut', 'cpm_comparison', 'frequency_trend', 'video_completion', 'top_creatives'],
            'funnel' => null,
            'rank_metric' => 'view_rate', 'top_creative_metric' => 'cpm',
        ],
        'traffic' => [
            'objective' => 'traffic', 'primary_kpi' => 'ctr',
            'secondary_kpis' => ['clicks', 'landing_page_views', 'cpc', 'sessions', 'engaged_sessions'],
            'hidden_kpis' => ['roas', 'revenue'],
            'charts' => ['clicks_lpv_trend', 'ctr_comparison', 'cpc_comparison', 'platform_traffic_contribution', 'landing_page_funnel', 'top_creatives'],
            'funnel' => ['impressions', 'clicks', 'landing_page_views', 'sessions'],
            'rank_metric' => 'ctr', 'top_creative_metric' => 'ctr',
        ],
        'engagement' => [
            'objective' => 'engagement', 'primary_kpi' => 'engagement_rate',
            'secondary_kpis' => ['engagement', 'video_views', 'cost_per_engagement', 'ctr'],
            'hidden_kpis' => ['roas', 'revenue', 'cpa'],
            'charts' => ['engagement_trend', 'engagement_by_platform', 'content_interaction_ranking', 'cost_per_engagement', 'top_creatives'],
            'funnel' => null,
            'rank_metric' => 'ctr', 'top_creative_metric' => 'ctr',
        ],
        'leads' => [
            'objective' => 'leads', 'primary_kpi' => 'cpl',
            'secondary_kpis' => ['conversions', 'qualified_leads', 'cost_per_qualified_lead', 'form_completion_rate', 'ctr'],
            'hidden_kpis' => ['roas', 'revenue', 'cpm'],
            'charts' => ['leads_trend', 'cpl_comparison', 'lead_funnel', 'platform_lead_contribution', 'top_creatives'],
            'funnel' => ['impressions', 'clicks', 'form_opens', 'leads', 'qualified_leads'],
            'rank_metric' => 'cpa', 'top_creative_metric' => 'cpa',
        ],
        'app' => [
            'objective' => 'app', 'primary_kpi' => 'cpa',
            'secondary_kpis' => ['conversions', 'registrations', 'in_app_events', 'purchases', 'retention'],
            'hidden_kpis' => ['revenue'],
            'charts' => ['installs_trend', 'cost_per_install', 'app_funnel', 'platform_install_contribution', 'top_creatives'],
            'funnel' => ['impressions', 'clicks', 'installs', 'registrations', 'in_app_events', 'purchase'],
            'rank_metric' => 'cpa', 'top_creative_metric' => 'cpa',
        ],
        'video' => [
            'objective' => 'video', 'primary_kpi' => 'completion_rate',
            'secondary_kpis' => ['video_views', 'view_rate', 'cost_per_completed_view', 'cpm', 'ctr'],
            'hidden_kpis' => ['roas', 'revenue', 'cpa'],
            'charts' => ['view_completion_funnel', 'video_views_trend', 'completion_rate_by_platform', 'cost_per_completed_view', 'top_videos'],
            'funnel' => ['views_25', 'views_50', 'views_75', 'views_100'],
            'rank_metric' => 'view_rate', 'top_creative_metric' => 'completion_rate',
        ],
        'custom' => [
            'objective' => 'custom', 'primary_kpi' => 'roas',
            'secondary_kpis' => ['spend', 'revenue', 'conversions', 'cpa', 'ctr'],
            'hidden_kpis' => [],
            'charts' => ['spend_vs_revenue', 'roas_trend', 'platform_revenue_donut', 'top_campaigns', 'top_creatives', 'budget_pacing'],
            'funnel' => ['impressions', 'clicks', 'conversions'],
            'rank_metric' => 'roas', 'top_creative_metric' => 'roas',
        ],
    ];
}
