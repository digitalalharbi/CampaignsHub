<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Metrics\Models\MetricDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds the global metric catalogue. Base metrics are additive (safe to sum across days/campaigns);
 * derived ratios are non-additive and must be recomputed at aggregation time, never summed.
 *
 * NORM-001: this seeder was written and never called. `DatabaseSeeder` ran Permission, RequestCatalog,
 * SubscriptionPlan and TaxonomyEngine and not this one, so `metric_definitions` was EMPTY on every
 * install — `DailyMetric::definition()` had always returned null and nothing could say what a metric
 * meant or how it aggregates. It is registered now, beside the other structural catalogues.
 *
 * The list was also a subset. `MetricsAggregator` pivots sixteen base keys and derives fifteen more;
 * fifteen of those thirty-one were named here. A catalogue that documents half of what the dashboard
 * computes is worse than none, because the gaps read as metrics the product does not have. Everything
 * the aggregator emits is now defined, and `MetricsTest` fails if a new one is added without an entry.
 *
 * `is_additive` is the load-bearing column: it is what says a ratio must be recomputed from its base
 * sums rather than summed across rows. Summing CPC across thirty days does not give you a month's CPC.
 */
final class MetricDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = [
            // key, name, unit, value_type, aggregation, is_currency, is_additive
            ['impressions', 'Impressions', 'count', 'integer', 'sum', false, true],
            ['clicks', 'Clicks', 'count', 'integer', 'sum', false, true],
            ['landing_page_views', 'Landing Page Views', 'count', 'integer', 'sum', false, true],
            ['add_to_cart', 'Add to Cart', 'count', 'integer', 'sum', false, true],
            ['checkout', 'Checkout', 'count', 'integer', 'sum', false, true],
            ['spend', 'Spend', 'currency', 'decimal', 'sum', true, true],
            ['conversions', 'Conversions', 'count', 'decimal', 'sum', false, true],
            ['revenue', 'Revenue', 'currency', 'decimal', 'sum', true, true],
            ['video_views', 'Video Views', 'count', 'integer', 'sum', false, true],
            ['video_completions', 'Video Completions', 'count', 'integer', 'sum', false, true],
            ['engagements', 'Engagements', 'count', 'integer', 'sum', false, true],
            ['reach', 'Reach', 'count', 'integer', 'sum', false, true],
            ['leads', 'Leads', 'count', 'decimal', 'sum', false, true],
            ['qualified_leads', 'Qualified Leads', 'count', 'decimal', 'sum', false, true],
            ['purchases', 'Purchases', 'count', 'decimal', 'sum', false, true],
            ['installs', 'Installs', 'count', 'decimal', 'sum', false, true],
            ['registrations', 'Registrations', 'count', 'decimal', 'sum', false, true],
            ['in_app_events', 'In-App Events', 'count', 'decimal', 'sum', false, true],
            // Derived — non-additive; recomputed from base metrics at aggregation time.
            ['ctr', 'Click-Through Rate', 'ratio', 'decimal', 'avg', false, false],
            ['cpc', 'Cost per Click', 'currency', 'decimal', 'avg', true, false],
            ['cpm', 'Cost per Mille', 'currency', 'decimal', 'avg', true, false],
            ['cpa', 'Cost per Acquisition', 'currency', 'decimal', 'avg', true, false],
            ['roas', 'Return on Ad Spend', 'ratio', 'decimal', 'avg', false, false],
            ['cpl', 'Cost per Lead', 'currency', 'decimal', 'avg', true, false],
            ['cpi', 'Cost per Install', 'currency', 'decimal', 'avg', true, false],
            ['cpe', 'Cost per Engagement', 'currency', 'decimal', 'avg', true, false],
            ['aov', 'Average Order Value', 'currency', 'decimal', 'avg', true, false],
            ['frequency', 'Frequency', 'ratio', 'decimal', 'avg', false, false],
            ['conversion_rate', 'Conversion Rate', 'ratio', 'decimal', 'avg', false, false],
            ['engagement_rate', 'Engagement Rate', 'ratio', 'decimal', 'avg', false, false],
            ['video_completion_rate', 'Video Completion Rate', 'ratio', 'decimal', 'avg', false, false],
        ];

        foreach ($catalogue as [$key, $name, $unit, $valueType, $aggregation, $isCurrency, $isAdditive]) {
            MetricDefinition::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $name,
                    'unit' => $unit,
                    'value_type' => $valueType,
                    'default_aggregation' => $aggregation,
                    'is_currency' => $isCurrency,
                    'is_additive' => $isAdditive,
                ],
            );
        }
    }
}
