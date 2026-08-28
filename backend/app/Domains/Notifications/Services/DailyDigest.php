<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Services\ReportObjectiveLens;
use App\Domains\Reports\Services\ReportObservations;
use App\Domains\Reports\Services\ReportTemplateEngine;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What a person needs to know this morning, and what to do about it — MAIL-001.
 *
 * ## The product promise this serves
 *
 * «كل حملاتك الإعلانية المدفوعة في مكان واحد» is only true if the operator does not have to open
 * the place. A digest exists so that nobody logs in to find out whether anything happened; they log
 * in because the email told them something did.
 *
 * That makes the writing rule as important as the arithmetic: every block answers **what happened,
 * why it matters, what changed, and what to do** — a row of figures with no verdict is a page of
 * numbers delivered by email, which is worse than the page because it cannot be filtered.
 *
 * ## One pipeline, and no exceptions for email
 *
 * Every figure comes from `MetricsAggregator` — the same engine the dashboard, the analytics tab and
 * the client link read (§15.17). A digest that computed its own totals would eventually disagree
 * with the dashboard it links to, and the reader would have no way to know which was lying. So this
 * class contains no SQL at all.
 *
 * ## Objective-aware, and never blended
 *
 * A cost per order across an awareness campaign and a sales campaign divides one objective's money
 * by another objective's events. The arithmetic works and the number is meaningless. So the digest
 * reports per PATH — awareness money beside awareness results — and offers a headline cost only for
 * the conversion path. `MarketingPath::headlineMetrics()` is the same answer the reports use.
 *
 * ## An unreported metric is not a zero
 *
 * `MetricsAggregator::reportedKeys()` says which base metrics any platform actually sent in the
 * window. A key that was never sent is `null` here and renders as «لم ترسله المنصة» in the email —
 * because «Reach 0» in somebody's inbox, over their morning coffee, is a false alarm they cannot
 * check without opening the product the digest exists to save them from opening.
 */
final class DailyDigest
{
    public function __construct(
        private readonly MetricsAggregator $metrics,
        private readonly DataFreshnessService $freshness,
        private readonly ReportObservations $observations,
        private readonly ReportTemplateEngine $template,
        private readonly DigestCreatives $creatives,
        private readonly DigestRecommendations $recommendations,
    ) {}

    /**
     * The digest for one recipient over one day.
     *
     * `$projectIds` is the ceiling from {@see DigestScope} and is trusted to be already narrowed —
     * this class never widens it and never resolves permissions of its own.
     *
     * @param  list<string>  $projectIds
     * @return array<string,mixed>
     */
    public function build(User $user, string $tenantId, array $projectIds, Carbon $day): array
    {
        return $this->buildRange($user, $tenantId, $projectIds, $day->copy()->startOfDay(), $day->copy()->endOfDay());
    }

    /**
     * The same digest over any window — one day, or a week (MAIL-005).
     *
     * The weekly executive digest is this method over seven days, not a second engine. Every rule
     * the daily digest follows — the path split, the absent blended cost, «not reported» rather than
     * zero — is a rule the weekly inherits by construction rather than by being reimplemented and
     * kept in step. The comparison window is always the SAME LENGTH immediately before, so a week is
     * compared with a week and a day with a day.
     *
     * @param  list<string>  $projectIds
     * @return array<string,mixed>
     */
    public function buildRange(User $user, string $tenantId, array $projectIds, Carbon $from, Carbon $to): array
    {
        /*
         * EMAIL-SETTINGS-DEPTH-001 — read once, here, from the recipient's own row.
         *
         * Read from `digests.recommendations`, which already holds this person's daily/weekly/alert
         * opt-ins, so a new column was not needed and a stored map written before this setting existed
         * is simply a map without the key. Absent means OFF: a preference nobody has expressed is not
         * consent to put somebody's approved recommendations into a colleague's inbox.
         */
        $wantsRecommendations = $this->recommendations->enabledFor($user, $tenantId);

        // Nothing reachable → nothing to say. The caller must not send an email at all; an empty
        // digest that says «no data» is indistinguishable from a real day with no spend.
        if ($projectIds === []) {
            return ['sendable' => false, 'reason' => 'no_projects_in_scope', 'projects' => []];
        }

        /*
         * DIGEST-PREV-WINDOW-001 — the comparison window must be the SAME LENGTH as the current one.
         *
         * Two faults compounded here, and both are invisible in the output because an oversized
         * previous window just makes every trend read slightly worse.
         *
         * 1. `$to` is an `endOfDay()`, so `diffInDays` returned a FLOAT — 7.999999999988426 for a
         *    seven-day window. `$days` was then fractional everywhere it was used.
         * 2. `$prevFrom` counted back from `$prevTo` (the day BEFORE the window) instead of from
         *    `$from`, which is one further day again.
         *
         * Together, a seven-day window compared itself against NINE days: 13–19 Aug against 4–12 Aug.
         * Nine days of spend against seven is not a trend, it is a longer ruler — and it applied to
         * every rhythm, daily, weekly and monthly alike.
         *
         * Counting from `startOfDay()` on both sides makes `$days` a whole number of calendar days,
         * and `$prevFrom` is now `$from` minus exactly that many days.
         */
        $days = (int) max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $from->copy()->subDays($days)->startOfDay();

        $projects = Project::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $projectIds)
            ->get(['id', 'name', 'client_workspace_id']);

