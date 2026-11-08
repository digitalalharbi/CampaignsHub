<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

/**
 * GA4-GAP-EXPLAINED-001 — «قراءة الفرق» (was «سبب الفرق»): the gap between what the platforms claimed and what GA4 measured,
 * decomposed by MEASURED quantities only.
 *
 * ## The identity (conversions; the same terms in USD for revenue)
 *
 *   gap   = C − T        C: conversions the platforms' campaigns claimed · T: purchases GA4 measured in all
 *
 *   C = C_unmatched + C_matched               campaigns with no GA4 trace · campaigns GA4 can see
 *   T = P_matched + P_channel + O + U         GA4 purchases tied to those campaigns · to a platform but no
 *                                             campaign · to unknown paid sources · to no platform at all
 *
 *   gap = C_unmatched                          no_trace          widens
 *       + (C_matched − P_matched)              attribution_rule  widens or narrows
 *       − P_channel                            untagged_channel  narrows
 *       − (O + U)                              no_platform_trace narrows
 *
 * Four terms that sum EXACTLY to the gap (the arithmetic is tested to the cent), so nothing is counted
 * twice: each GA4 purchase and each claimed conversion is in exactly one term. `unexplained` is what the
 * terms leave: it is the difference between the gap and their sum, and is normally 0 — it is shown anyway,
 * as a chip, because a decomposition that cannot show its own remainder is a story.
 *
 * ## What is not a term, and is never given a number
 *
 * View-through, window length, double claims across platforms and conversion lag are the CAUSES inside the
 * attribution_rule term. GA4 cannot split them, so they are listed as «غير مقاس» (measured: false, value
 * null). Ratios in other units (click-to-session, untagged landing share, returning purchasers) are chips
 * with their own measured value and no share of the gap: turning sessions into conversions would be an
 * estimate. A store total is a chip only when the system holds one.
 *
 * Pure arithmetic: the caller passes scoped readings.
 */
final class Ga4GapExplainer
{
    /** key => [ar, en] */
    private const LABELS = [
        // REPORT-WEBSITE-GAP-READING-001 (Owner 2026-11-07) — tracking and attribution PATHS, never a verdict on the campaigns.
        'no_trace' => ['نقرات خارج التتبع', 'Clicks outside tracking'],
        'attribution_rule' => ['قاعدة الإسناد', 'Attribution rule'],
        'untagged_channel' => ['زيارات المنصة دون وسم', 'Platform visits, untagged'],
        'no_platform_trace' => ['مسار غير مباشر', 'Indirect path'],
        'unexplained' => ['غير مفسَّر', 'Unexplained'],
    ];

    /** @var array<string, array{0: string, 1: string}> */
    private const UNMEASURED = [
        'view_through' => ['مشاهدة دون نقر', 'View-through'],
        'window_difference' => ['فرق النافذة', 'Window length'],
        'double_claim' => ['ادّعاء مزدوج', 'Double claim'],
        'timing' => ['تأخر التحويل', 'Conversion lag'],
    ];

