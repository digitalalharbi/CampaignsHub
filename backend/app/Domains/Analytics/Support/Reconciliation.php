<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Support;

/**
 * ANALYTICS-DATA-001 — one shaping of the three-source comparison, used by the site read and the attribution read so
 * the two can never disagree about a platform's gap.
 *
 * Input is `AttributionTransparency::threeSources()`. Each cell keeps its own source; `summed` is false because the
 * three are three counts of the same sales, and the gap is derived from the two cells it names.
 */
final class Reconciliation
{
    /**
     * @param  array<string, mixed>  $three
     * @return array<string, mixed>
     */
    public static function from(array $three): array
    {
        $rows = [];
        foreach ($three['rows'] as $r) {
            $claim = $r['platform_reported'];
            $site = $r['ga4_measured'];
            $store = $r['store_confirmed'];
            $rows[] = [
                'platform' => $r['platform'],
                'platform_reported' => $claim === null ? null : $claim + ['source' => Source::PLATFORM_REPORTED],
                'ga4_confirmed' => $site === null ? null : $site + ['source' => Source::GA4],
                'store_confirmed' => $store === null ? null : $store + ['source' => Source::STORE_CONFIRMED],
                'gap' => [
                    'platform_minus_ga4_orders' => $claim !== null && $site !== null ? round($claim['orders'] - $site['orders'], 2) : null,
                    'platform_minus_store_orders' => $claim !== null && $store !== null ? round($claim['orders'] - $store['orders'], 2) : null,
                    'ga4_share_of_claim' => $claim !== null && $site !== null && $claim['orders'] > 0 ? round($site['orders'] / $claim['orders'], 4) : null,
                    'store_share_of_claim' => $claim !== null && $store !== null && $claim['orders'] > 0 ? round($store['orders'] / $claim['orders'], 4) : null,
                    'source' => 'derived_from_the_cells_above',
                ],
            ];
        }

        return [
            'summed' => false,
            'available' => $three['available'],
            'rows' => $rows,
            'ga4_other_paid' => $three['other_paid'],
            'store_unattributed' => $three['store_unattributed'],
        ];
    }
}