        $blocks = [];
        foreach ($projects as $project) {
            $block = $this->forProject($tenantId, (string) $project->id, $from, $to, $prevFrom, $prevTo, $wantsRecommendations);
            $block['project_id'] = (string) $project->id;
            $block['project_name'] = (string) $project->name;
            $blocks[] = $block;
        }

        /*
         * A day where nothing was spent anywhere is not a digest.
         *
         * Sending «0 SAR across 6 projects» every morning is how a daily email becomes a filter
         * rule. The caller records the decision rather than silently skipping, so «why didn't I get
         * one» has an answer in the ledger.
         */
        $anySpend = array_sum(array_map(static fn (array $b): float => $b['totals']['spend'] ?? 0.0, $blocks)) > 0;

        return [
            'sendable' => $anySpend,
            'reason' => $anySpend ? null : 'no_activity',
            'date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'days' => $days,
            'previous_date' => $prevFrom->toDateString(),
            'projects' => $blocks,
            'totals' => $this->rollUp($blocks),
        ];
    }

    /**
     * One project's day.
     *
     * @return array<string,mixed>
     */
    private function forProject(string $tenantId, string $projectId, Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo, bool $withRecommendations): array
    {
        $scoped = $this->metrics->acrossProjects()->forProjects([$projectId]);

        $current = $scoped->totals($from, $to);
        $previous = $scoped->totals($prevFrom, $prevTo);
        $reported = $scoped->reportedKeys($from, $to);

        $platforms = $scoped->byProvider($from, $to);
        $campaigns = $scoped->byCampaign($from, $to);

        /*
         * What this project's money was FOR — the same lens the reports use (§14.6).
         *
         * It decides which KPI cards lead and which figures the notes are allowed to talk about, so
         * a brand project's digest is not scored on a cost per order it never tried to produce.
         */
        $lens = ReportObjectiveLens::infer($campaigns);
        $budget = $scoped->budgetPacing($from, $to, $to);
        $funnel = $scoped->funnel($from, $to);
        $freshness = $this->freshnessFor($projectId, $from, $to);

        $block = [
            'objective' => $lens->value(),
            'metric_set' => $this->template->metricSet($lens->value()),
            'totals' => $current,
            'previous' => $previous,
            'reported' => $reported,
            'change' => $this->change($current, $previous),
            'paths' => $this->byPath($campaigns),
            'best_platform' => $this->pick($platforms, 'provider', best: true),
            'worst_platform' => $this->pick($platforms, 'provider', best: false),
            'best_campaign' => $this->pick($campaigns, 'campaign_name', best: true),
            'worst_campaign' => $this->pick($campaigns, 'campaign_name', best: false),
            'budget' => $this->budgetAttention($budget),
            // The funnel STAGES only — `funnel()` returns its own spend beside them and the email has
            // no room for a second spend figure it would then have to reconcile.
            'funnel' => $funnel['stages'],
            'creatives' => $this->creatives->forProject($projectId, $from, $to),
            /*
             * EMAIL-SETTINGS-DEPTH-001 — carried only when this recipient asked for them.
             *
             * An empty list and «switched off» are deliberately the same shape here: the presenter
             * renders nothing either way, and the digest never announces a section the reader turned
             * off. What it must never do is the reverse — silently start mailing a colleague's
             * approved recommendations to somebody who did not ask for them.
             */
            'recommendations' => $withRecommendations
                ? $this->recommendations->forProject($tenantId, $projectId, $from, $to)
                : [],
            'freshness' => $freshness,
        ];

        /*
         * The notes, from the SAME engine the reports use — MAIL-005.
         *
         * A second set of thresholds would mean the email and the report could disagree about
         * whether a campaign is overspending, and the reader has no way to tell which is right. The
         * detectors read a snapshot-shaped array, so the digest hands them one.
         */
        $block['observations'] = $this->observations->build($lens, [
            'currency' => 'SAR',
            'kpis' => $current,
            'delta' => $block['change'],
            'reported' => $reported,
            'metric_set' => $block['metric_set'],
            'platforms' => $platforms,
            'budget' => $budget,
            'freshness' => $freshness,
        ]);

        return $block;
    }

    /**
     * Day-over-day change, as a ratio, for the figures a person acts on.
     *
     * `null` where either side is missing or the previous day was zero: «+100% on nothing» is not a
     * change, and an arrow beside it is a claim the data does not support.
     *
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>  $previous
     * @return array<string,float|null>
     */
    private function change(array $current, array $previous): array
    {
        $keys = ['spend', 'impressions', 'clicks', 'conversions', 'revenue', 'purchases', 'leads', 'cpa', 'roas', 'ctr'];
        $out = [];

        foreach ($keys as $key) {
            $now = $current[$key] ?? null;
            $was = $previous[$key] ?? null;
            $out[$key] = is_numeric($now) && is_numeric($was) && (float) $was != 0.0
                ? round(((float) $now - (float) $was) / abs((float) $was), 4)
                : null;
        }

        return $out;
    }

    /**
     * The day split by marketing path — awareness money beside awareness results.
     *
     * This is the block that keeps the digest honest about objectives. A single «cost per result»
     * over a mixed account divides a brand campaign's spend by a sales campaign's orders; reported
     * per path, each figure answers the question its own money was spent to answer, and the
     * conversion path is the only one that carries a cost per order at all.
     *
     * @param  list<array<string,mixed>>  $campaigns
     * @return array<string,mixed>
     */
    private function byPath(array $campaigns): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $c): ?string => isset($c['campaign_id']) ? (string) $c['campaign_id'] : null,
            $campaigns,
        )));

        $objectives = $ids === [] ? [] : UnifiedCampaign::query()
            ->whereIn('id', $ids)
            ->pluck('objective', 'id')
            ->map(static fn ($o): string => (string) $o)
            ->all();

        $buckets = [];
        foreach (MarketingPath::cases() as $path) {
            $buckets[$path->value] = ['spend' => 0.0, 'conversions' => 0.0, 'revenue' => 0.0, 'campaigns' => 0];
        }

        foreach ($campaigns as $row) {
            $id = isset($row['campaign_id']) ? (string) $row['campaign_id'] : null;
            $objective = CampaignObjective::tryFrom((string) ($objectives[$id] ?? '')) ?? CampaignObjective::Other;
            $key = $objective->path()->value;

            $buckets[$key]['spend'] += (float) ($row['spend'] ?? 0);
            $buckets[$key]['conversions'] += (float) ($row['conversions'] ?? 0);
            $buckets[$key]['revenue'] += (float) ($row['revenue'] ?? 0);
            $buckets[$key]['campaigns']++;
        }

        foreach ($buckets as $key => $bucket) {
            $isConversion = $key === MarketingPath::Conversion->value;

            // Only the conversion path gets a cost per result and a return — the other two were
            // never bought to produce one, and printing one anyway is the defect this splits to avoid.
            $buckets[$key]['cost_per_result'] = $isConversion && $bucket['conversions'] > 0
                ? round($bucket['spend'] / $bucket['conversions'], 2)
                : null;
            $buckets[$key]['roas'] = $isConversion && $bucket['spend'] > 0
                ? round($bucket['revenue'] / $bucket['spend'], 2)
                : null;
            $buckets[$key]['headline_metrics'] = MarketingPath::from($key)->headlineMetrics();
        }

        return $buckets;
    }

    /**
     * The best or worst row by cost per result, among rows that HAVE one.
     *
     * Rows with no conversions are excluded rather than ranked last: a campaign that produced
     * nothing has no cost per result, and calling it «the worst» is a comparison against a figure
     * that does not exist. It shows up in the attention list instead, which is where it belongs.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>|null
     */
    private function pick(array $rows, string $labelKey, bool $best): ?array
    {
        $comparable = array_values(array_filter(
            $rows,
            static fn (array $r): bool => isset($r['cpa']) && is_numeric($r['cpa']) && (float) $r['spend'] > 0,
        ));

        /*
         * A ranking of one is not a ranking.
         *
         * With a single comparable row, «best» and «weakest» resolve to the SAME platform and the
         * email prints it twice under two contradictory headings — which reads as a bug and, worse,
         * implies a comparison that was never made. Seen in the live render of a single-platform
         * account. Two rows is the minimum at which either word means anything.
         */
        if (count($comparable) < 2) {
            return null;
        }

        usort($comparable, static fn (array $a, array $b): int => $a['cpa'] <=> $b['cpa']);
        $row = $best ? $comparable[0] : $comparable[count($comparable) - 1];

        return [
            'label' => (string) ($row[$labelKey] ?? '—'),
            'spend' => round((float) $row['spend'], 2),
            'conversions' => round((float) ($row['conversions'] ?? 0), 2),
            'cpa' => round((float) $row['cpa'], 2),
            'roas' => isset($row['roas']) && is_numeric($row['roas']) ? round((float) $row['roas'], 2) : null,
        ];
    }

    /**
     * Only the budgets worth waking up for.
     *
     * Everything pacing normally is noise in an inbox. Over 1.4× or under 0.6× of plan is a decision
     * somebody has to make today; the rest is a number they can look at when they choose to.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function budgetAttention(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $pace = $row['pace'] ?? null;
            if ($pace === null || ((float) $pace <= 1.4 && (float) $pace >= 0.6)) {
                continue;
            }

            $out[] = [
                'campaign' => (string) ($row['campaign_name'] ?? '—'),
                'pace' => round((float) $pace, 2),
                'spent' => $row['spent'] ?? null,
                'budget' => $row['budget'] ?? null,
                'direction' => (float) $pace > 1.4 ? 'ahead' : 'behind',
            ];
        }

        return array_slice($out, 0, 5);
    }

    /**
     * How old the figures are, and whether anything failed.
     *
     * A digest that quotes yesterday's spend from a source that stopped syncing three days ago is
     * confidently wrong, and the reader has no way to tell. Freshness travels with the numbers.
     *
     * @return array<string,mixed>
     */
    private function freshnessFor(string $projectId, Carbon $from, Carbon $to): array
    {
        $tenantId = (string) (Project::query()->whereKey($projectId)->value('tenant_id') ?? '');

        if ($tenantId === '') {
            return ['state' => 'unknown', 'sources' => [], 'sync_failed' => false];
        }

        $state = $this->freshness->state($tenantId, [$projectId], $from, $to, null);

        return [
            'state' => $state['state'] ?? 'unknown',
            'last_sync_at' => $state['last_sync_at'] ?? null,
            'missing_days' => $state['missing_days'] ?? null,
            'sync_failed' => (bool) ($state['sync_failed'] ?? false),
            'failing' => array_values(array_filter(
                array_map(
                    static fn (array $s): ?array => ($s['state'] ?? null) === 'failed'
                        ? ['name' => $s['name'] ?? $s['provider'], 'provider' => $s['provider']]
                        : null,
                    $state['sources'] ?? [],
                ),
            )),
        ];
    }

    /**
     * The account-wide line at the top.
     *
     * Spend and results sum across projects because they are counts of the same thing. A cost per
     * result deliberately does NOT: it would divide one client's money by another client's orders.
     *
     * @param  list<array<string,mixed>>  $blocks
     * @return array<string,mixed>
     */
    private function rollUp(array $blocks): array
    {
        $spend = 0.0;
        $conversions = 0.0;
        $revenue = 0.0;

        foreach ($blocks as $block) {
            $spend += (float) ($block['totals']['spend'] ?? 0);
            $conversions += (float) ($block['totals']['conversions'] ?? 0);
            $revenue += (float) ($block['totals']['revenue'] ?? 0);
        }

        return [
            'projects' => count($blocks),
            'spend' => round($spend, 2),
            'conversions' => round($conversions, 2),
            'revenue' => round($revenue, 2),
            /*
             * Deliberately absent: a blended cost per result and a blended return.
             *
             * Across projects they would divide one client's money by another client's orders. The
             * per-path figures inside each project block are where a cost per result legitimately
             * appears, and this roll-up carries none.
             */
        ];
    }
}