    /**
     * @param  array<string, mixed>  $match  the internal Ga4AttributionMatch reading
     * @param  array<string, mixed>  $context  see Ga4GapExplain::context()
     * @return array<string, mixed>
     */
    public function explain(array $match, array $context, bool $client = false): array
    {
        $claimed = ['conversions' => 0.0, 'revenue_usd' => 0.0];
        $excluded = 0;
        $terms = [
            'no_trace' => ['conversions' => 0.0, 'revenue_usd' => 0.0, 'rows' => []],
            'attribution_rule' => ['conversions' => 0.0, 'revenue_usd' => 0.0, 'rows' => []],
            'untagged_channel' => ['conversions' => 0.0, 'revenue_usd' => 0.0, 'rows' => []],
            'no_platform_trace' => ['conversions' => 0.0, 'revenue_usd' => 0.0, 'rows' => []],
        ];
        $usdOk = true;
        $measured = ['conversions' => 0.0, 'revenue_usd' => 0.0];

        foreach ((array) ($match['platforms'] ?? []) as $p) {
            foreach ((array) $p['campaigns'] as $c) {
                $conv = $c['claimed']['conversions'] ?? null;
                if ($conv === null) {
                    $excluded++;

                    continue;
                }
                $rev = $c['claimed']['revenue_usd'] ?? null;
                $claimed['conversions'] += $conv;
                $usdOk = $usdOk && $rev !== null;
                $claimed['revenue_usd'] += (float) $rev;
                if ($c['confidence'] === 'unmatched') {
                    $terms['no_trace']['conversions'] += $conv;
                    $terms['no_trace']['revenue_usd'] += (float) $rev;
                    $terms['no_trace']['rows'][] = $this->row($p['platform'], $c['name'], $conv, null, $client);

                    continue;
                }
                $g = $c['ga4'] ?? ['purchases' => 0.0, 'revenue_usd' => null];
                $usdOk = $usdOk && $g['revenue_usd'] !== null;
                $terms['attribution_rule']['conversions'] += $conv - $g['purchases'];
                $terms['attribution_rule']['revenue_usd'] += (float) $rev - (float) $g['revenue_usd'];
                $terms['attribution_rule']['rows'][] = $this->row($p['platform'], $c['name'], $conv, $g['purchases'], $client);
                $measured['conversions'] += $g['purchases'];
                $measured['revenue_usd'] += (float) $g['revenue_usd'];
            }
            if (is_array($p['channel']['ga4'] ?? null)) {
                $ch = $p['channel']['ga4'];
                $usdOk = $usdOk && $ch['revenue_usd'] !== null;
                $terms['untagged_channel']['conversions'] -= $ch['purchases'];
                $terms['untagged_channel']['revenue_usd'] -= (float) $ch['revenue_usd'];
                $terms['untagged_channel']['rows'][] = $this->row($p['platform'], null, null, $ch['purchases'], $client);
                $measured['conversions'] += $ch['purchases'];
                $measured['revenue_usd'] += (float) $ch['revenue_usd'];
            }
        }
        $buckets = [];
        if (is_array($match['other_paid']['ga4'] ?? null)) {
            $buckets[] = ['other_paid', $match['other_paid']['ga4']];
        }
        foreach ((array) ($match['unattributed']['classes'] ?? []) as $c) {
            $buckets[] = [$c['class'], $c];
        }
        foreach ($buckets as [$class, $b]) {
            $usdOk = $usdOk && $b['revenue_usd'] !== null;
            $terms['no_platform_trace']['conversions'] -= $b['purchases'];
            $terms['no_platform_trace']['revenue_usd'] -= (float) $b['revenue_usd'];
            $terms['no_platform_trace']['rows'][] = ['source' => $class, 'ga4' => $b['purchases']];
            $measured['conversions'] += $b['purchases'];
            $measured['revenue_usd'] += (float) $b['revenue_usd'];
        }

        $gap = round($claimed['conversions'] - $measured['conversions'], 2);
        $reasons = [];
        $bar = [];
        $sum = 0.0;
        foreach ($terms as $key => $t) {
            $v = round($t['conversions'], 2);
            $sum += $v;
            $reasons[] = [
                'key' => $key, 'label_ar' => self::LABELS[$key][0], 'label_en' => self::LABELS[$key][1],
                'measured' => true, 'value' => $v, 'value_usd' => $usdOk ? round($t['revenue_usd'], 2) : null,
                'share' => $gap != 0.0 ? round($v / $gap, 4) : null,
                'direction' => $v >= 0 ? 'widens' : 'narrows',
                'cause_unmeasured' => $key === 'attribution_rule',
                'rows' => $t['rows'],
            ];
            $bar[] = ['key' => $key, 'value' => $v, 'share' => $gap != 0.0 ? round($v / $gap, 4) : null];
        }
        $unexplained = round($gap - $sum, 2);
        $bar[] = ['key' => 'unexplained', 'value' => $unexplained, 'share' => $gap != 0.0 ? round($unexplained / $gap, 4) : null];

        $claimedConv = round($claimed['conversions'], 2);

        return [
            'basis' => 'ga4',
            'order_level' => false,
            'headline' => [
                'unit' => 'conversions',
                'claimed' => $claimedConv,
                'measured' => round($measured['conversions'], 2),
                'gap' => $gap,
                'gap_share' => $claimedConv > 0 ? round($gap / $claimedConv, 4) : null,
                'gap_usd' => $usdOk ? round($claimed['revenue_usd'] - $measured['revenue_usd'], 2) : null,
                'excluded_campaigns' => $excluded,
            ],
            'bar' => $bar,
            'reasons' => $reasons,
            'unexplained' => ['key' => 'unexplained', 'label_ar' => self::LABELS['unexplained'][0], 'label_en' => self::LABELS['unexplained'][1], 'value' => $unexplained],
            'unmeasured' => array_map(static fn (string $k, array $l): array => ['key' => $k, 'label_ar' => $l[0], 'label_en' => $l[1], 'measured' => false, 'value' => null], array_keys(self::UNMEASURED), array_values(self::UNMEASURED)),
            'context' => $this->context($context, $match),
            'check' => ['sum' => round($sum + $unexplained, 2), 'gap' => $gap],
        ];
    }

