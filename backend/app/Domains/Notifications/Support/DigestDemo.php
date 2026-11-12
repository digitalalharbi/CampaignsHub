<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * DIGESTS-VISUAL-V2-001 — a believable portfolio, built from arrays, for previews and tests.
 *
 * Shaped exactly like `DailyDigest::buildRange()` so the real model and the real views render it, with no database and no
 * sending. Deterministic: project N is always the same project, so a test can say «project 3 is the one whose sync stopped».
 * Thirty unalike projects across up to six platforms, with every state the rule knows on purpose.
 */
final class DigestDemo
{
    private const NAMES = [
        'متجر الندى', 'مطاعم الرواد', 'عيادات الشفاء', 'عقارات الأفق', 'أزياء لمار', 'قهوة الصباح', 'مدارس النخبة', 'سفر وسياحة الرحلات',
        'سيارات الخليج', 'مجوهرات الماسة', 'تطبيق وصّل', 'بنك الادخار', 'أثاث البيت', 'مستحضرات سحر', 'نادي القوة', 'مكتبة الفكر',
        'حلويات الفرح', 'إلكترونيات التقنية', 'مركز الأسنان', 'شركة التطوير', 'عطور الشرق', 'ألعاب الصغار', 'مغسلة النظافة', 'مزارع الخير',
        'تأمين الأمان', 'صيدلية الحياة', 'لوازم الحيوانات', 'ورش البناء', 'معهد اللغات', 'منتجع الواحة',
    ];

    private const PLATFORMS = ['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin'];

    private const OBJECTIVES = ['sales', 'leads', 'sales', 'awareness', 'leads', 'traffic'];

    /**
     * @return array<string,mixed> the payload `OperationalDigestModel::portfolio()` takes
     */
    public static function payload(int $projects = 30, int $platforms = 6, bool $weekly = false, bool $monthly = false): array
    {
        // DIGESTS-CADENCE-BUDGETS-001 — the monthly fixture is October 2026 whole (sent on 1 November), plain calendar days.
        $days = $monthly
            ? array_map(static fn (int $d): string => sprintf('2026-10-%02d', $d), range(1, 31))
            : ['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09'];
        $blocks = [];
        for ($i = 0; $i < $projects; $i++) {
            $blocks[] = self::project($i, min($platforms, 6 - ($i % 3 === 2 ? 1 : 0) - ($i % 7 === 6 ? 1 : 0)), $days);
            $blocks[$i]['budget_month'] = self::budgetMonth($i, (float) $blocks[$i]['totals']['spend'], $monthly);
        }
        $spend = array_sum(array_map(static fn (array $b): float => (float) $b['totals']['spend'], $blocks));
        $prev = array_sum(array_map(static fn (array $b): float => (float) $b['previous']['spend'], $blocks));
        $conv = array_sum(array_map(static fn (array $b): float => (float) ($b['totals']['conversions'] ?? 0), $blocks));
        $convPrev = array_sum(array_map(static fn (array $b): float => (float) ($b['previous']['conversions'] ?? 0), $blocks));

        $n = count($days);

        return [
            'sendable' => true, 'reason' => null, 'date' => $days[0], 'to_date' => $days[$n - 1], 'days' => $n, 'previous_date' => $monthly ? '2026-09-01' : '2026-10-27', 'currency' => 'SAR',
            'projects' => $blocks,
            'totals' => [
                'spend' => $spend, 'conversions' => $conv, 'revenue' => 0.0,
                'change' => ['spend' => $prev > 0 ? round(($spend - $prev) / $prev, 4) : null, 'conversions' => $convPrev > 0 ? round(($conv - $convPrev) / $convPrev, 4) : null],
                'platforms' => [],
                'series' => array_map(static fn (string $d, int $k): array => ['date' => $d, 'spend' => round($spend / $n * (0.85 + 0.05 * (($k * 3) % 5)), 2)], $days, array_keys($days)),
            ],
        ];
    }

