<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Services\DigestPresenter;
use App\Support\AdPlatforms;

/**
 * EMAIL-DIGESTS-OPERATIONAL-001 — what the operational digests say, as view models.
 *
 * The input is the payload `DailyDigest::buildRange()` already produces from `MetricsAggregator` — the
 * engine behind the live report link — so there is no second pipeline here and this class touches no
 * database. It only chooses WHICH of those figures an objective is judged on, orders by what needs a
 * person, and turns each into the text a reader needs with no metric keys, no English codes and no
 * «go to the platform».
 *
 * ## Rules it keeps
 *
 * - A figure that did not arrive is «لا بيانات» / «No data» — never `0`. A platform that sent no
 *   `reach` says so; spend with a withheld row (no exchange rate) is withheld, not understated.
 * - Cost metrics are coloured as good when they FALL.
 * - Per-platform rows are the project's own `byProvider()` rows, untouched: the numbers equal the live
 *   report's «platforms» section for the same project and window (`OperationalDigestReconciliationTest`).
 * - Adding X or LinkedIn is data only: a platform is a row of the payload, named by `AdPlatforms`.
 */
final class OperationalDigestModel
{
    /** @var array<string, array{0: string, 1: string, 2: string, 3?: bool}> key → [ar, en, kind, lowerIsBetter] */
    private const FIG = [
        'spend' => ['الإنفاق', 'Spend', 'money'],
        'purchases' => ['الطلبات', 'Orders', 'count'],
        'revenue' => ['قيمة الطلبات', 'Order value', 'money'],
        'roas' => ['العائد على الإنفاق', 'Return on ad spend', 'ratio'],
        'leads' => ['العملاء المحتملون', 'Leads', 'count'],
        'cpl' => ['تكلفة العميل المحتمل', 'Cost per lead', 'money', true],
        'clicks' => ['النقرات', 'Clicks', 'count'],
        'cpc' => ['تكلفة النقرة', 'Cost per click', 'money', true],
        'impressions' => ['الظهور', 'Impressions', 'count'],
        'reach' => ['الوصول', 'Reach', 'count'],
        'cpm' => ['تكلفة الألف ظهور', 'Cost per 1,000', 'money', true],
        'installs' => ['التثبيتات', 'Installs', 'count'],
        'cpi' => ['تكلفة التثبيت', 'Cost per install', 'money', true],
        'engagements' => ['التفاعلات', 'Engagements', 'count'],
        'engagement_rate' => ['معدل التفاعل', 'Engagement rate', 'percent'],
        'video_views' => ['مشاهدات الفيديو', 'Video views', 'count'],
        'video_completion_rate' => ['معدل الإكمال', 'Completion rate', 'percent'],
        'ctr' => ['معدل النقر', 'Click-through rate', 'percent'],
        'conversions' => ['النتائج', 'Results', 'count'],
        'cpa' => ['تكلفة النتيجة', 'Cost per result', 'money', true],
    ];

    /** What each objective is judged on, per platform, after spend. The first is the project's headline figure. */
    public const BY_OBJECTIVE = [
        'sales' => ['purchases', 'revenue', 'roas'],
        'leads' => ['leads', 'cpl'],
        'traffic' => ['clicks', 'cpc'],
        'awareness' => ['impressions', 'reach', 'cpm'],
        'app_installs' => ['installs', 'cpi'],
        'engagement' => ['engagements', 'engagement_rate'],
        'video' => ['video_views', 'video_completion_rate'],
    ];

    private const FALLBACK = ['impressions', 'clicks', 'ctr'];

    /** The ONE figure that heads a project's row, per objective (the Moasher-style headline block). */
    private const HEADLINE = [
        'sales' => 'roas', 'leads' => 'cpl', 'traffic' => 'cpc', 'awareness' => 'cpm',
        'app_installs' => 'cpi', 'engagement' => 'engagement_rate', 'video' => 'video_completion_rate',
    ];

