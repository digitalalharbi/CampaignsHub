<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services\Attention;

use App\Domains\Campaigns\Enums\ObjectiveFamily;

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — «what needs attention → the one thing to do», as figures.
 *
 * ## Objective-aware, per family and platform
 *
 * A finding is judged on the metrics its objective family is bought for, and on nothing else:
 *
 *   - awareness — CPM
 *   - traffic   — CTR, CPC, landing-page-view rate
 *   - leads     — CPL (a lead campaign's cost per result, never blended with sales)
 *   - sales     — CPA and ROAS
 *
 * A rising CPA on a brand campaign is not a finding; a rising CPM is. Families with no metric set here
 * (engagement, video, app, unclassified) produce nothing rather than being judged on a neighbour's.
 * Frequency is not judged: reach is not additive across days, and deriving frequency from summed
 * reach would be the averaged-ratio mistake the metric contract forbids.
 *
 * ## No finding from tiny volume
 *
 * Every ratio needs its denominator to be real in BOTH windows, and every family needs real spend in
 * both. A CPL that «doubled» from 3 leads to 2 is noise; saying so to a client teaches them to ignore
 * the section. Each floor is a named constant below.
 *
 * ## Pure
 *
 * Takes summed rows for the current and previous windows and returns items. No query, no clock, no
 * audience decision — the client filter is `AttentionAudience`, so the same list feeds every surface.
 */
final class AttentionFindings
{
    /** Neither window may be judged below this much spend, in the reporting currency. */
    public const MIN_SPEND = 100.0;

    /** CPM and CTR need this many impressions in both windows. */
    public const MIN_IMPRESSIONS = 10_000.0;

    /** CPC, LPV rate and conversion rate need this many clicks in both windows. */
    public const MIN_CLICKS = 100.0;

    /** A cost per lead / per sale needs this many results in both windows. */
    public const MIN_RESULTS = 10.0;

    /** A cost moving less than this is ordinary period-to-period variance. */
    public const COST_MATERIAL = 0.20;

    /** A rate (CTR, LPV rate, ROAS) moving less than this is ordinary variance. */
    public const RATE_MATERIAL = 0.15;

    /** Two platforms on one family whose costs differ by less than this are not worth moving money over. */
    public const SHIFT_GAP = 0.40;

    /** A client reads a short list. */
    public const MAX_ITEMS = 6;

    /**
     * metric => [kind, higher_is_better, material threshold]
     */
    private const METRICS = [
        'cpm' => ['money', false, self::COST_MATERIAL],
        'ctr' => ['percent', true, self::RATE_MATERIAL],
        'cpc' => ['money', false, self::COST_MATERIAL],
        'lpv_rate' => ['percent', true, self::RATE_MATERIAL],
        'cost_per_lpv' => ['money', false, self::COST_MATERIAL],
        'cpl' => ['money', false, self::COST_MATERIAL],
        'cpa' => ['money', false, self::COST_MATERIAL],
        'roas' => ['ratio', true, self::RATE_MATERIAL],
        'conversion_rate' => ['percent', true, self::RATE_MATERIAL],
    ];

    /** family => [judged metrics in priority order, supporting metrics shown beside them] */
    private const FAMILIES = [
        'awareness' => [['cpm'], []],
        'traffic' => [['ctr', 'lpv_rate', 'cpc'], ['cost_per_lpv']],
        'leads' => [['cpl'], ['conversion_rate']],
        'sales' => [['cpa', 'roas'], ['conversion_rate']],
    ];

    /** The cost a family is compared across platforms on, for the operator-only budget note. */
    private const FAMILY_COST = ['awareness' => 'cpm', 'traffic' => 'cpc', 'leads' => 'cpl', 'sales' => 'cpa'];

    /**
     * @param  list<array<string,mixed>>  $current  summed rows, one per (family, provider)
     * @param  list<array<string,mixed>>  $previous  the same for the previous window of equal length
     * @return list<array<string,mixed>>
     */
    public function build(array $current, array $previous, string $currency): array
    {
        $before = [];
        foreach ($previous as $row) {
            $before[$row['family'].'|'.$row['provider']] = $row;
        }

        $items = [];
        foreach ($current as $row) {
            $family = (string) $row['family'];
            if (! isset(self::FAMILIES[$family])) {
                continue;
            }
            $item = $this->judge($row, $before[$family.'|'.$row['provider']] ?? null, $currency);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        foreach ($this->budgetShifts($current, $currency) as $shift) {
            $items[] = $shift;
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($items, static fn (array $a, array $b): int => [$rank[$a['severity']], $a['nature'] === 'problem' ? 0 : 1, -$a['_spend']]
            <=> [$rank[$b['severity']], $b['nature'] === 'problem' ? 0 : 1, -$b['_spend']]);

        return array_map(static function (array $item): array {
            unset($item['_spend']);

            return $item;
        }, array_slice($items, 0, self::MAX_ITEMS));
    }

    /**
     * @param  array<string,mixed>  $cur
     * @param  array<string,mixed>|null  $prev
     * @return array<string,mixed>|null
     */
    private function judge(array $cur, ?array $prev, string $currency): ?array
    {
        // No previous window, or too little money in either, is silence — not a hedged claim.
        if ($prev === null || (float) $cur['spend'] < self::MIN_SPEND || (float) $prev['spend'] < self::MIN_SPEND) {
            return null;
        }

        $family = (string) $cur['family'];
        $provider = (string) $cur['provider'];

        // Results stopped: spend continued, the thing it was buying did not arrive.
        if (in_array($family, ['leads', 'sales'], true)
            && (float) $cur['results'] <= 0.0
            && (float) $prev['results'] >= self::MIN_RESULTS
            && ! self::moneyWithheld($cur)) {
            return $this->item($family, $provider, 'results_stopped', 'critical', 'problem', 'check_conversion_tracking',
                [$this->kpi('results', 'count', true, (float) $prev['results'], 0.0, null, true)],
                ['kind' => 'spend_without_results', 'amount' => round((float) $cur['spend'], 2), 'currency' => $currency],
                (float) $cur['spend']);
        }

        [$judged, $supporting] = self::FAMILIES[$family];

        $best = null;
        foreach ($judged as $metric) {
            $b = self::value($metric, $prev);
            $c = self::value($metric, $cur);
            if ($b === null || $c === null || $b <= 0.0) {
                continue;
            }
            [, $higherIsBetter, $material] = self::METRICS[$metric];
            $change = ($c - $b) / $b;
            if (abs($change) < $material) {
                continue;
            }
            $good = $higherIsBetter ? $change > 0 : $change < 0;
            $candidate = ['metric' => $metric, 'before' => $b, 'current' => $c, 'change' => $change, 'problem' => ! $good];

            // A problem outranks an opportunity; within the same kind, the larger movement wins.
            if ($best === null
                || ($candidate['problem'] && ! $best['problem'])
                || ($candidate['problem'] === $best['problem'] && abs($change) > abs($best['change']))) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            return null;
        }

        $metric = $best['metric'];
        $magnitude = abs($best['change']);
        $severity = $magnitude >= 0.5 ? 'critical' : ($magnitude >= 0.3 ? 'warning' : 'info');
        if (! $best['problem'] && $severity === 'critical') {
            // Good news is never an emergency.
            $severity = 'warning';
        }

        $kpis = [$this->kpi($metric, self::METRICS[$metric][0], self::METRICS[$metric][1], $best['before'], $best['current'], $currency, true)];
        foreach (array_merge($judged, $supporting) as $other) {
            if ($other === $metric) {
                continue;
            }
            $b = self::value($other, $prev);
            $c = self::value($other, $cur);
            if ($b === null && $c === null) {
                continue;
            }
            $kpis[] = $this->kpi($other, self::METRICS[$other][0], self::METRICS[$other][1], $b, $c, $currency, false);
        }

        $code = $metric.($best['change'] > 0 ? '_rise' : '_drop');
        $action = $best['problem'] ? $this->actionFor($metric, $prev, $cur) : 'keep_what_works';

        /*
         * Impact only where it is arithmetic on the stated figures: the extra a result cost this
         * period over last period's rate, times the results bought at it. Nothing for a rate or a
         * ROAS — that would assume what the money would have done, which nobody measured.
         */
        $impact = null;
        if ($best['problem'] && in_array($metric, ['cpl', 'cpa'], true)) {
            $impact = [
                'kind' => 'extra_cost_vs_previous_rate',
                'amount' => round(($best['current'] - $best['before']) * (float) $cur['results'], 2),
                'currency' => $currency,
            ];
        }

        return $this->item($family, $provider, $code, $severity, $best['problem'] ? 'problem' : 'opportunity', $action, $kpis, $impact, (float) $cur['spend']);
    }

    /**
     * Operator-only: the same family bought on two platforms at very different costs.
     *
     * Only between platforms that each cleared the volume floors this window. Never across families
     * — a platform buying awareness is not «cheaper» than one buying sales.
     *
     * @param  list<array<string,mixed>>  $current
     * @return list<array<string,mixed>>
     */
    private function budgetShifts(array $current, string $currency): array
    {
        $out = [];
        foreach (self::FAMILY_COST as $family => $metric) {
            $rated = [];
            foreach ($current as $row) {
                if ($row['family'] !== $family || (float) $row['spend'] < self::MIN_SPEND) {
                    continue;
                }
                $v = self::value($metric, $row);
                if ($v !== null && $v > 0.0) {
                    $rated[] = ['row' => $row, 'value' => $v];
                }
            }
            if (count($rated) < 2) {
                continue;
            }
            usort($rated, static fn ($a, $b) => $a['value'] <=> $b['value']);
            $cheap = $rated[0];
            $dear = $rated[count($rated) - 1];
            $gap = ($dear['value'] - $cheap['value']) / $dear['value'];
            if ($gap < self::SHIFT_GAP) {
                continue;
            }

            $kpi = $this->kpi($metric, 'money', false, null, $cheap['value'], $currency, true);
            $kpi['peer'] = ['platform' => (string) $dear['row']['provider'], 'value' => round($dear['value'], 4)];

            $out[] = $this->item($family, (string) $cheap['row']['provider'], 'budget_shift', 'info', 'opportunity', 'shift_budget', [$kpi], null,
                (float) $cheap['row']['spend']);
        }

        return $out;
    }

    /** Which one action a problem calls for — operator-internal only where the cause is the buying. */
    private function actionFor(string $metric, array $prev, array $cur): string
    {
        return match ($metric) {
            'ctr' => 'refresh_creative',
            'lpv_rate' => 'review_landing_page',
            // A click got dearer while people kept clicking at the same rate: the creative did not
            // change, the auction did. That is bid strategy, and it is the agency's to handle.
            'cpc' => self::steady('ctr', $prev, $cur) ? 'review_bidding' : 'review_cost_drivers',
            default => 'review_cost_drivers',
        };
    }

    private static function steady(string $metric, array $prev, array $cur): bool
    {
        $b = self::value($metric, $prev);
        $c = self::value($metric, $cur);

        return $b !== null && $c !== null && $b > 0.0 && abs(($c - $b) / $b) < self::RATE_MATERIAL;
    }

    /** A ratio from summed numerator and denominator, or null where either floor is not met. */
    private static function value(string $metric, array $row): ?float
    {
        $spend = self::moneyWithheld($row) ? null : (float) $row['spend'];
        $impressions = (float) $row['impressions'];
        $clicks = (float) $row['clicks'];
        $lpv = (float) $row['landing_page_views'];
        $results = (float) $row['results'];

        return match ($metric) {
            'cpm' => $spend !== null && $impressions >= self::MIN_IMPRESSIONS ? $spend / $impressions * 1000 : null,
            'ctr' => $impressions >= self::MIN_IMPRESSIONS && $clicks >= self::MIN_CLICKS ? $clicks / $impressions : null,
            'cpc' => $spend !== null && $clicks >= self::MIN_CLICKS ? $spend / $clicks : null,
            'lpv_rate' => $clicks >= self::MIN_CLICKS && $lpv > 0.0 ? $lpv / $clicks : null,
            'cost_per_lpv' => $spend !== null && $lpv >= self::MIN_CLICKS ? $spend / $lpv : null,
            'cpl', 'cpa' => $spend !== null && $results >= self::MIN_RESULTS ? $spend / $results : null,
            'roas' => $spend !== null && ($row['revenue_withheld_rows'] ?? 0) === 0 && $results >= self::MIN_RESULTS && (float) $row['revenue'] > 0.0
                ? (float) $row['revenue'] / $spend : null,
            'conversion_rate' => $clicks >= self::MIN_CLICKS && $results > 0.0 ? $results / $clicks : null,
            default => null,
        };
    }

    private static function moneyWithheld(array $row): bool
    {
        return (int) ($row['spend_withheld_rows'] ?? 0) > 0;
    }

    /** @return array<string,mixed> */
    private function kpi(string $key, string $kind, bool $higherIsBetter, ?float $before, ?float $current, ?string $currency, bool $primary): array
    {
        $round = static fn (?float $v): ?float => $v === null ? null : round($v, 4);

        return [
            'key' => $key,
            'kind' => $kind,
            'before' => $round($before),
            'current' => $round($current),
            'change' => $before !== null && $current !== null && $before > 0.0 ? round(($current - $before) / $before, 4) : null,
            'currency' => $kind === 'money' ? $currency : null,
            'higher_is_better' => $higherIsBetter,
            'primary' => $primary,
        ];
    }

    /** @return array<string,mixed> */
    private function item(string $family, string $provider, string $code, string $severity, string $nature, string $action, array $kpis, ?array $impact, float $spend): array
    {
        $labels = (ObjectiveFamily::tryFrom($family) ?? ObjectiveFamily::Unknown)->label();

        return [
            'key' => substr(hash('sha256', $family.'|'.$provider.'|'.$code), 0, 16),
            'code' => $code,
            'severity' => $severity,
            'nature' => $nature,
            'family' => $family,
            'family_label_ar' => $labels['ar'],
            'family_label_en' => $labels['en'],
            'platform' => $provider,
            'kpis' => $kpis,
            'action' => $action,
            'audience' => AttentionActions::isClientSafe($action) ? 'client' : 'operator',
            'impact' => $impact,
            'evidence' => ['platform' => $provider, 'content' => []],
            '_spend' => $spend,
        ];
    }
}