    /**
     * @param  list<string>  $days
     * @return array<string,mixed>
     */
    private static function project(int $i, int $nPlatforms, array $days): array
    {
        $objective = self::OBJECTIVES[$i % count(self::OBJECTIVES)];
        $name = self::NAMES[$i % count(self::NAMES)];
        $providers = array_slice(self::PLATFORMS, 0, max(2, $nPlatforms));
        // Rotate which platforms a project has, so the matrix is not a perfect rectangle.
        $providers = array_values(array_filter($providers, static fn (string $p, int $k): bool => ! ($i % 5 === 4 && $k === 4), ARRAY_FILTER_USE_BOTH));
        $result = match ($objective) {
            'sales' => ['purchases', 'roas'], 'leads' => ['leads', 'cpl'], 'traffic' => ['clicks', 'cpc'], default => ['impressions', 'cpm'],
        };

        $rows = [];
        $prev = [];
        $series = [];
        $budget = [];
        $sources = [];
        $failing = [];
        $total = 0.0;
        $totalPrev = 0.0;
        $conv = 0.0;
        $convPrev = 0.0;
        foreach ($providers as $k => $provider) {
            $spend = 800.0 + (($i * 37 + $k * 211) % 4200);
            $before = round($spend * (0.9 + (($i + $k) % 4) * 0.05), 2);
            [$rKey, $hKey] = $result;
            $results = (float) (10 + (($i * 13 + $k * 7) % 90));
            $resultsBefore = round($results * 1.05);
            $head = match ($hKey) {
                'roas' => 3.0 + (($i + $k) % 5) * 0.4, 'cpl' => 40.0 + (($i * 3 + $k) % 6) * 6, 'cpc' => 1.1 + (($i + $k) % 4) * 0.2, default => 3.0 + (($i + $k) % 3) * 0.3,
            };
            $headBefore = $head;
            $state = 'ok';
            // The rule's cases, spread over the portfolio on purpose.
            if ($i % 10 === 3 && $k === 1) {
                $state = 'failed';
            } elseif ($i % 10 === 5 && $k === 0) {
                $results = 0.0;
                $head = null;
            } elseif ($i % 6 === 1 && $k === 2) {
                $headBefore = $hKey === 'roas' ? $head * 1.7 : $head / 1.6; // cost rose ≥ 40% / return fell ≥ 40%
            } elseif ($i % 8 === 2 && $k === 3) {
                $headBefore = $hKey === 'roas' ? $head * 1.28 : $head / 1.27;
            } elseif ($i % 9 === 4 && $k === 0) {
                $state = 'stale';
            }
            if ($i % 7 === 0 && $k === 1) {
                $budget[] = ['provider' => $provider, 'pace' => 1.67, 'spent' => $spend, 'budget' => round($spend / 0.8, 2)];
            } elseif ($i % 11 === 2 && $k === 0) {
                $budget[] = ['provider' => $provider, 'pace' => 0.42, 'spent' => $spend, 'budget' => round($spend / 0.3, 2)];
            }
            if ($state !== 'failed') {
                $rows[] = ['provider' => $provider, 'spend' => $spend, 'spend_withheld_rows' => 0, 'revenue_withheld_rows' => 0, $rKey => $results, $hKey => $head, 'impressions' => 90000 + $k * 1000, 'clicks' => 2000 + $k * 100, 'ctr' => 0.02];
                $prev[] = ['provider' => $provider, 'spend' => $before, 'spend_withheld_rows' => 0, 'revenue_withheld_rows' => 0, $rKey => $resultsBefore, $hKey => $headBefore, 'impressions' => 88000, 'clicks' => 1900, 'ctr' => 0.02];
                $series[$provider] = array_map(static fn (string $d, int $j): array => ['date' => $d, 'spend' => round($spend / count($days) * (0.8 + (($j + $k + $i) % 5) * 0.1), 2)], $days, array_keys($days));
                $total += $spend;
                $totalPrev += $before;
                $conv += $results;
                $convPrev += $resultsBefore;
            }
            $sources[] = ['provider' => $provider, 'kind' => 'ad_platform', 'state' => $state === 'failed' ? 'failed' : ($state === 'stale' ? 'stale' : 'fresh')];
            if ($state === 'failed') {
                $failing[] = ['name' => $provider, 'provider' => $provider];
            }
        }

        // Two projects with no figures at all and no source — they must still be in the matrix as «–».
        if ($i % 15 === 12) {
            $rows = $prev = $series = $budget = $sources = $failing = [];
            $total = $totalPrev = $conv = $convPrev = 0.0;
        }

        $totals = ['spend' => $total, 'conversions' => $conv] + [$result[1] => $total > 0 ? round($total / max(1, $conv), 2) : null];
        $previous = ['spend' => $totalPrev, 'conversions' => $convPrev] + [$result[1] => $totalPrev > 0 ? round($totalPrev / max(1, $convPrev), 2) : null];
        $change = static fn (float $a, float $b): ?float => $b > 0 ? round(($a - $b) / $b, 4) : null;

        // DIGESTS-OBJECTIVE-KPIS-001 — every fourth project is MIXED: its last two platforms carry an awareness campaign, the rest the project's own objective.
        $groups = [];
        if ($rows !== []) {
            $mixed = $i % 4 === 0 && count($rows) >= 3;
            $mainRows = $mixed ? array_slice($rows, 0, count($rows) - 2) : $rows;
            $mainPrev = $mixed ? array_slice($prev, 0, count($rows) - 2) : $prev;
            $sum = static fn (array $r, string $k): float => array_sum(array_map(static fn (array $x): float => (float) ($x[$k] ?? 0), $r));
            $tot = static function (array $r) use ($result, $sum): array {
                $spend = $sum($r, 'spend');
                $res = $sum($r, $result[0]);
                $base = ['spend' => $spend, $result[0] => $res, 'impressions' => $sum($r, 'impressions'), 'clicks' => $sum($r, 'clicks')];
                if ($result[0] === 'purchases') {
                    $base += ['revenue' => $spend * 3.4, 'roas' => 3.4, 'cpa' => $res > 0 ? round($spend / $res, 2) : null, 'aov' => 210.0];
                }
                if ($result[0] === 'leads') {
                    $base += ['cpl' => $res > 0 ? round($spend / $res, 2) : null, 'qualified_leads' => (int) round($res * 0.4)];
                }
                if ($result[0] === 'clicks') {
                    $base += ['ctr' => 0.02, 'cpc' => 1.3, 'landing_page_views' => (int) round($res * 0.8)];
                }

                return $base;
            };
            $groups[] = ['objective' => $objective, 'campaigns' => 3, 'totals' => $tot($mainRows), 'previous' => $tot($mainPrev), 'reported' => [], 'platform_rows' => $mainRows, 'platform_previous' => $mainPrev, 'platform_reported' => [],
                'funnel' => $objective === 'sales' ? [['stage' => 'impressions', 'count' => 900000, 'step_rate' => null], ['stage' => 'clicks', 'count' => 21000, 'step_rate' => 0.023], ['stage' => 'purchases', 'count' => 640, 'step_rate' => 0.03]] : []];
            if ($mixed) {
                $awRows = array_map(static fn (array $r): array => ['provider' => $r['provider'], 'spend' => $r['spend'], 'spend_withheld_rows' => 0, 'impressions' => 600000, 'reach' => 210000, 'frequency' => 2.9, 'cpm' => 4.6], array_slice($rows, -2));
                $awPrev = array_map(static fn (array $r): array => $r + ['impressions' => 560000, 'cpm' => 4.2], array_map(static fn (array $r): array => ['provider' => $r['provider'], 'spend' => $r['spend'] * 0.95, 'spend_withheld_rows' => 0], array_slice($rows, -2)));
                $groups[] = ['objective' => 'awareness', 'campaigns' => 2, 'totals' => ['spend' => $sum($awRows, 'spend'), 'impressions' => 1200000, 'reach' => 420000, 'frequency' => 2.9, 'cpm' => 4.6], 'previous' => ['spend' => $sum($awPrev, 'spend'), 'impressions' => 1100000, 'cpm' => 4.2],
                    'reported' => [], 'platform_rows' => $awRows, 'platform_previous' => $awPrev, 'platform_reported' => [], 'funnel' => []];
            }
        }

        return [
            'project_id' => sprintf('00000000-0000-4000-8000-%012d', $i + 1),
            'project_name' => $name,
            'currency' => 'SAR',
            'objective' => $objective,
            'totals' => $totals,
            'previous' => $previous,
            'reported' => ['spend' => true],
            'change' => ['spend' => $change($total, $totalPrev), 'conversions' => $change($conv, $convPrev)],
            'platform_rows' => $rows,
            'platform_previous' => $prev,
            'platform_reported' => [],
            'platform_series' => $series,
            'budget_rows' => $budget,
            'series' => array_map(static fn (string $d, int $j): array => ['date' => $d, 'spend' => $total > 0 ? round($total / count($days) * (0.85 + (($j + $i) % 5) * 0.08), 2) : null], $days, array_keys($days)),
            'groups' => $groups,
            'funnel' => [],
            'creatives' => null,
            'follow_up' => null,
            'observations' => [],
            'freshness' => [
                'state' => in_array('stale', array_column($sources, 'state'), true) ? 'stale' : ($failing !== [] ? 'sync_failed' : ($sources === [] ? 'no_data' : 'fresh')),
                'last_sync_at' => '2026-11-09T05:00:00Z', 'missing_days' => 0, 'sync_failed' => $failing !== [], 'failing' => $failing, 'sources' => $sources,
            ],
        ];
    }

