<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

/**
 * SPEND-ACCURACY-001 — provider spend against stored spend, day by day and campaign by campaign.
 *
 * Pure: it takes what the provider returned and what we hold and says where they differ, with the
 * numbers. It changes nothing and fetches nothing, so the same comparison backs the read-only
 * `integrations:spend-reconcile` command and its tests.
 */
final class SpendReconciliation
{
    /**
     * @param  list<array{campaign_id: string, date: string, spend: float|int|string|null}>  $provider  one row per campaign per day, as the connector parsed them
     * @param  list<array{campaign_id: string, date: string, spend: float|int|string}>  $stored  the same grain from daily_metrics (project currency)
     * @return array{provider_total: float, stored_total: float, diff: float, diff_pct: float|null, days: list<array{date: string, provider: float, stored: float, diff: float}>, campaigns: list<array{campaign_id: string, provider: float, stored: float, diff: float}>, only_provider: list<string>, only_stored: list<string>}
     */
    public static function compare(array $provider, array $stored): array
    {
        $pDay = $pCamp = $sDay = $sCamp = [];

        foreach ($provider as $r) {
            $v = (float) ($r['spend'] ?? 0);
            $pDay[$r['date']] = ($pDay[$r['date']] ?? 0.0) + $v;
            $pCamp[$r['campaign_id']] = ($pCamp[$r['campaign_id']] ?? 0.0) + $v;
        }

        foreach ($stored as $r) {
            $v = (float) $r['spend'];
            $sDay[$r['date']] = ($sDay[$r['date']] ?? 0.0) + $v;
            $sCamp[$r['campaign_id']] = ($sCamp[$r['campaign_id']] ?? 0.0) + $v;
        }

        $dates = array_unique([...array_keys($pDay), ...array_keys($sDay)]);
        sort($dates);

        $days = [];
        foreach ($dates as $d) {
            $p = round($pDay[$d] ?? 0.0, 2);
            $s = round($sDay[$d] ?? 0.0, 2);
            $days[] = ['date' => $d, 'provider' => $p, 'stored' => $s, 'diff' => round($s - $p, 2)];
        }

        $ids = array_unique([...array_keys($pCamp), ...array_keys($sCamp)]);
        $campaigns = [];
        foreach ($ids as $id) {
            $p = round($pCamp[$id] ?? 0.0, 2);
            $s = round($sCamp[$id] ?? 0.0, 2);
            $campaigns[] = ['campaign_id' => (string) $id, 'provider' => $p, 'stored' => $s, 'diff' => round($s - $p, 2)];
        }
        usort($campaigns, static fn (array $a, array $b): int => abs($b['diff']) <=> abs($a['diff']));

        $pt = round(array_sum($pDay), 2);
        $st = round(array_sum($sDay), 2);

        return [
            'provider_total' => $pt,
            'stored_total' => $st,
            'diff' => round($st - $pt, 2),
            'diff_pct' => $pt > 0 ? round(($st - $pt) / $pt * 100, 2) : null,
            'days' => $days,
            'campaigns' => $campaigns,
            'only_provider' => array_values(array_map('strval', array_diff(array_keys($pCamp), array_keys($sCamp)))),
            'only_stored' => array_values(array_map('strval', array_diff(array_keys($sCamp), array_keys($pCamp)))),
        ];
    }
}
