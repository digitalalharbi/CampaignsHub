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
        'purchases' => ['المشتريات', 'Purchases', 'count'],
        'revenue' => ['الإيرادات', 'Revenue', 'money'],
        'roas' => ['العائد على الإنفاق', 'ROAS', 'ratio'],
        'leads' => ['العملاء المحتملون', 'Leads', 'count'],
        'cpl' => ['تكلفة العميل المحتمل', 'CPL', 'money', true],
        'clicks' => ['النقرات', 'Clicks', 'count'],
        'cpc' => ['تكلفة النقرة', 'CPC', 'money', true],
        'impressions' => ['الظهور', 'Impressions', 'count'],
        'reach' => ['الوصول', 'Reach', 'count'],
        'cpm' => ['تكلفة الألف ظهور', 'CPM', 'money', true],
        'installs' => ['التثبيتات', 'Installs', 'count'],
        'cpi' => ['تكلفة التثبيت', 'CPI', 'money', true],
        'engagements' => ['التفاعلات', 'Engagements', 'count'],
        'engagement_rate' => ['معدل التفاعل', 'Engagement rate', 'percent'],
        'video_views' => ['مشاهدات الفيديو', 'Video views', 'count'],
        'video_completion_rate' => ['معدل الإكمال', 'Completion rate', 'percent'],
        'ctr' => ['نسبة النقر', 'CTR', 'percent'],
        'conversions' => ['تحويلات المنصة', 'Platform conversions', 'count'],
        'cpa' => ['تكلفة تحويل المنصة', 'Cost per platform conversion', 'money', true],
        'frequency' => ['التكرار', 'Frequency', 'decimal'],
        'aov' => ['متوسط الطلب', 'Average order', 'money'],
        'landing_page_views' => ['زيارات الصفحة', 'Landing page views', 'count'],
        'conversion_rate' => ['معدل التحويل', 'Conversion rate', 'percent'],
        'qualified_leads' => ['عملاء مؤهلون', 'Qualified leads', 'count'],
    ];

    /** What each objective is judged on, per platform, after spend. The first is the project's headline figure. */
    public const BY_OBJECTIVE = [
        'sales' => ['purchases', 'revenue', 'roas', 'cpa', 'aov'],
        'leads' => ['leads', 'cpl', 'qualified_leads'],
        'traffic' => ['clicks', 'ctr', 'cpc', 'landing_page_views'],
        'awareness' => ['reach', 'impressions', 'frequency', 'cpm'],
        'app_installs' => ['installs', 'cpi'],
        'engagement' => ['engagements', 'engagement_rate'],
        'video' => ['video_views', 'video_completion_rate', 'cpm'],
    ];

    /** Figures that ADD UP across projects (counts and money); a ratio is recomputed from the sums, never averaged; reach, frequency and rates are not rolled up. */
    private const ROLL_UP = [
        'sales' => ['purchases', 'revenue', 'roas', 'cpa'],
        'leads' => ['leads', 'cpl'],
        'traffic' => ['clicks', 'ctr', 'cpc'],
        'awareness' => ['impressions', 'cpm'],
        'app_installs' => ['installs', 'cpi'],
        'engagement' => ['engagements'],
        'video' => ['video_views', 'cpm'],
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

    /** DIGESTS-CADENCE-BUDGETS-001 — budget lines drawn in «ملخص المشاريع»; the rest are counted on one «+N» line. */
    public const BUDGET_ROWS = 10;

    public static function objectiveLabel(string $objective, bool $ar): string
    {
        return (self::OBJECTIVE_LABEL[$objective] ?? self::OBJECTIVE_LABEL['custom'])[$ar ? 0 : 1];
    }

    public static function statusLabel(string $tone, bool $ar): string
    {
        return match ($tone) {
            'bad' => $ar ? 'مشكلة' : 'Problem',
            'warn' => $ar ? 'مراقبة' : 'Watch',
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
    public static function project(array $block, string $lang, int $days = 7, string $kind = 'daily'): array
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
        // DIGESTS-OBJECTIVE-KPIS-001 — a project is judged PER OBJECTIVE GROUP: an awareness group is never «a problem» for a low ROAS.
        $groupsIn = array_values(array_filter((array) ($block['groups'] ?? []), 'is_array'));
        $mixed = count($groupsIn) > 1;
        $groups = [];
        $cellSets = [];
        if ($groupsIn === []) {
            // No group breakdown (an older payload): the project is one group, its own objective.
            $cellSets[] = self::cells($block, $rows, $prevByProvider, $budgetBy, $sources, $objective, $p, $ar, $id, $name, $days, null);
        }
        foreach ($groupsIn as $g) {
            $go = (string) ($g['objective'] ?? 'custom');
            $gRows = array_values(array_filter((array) ($g['platform_rows'] ?? []), 'is_array'));
            usort($gRows, static fn (array $a, array $b): int => ((float) ($b['spend'] ?? 0) <=> (float) ($a['spend'] ?? 0)) ?: $rank($a) <=> $rank($b));
            $gPrev = [];
            foreach ((array) ($g['platform_previous'] ?? []) as $r) {
                $gPrev[(string) ($r['provider'] ?? '')] = (array) $r;
            }
            $gBlock = array_merge($block, ['platform_reported' => (array) ($g['platform_reported'] ?? []), 'reported' => (array) ($g['reported'] ?? [])]);
            $gCells = self::cells($gBlock, $gRows, $gPrev, $budgetBy, $sources, $go, $p, $ar, $id, $name, $days, $mixed ? self::objectiveLabel($go, $ar) : null);
            $cellSets[] = $gCells;
            $groups[] = self::group($g, $go, $gRows, $gPrev, $gCells, $p, $ar);
        }
        $cells = self::mergeCells($cellSets);
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

        $series = (array) ($block['series'] ?? []);

        return [
            'id' => $id,
            'kind' => $kind,
            'name' => (string) ($block['project_name'] ?? ''),
            // DIGESTS-CADENCE-BUDGETS-001 — the month's budget, spend to date, remaining and pace, drawn as one bar.
            'budget' => self::budget(isset($block['budget_month']) && is_array($block['budget_month']) ? $block['budget_month'] : null, $lang),
            // The monthly's own framing: the month as 4–5 week bars, and the best and weakest platform on the objective's figure.
            'weeks' => $kind === 'monthly' ? self::weeks($series, $ar) : [],
            'best' => $kind === 'monthly' && ! $mixed ? self::extreme($rows, $objective, $p, $ar, true) : null,
            'worst' => $kind === 'monthly' && ! $mixed ? self::extreme($rows, $objective, $p, $ar, false) : null,
            'objective' => $objective,
            'objective_label' => self::objectiveLabel($objective, $ar),
            'unit' => $block['currency'] ?? null,
            'status' => $status,
            'state' => $state,
            'cells' => array_map(static fn (array $c): array => array_diff_key($c, ['issue' => 1]), $cells),
            'issues' => $issues,
            'fresh' => ! in_array((string) ($freshness['state'] ?? ''), ['stale', 'sync_failed', 'no_data'], true) && ! ($freshness['sync_failed'] ?? false),
            // Unlike objectives are never averaged: a mixed project's own tile row is the money alone; each group has its own row.
            'mixed' => $mixed,
            'groups' => $groups,
            'objective_paths' => self::objectivePaths(is_array($block['objective_paths'] ?? null) ? $block['objective_paths'] : null, $p, $ar),
            'headline' => self::figures([$mixed ? 'spend' : $headlineKey], $totals, $previous, $reported, $p, $ar)[0],
            'kpis' => self::figures($mixed ? ['spend'] : ['spend', ...$keys], $totals, $previous, $reported, $p, $ar),
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
            'key' => $key,
            'label' => self::FIG[$key][$ar ? 0 : 1] ?? $key,
            'missing' => $value === null,
            'value' => $value === null ? ($ar ? 'لا بيانات' : 'No data') : ($kind2 === 'money' ? $p->headline($value) : number_format($value)),
            'change' => $p->change($delta),
            'colour' => $key === 'spend' ? '#8b9a97' : $p->changeColour($delta, $lower),
        ];
        // Money is the only figure every objective shares; results are NEVER summed across unlike objectives — each group has its own tile row.
        $kpis = [$mk('spend', 'money', isset($t['spend']) ? (float) $t['spend'] : null, $change['spend'] ?? null)];
        $groups = self::portfolioGroups($projects, $p, $ar);

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
            // DIGESTS-CADENCE-BUDGETS-001 — «ملخص المشاريع» LEADS with the money: every project's budget, spend, remaining and pace.
            'budgets' => self::budgets($projects, $lang),
            'weeks' => $kind === 'monthly' ? self::weeks((array) ($t['series'] ?? []), $ar) : [],
            'projects_total' => count($projects),
            'need_attention' => $health['bad'] + $health['watch'],
            'kpis' => $kpis,
            'groups' => $groups,
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
     * DIGESTS-CADENCE-BUDGETS-001 — one project's month: budget, spend to date, remaining, pace — or why there is no figure.
     *
     * The input is `ClientBudgetRollup::of()` plus the month's elapsed share (see `DailyDigest::monthBudget()`), so every rule
     * of the budgets screen holds here by construction: a spend that is withheld is «لا بيانات», never 0; two budget currencies
     * give no total; a pace is stated only when every part of it is measured.
     *
     * @param  array<string,mixed>|null  $b
     * @return array<string,mixed>|null null when the payload carries no budget block at all (an older payload)
     */
    public static function budget(?array $b, string $lang): ?array
    {
        if ($b === null) {
            return null;
        }
        $ar = $lang === 'ar';
        $budget = is_numeric($b['budget'] ?? null) ? (float) $b['budget'] : null;
        $spent = is_numeric($b['spent'] ?? null) ? (float) $b['spent'] : null;
        $currency = is_string($b['currency'] ?? null) ? (string) $b['currency'] : null;
        $p = new DigestPresenter($lang, 30, $currency);
        $elapsed = (int) round(min(1.0, max(0.0, (float) ($b['elapsed'] ?? 0))) * 100);
        $outside = (int) ($b['outside'] ?? 0);
        $unmeasured = (int) ($b['unmeasured'] ?? 0);
        $why = self::unpricedReason((array) ($b['outside_reasons'] ?? []), $unmeasured, $ar);
        $outsideLine = $outside > 0 ? self::campaignsOutside($outside, $ar).': '.$why : '';
        $noData = $ar ? 'لا بيانات' : 'No data';

        if ((int) ($b['currencies'] ?? 0) > 1) {
            return ['state' => 'mixed', 'line' => $ar ? 'الميزانيات بعملات مختلفة، فما نجمعها' : 'Budgets are in different currencies, so they are not added up', 'elapsed' => $elapsed, 'outside_line' => $outsideLine] + self::emptyBudget();
        }
        if ($budget === null || $budget <= 0) {
            /*
             * No budget in the figure is not «no budget»: when every budgeted campaign was left out — its spend has no figure in
             * the budget's currency — the project HAS budgets, and saying otherwise hides exactly the project somebody must fix.
             */
            if ($outside > 0) {
                return ['state' => 'unpriced', 'line' => $why, 'elapsed' => $elapsed, 'outside_line' => $outsideLine, 'budget' => $noData, 'spent' => $noData, 'remaining' => $noData] + self::emptyBudget();
            }

            return ['state' => 'none', 'line' => $ar ? 'ما فيه ميزانية مسجّلة لهذا الشهر' : 'No budget recorded for this month', 'elapsed' => $elapsed, 'outside_line' => ''] + self::emptyBudget();
        }

        // The roll-up's own remaining when it states one (it withholds it while any part of the spend is unmeasured).
        $remaining = array_key_exists('remaining', $b)
            ? (is_numeric($b['remaining']) ? round((float) $b['remaining'], 2) : null)
            : ($spent === null ? null : round($budget - $spent, 2));
        $complete = $spent !== null && $remaining !== null;
        $pace = $complete && is_numeric($b['pace'] ?? null) ? (float) $b['pace'] : null;
        $band = DigestSchedule::paceBand($pace);
        $used = $complete ? (int) round($spent / $budget * 100) : null;
        // Spend past the budget is its own fact, whatever the pace says about the rest of the month.
        if ($remaining !== null && $remaining < 0) {
            $band = 'over';
        }

        return [
            'state' => $complete ? 'ok' : 'withheld',
            'currency' => $currency,
            'budget_value' => $budget,
            'spent_value' => $spent,
            'remaining_value' => $remaining,
            'projected_value' => is_numeric($b['projected'] ?? null) ? (float) $b['projected'] : null,
            'outside' => $outside,
            'outside_reasons' => (array) ($b['outside_reasons'] ?? []),
            'budget' => $p->headline($budget),
            'spent' => $spent === null ? $noData : $p->headline($spent),
            'remaining' => $remaining === null ? $noData : $p->headline($remaining),
            'over' => $remaining !== null && $remaining < 0,
            'used' => $used,
            'bar' => $used === null ? 0 : max(2, min(100, $used)),
            'elapsed' => $elapsed,
            'band' => $band,
            'band_label' => self::bandLabel($band, $ar),
            'tone' => match ($band) {
                'ahead', 'over' => 'bad', 'behind' => 'watch', 'on_track' => 'ok', default => 'none'
            },
            'line' => $complete
                ? ($ar ? 'مضى '.AlertEventText::ltr($elapsed.'%').' من الشهر · الإنفاق '.AlertEventText::ltr($used.'%').' من الميزانية' : AlertEventText::ltr($elapsed.'%').' of the month gone · '.AlertEventText::ltr($used.'%').' of the budget spent')
                : self::unpricedReason([], max(1, $unmeasured), $ar),
            'outside_line' => $outsideLine,
        ];
    }

    /**
     * Why a budget has no full figure, from the pacing basis of the campaigns left out — the reason the data gives, never a
     * guessed one: spend in another currency than the budget, spend with no exchange rate, or no spend figures yet this month.
     *
     * @param  array<string,int>  $reasons  `pacing_basis` → how many budgeted campaigns
     */
    private static function unpricedReason(array $reasons, int $unmeasured, bool $ar): string
    {
        arsort($reasons);
        $top = array_key_first($reasons);

        return match (true) {
            $top === 'currency_mismatch' => $ar ? 'عملة الإنفاق غير عملة الميزانية، فما نقارنهم' : 'Spend is in another currency than the budget, so they are not compared',
            in_array($top, ['partial', 'mixed_currency', 'complete_withheld', 'absent'], true) => $ar ? 'جزء من الإنفاق بدون سعر صرف، فما نحسب الرقم' : 'Part of the spend has no exchange rate, so the figure is not computed',
            $top === null && $unmeasured > 0 => $ar ? 'ما وصلت أرقام إنفاق لهذا الشهر بعد' : 'No spend figures have arrived for this month yet',
            default => $ar ? 'ما نقدر نحسب الإنفاق بعملة الميزانية' : 'Spend cannot be stated in the budget’s currency',
        };
    }

    private static function campaignsOutside(int $n, bool $ar): string
    {
        if (! $ar) {
            return AlertEventText::ltr((string) $n).($n === 1 ? ' campaign outside the figure' : ' campaigns outside the figure');
        }

        return match (true) {
            $n === 1 => 'حملة وحدة خارج الرقم',
            $n === 2 => 'حملتين خارج الرقم',
            default => AlertEventText::ltr((string) $n).' حملات خارج الرقم',
        };
    }

    /** @return array<string,mixed> */
    private static function emptyBudget(): array
    {
        return ['currency' => null, 'budget_value' => null, 'spent_value' => null, 'remaining_value' => null, 'projected_value' => null, 'outside' => 0,
            'budget' => '', 'spent' => '', 'remaining' => '', 'over' => false, 'used' => null, 'bar' => 0, 'band' => null, 'band_label' => '', 'tone' => 'none'];
    }

    public static function bandLabel(?string $band, bool $ar): string
    {
        return match ($band) {
            'over' => $ar ? 'تجاوزت الميزانية' : 'Over budget',
            'ahead' => $ar ? 'أسرع من الخطة' : 'Ahead of plan',
            'behind' => $ar ? 'أبطأ من الخطة' : 'Behind plan',
            'on_track' => $ar ? 'ضمن الخطة' : 'On track',
            default => $ar ? 'بدون سرعة صرف' : 'No pace',
        };
    }

    /**
     * Every project's budget row, closest to running out first, and ONE total row.
     *
     * The total adds budget and spend only across projects whose budgets share one currency and whose spend is measured; a
     * project left out is counted, never silently dropped. Two currencies → no total at all, with the reason. Results are never
     * summed here: this block is money only, so no objective is ever added to another.
     *
     * @param  list<array<string,mixed>>  $projects  built by {@see project()}
     * @return array<string,mixed>
     */
    private static function budgets(array $projects, string $lang): array
    {
        $ar = $lang === 'ar';
        $rows = [];
        $none = 0;
        foreach ($projects as $pr) {
            if ($pr['budget'] === null) {
                continue;
            }
            // A project with no budget is counted on one line, not drawn as thirty empty rows.
            if ($pr['budget']['state'] === 'none') {
                $none++;

                continue;
            }
            $rows[] = ['id' => $pr['id'], 'name' => $pr['name'], 'url' => $pr['links']['budget']] + $pr['budget'];
        }
        // A project whose budget cannot be stated comes FIRST (somebody has to fix its money), then closest to running out.
        usort($rows, static fn (array $a, array $b): int => (($b['state'] !== 'ok') <=> ($a['state'] !== 'ok'))
            ?: (($b['used'] ?? -1) <=> ($a['used'] ?? -1)) ?: strcmp((string) $a['name'], (string) $b['name']));

        $counted = array_values(array_filter($rows, static fn (array $r): bool => $r['state'] === 'ok'));
        $currencies = array_values(array_unique(array_map(static fn (array $r): string => strtoupper((string) $r['currency']), $counted)));
        $left = count($rows) - count($counted) + $none;
        $total = null;
        $reason = null;
        if (count($currencies) > 1) {
            $reason = $ar ? 'المشاريع بعملات مختلفة، فما فيه مجموع واحد' : 'Projects budget in different currencies, so there is no single total';
        } elseif ($counted !== []) {
            $budget = array_sum(array_column($counted, 'budget_value'));
            $spent = array_sum(array_column($counted, 'spent_value'));
            $projectable = array_filter($counted, static fn (array $r): bool => $r['projected_value'] === null) === [];
            $projected = $projectable ? array_sum(array_column($counted, 'projected_value')) : null;
            $elapsed = (int) ($counted[0]['elapsed'] ?? 0);
            $remaining = array_sum(array_column($counted, 'remaining_value'));
            $total = self::budget([
                'budget' => $budget, 'spent' => $spent, 'remaining' => $remaining, 'currency' => $currencies[0] ?? null, 'currencies' => 1,
                'pace' => $projected !== null && $budget > 0 ? round($projected / $budget, 4) : null, 'projected' => $projected, 'elapsed' => $elapsed / 100,
                // The campaigns each counted project left out, so the total says it is short of them rather than looking whole.
                'outside' => array_sum(array_column($counted, 'outside')),
                'outside_reasons' => array_reduce($counted, static function (array $all, array $r): array {
                    foreach ((array) ($r['outside_reasons'] ?? []) as $k => $n) {
                        $all[$k] = ($all[$k] ?? 0) + (int) $n;
                    }

                    return $all;
                }, []),
            ], $lang);
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'total_reason' => $reason,
            'left_out' => $left,
            'left_out_line' => $left > 0 ? ($ar ? AlertEventText::ltr((string) $left).' بدون ميزانية أو إنفاق مقيس، خارج المجموع' : AlertEventText::ltr((string) $left).' without a budget or measured spend, outside the total') : null,
        ];
    }

    /**
     * The month as week bars: days 1–7, 8–14, 15–21, 22–28 and 29 to the end — four or five bars, plain calendar days.
     *
     * @param  list<array<string,mixed>>  $series  daily `{date, spend}` points across the month
     * @return list<array<string,mixed>>
     */
    public static function weeks(array $series, bool $ar): array
    {
        $buckets = [];
        foreach ($series as $pt) {
            try {
                $d = Carbon::parse((string) ($pt['date'] ?? ''));
            } catch (\Throwable) {
                continue;
            }
            $i = intdiv($d->day - 1, 7);
            $buckets[$i]['from'] ??= $d->day;
            $buckets[$i]['to'] = $d->day;
            if (is_numeric($pt['spend'] ?? null)) {
                $buckets[$i]['spend'] = (float) ($buckets[$i]['spend'] ?? 0) + (float) $pt['spend'];
            } else {
                $buckets[$i]['spend'] ??= null;
            }
        }
        ksort($buckets);
        $bars = self::bars(array_map(static fn (array $b): ?float => $b['spend'] ?? null, array_values($buckets)), [], $ar);

        return array_values(array_map(static fn (array $bar, array $b): array => array_merge($bar, ['label' => $b['from'].'–'.$b['to']]), $bars, array_values($buckets)));
    }

    /**
     * The best (or weakest) platform of the month on the OBJECTIVE's own headline figure — a cost falls to be better, a return rises.
     *
     * Null with fewer than two platforms that carry the figure: a ranking of one is not a ranking.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,string>|null
     */
    /**
     * OBJECTIVE-PATHS-001 — the one total, and each objective's share of it with its own result.
     *
     * Only where ad sets bought more than one thing; a single objective is already the tile row.
     *
     * @param  array<string, mixed>|null  $block
     * @return array{total: string, paths: list<array{family: string, label: string, share: int, spend: string, result: string, roas: string|null}>}|null
     */
    private static function objectivePaths(?array $block, DigestPresenter $p, bool $ar): ?array
    {
        if ($block === null || ($block['mixed'] ?? false) !== true) {
            return null;
        }

        return [
            'total' => $p->money(isset($block['total_spend']) ? (float) $block['total_spend'] : null),
            'paths' => array_values(array_map(static fn (array $path): array => [
                'family' => (string) $path['family'],
                'label' => (string) ($path['label'][$ar ? 'ar' : 'en'] ?? $path['family']),
                'share' => (int) round(((float) ($path['share'] ?? 0)) * 100),
                'spend' => $p->money((float) $path['spend']),
                'result' => $path['result'] === null
                    ? '—'
                    : ($path['result_label'][$ar ? 'ar' : 'en'] ?? '').' '.number_format((float) $path['result']),
                'roas' => $path['family'] === 'sales' && $path['roas'] !== null ? $p->ratio((float) $path['roas']) : null,
            ], (array) ($block['paths'] ?? []))),
        ];
    }

    private static function extreme(array $rows, string $objective, DigestPresenter $p, bool $ar, bool $best): ?array
    {
        $key = self::HEADLINE[$objective] ?? null;
        if ($key === null) {
            return null;
        }
        $have = array_values(array_filter($rows, static fn (array $r): bool => is_numeric($r[$key] ?? null) && (float) ($r['spend'] ?? 0) > 0));
        if (count($have) < 2) {
            return null;
        }
        $lower = (bool) (self::FIG[$key][3] ?? false);
        usort($have, static fn (array $a, array $b): int => $lower ? ((float) $a[$key] <=> (float) $b[$key]) : ((float) $b[$key] <=> (float) $a[$key]));
        $row = $best ? $have[0] : $have[count($have) - 1];
        $fig = self::figures([$key], $row, [], [], $p, $ar)[0];

        return ['name' => AdPlatforms::name((string) $row['provider'], $ar ? 'ar' : 'en'), 'label' => $fig['label'], 'value' => $fig['value']];
    }

    /**
     * One tile row per OBJECTIVE across every project: the additive figures are summed within the objective, a ratio is recomputed
     * from those sums, and what cannot be rolled up (reach, frequency, rates) is not shown. Never a figure across unlike objectives.
     *
     * @param  list<array<string,mixed>>  $projects
     * @return list<array<string,mixed>>
     */
    private static function portfolioGroups(array $projects, DigestPresenter $p, bool $ar): array
    {
        $add = ['spend', 'impressions', 'clicks', 'purchases', 'revenue', 'leads', 'installs', 'engagements', 'video_views'];
        $roll = [];
        foreach ($projects as $pr) {
            $gs = $pr['groups'] !== [] ? $pr['groups'] : [['objective' => $pr['objective'], 'raw' => ['totals' => $pr['raw']['totals'], 'previous' => $pr['raw']['previous']]]];
            foreach ($gs as $g) {
                $o = (string) $g['objective'];
                $roll[$o]['projects'][(string) $pr['id']] = true;
                foreach (['now' => 'totals', 'prev' => 'previous'] as $side => $src) {
                    foreach ($add as $k) {
                        $v = $g['raw'][$src][$k] ?? null;
                        $roll[$o][$side][$k] = ($roll[$o][$side][$k] ?? null) === null && ! is_numeric($v) ? null : (float) ($roll[$o][$side][$k] ?? 0) + (is_numeric($v) ? (float) $v : 0.0);
                    }
                }
            }
        }
        $ratios = static function (array $x): array {
            $d = static fn (?float $n, ?float $den, float $mul = 1.0): ?float => $n !== null && $den !== null && $den > 0 ? round($n / $den * $mul, 4) : null;

            return $x + [
                'roas' => $d($x['revenue'] ?? null, $x['spend'] ?? null), 'cpa' => $d($x['spend'] ?? null, $x['purchases'] ?? null), 'cpl' => $d($x['spend'] ?? null, $x['leads'] ?? null),
                'cpm' => $d($x['spend'] ?? null, $x['impressions'] ?? null, 1000.0), 'cpc' => $d($x['spend'] ?? null, $x['clicks'] ?? null), 'ctr' => $d($x['clicks'] ?? null, $x['impressions'] ?? null),
                'cpi' => $d($x['spend'] ?? null, $x['installs'] ?? null),
            ];
        };
        $order = ['sales', 'leads', 'traffic', 'awareness', 'video', 'app_installs', 'engagement', 'custom'];
        uksort($roll, static fn (string $a, string $b): int => (array_search($a, $order, true) === false ? 99 : array_search($a, $order, true)) <=> (array_search($b, $order, true) === false ? 99 : array_search($b, $order, true)));

        $out = [];
        foreach ($roll as $o => $r) {
            $keys = self::ROLL_UP[$o] ?? ['impressions', 'clicks', 'ctr'];
            $out[] = [
                'objective' => $o,
                'label' => self::objectiveLabel($o, $ar),
                'projects' => count($r['projects']),
                // The objective's own figures, at most three (one tile row); the objective's spend is its own first tile.
                'spend' => self::figures(['spend'], $ratios($r['now'] ?? []), $ratios($r['prev'] ?? []), [], $p, $ar)[0],
                'kpis' => self::figures(array_slice($keys, 0, 3), $ratios($r['now'] ?? []), $ratios($r['prev'] ?? []), [], $p, $ar),
            ];
        }

        return $out;
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
                    'decimal' => number_format((float) $value, 1),
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
                $out[] = $names[$ar ? 0 : 1].' '.($c[$key] > 0 ? ($ar ? 'زاد' : 'rose') : ($ar ? 'نزل' : 'fell')).' '.number_format(abs((float) $c[$key]) * 100, 0).'% '.($ar ? 'عن ' : 'against ').$versus;
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
                    $state === 'none' => $ar ? 'ما وصلت أرقام في هذه الفترة' : 'No figures arrived in this period',
                    $tone === 'good' => $ar ? 'الأداء أحسن من الفترة اللي قبلها' : 'Performance is improving on the previous period',
                    default => $ar ? 'ما في شيء يحتاج قرار' : 'Nothing needs a decision now',
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
    private static function cells(array $block, array $rows, array $prevByProvider, array $budgetBy, array $sources, string $objective, DigestPresenter $p, bool $ar, string $projectId, string $projectName, int $days, ?string $groupLabel): array
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
                'alert' => DigestStatus::alertState($mine = self::alertsOn($key, (array) ($block['open_alerts'] ?? []))),
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
                $issue = self::issue($verdict, $key, $name, $row, $prev, $budget, $headlineKey, $resultKey, $p, $ar, $projectId, $projectName, $order, $days, $mine);
                $issue['objective'] = $groupLabel;
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
     * One objective group of a project: its own tile row, its platforms (spend and the objective's headline figure), its funnel.
     *
     * @param  array<string,mixed>  $g
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>>|array<string,array<string,mixed>>  $prev
     * @param  list<array<string,mixed>>  $cells
     * @return array<string,mixed>
     */
    private static function group(array $g, string $objective, array $rows, array $prev, array $cells, DigestPresenter $p, bool $ar): array
    {
        $keys = self::BY_OBJECTIVE[$objective] ?? self::FALLBACK;
        $head = self::HEADLINE[$objective] ?? 'ctr';
        $reported = (array) ($g['reported'] ?? []);
        $sum = array_sum(array_map(static fn (array $r): float => (float) ($r['spend'] ?? 0), $rows));
        $stateOf = array_column($cells, 'state', 'provider');
        $platforms = [];
        foreach ($rows as $row) {
            $provider = (string) ($row['provider'] ?? '');
            $platforms[] = [
                'provider' => $provider,
                'name' => AdPlatforms::name($provider, $ar ? 'ar' : 'en'),
                'state' => $stateOf[AdPlatforms::canonical($provider)] ?? 'none',
                'share' => $sum > 0 ? (int) round(((float) ($row['spend'] ?? 0)) / $sum * 100) : 0,
                // The platform row shows the objective's primary figure, and only that: ROAS never appears on an awareness row.
                'figures' => self::figures(['spend', $head], $row, (array) ($prev[$provider] ?? []), (array) ((($g['platform_reported'] ?? [])[$provider] ?? null) ?? $reported), $p, $ar),
            ];
        }
        $states = array_column($cells, 'state');

        return [
            'objective' => $objective,
            'label' => self::objectiveLabel($objective, $ar),
            'campaigns' => (int) ($g['campaigns'] ?? 0),
            'state' => in_array('bad', $states, true) ? 'bad' : (in_array('watch', $states, true) ? 'watch' : (array_filter($states, static fn (string $x): bool => $x !== 'none') === [] ? 'none' : 'ok')),
            'kpis' => self::figures(['spend', ...$keys], (array) ($g['totals'] ?? []), (array) ($g['previous'] ?? []), $reported, $p, $ar),
            'raw' => ['totals' => (array) ($g['totals'] ?? []), 'previous' => (array) ($g['previous'] ?? [])],
            'headline_key' => $head,
            'platforms' => $platforms,
            'funnel' => self::funnel((array) ($g['funnel'] ?? []), $ar),
        ];
    }

    /**
     * The worst verdict per platform across the groups: the same platform can carry an awareness and a sales campaign.
     *
     * @param  list<list<array<string,mixed>>>  $sets
     * @return list<array<string,mixed>>
     */
    private static function mergeCells(array $sets): array
    {
        $by = [];
        foreach ($sets as $cells) {
            foreach ($cells as $c) {
                $k = $c['provider'];
                if (! isset($by[$k]) || DigestStatus::STATE_RANK[$c['state']] < DigestStatus::STATE_RANK[$by[$k]['state']]
                    || ($c['state'] === $by[$k]['state'] && ($c['issue'] ?? null) !== null && ($by[$k]['issue'] ?? null) === null)) {
                    $by[$k] = $c;
                }
            }
        }
        uksort($by, static fn (string $a, string $b): int => AdPlatforms::rank($a) <=> AdPlatforms::rank($b));

        return array_values($by);
    }

    /**
     * The alerts that touch this platform's cell: those naming it, plus those naming none (campaign- or project-level, which mark
     * every cell, as on the board). The website's own alerts (`ga4`) are not a platform's.
     *
     * @param  list<array<string,mixed>>  $alerts
     * @return list<array<string,mixed>>
     */
    private static function alertsOn(string $provider, array $alerts): array
    {
        return array_values(array_filter($alerts, static function (array $a) use ($provider): bool {
            $named = (string) ($a['provider'] ?? '');

            return $named === '' || ($named !== 'ga4' && AdPlatforms::canonical($named) === $provider);
        }));
    }

    /**
     * @param  list<array<string,mixed>>  $alerts
     * @return array{priority: string, sev: int, fired: int}
     */
    private static function rankOf(string $state, array $alerts): array
    {
        $best = null;
        foreach ($alerts as $a) {
            $key = [DigestStatus::PRIORITY_RANK[$a['priority'] ?? 'P3'] ?? 2, $a['severity'] === 'critical' ? 0 : 1, -(int) ($a['fired'] ?? 0)];
            if ($best === null || $key < $best[0]) {
                $best = [$key, $a];
            }
        }
        if ($best !== null && ! ($best[1]['snoozed'] ?? false)) {
            return ['priority' => (string) ($best[1]['priority'] ?? 'P3'), 'sev' => $best[0][1], 'fired' => (int) ($best[1]['fired'] ?? 0)];
        }

        return ['priority' => $state === 'bad' ? 'P1' : 'P3', 'sev' => $state === 'bad' ? 0 : 1, 'fired' => 0];
    }

    /** @return array<string,mixed> */
    private static function issue(array $verdict, string $provider, string $platform, array $row, array $prev, ?array $budget, string $headlineKey, ?string $resultKey, DigestPresenter $p, bool $ar, string $projectId, string $projectName, int $order, int $days, array $alerts = []): array
    {
        $reason = (string) $verdict['reason'];
        $label = self::FIG[$headlineKey][$ar ? 0 : 1] ?? $headlineKey;
        $fmt = static fn (string $key, mixed $v): string => match (self::FIG[$key][2] ?? 'count') {
            'money' => $p->money((float) $v),
            'percent' => $p->percent((float) $v),
            'ratio' => $p->ratio((float) $v),
            'decimal' => number_format((float) $v, 1),
            default => number_format((float) $v),
        };
        $pct = static fn (float $r): string => AlertEventText::ltr(number_format(abs($r) * 100, 0).'%');
        $viz = null;
        $headline = '';
        $figure = '';

        switch ($reason) {
            case 'sync_failed':
                $headline = $ar ? 'المزامنة متوقفة' : 'Sync stopped';
                break;
            case 'alert_bad':
            case 'alert_watch':
                $headline = $reason === 'alert_bad' ? ($ar ? 'تنبيه مفتوح، قرّر فيه' : 'An open alert needs a decision') : ($ar ? 'تنبيه مفتوح للمتابعة' : 'An open alert to follow');
                break;
            case 'stale':
                $headline = $ar ? 'البيانات متأخرة' : 'Data is out of date';
                break;
            case 'no_results':
                $headline = $ar ? 'صرف بدون نتائج' : 'Spend with no results';
                $figure = ($ar ? 'صرف ' : 'Spent ').$p->money((float) ($row['spend'] ?? 0)).' · '.(self::FIG[$resultKey ?? 'conversions'][$ar ? 0 : 1] ?? '').' 0';
                break;
            case 'pace_hot':
            case 'pace_slow':
                $pace = (float) ($budget['pace'] ?? 0);
                $used = (int) round(min(1.0, (float) ($budget['spent'] ?? 0) / max(0.0001, (float) ($budget['budget'] ?? 1))) * 100);
                $expected = $pace > 0 ? (int) round(min(100, $used / $pace)) : 0;
                $headline = $reason === 'pace_hot' ? ($ar ? 'الإنفاق أسرع من الخطة' : 'Spending ahead of plan') : ($ar ? 'الإنفاق أبطأ من الخطة' : 'Spending behind plan');
                // Both bars are shares of the BUDGET, so «used» and «plan» are comparable and no second percentage can contradict the first.
                $figure = ($ar ? 'انصرف ' : 'Spent ').AlertEventText::ltr($used.'%').' · '.($ar ? 'المخطط ' : 'planned ').AlertEventText::ltr($expected.'%');
                $viz = ['type' => 'pace', 'used' => $used, 'plan' => $expected, 'used_label' => ($ar ? 'الإنفاق' : 'Spent').' '.AlertEventText::ltr($used.'%'), 'plan_label' => ($ar ? 'الخطة' : 'Plan').' '.AlertEventText::ltr($expected.'%')];
                break;
            case 'worse':
            case 'results_down':
                $key = $reason === 'results_down' ? (string) $resultKey : $headlineKey;
                $now = (float) ($row[$key] ?? 0);
                $was = (float) ($prev[$key] ?? 0);
                $label = self::FIG[$key][$ar ? 0 : 1] ?? $key;
                $up = $now > $was;
                $fem = in_array($key, ['cpl', 'cpa', 'cpc', 'cpm', 'cpi', 'revenue', 'purchases', 'conversions', 'clicks', 'installs', 'engagements', 'video_views'], true);
                $verb = $ar ? ($up ? ($fem ? 'زادت' : 'زاد') : ($fem ? 'نزلت' : 'نزل')) : ($up ? 'rose' : 'fell');
                $headline = $label.' '.$verb.' '.$pct($was > 0 ? ($now - $was) / $was : 0);
                $figure = $fmt($key, $was).' ← '.$fmt($key, $now);
                $max = max($now, $was, 0.0001);
                $viz = ['type' => 'before_now', 'before' => max(6, (int) round($was / $max * 100)), 'now' => max(6, (int) round($now / $max * 100)), 'before_label' => $fmt($key, $was), 'now_label' => $fmt($key, $now), 'key' => $key];
                break;
        }

        // Priority, severity and recency, as the board ranks: the worst open alert on the cell when there is one; else a metric finding is P1 when it is a problem and P3 when it is a watch.
        $meta = self::rankOf($verdict['state'], $alerts);
        // The objective's own figure for this platform, for the card's leading edge: big value, small metric name, delta.
        $fig = self::figures([$headlineKey], $row, $prev, [], $p, $ar)[0] ?? null;

        return $meta + [
            'kind' => 'cell',
            'state' => $verdict['state'],
            'reason' => $reason,
            'project_id' => $projectId,
            'project' => $projectName,
            'provider' => $provider,
            'platform' => $platform,
            'headline' => $headline,
            'figure' => $figure,
            'fig' => $fig,
            'viz' => $viz,
            'at_stake' => (float) ($row['spend'] ?? 0),
            'order' => $order,
            'url' => in_array($reason, ['sync_failed', 'stale'], true) ? MailDeepLinks::integrations($projectId) : (str_starts_with($reason, 'alert_') ? MailDeepLinks::alerts() : MailDeepLinks::platforms($projectId, $days, $provider)),
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
            $out[] = self::rankOf($note['severity'] === 'critical' ? 'bad' : 'watch', []) + [
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
            $out[] = self::rankOf('bad', []) + [
                'kind' => 'project', 'state' => 'bad', 'reason' => 'sync_failed', 'project_id' => $projectId, 'project' => $projectName,
                'provider' => '', 'platform' => '', 'headline' => $ar ? 'المزامنة متوقفة' : 'Sync stopped', 'figure' => $ar ? 'الأرقام ممكن تكون ناقصة' : 'Figures may be incomplete',
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
            $line = ['name' => $pr['name'], 'text' => $pr['headline']['label'].' '.($d > 0 ? ($ar ? 'زاد' : 'rose') : ($ar ? 'نزل' : 'fell')).' '.number_format(abs($d) * 100, 0).'% '.($ar ? 'وصار ' : 'to ').$pr['headline']['value'], 'score' => (string) $score];
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