    /**
     * DIGESTS-CADENCE-BUDGETS-001 — the month's budget block as `DailyDigest::monthBudget()` returns it, every case on purpose:
     * ahead, on track, behind, over budget, no budget, a withheld spend and two budget currencies.
     *
     * @return array<string,mixed>
     */
    private static function budgetMonth(int $i, float $windowSpend, bool $monthly): array
    {
        $elapsed = $monthly ? 1.0 : 0.3; // 9 of 30 November days, or the whole of October
        $base = ['from' => $monthly ? '2026-10-01' : '2026-11-01', 'to' => $monthly ? '2026-10-31' : '2026-11-30', 'through' => $monthly ? '2026-10-31' : '2026-11-09',
            'elapsed' => $elapsed, 'withheld' => false, 'excluded' => 0, 'unmeasured' => 0, 'campaigns' => 3, 'currencies' => 1];
        if ($i % 15 === 12 || $windowSpend <= 0.0) {
            return ['budget' => null, 'spent' => null, 'remaining' => null, 'projected' => null, 'pace' => null, 'currency' => null, 'currencies' => 0, 'campaigns' => 0] + $base;
        }
        $spent = round($windowSpend * ($monthly ? 1.0 : 9 / 7), 2);
        if ($i % 17 === 9) {
            return ['budget' => null, 'spent' => null, 'remaining' => null, 'projected' => null, 'pace' => null, 'currency' => null, 'currencies' => 2] + $base;
        }
        $pace = [1.25, 1.0, 0.7, 1.05, 0.95, 1.4][$i % 6];
        $budget = round($spent / $elapsed / $pace, -2);
        if ($i % 13 === 7) {
            // Budgets committed, no spend figure yet this month.
            return ['budget' => $budget, 'spent' => null, 'remaining' => null, 'projected' => null, 'pace' => null, 'currency' => 'SAR', 'unmeasured' => 2] + $base;
        }
        if ($i % 11 === 5) {
            // Every budgeted campaign's spend is in another currency than its budget: budgets exist, no figure can be stated.
            return ['budget' => null, 'spent' => null, 'remaining' => null, 'projected' => null, 'pace' => null, 'currency' => null, 'currencies' => 0,
                'excluded' => 2, 'outside' => 2, 'outside_reasons' => ['currency_mismatch' => 2]] + $base;
        }
        $projected = round($spent / $elapsed, 2);
        // Some projects carry one campaign left out of the figure (spend without an exchange rate), counted on the row.
        $outside = $i % 7 === 3 ? ['excluded' => 1, 'outside' => 1, 'outside_reasons' => ['partial' => 1]] : [];

        return $outside + ['budget' => $budget, 'spent' => $spent, 'remaining' => round($budget - $spent, 2), 'projected' => $projected, 'pace' => round($projected / $budget, 4), 'currency' => 'SAR'] + $base;
    }
}
