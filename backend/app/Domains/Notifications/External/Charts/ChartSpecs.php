<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Charts;

/**
 * The chart configuration of an external message (spec §11), built from the SAME figures the message prints — the
 * composer's view model and the data source — so a chart can never disagree with its own table. Kinds mirror the UI's:
 * donut (platform split, total in the middle), line/area (trend, previous period faded), progress ring (budget).
 */
final class ChartSpecs
{
    /**
     * @param  array<string, mixed>  $model  ReportView::build()
     * @param  array<string, mixed>  $data  DigestDataSource::project()
     * @return list<array<string, mixed>>
     */
    public static function for(array $model, array $data): array
    {
        $specs = [];
        $rtl = ($model['dir'] ?? 'rtl') === 'rtl';
        if (is_array($model['platforms'] ?? null)) {
            $specs[] = [
                'key' => 'donut', 'kind' => 'donut', 'rtl' => $rtl, 'center' => (string) $model['platforms']['total'],
                'segments' => array_values(array_map(static fn (array $r): array => ['provider' => (string) $r['provider'], 'label' => (string) $r['label'], 'value' => (float) $r['raw']], $model['platforms']['rows'])),
            ];
        }
        if (is_array($model['trend'] ?? null)) {
            $specs[] = [
                'key' => 'trend', 'kind' => 'trend', 'rtl' => $rtl,
                'values' => array_values(array_map(static fn (array $p): ?float => is_numeric($p['spend'] ?? null) ? (float) $p['spend'] : null, (array) ($data['series'] ?? []))),
                'previous' => array_values(array_map(static fn (array $p): ?float => is_numeric($p['spend'] ?? null) ? (float) $p['spend'] : null, (array) ($data['previous_series'] ?? []))),
                'first' => (string) (($data['series'][0]['date'] ?? '') ?: ''), 'last' => (string) ((end($data['series']) ?: [])['date'] ?? ''),
            ];
        }
        if (is_array($model['opening']['ring'] ?? null)) {
            $specs[] = ['key' => 'ring', 'kind' => 'ring', 'rtl' => $rtl, 'ratio' => (float) $model['opening']['ring']['ratio'], 'label' => (string) round((float) $model['opening']['ring']['ratio'] * 100).'%'];
        }

        return $specs;
    }
}
