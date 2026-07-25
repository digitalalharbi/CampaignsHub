<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Metrics\Models\MetricDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds the global metric catalogue. Base metrics are additive (safe to sum across days/campaigns);
 * derived ratios are non-additive and must be recomputed at aggregation time, never summed.
 */
final class MetricDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = [
            // key, name, unit, value_type, aggregation, is_currency, is_additive
            ['impressions', 'Impressions', 'count', 'integer', 'sum', false, true],
            ['clicks', 'Clicks', 'count', 'integer', 'sum', false, true],
            ['spend', 'Spend', 'currency', 'decimal', 'sum', true, true],
            ['conversions', 'Conversions', 'count', 'decimal', 'sum', false, true],
            ['revenue', 'Revenue', 'currency', 'decimal', 'sum', true, true],
            ['video_views', 'Video Views', 'count', 'integer', 'sum', false, true],
            ['engagements', 'Engagements', 'count', 'integer', 'sum', false, true],
            // Derived — non-additive; recomputed from base metrics at aggregation time.
            ['ctr', 'Click-Through Rate', 'ratio', 'decimal', 'avg', false, false],
            ['cpc', 'Cost per Click', 'currency', 'decimal', 'avg', true, false],
            ['cpm', 'Cost per Mille', 'currency', 'decimal', 'avg', true, false],
            ['cpa', 'Cost per Acquisition', 'currency', 'decimal', 'avg', true, false],
            ['roas', 'Return on Ad Spend', 'ratio', 'decimal', 'avg', false, false],
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