    private const OBJECTIVE_LABEL = [
        'sales' => ['المبيعات', 'Sales'], 'leads' => ['العملاء المحتملون', 'Leads'], 'traffic' => ['الزيارات', 'Traffic'],
        'awareness' => ['الوعي', 'Awareness'], 'app_installs' => ['تثبيت التطبيق', 'App installs'],
        'engagement' => ['التفاعل', 'Engagement'], 'video' => ['مشاهدات الفيديو', 'Video views'], 'custom' => ['أهداف متعددة', 'Mixed objectives'],
    ];

    private const TONE_RANK = ['bad' => 0, 'warn' => 1, 'neutral' => 2, 'good' => 3];

    public static function objectiveLabel(string $objective, bool $ar): string
    {
        return (self::OBJECTIVE_LABEL[$objective] ?? self::OBJECTIVE_LABEL['custom'])[$ar ? 0 : 1];
    }

    public static function statusLabel(string $tone, bool $ar): string
    {
        return match ($tone) {
            'bad' => $ar ? 'خطر' : 'At risk',
            'warn' => $ar ? 'انتباه' : 'Watch',
            'good' => $ar ? 'ممتاز' : 'Excellent',
            default => $ar ? 'مستقر' : 'Steady',
        };
    }

    /**
     * One project, in full.
     *
     * @param  array<string,mixed>  $block  one entry of `DailyDigest::buildRange()['projects']`
     * @return array<string,mixed>
     */
    public static function project(array $block, string $lang, int $days = 7): array
    {
        $ar = $lang === 'ar';
        $p = (new DigestPresenter($lang, $days))->forPayload($block);
        $id = (string) ($block['project_id'] ?? '');
        $objective = (string) ($block['objective'] ?? 'custom');
        $keys = self::BY_OBJECTIVE[$objective] ?? self::FALLBACK;
        $totals = (array) ($block['totals'] ?? []);
        $previous = (array) ($block['previous'] ?? []);
        $reported = (array) ($block['reported'] ?? []);

        $decisions = self::decisions($block, $lang, $id);
        $status = self::status($block, $decisions, $ar);

        // The platform rows, biggest spend first. Share is of the platforms' own total so the bars add up.
        $rows = array_values(array_filter((array) ($block['platform_rows'] ?? []), 'is_array'));
        // Biggest spend first; ties (and platforms that spent nothing) fall back to the canonical platform order, never a list of ours.
        $rank = static fn (array $r): int => ($i = array_search(AdPlatforms::canonical((string) ($r['provider'] ?? '')), AdPlatforms::ORDER, true)) === false ? PHP_INT_MAX : $i;
        usort($rows, static fn (array $a, array $b): int => ((float) ($b['spend'] ?? 0) <=> (float) ($a['spend'] ?? 0)) ?: $rank($a) <=> $rank($b));
        $prevByProvider = [];
        foreach ((array) ($block['platform_previous'] ?? []) as $r) {
            $prevByProvider[(string) ($r['provider'] ?? '')] = (array) $r;
        }
        $budgetBy = [];
        foreach ((array) ($block['budget_rows'] ?? []) as $r) {
            $budgetBy[(string) ($r['provider'] ?? '')] = (array) $r;
        }
        $sumSpend = array_sum(array_map(static fn (array $r): float => (float) ($r['spend'] ?? 0), $rows));
        $platforms = [];
        foreach ($rows as $row) {
            $provider = (string) ($row['provider'] ?? '');
            $series = (array) (($block['platform_series'] ?? [])[$provider] ?? []);
            $platforms[] = [
                'provider' => $provider,
                'name' => AdPlatforms::name($provider, $ar ? 'ar' : 'en'),
                'share' => $sumSpend > 0 ? (int) round(((float) ($row['spend'] ?? 0)) / $sumSpend * 100) : 0,
                'figures' => self::figures(['spend', ...$keys], $row, $prevByProvider[$provider] ?? [], (array) (($block['platform_reported'] ?? [])[$provider] ?? $reported), $p, $ar),
                'spark' => self::bars(array_map(static fn (array $pt): ?float => $pt['spend'] ?? null, $series)),
                'pacing' => self::pacing($budgetBy[$provider] ?? null, $ar),
                'raw' => ['spend' => $row['spend'] ?? null] + array_intersect_key($row, array_flip($keys)),
            ];
        }

        $headlineKey = self::HEADLINE[$objective] ?? 'ctr';

        return [
            'id' => $id,
            'name' => (string) ($block['project_name'] ?? ''),
            'objective' => $objective,
            'objective_label' => self::objectiveLabel($objective, $ar),
            'unit' => $block['currency'] ?? null,
            'status' => $status,
            'headline' => self::figures([$headlineKey], $totals, $previous, $reported, $p, $ar)[0],
            'kpis' => self::figures(['spend', ...$keys], $totals, $previous, $reported, $p, $ar),
            'spark' => self::bars(array_map(static fn (array $pt): ?float => $pt['spend'] ?? null, (array) ($block['series'] ?? []))),
            'yesterday' => self::lastDay((array) ($block['series'] ?? []), $p, $ar),
            'changes' => self::changes($block, $p, $ar),
            'platforms' => $platforms,
            'funnel' => self::funnel((array) ($block['funnel'] ?? []), $ar),
            'content' => self::content($block['creatives'] ?? null, $ar),
            'decisions' => $decisions,
            'follow_up' => $block['follow_up'] ?? null,
            'freshness' => (array) ($block['freshness'] ?? []),
            'links' => [
                'report' => MailDeepLinks::report($id),
                'platforms' => MailDeepLinks::platforms($id, $days),
                'attention' => MailDeepLinks::campaignsNeedingAttention($id),
                'content' => MailDeepLinks::analytics($id, 'creative', $days),
                'budget' => MailDeepLinks::analytics($id, 'budget', $days),
            ],
            // The unformatted figures, so a test (and the reconciliation proof) compares numbers, not strings.
            'raw' => ['totals' => $totals, 'previous' => $previous, 'platforms' => array_column($rows, null, 'provider')],
        ];
    }