    /**
     * Ratios and facts in their own units. Each is measured or says it is not; none claims a share of the gap.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $match
     * @return list<array<string, mixed>>
     */
    private function context(array $c, array $match): array
    {
        $out = [];
        $clicks = [];
        foreach ((array) ($match['platforms'] ?? []) as $p) {
            $cl = $p['claimed']['clicks'] ?? null;
            $s = $p['ga4']['sessions'] ?? null;
            if ($cl !== null && $cl > 0 && $s !== null) {
                $clicks[] = ['platform' => $p['platform'], 'ratio' => round($s / $cl, 4)];
            }
        }
        $out[] = ['key' => 'click_to_session', 'measured' => $clicks !== [], 'value' => null, 'unit' => 'ratio', 'rows' => $clicks];

        $untagged = $c['untagged_share'] ?? null;
        $out[] = ['key' => 'untagged_landing', 'measured' => $untagged !== null, 'value' => $untagged, 'unit' => 'share'];

        $windows = (array) ($c['windows'] ?? []);
        $out[] = ['key' => 'platform_windows', 'measured' => $windows !== [], 'value' => null, 'unit' => 'text', 'rows' => array_map(static fn (string $p, array $w): array => ['platform' => $p, 'windows' => $w], array_keys($windows), array_values($windows))];

        $store = $c['store'] ?? null;
        $total = (float) array_sum(array_map(static fn (array $p): float => (float) ($p['ga4']['purchases'] ?? 0), (array) ($match['platforms'] ?? [])))
            + (float) ($match['other_paid']['ga4']['purchases'] ?? 0) + (float) array_sum(array_column((array) ($match['unattributed']['classes'] ?? []), 'purchases'));
        $out[] = $store === null
            ? ['key' => 'store_total', 'measured' => false, 'value' => null, 'unit' => 'orders', 'source' => 'ga4']
            : ['key' => 'store_total', 'measured' => true, 'value' => round((float) $store['orders'] - $total, 2), 'unit' => 'orders', 'store' => (float) $store['orders'], 'ga4' => round($total, 2)];

        $out[] = ['key' => 'returning', 'measured' => ($c['returning_share'] ?? null) !== null, 'value' => $c['returning_share'] ?? null, 'unit' => 'share'];
        $out[] = ['key' => 'currency_basis', 'measured' => ($c['currency'] ?? null) !== null, 'value' => null, 'unit' => 'text', 'currency' => $c['currency'] ?? null, 'usd' => $c['usd_basis'] ?? null];

        return $out;
    }

    /** @return array<string, mixed> */
    private function row(string $platform, ?string $name, ?float $claimed, ?float $ga4, bool $client): array
    {
        $row = ['platform' => $platform, 'claimed' => $claimed, 'ga4' => $ga4];
        if (! $client && $name !== null) {
            $row['campaign'] = $name;
        }

        return $row;
    }
}
