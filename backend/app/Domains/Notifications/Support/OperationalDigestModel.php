<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Services\DigestPresenter;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;

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

    /** Column headers of the heatmap: short enough for a phone. */
    private const SHORT = [
        'snapchat' => ['سناب', 'Snap'], 'tiktok' => ['تيك توك', 'TikTok'], 'meta' => ['ميتا', 'Meta'], 'google' => ['جوجل', 'Google'],
        'x' => ['إكس', 'X'], 'linkedin' => ['لينكد', 'LinkedIn'], 'openai_ads' => ['AI', 'AI Ads'],
    ];

    /** How many ranked issues are drawn in full; the rest are counted on the «+N» line. */
    public const TOP_ISSUES = 5;

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
        $freshness = (array) ($block['freshness'] ?? []);

        $decisions = self::decisions($block, $lang, $id);

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
        $sources = self::sources($block);
        $platforms = [];
        foreach ($rows as $row) {
            $provider = (string) ($row['provider'] ?? '');
            $series = (array) (($block['platform_series'] ?? [])[$provider] ?? []);
            $platforms[] = [
                'provider' => $provider,
                'name' => AdPlatforms::name($provider, $ar ? 'ar' : 'en'),
                'share' => $sumSpend > 0 ? (int) round(((float) ($row['spend'] ?? 0)) / $sumSpend * 100) : 0,
                'figures' => self::figures(['spend', ...$keys], $row, $prevByProvider[$provider] ?? [], (array) (($block['platform_reported'] ?? [])[$provider] ?? $reported), $p, $ar),
                'spark' => self::bars(array_map(static fn (array $pt): ?float => $pt['spend'] ?? null, $series), array_column($series, 'date'), $ar),
                'pacing' => self::pacing($budgetBy[$provider] ?? null, $ar),
                'raw' => ['spend' => $row['spend'] ?? null] + array_intersect_key($row, array_flip($keys)),
            ];
        }

        $name = (string) ($block['project_name'] ?? '');
        // DIGESTS-VISUAL-V2-001 — one cell per platform the project has (figures or a source), judged by `DigestStatus`.
        $cells = self::cells($block, $rows, $prevByProvider, $budgetBy, $sources, $objective, $p, $ar, $id, $name, $days);
        $stateOf = array_column($cells, 'state', 'provider');
        $platforms = array_map(static fn (array $pf): array => $pf + ['state' => $stateOf[AdPlatforms::canonical($pf['provider'])] ?? 'none'], $platforms);
        $issues = array_merge(
            array_values(array_filter(array_map(static fn (array $c): ?array => $c['issue'], $cells))),
            self::projectFindings($block, $lang, $id, $name, (float) ($totals['spend'] ?? 0), $sources, $cells),
        );
        usort($issues, [DigestStatus::class, 'compare']);
        $state = DigestStatus::project(
            array_column($cells, 'state'),
            array_map(static fn (array $i): string => $i['state'] === 'bad' ? 'critical' : 'warning', array_filter($issues, static fn (array $i): bool => $i['kind'] === 'project')),
        );
        $status = self::status($block, $decisions, $issues, $state, $ar);

        $headlineKey = self::HEADLINE[$objective] ?? 'ctr';

        return [
            'id' => $id,
            'name' => (string) ($block['project_name'] ?? ''),
            'objective' => $objective,
            'objective_label' => self::objectiveLabel($objective, $ar),
            'unit' => $block['currency'] ?? null,
            'status' => $status,
            'state' => $state,
            'cells' => array_map(static fn (array $c): array => array_diff_key($c, ['issue' => 1]), $cells),
            'issues' => $issues,
            'fresh' => ! in_array((string) ($freshness['state'] ?? ''), ['stale', 'sync_failed', 'no_data'], true) && ! ($freshness['sync_failed'] ?? false),
            'headline' => self::figures([$headlineKey], $totals, $previous, $reported, $p, $ar)[0],
            'kpis' => self::figures(['spend', ...$keys], $totals, $previous, $reported, $p, $ar),
            'spark' => self::bars(array_map(static fn (array $pt): ?float => $pt['spend'] ?? null, (array) ($block['series'] ?? [])), array_column((array) ($block['series'] ?? []), 'date'), $ar),
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
        // Worst first by the ONE state rule, then by money, then by name — so the order is the same on every run.
        usort($projects, static function (array $a, array $b): int {
            return (DigestStatus::STATE_RANK[$a['state']] <=> DigestStatus::STATE_RANK[$b['state']])
                ?: ((float) ($b['raw']['totals']['spend'] ?? 0) <=> (float) ($a['raw']['totals']['spend'] ?? 0))
                ?: strcmp((string) $a['name'], (string) $b['name']);
        });

        $health = ['bad' => 0, 'watch' => 0, 'ok' => 0, 'none' => 0];
        foreach ($projects as $pr) {
            $health[$pr['state']]++;
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

        /*
         * What needs the Owner NOW — ONE list across every project, in global priority order (`DigestStatus::compare`).
         * Only the top `TOP_ISSUES` are drawn in full; the rest are counted on a «+N» line, and every one of them is
         * still a ! or ✕ chip in the heatmap — nothing is dropped, nothing is hidden by project order.
         */
        $issues = [];
        foreach ($projects as $pr) {
            foreach ($pr['issues'] as $i) {
                $issues[] = $i;
            }
        }
        usort($issues, [DigestStatus::class, 'compare']);

        return [
            'kind' => $kind,
            'projects_total' => count($projects),
            'need_attention' => $health['bad'] + $health['watch'],
            'kpis' => $kpis,
            'spark' => self::bars(array_map(static fn (array $pt): ?float => $pt['spend'] ?? null, (array) ($t['series'] ?? [])), array_column((array) ($t['series'] ?? []), 'date'), $ar),
            'health' => $health,
            'heatmap' => self::heatmap($projects, $ar),
            'coverage' => self::coverage($projects, $ar),
            'moves' => self::moves($projects, $ar),
            'movers' => self::movers($projects, $ar),
            'mix' => $mix,
            'projects' => $projects,
            'issues' => $issues,
            'issues_shown' => array_slice($issues, 0, self::TOP_ISSUES),
            'issues_more' => max(0, count($issues) - self::TOP_ISSUES),
            'links' => ['alerts' => MailDeepLinks::alerts(), 'dashboard' => MailDeepLinks::dashboard()],
            'raw' => ['totals' => $t],
        ];
    }

    /**
     * The project × platform matrix: every project once, every platform any of them has once, a state in each cell.
     *
     * @param  list<array<string,mixed>>  $projects
     * @return array{columns: list<array<string,string>>, rows: list<array<string,mixed>>}
     */
    private static function heatmap(array $projects, bool $ar): array
    {
        $providers = [];
        foreach ($projects as $pr) {
            foreach ($pr['cells'] as $c) {
                $providers[$c['provider']] = $c['short'];
            }
        }
        uksort($providers, static fn (string $a, string $b): int => AdPlatforms::rank($a) <=> AdPlatforms::rank($b));
        $rows = [];
        foreach ($projects as $pr) {
            $cells = array_column($pr['cells'], null, 'provider');
            $rows[] = [
                'id' => $pr['id'],
                'name' => $pr['name'],
                'state' => $pr['state'],
                'url' => $pr['links']['report'],
                'cells' => array_map(static fn (string $k): ?array => isset($cells[$k]) ? ['state' => $cells[$k]['state'], 'reason' => $cells[$k]['reason']] : null, array_keys($providers)),
            ];
        }

        return ['columns' => array_map(static fn (string $k, string $short): array => ['provider' => $k, 'short' => $short], array_keys($providers), array_values($providers)), 'rows' => $rows];
    }

    /**
     * «N of M projects were checked», and who was not. A project is CHECKED when its figures arrived and its sources are current;
     * it is NOT when it has no figures, a stale source or a stopped sync — each named with its reason, never just counted.
     *
     * @param  list<array<string,mixed>>  $projects
     * @return array{total: int, checked: int, unchecked: list<array<string,string>>}
     */
    private static function coverage(array $projects, bool $ar): array
    {
        $unchecked = [];
        foreach ($projects as $pr) {
            $reason = match (true) {
                $pr['state'] === 'none' => 'none',
                ($pr['freshness']['sync_failed'] ?? false) === true => 'failed',
                ! $pr['fresh'] => 'stale',
                default => null,
            };
            if ($reason !== null) {
                $unchecked[] = [
                    'id' => (string) $pr['id'],
                    'name' => (string) $pr['name'],
                    'reason' => $reason,
                    'label' => match ($reason) {
                        'none' => $ar ? 'لا بيانات' : 'No data',
                        'failed' => $ar ? 'مزامنة متوقفة' : 'Sync stopped',
                        default => $ar ? 'بيانات متأخرة' : 'Out of date',
                    },
                    'url' => MailDeepLinks::integrations((string) $pr['id']),
                ];
            }
        }

        return ['total' => count($projects), 'checked' => count($projects) - count($unchecked), 'unchecked' => $unchecked];
    }

    /**
     * The weekly view's best and worst: up to three projects each, by the headline figure's own direction against the previous window.
     *
     * @param  list<array<string,mixed>>  $projects
     * @return array{best: list<array<string,mixed>>, worst: list<array<string,mixed>>}
     */
    private static function movers(array $projects, bool $ar): array
    {
        $scored = [];
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
            $scored[] = ['name' => $pr['name'], 'label' => $pr['headline']['label'], 'value' => $pr['headline']['value'], 'change' => $pr['headline']['change'], 'score' => $score, 'good' => $score > 0];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return [
            'best' => array_slice(array_values(array_filter($scored, static fn (array $r): bool => $r['good'])), 0, 3),
            'worst' => array_slice(array_reverse(array_values(array_filter($scored, static fn (array $r): bool => ! $r['good']))), 0, 3),
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
     * @param  list<string>  $dates  one per value; a weekday initial is printed under each bar
     * @return list<array{h: int, missing: bool, label: string}>
     */
    private static function bars(array $values, array $dates = [], bool $ar = true): array
    {
        $max = 0.0;
        foreach ($values as $v) {
            $max = max($max, (float) ($v ?? 0));
        }
        $values = array_values($values);
        $dates = array_values($dates);

        return array_map(static function ($v, int $i) use ($max, $dates, $ar): array {
            $label = '';
            if (isset($dates[$i])) {
                try {
                    // A weekday initial under each bar: the week reads as a week without a caption.
                    $label = mb_substr(Carbon::parse((string) $dates[$i])->locale($ar ? 'ar' : 'en')->isoFormat('dd'), 0, 2);
                } catch (\Throwable) {
                    $label = '';
                }
            }

            return [
                'h' => $v === null ? 3 : ($max > 0 ? max(4, (int) round(((float) $v) / $max * 40)) : 4),
                'missing' => $v === null,
                'label' => $label,
            ];
        }, $values, array_keys($values));
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
            'label' => ($ar ? 'من الميزانية ' : 'of budget ').AlertEventText::ltr($used.'%'),
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

    /**
     * The project's verdict, from the ONE rule in `DigestStatus` (the state), with the plain reason of its top issue.
     *
     * @param  list<array<string,mixed>>  $issues  ranked
     * @return array{tone: string, label: string, reason: string}
     */
    private static function status(array $block, array $decisions, array $issues, string $state, bool $ar): array
    {
        $positive = in_array('positive', array_column((array) ($block['observations'] ?? []), 'severity'), true);
        $tone = match ($state) {
            'bad' => 'bad',
            'watch' => 'warn',
            'ok' => $positive ? 'good' : 'neutral',
            default => 'neutral',
        };
        $top = $issues[0] ?? null;

        return [
            'tone' => $tone,
            'label' => $state === 'none' ? ($ar ? 'لا بيانات' : 'No data') : self::statusLabel($tone, $ar),
            'reason' => $top !== null
                ? trim(($top['platform'] !== '' ? $top['platform'].': ' : '').$top['headline'])
                : match (true) {
                    $state === 'none' => $ar ? 'لم تصل أرقام في هذه الفترة' : 'No figures arrived in this period',
                    $tone === 'good' => $ar ? 'الأداء يتحسن عن الفترة السابقة' : 'Performance is improving on the previous period',
                    default => $ar ? 'لا شيء يحتاج قرارًا الآن' : 'Nothing needs a decision now',
                },
        ];
    }

    /**
     * Every ad platform the project has, by canonical key → its source state (`failed` / `stale` / `fresh` / `partial`).
     *
     * @return array<string,string>
     */
    private static function sources(array $block): array
    {
        $out = [];
        $f = (array) ($block['freshness'] ?? []);
        foreach ((array) ($f['sources'] ?? []) as $s) {
            if (($s['kind'] ?? 'ad_platform') === 'ad_platform' && ($s['provider'] ?? '') !== '') {
                $out[AdPlatforms::canonical((string) $s['provider'])] = (string) ($s['state'] ?? 'fresh');
            }
        }
        foreach ((array) ($f['failing'] ?? []) as $s) {
            if (($s['provider'] ?? '') !== '') {
                $out[AdPlatforms::canonical((string) $s['provider'])] = 'failed';
            }
        }

        return $out;
    }

    /**
     * One cell per platform: from its figures, or only from its source when it sent none.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>> in the canonical platform order; `issue` is the ranked item or null
     */
    private static function cells(array $block, array $rows, array $prevByProvider, array $budgetBy, array $sources, string $objective, DigestPresenter $p, bool $ar, string $projectId, string $projectName, int $days): array
    {
        $byProvider = [];
        foreach ($rows as $r) {
            $byProvider[AdPlatforms::canonical((string) ($r['provider'] ?? ''))] = $r;
        }
        $keys = array_values(array_unique([...array_keys($byProvider), ...array_keys($sources)]));
        usort($keys, static fn (string $a, string $b): int => AdPlatforms::rank($a) <=> AdPlatforms::rank($b));

        $resultKey = in_array($objective, ['sales', 'leads', 'app_installs'], true) ? self::BY_OBJECTIVE[$objective][0] : null;
        $headlineKey = self::HEADLINE[$objective] ?? 'ctr';
        $lower = (bool) (self::FIG[$headlineKey][3] ?? false);
        $prevCanon = [];
        foreach ($prevByProvider as $k => $r) {
            $prevCanon[AdPlatforms::canonical((string) $k)] = $r;
        }
        $budgetCanon = [];
        foreach ($budgetBy as $k => $r) {
            $budgetCanon[AdPlatforms::canonical((string) $k)] = $r;
        }

        $cells = [];
        foreach ($keys as $order => $key) {
            $row = $byProvider[$key] ?? [];
            $prev = $prevCanon[$key] ?? [];
            $raw = (string) ($row['provider'] ?? $key);
            $reportedFor = (array) ((($block['platform_reported'] ?? [])[$raw] ?? null) ?? ($block['reported'] ?? []));
            $budget = $budgetCanon[$key] ?? null;
            $verdict = DigestStatus::cell([
                'alert' => self::alertFor($key, (array) ($block['open_alerts'] ?? [])),
                'source' => $sources[$key] ?? null,
                'spend' => $row['spend'] ?? null,
                'spend_withheld' => (int) ($row['spend_withheld_rows'] ?? 0) > 0,
                'results_key' => $resultKey,
                'results' => $resultKey !== null ? ($row[$resultKey] ?? null) : null,
                'results_prev' => $resultKey !== null ? ($prev[$resultKey] ?? null) : null,
                'results_reported' => $resultKey !== null && ($reportedFor[$resultKey] ?? true) !== false,
                'headline_now' => $row[$headlineKey] ?? null,
                'headline_prev' => $prev[$headlineKey] ?? null,
                'headline_lower_is_better' => $lower,
                'pace' => $budget !== null && (float) ($budget['budget'] ?? 0) > 0 ? ($budget['pace'] ?? null) : null,
            ]);
            $name = AdPlatforms::name($key, $ar ? 'ar' : 'en');
            $issue = null;
            if (in_array($verdict['state'], ['bad', 'watch'], true)) {
                $issue = self::issue($verdict, $key, $name, $row, $prev, $budget, $headlineKey, $resultKey, $p, $ar, $projectId, $projectName, $order, $days);
            }
            $cells[] = [
                'provider' => $key,
                'name' => $name,
                'short' => self::SHORT[$key][$ar ? 0 : 1] ?? $name,
                'state' => $verdict['state'],
                'reason' => $verdict['reason'],
                'issue' => $issue,
            ];
        }

        return $cells;
    }

    /**
     * The in-app board's rule for a cell: an open warning/critical alert → bad; an open info alert or a snoozed one → watch.
     * An alert naming a platform marks that column; one naming none (campaign- or project-level) marks every column.
     *
     * @param  list<array<string,mixed>>  $alerts
     */
    private static function alertFor(string $provider, array $alerts): ?string
    {
        $out = null;
        foreach ($alerts as $a) {
            $named = (string) ($a['provider'] ?? '');
            if ($named !== '' && $named !== 'ga4' && AdPlatforms::canonical($named) !== $provider) {
                continue;
            }
            if ($named === 'ga4') {
                continue; // the website's own alerts are not a platform's
            }
            if (! ($a['snoozed'] ?? false) && in_array($a['severity'] ?? '', ['critical', 'warning'], true)) {
                return 'bad';
            }
            $out = 'watch';
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function issue(array $verdict, string $provider, string $platform, array $row, array $prev, ?array $budget, string $headlineKey, ?string $resultKey, DigestPresenter $p, bool $ar, string $projectId, string $projectName, int $order, int $days): array
    {
        $reason = (string) $verdict['reason'];
        $label = self::FIG[$headlineKey][$ar ? 0 : 1] ?? $headlineKey;
        $fmt = static fn (string $key, mixed $v): string => match (self::FIG[$key][2] ?? 'count') {
            'money' => $p->money((float) $v),
            'percent' => $p->percent((float) $v),
            'ratio' => $p->ratio((float) $v),
            default => number_format((float) $v),
        };
        $pct = static fn (float $r): string => AlertEventText::ltr(number_format(abs($r) * 100, 0).'%');
        $viz = null;
        $headline = '';
        $figure = '';

        switch ($reason) {
            case 'sync_failed':
                $headline = $ar ? 'توقفت المزامنة' : 'Sync stopped';
                break;
            case 'alert_bad':
            case 'alert_watch':
                $headline = $reason === 'alert_bad' ? ($ar ? 'تنبيه مفتوح يحتاج قرارًا' : 'An open alert needs a decision') : ($ar ? 'تنبيه مفتوح للمتابعة' : 'An open alert to follow');
                break;
            case 'stale':
                $headline = $ar ? 'البيانات متأخرة' : 'Data is out of date';
                break;
            case 'no_results':
                $headline = $ar ? 'إنفاق بلا نتائج' : 'Spend with no results';
                $figure = ($ar ? 'الإنفاق ' : 'Spent ').$p->money((float) ($row['spend'] ?? 0)).' · '.(self::FIG[$resultKey ?? 'conversions'][$ar ? 0 : 1] ?? '').' 0';
                break;
            case 'pace_hot':
            case 'pace_slow':
                $pace = (float) ($budget['pace'] ?? 0);
                $used = (int) round(min(1.0, (float) ($budget['spent'] ?? 0) / max(0.0001, (float) ($budget['budget'] ?? 1))) * 100);
                $expected = $pace > 0 ? (int) round(min(100, $used / $pace)) : 0;
                $headline = $reason === 'pace_hot' ? ($ar ? 'ينفق أسرع من الخطة' : 'Spending ahead of plan') : ($ar ? 'ينفق أبطأ من الخطة' : 'Spending behind plan');
                // Both bars are shares of the BUDGET, so «used» and «plan» are comparable and no second percentage can contradict the first.
                $figure = ($ar ? 'صُرف ' : 'Spent ').AlertEventText::ltr($used.'%').' · '.($ar ? 'المخطط ' : 'planned ').AlertEventText::ltr($expected.'%');
                $viz = ['type' => 'pace', 'used' => $used, 'plan' => $expected, 'used_label' => ($ar ? 'صُرف' : 'Spent').' '.AlertEventText::ltr($used.'%'), 'plan_label' => ($ar ? 'الخطة' : 'Plan').' '.AlertEventText::ltr($expected.'%')];
                break;
            case 'worse':
            case 'results_down':
                $key = $reason === 'results_down' ? (string) $resultKey : $headlineKey;
                $now = (float) ($row[$key] ?? 0);
                $was = (float) ($prev[$key] ?? 0);
                $label = self::FIG[$key][$ar ? 0 : 1] ?? $key;
                $up = $now > $was;
                $fem = in_array($key, ['cpl', 'cpa', 'cpc', 'cpm', 'cpi', 'revenue', 'purchases', 'conversions', 'clicks', 'installs', 'engagements', 'video_views'], true);
                $verb = $ar ? ($up ? ($fem ? 'ارتفعت' : 'ارتفع') : ($fem ? 'تراجعت' : 'تراجع')) : ($up ? 'rose' : 'fell');
                $headline = $label.' '.$verb.' '.$pct($was > 0 ? ($now - $was) / $was : 0);
                $figure = $fmt($key, $was).' ← '.$fmt($key, $now);
                $max = max($now, $was, 0.0001);
                $viz = ['type' => 'before_now', 'before' => max(6, (int) round($was / $max * 100)), 'now' => max(6, (int) round($now / $max * 100)), 'before_label' => $fmt($key, $was), 'now_label' => $fmt($key, $now), 'key' => $key];
                break;
        }

        return [
            'kind' => 'cell',
            'state' => $verdict['state'],
            'reason' => $reason,
            'project_id' => $projectId,
            'project' => $projectName,
            'provider' => $provider,
            'platform' => $platform,
            'headline' => $headline,
            'figure' => $figure,
            'viz' => $viz,
            'at_stake' => (float) ($row['spend'] ?? 0),
            'order' => $order,
            'url' => in_array($reason, ['sync_failed', 'stale'], true) ? MailDeepLinks::integrations($projectId) : (str_starts_with($reason, 'alert_') ? MailDeepLinks::alerts() : MailDeepLinks::platforms($projectId, $days)),
            'button' => in_array($reason, ['sync_failed', 'stale'], true) ? ($ar ? 'افتح الربط' : 'Open connection') : (str_starts_with($reason, 'alert_') ? ($ar ? 'افتح التنبيهات' : 'Open alerts') : ($ar ? 'افتح المنصة' : 'Open platform')),
        ];
    }

    /**
     * The findings that belong to the project as a whole (not one platform), as ranked issues.
     * Platform-scoped findings (budget pace, stale data) are the cells' own and are not repeated here.
     *
     * @param  array<string,string>  $sources
     * @param  list<array<string,mixed>>  $cells
     * @return list<array<string,mixed>>
     */
    private static function projectFindings(array $block, string $lang, string $projectId, string $projectName, float $spend, array $sources, array $cells): array
    {
        $ar = $lang === 'ar';
        $out = [];
        foreach ((array) ($block['observations'] ?? []) as $note) {
            $kind = (string) ($note['kind'] ?? '');
            if (! in_array($note['severity'] ?? '', ['critical', 'warning'], true) || in_array($kind, ['budget_pace', 'stale_data', 'data_gap', 'reallocation'], true)) {
                continue;
            }
            $say = PlainFindings::say($note, $ar);
            $out[] = [
                'kind' => 'project',
                'state' => $note['severity'] === 'critical' ? 'bad' : 'watch',
                'reason' => $note['severity'] === 'critical' ? 'project_critical' : 'project_warning',
                'project_id' => $projectId,
                'project' => $projectName,
                'provider' => '',
                'platform' => '',
                'headline' => $say['headline'],
                'figure' => $say['text'],
                'viz' => null,
                'at_stake' => $spend,
                'order' => 99,
                'url' => MailDeepLinks::platforms($projectId),
                'button' => $ar ? 'افتح المشروع' : 'Open project',
            ];
        }
        // A sync reported as failed with no platform named still has to reach the Owner.
        if (($block['freshness']['sync_failed'] ?? false) === true && ! in_array('failed', $sources, true)) {
            $out[] = [
                'kind' => 'project', 'state' => 'bad', 'reason' => 'sync_failed', 'project_id' => $projectId, 'project' => $projectName,
                'provider' => '', 'platform' => '', 'headline' => $ar ? 'توقفت المزامنة' : 'Sync stopped', 'figure' => $ar ? 'الأرقام قد تكون ناقصة' : 'Figures may be incomplete',
                'viz' => null, 'at_stake' => $spend, 'order' => 99, 'url' => MailDeepLinks::integrations($projectId), 'button' => $ar ? 'افتح الربط' : 'Open connection',
            ];
        }

        return $out;
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