    /**
     * The portfolio: every project the reader may see, ordered by what needs attention.
     *
     * @param  array<string,mixed>  $digest  the payload of `DailyDigest::buildRange()`
     * @return array<string,mixed>
     */
    public static function portfolio(array $digest, string $lang, string $kind = 'daily'): array
    {
        $ar = $lang === 'ar';
        $days = (int) ($digest['days'] ?? 7);
        $p = (new DigestPresenter($lang, $days))->forPayload($digest);

        $projects = array_map(static fn (array $b): array => self::project($b, $lang, $days), array_values((array) ($digest['projects'] ?? [])));
        usort($projects, static function (array $a, array $b): int {
            $by = self::TONE_RANK[$a['status']['tone']] <=> self::TONE_RANK[$b['status']['tone']];

            return $by !== 0 ? $by : (float) ($b['raw']['totals']['spend'] ?? 0) <=> (float) ($a['raw']['totals']['spend'] ?? 0);
        });

        $health = ['bad' => 0, 'warn' => 0, 'good' => 0, 'neutral' => 0];
        foreach ($projects as $pr) {
            $health[$pr['status']['tone']]++;
        }

        $t = (array) ($digest['totals'] ?? []);
        $change = (array) ($t['change'] ?? []);
        $mk = static fn (string $key, string $kind2, ?float $value, ?float $delta, bool $lower = false) => [
            'label' => self::FIG[$key][$ar ? 0 : 1] ?? $key,
            'missing' => $value === null,
            'value' => $value === null ? ($ar ? 'لا بيانات' : 'No data') : ($kind2 === 'money' ? $p->headline($value) : number_format($value)),
            'change' => $p->change($delta),
            'colour' => $key === 'spend' ? '#8b9a97' : $p->changeColour($delta, $lower),
        ];
        $kpis = [
            $mk('spend', 'money', isset($t['spend']) ? (float) $t['spend'] : null, $change['spend'] ?? null),
            $mk('conversions', 'count', isset($t['conversions']) ? (float) $t['conversions'] : null, $change['conversions'] ?? null),
        ];
        if ((float) ($t['revenue'] ?? 0) > 0) {
            $kpis[] = $mk('revenue', 'money', (float) $t['revenue'], $change['revenue'] ?? null);
        }

        // All platforms, summed across projects — the stacked mix bar.
        $mix = [];
        $total = array_sum(array_map(static fn (array $r): float => (float) ($r['spend'] ?? 0), (array) ($t['platforms'] ?? [])));
        foreach ((array) ($t['platforms'] ?? []) as $r) {
            if ((float) ($r['spend'] ?? 0) <= 0 || $total <= 0) {
                continue;
            }
            $mix[] = [
                'name' => AdPlatforms::name((string) $r['provider'], $ar ? 'ar' : 'en'),
                'share' => (int) round((float) $r['spend'] / $total * 100),
                'value' => $p->headline((float) $r['spend']),
            ];
        }

        // Decisions across the portfolio, worst project first, a few per project, never more than eight.
        $decisions = [];
        foreach ($projects as $pr) {
            foreach (array_slice($pr['decisions'], 0, 2) as $d) {
                $decisions[] = $d + ['project' => $pr['name']];
            }
        }

        return [
            'kind' => $kind,
            'projects_total' => count($projects),
            'need_attention' => $health['bad'] + $health['warn'],
            'kpis' => $kpis,
            'spark' => self::bars(array_map(static fn (array $pt): ?float => $pt['spend'] ?? null, (array) ($t['series'] ?? []))),
            'health' => $health,
            'moves' => self::moves($projects, $ar),
            'mix' => $mix,
            'projects' => $projects,
            'decisions' => array_slice($decisions, 0, 8),
            'links' => ['alerts' => MailDeepLinks::alerts(), 'dashboard' => MailDeepLinks::dashboard()],
            'raw' => ['totals' => $t],
        ];
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $prev
     * @param  array<string,bool>  $reported
     * @return list<array<string,mixed>>
     */
    private static function figures(array $keys, array $row, array $prev, array $reported, DigestPresenter $p, bool $ar): array
    {
        $out = [];
        foreach ($keys as $key) {
            $spec = self::FIG[$key] ?? null;
            if ($spec === null) {
                continue;
            }
            $value = $row[$key] ?? null;
            $withheld = in_array($key, ['spend', 'revenue'], true) && (int) ($row[$key.'_withheld_rows'] ?? 0) > 0;
            $missing = $withheld || ! is_numeric($value) || (($reported[$key] ?? true) === false);
            $delta = ! $missing && is_numeric($prev[$key] ?? null) && (float) $prev[$key] != 0.0 && (int) ($prev[$key.'_withheld_rows'] ?? 0) === 0
                ? round(((float) $value - (float) $prev[$key]) / abs((float) $prev[$key]), 4) : null;
            $lower = (bool) ($spec[3] ?? false);

            $out[] = [
                'key' => $key,
                'label' => $spec[$ar ? 0 : 1],
                // Never `0`: a figure that did not arrive is said to be missing.
                'value' => $missing ? ($ar ? 'لا بيانات' : 'No data') : match ($spec[2]) {
                    'money' => $p->money((float) $value),
                    'percent' => $p->percent((float) $value),
                    'ratio' => $p->ratio((float) $value),
                    default => number_format((float) $value),
                },
                'missing' => $missing,
                'change' => $p->change($delta),
                'colour' => $key === 'spend' ? '#8b9a97' : $p->changeColour($delta, $lower),
            ];
        }

        return $out;
    }

    /**
     * Bars for a series: heights 6–40 (px) scaled to the largest value; a missing day is a flat stub, never a zero-height claim.
     *
     * @param  list<float|null>  $values
     * @return list<array{h: int, missing: bool}>
     */
    private static function bars(array $values): array
    {
        $max = 0.0;
        foreach ($values as $v) {
            $max = max($max, (float) ($v ?? 0));
        }

        return array_map(static fn ($v): array => [
            'h' => $v === null ? 3 : ($max > 0 ? max(4, (int) round(((float) $v) / $max * 40)) : 4),
            'missing' => $v === null,
        ], $values);
    }

    /** @param  array<string,mixed>|null  $row */
    private static function pacing(?array $row, bool $ar): ?array
    {
        if ($row === null || ! is_numeric($row['pace'] ?? null) || (float) ($row['budget'] ?? 0) <= 0) {
            return null;
        }
        $pace = (float) $row['pace'];
        $tone = $pace > 1.4 ? 'bad' : ($pace < 0.6 ? 'warn' : 'good');
        $used = (int) round(min(1.0, (float) ($row['spent'] ?? 0) / (float) $row['budget']) * 100);

        return [
            'tone' => $tone,
            'used' => $used,
            'text' => match ($tone) {
                'bad' => $ar ? 'يُنفَق أسرع من الخطة' : 'Spending ahead of plan',
                'warn' => $ar ? 'يُنفَق أبطأ من الخطة' : 'Spending behind plan',
                default => $ar ? 'ضمن الخطة' : 'On plan',
            },
            'label' => ($ar ? 'من الميزانية ' : 'of budget ').$used.'%',
        ];
    }

    /** @param  list<array<string,mixed>>  $series */
    private static function lastDay(array $series, DigestPresenter $p, bool $ar): ?string
    {
        $spend = array_values(array_filter($series, static fn (array $pt): bool => is_numeric($pt['spend'] ?? null)));
        if (count($spend) < 2) {
            return null;
        }
        $last = (float) $spend[count($spend) - 1]['spend'];
        $before = (float) $spend[count($spend) - 2]['spend'];
        $delta = $before > 0 ? round(($last - $before) / $before, 4) : null;

        return ($ar ? 'آخر يوم: ' : 'Latest day: ').$p->money($last).($delta !== null ? ' ('.$p->change($delta).($ar ? ' عن اليوم قبله)' : ' vs the day before)') : '');
    }

    /**
     * «What changed»: the movements worth a sentence, from the project's own change ratios.
     *
     * @return list<string>
     */
    private static function changes(array $block, DigestPresenter $p, bool $ar): array
    {
        $c = (array) ($block['change'] ?? []);
        $versus = $p->comparedWith();
        $out = [];
        foreach (['spend' => ['الإنفاق', 'Spend'], 'conversions' => ['النتائج', 'Results'], 'roas' => ['العائد على الإنفاق', 'Return on ad spend'], 'cpa' => ['تكلفة النتيجة', 'Cost per result']] as $key => $names) {
            if (isset($c[$key]) && abs((float) $c[$key]) >= 0.05) {
                $out[] = $names[$ar ? 0 : 1].' '.($c[$key] > 0 ? ($ar ? 'ارتفع' : 'rose') : ($ar ? 'انخفض' : 'fell')).' '.number_format(abs((float) $c[$key]) * 100, 0).'% '.($ar ? 'مقارنة ' : 'against ').$versus;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $stages
     * @return list<array{label: string, count: string, width: int, step: ?string}>
     */
    private static function funnel(array $stages, bool $ar): array
    {
        $labels = [
            'impressions' => ['الظهور', 'Impressions'], 'clicks' => ['النقرات', 'Clicks'], 'landing_page_views' => ['زيارات الصفحة', 'Landing page views'],
            'add_to_cart' => ['الإضافة للسلة', 'Add to cart'], 'checkout' => ['بدء الدفع', 'Checkout'], 'purchases' => ['الطلبات', 'Orders'],
            'leads' => ['العملاء المحتملون', 'Leads'], 'installs' => ['التثبيتات', 'Installs'],
        ];
        $kept = array_values(array_filter($stages, static fn (array $s): bool => is_numeric($s['count'] ?? null)));
        if (count($kept) < 2) {
            return [];
        }
        $top = max(1.0, (float) $kept[0]['count']);

        return array_map(static fn (array $s): array => [
            'label' => ($labels[$s['stage'] ?? ''] ?? null)[$ar ? 0 : 1] ?? ($ar ? 'مرحلة' : 'Stage'),
            'count' => number_format((float) $s['count']),
            'width' => max(2, (int) round((float) $s['count'] / $top * 100)),
            'step' => is_numeric($s['step_rate'] ?? null) ? number_format((float) $s['step_rate'] * 100, 1).'%' : null,
        ], $kept);
    }

    /**
     * Top and weak content, with a cover from OUR domain where there is one, and the plain reason.
     *
     * @param  array<string,mixed>|null  $creatives
     * @return array{top: list<array<string,mixed>>, weak: list<array<string,mixed>>}|null
     */
    private static function content(?array $creatives, bool $ar): ?array
    {
        if ($creatives === null) {
            return null;
        }
        $weak = [];
        foreach ((array) ($creatives['declining'] ?? []) as $c) {
            $weak[] = $c + ['why' => $ar ? 'أداؤه يتراجع' : 'Performance is slipping'];
        }
        foreach ((array) ($creatives['fatigued'] ?? []) as $c) {
            $weak[] = $c + ['why' => $ar ? 'تكرار مرتفع: الجمهور مَلّه' : 'Seen too often: the audience is tired of it'];
        }

        return ['top' => array_slice((array) ($creatives['top'] ?? array_filter([$creatives['best'] ?? null])), 0, 3), 'weak' => array_slice($weak, 0, 3)];
    }

    /**
     * What needs a person, plainly, with its button.
     *
     * @return list<array<string,mixed>>
     */
    private static function decisions(array $block, string $lang, string $projectId): array
    {
        $ar = $lang === 'ar';
        $out = [];
        foreach ((array) ($block['observations'] ?? []) as $note) {
            if (! in_array($note['severity'] ?? '', ['critical', 'warning'], true)) {
                continue;
            }
            $say = PlainFindings::say($note, $ar);
            $stale = ($note['kind'] ?? '') === 'stale_data';
            $out[] = $say + [
                'severity' => (string) $note['severity'],
                'url' => $stale ? MailDeepLinks::integrations($projectId) : MailDeepLinks::platforms($projectId),
                'button' => $stale ? ($ar ? 'افتح صفحة الربط' : 'Open the connections page') : ($ar ? 'افتح المنصة داخل النظام' : 'Open the platform in the system'),
            ];
        }

        return array_slice($out, 0, 5);
    }

    /** @return array{tone: string, label: string, reason: string} */
    private static function status(array $block, array $decisions, bool $ar): array
    {
        $freshness = (array) ($block['freshness'] ?? []);
        $notes = (array) ($block['observations'] ?? []);
        $severities = array_column($notes, 'severity');

        $tone = match (true) {
            ($freshness['sync_failed'] ?? false) === true, in_array('critical', $severities, true) => 'bad',
            in_array('warning', $severities, true) => 'warn',
            in_array('positive', $severities, true) => 'good',
            default => 'neutral',
        };

        return [
            'tone' => $tone,
            'label' => self::statusLabel($tone, $ar),
            'reason' => $decisions[0]['headline'] ?? match ($tone) {
                'good' => $ar ? 'الأداء يتحسن عن الفترة السابقة' : 'Performance is improving on the previous period',
                default => $ar ? 'لا شيء يحتاج قرارًا الآن' : 'Nothing needs a decision now',
            },
        ];
    }

    /**
     * The best and the sharpest move among projects, judged on each project's own headline figure.
     *
     * @param  list<array<string,mixed>>  $projects
     * @return array{best: ?array<string,string>, worst: ?array<string,string>}
     */
    private static function moves(array $projects, bool $ar): array
    {
        $best = $worst = null;
        foreach ($projects as $pr) {
            $key = self::HEADLINE[$pr['objective']] ?? 'ctr';
            $now = $pr['raw']['totals'][$key] ?? null;
            $was = $pr['raw']['previous'][$key] ?? null;
            if (! is_numeric($now) || ! is_numeric($was) || (float) $was == 0.0) {
                continue;
            }
            $d = ((float) $now - (float) $was) / abs((float) $was);
            $score = (self::FIG[$key][3] ?? false) ? -$d : $d;
            if (abs($d) < 0.05) {
                continue;
            }
            $line = ['name' => $pr['name'], 'text' => $pr['headline']['label'].' '.($d > 0 ? ($ar ? 'ارتفع' : 'rose') : ($ar ? 'انخفض' : 'fell')).' '.number_format(abs($d) * 100, 0).'% '.($ar ? 'وصار ' : 'to ').$pr['headline']['value'], 'score' => (string) $score];
            if ($score > 0 && ($best === null || $score > (float) $best['score'])) {
                $best = $line;
            }
            if ($score < 0 && ($worst === null || $score < (float) $worst['score'])) {
                $worst = $line;
            }
        }

        return ['best' => $best, 'worst' => $worst];
    }
}
