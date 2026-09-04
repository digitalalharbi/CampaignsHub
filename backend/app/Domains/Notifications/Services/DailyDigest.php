<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\CRM\Models\Lead;
use App\Domains\CRM\Services\FollowUpWorkspace;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\SpendLimitGovernor;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Services\ReportObjectiveLens;
use App\Domains\Reports\Services\ReportObservations;
use App\Domains\Reports\Services\ReportTemplateEngine;
use App\Models\User;
use App\Support\AdPlatforms;
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
        private readonly SpendLimitGovernor $limits,
        private readonly ReportTemplateEngine $template,
        private readonly DigestCreatives $creatives,
        private readonly DigestRecommendations $recommendations,
        private readonly FollowUpWorkspace $followUp,
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

        /*
         * EXECUTIVE-DAILY-DIGEST-001 — a lead-generation day is a day, even with no spend recorded.
         *
         * Spend arrives through a sync that can lag by hours; a lead arrives the moment somebody
         * submits a form. Gating the whole email on spend meant a morning with eleven new leads and
         * a late Meta sync sent nothing at all, and the reader learned about the leads a day later.
         * Either signal makes it a day worth reporting.
         */
        $anyLeads = array_sum(array_map(
            static fn (array $b): int => (int) ($b['follow_up']['received'] ?? 0)
                + (int) ($b['follow_up']['overdue'] ?? 0),
            $blocks,
        )) > 0;

        return [
            'sendable' => $anySpend || $anyLeads,
            'reason' => $anySpend || $anyLeads ? null : 'no_activity',
            'date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'days' => $days,
            'previous_date' => $prevFrom->toDateString(),
            'projects' => $blocks,
            'totals' => $this->rollUp($blocks),
        ];
    }

    /**
     * The follow-up picture for one project, in counts.
     *
     * Built from the SAME service the follow-up workspace screen uses — a second set of definitions
     * would let the email and the screen disagree about what «contacted» means, and the reader has
     * no way to tell which is right. `FollowUpWorkspace` is handed a scoped query and answers it;
     * this class resolves no permissions and widens no scope.
     *
     * Returns null where the project has no CRM activity at all, so a media-only client's digest
     * does not grow a section of zeroes that says nothing.
     *
     * @return array<string,mixed>|null
     */
    private function followUpFor(string $tenantId, string $projectId, Carbon $from, Carbon $to): ?array
    {
        $scope = Lead::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId);

        if (! (clone $scope)->exists()) {
            return null;
        }

        $summary = $this->followUp->summary(clone $scope, $from, $to);

        /*
         * The three that need a person, separated from the twelve that describe the day.
         *
         * A digest that lists every figure equally is a digest nobody acts on. These are the ones
         * where the answer is «somebody has to do something», and the presenter leads with them.
         */
        $summary['attention'] = array_values(array_filter([
            $summary['unassigned'] > 0 ? ['kind' => 'unassigned_leads', 'count' => $summary['unassigned']] : null,
            $summary['overdue'] > 0 ? ['kind' => 'overdue_follow_up', 'count' => $summary['overdue']] : null,
            $summary['not_contacted'] > 0 ? ['kind' => 'never_contacted', 'count' => $summary['not_contacted']] : null,
        ]));

        /*
         * And the same picture per owner, so «the team is slow» can be answered with «who».
         *
         * A team member's NAME is staff, not lead PII — the constraint this section observes is
         * about the client's customers, and blanking a colleague's name would make the block
         * useless without protecting anybody. Owners with no leads in the window are dropped: a
         * roster of zeroes is not a report on the team.
         */
        $names = User::query()
            ->whereIn('id', array_values(array_filter(array_column($this->followUp->byOwner(clone $scope, $from, $to), 'owner_id'))))
            ->pluck('name', 'id');

        $summary['by_owner'] = array_values(array_filter(
            array_map(
                static fn (array $row): array => $row + [
                    'owner_name' => $row['owner_id'] === null ? null : ($names[$row['owner_id']] ?? null),
                ],
                $this->followUp->byOwner(clone $scope, $from, $to),
            ),
            static fn (array $row): bool => ($row['received'] ?? 0) > 0 || ($row['overdue'] ?? 0) > 0,
        ));

        return $summary;
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
        // Per PLATFORM — CLIENT-REPORT-ENTITY-BOUNDARY-001. A digest lands in a client's inbox.
        $budget = $scoped->budgetPacingByProvider($from, $to, $to);
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
            /*
             * CLIENT-REPORT-ENTITY-BOUNDARY-001 — no campaign is named in a digest.
             *
             * These picked the best and worst CAMPAIGN by the objective's own metric and the mail
             * printed the name — «Meta — Retargeting · 4,300 SAR · cost/result 61» — in an email a
             * merchant reads. `best_platform` and `worst_platform` above answer the same question on
             * the axis a client may be told about, and the mailer already falls back to them.
             *
             * `$campaigns` is still read: it is what `ReportObjectiveLens::infer()` classifies the
             * project by, and what `byPath()` divides. The classification is ours; the roster is not
             * the reader's business.
             */
            'best_campaign' => null,
            'worst_campaign' => null,
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
            /*
             * EXECUTIVE-DAILY-DIGEST-001 — what happened AFTER the lead arrived.
             *
             * The digest could already say what was spent and what it produced, and stopped exactly
             * where a lead-generation client's day starts. «40 leads» is not an outcome; forty leads
             * of which eleven nobody has called is.
             *
             * **Counts only — never a name, an email or a phone number.** A digest goes to whoever
             * subscribed to it, through an inbox nobody in this product controls, and lead PII may
             * not be mailed by default. The figures below identify no one, and the link into the
             * product is where a person with the permission goes to see who.
             */
            'follow_up' => $this->followUpFor($tenantId, $projectId, $from, $to),
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
            /*
             * BUDGET-ALERT-EMAIL-001 — the workspace's own ceilings, so a crossing reaches an inbox.
             *
             * `AlertEvaluator` already records the crossing and raises it in-app. The dispatcher
             * builds its findings from THESE observations, so a crossing that never became one was
             * never emailed — which is the half of «budget alerts» that was missing.
             */
            'spend_limits' => $this->spendLimits($projectId, $to),
            'freshness' => $freshness,
        ]);

        return $block;
    }

    /**
     * This project's own spend ceilings, and which threshold each has passed.
     *
     * Read through `SpendLimitGovernor`, the same service the screen and the alert evaluator use —
     * a second reading here would eventually disagree with the panel an operator is looking at while
     * the email says something else.
     *
     * A limit with no comparable figure is returned with `crossed: null` rather than dropped, so the
     * caller can tell «nothing crossed» from «nothing could be computed».
     *
     * @return list<array<string,mixed>>
     */
    private function spendLimits(string $projectId, Carbon $day): array
    {
        $out = [];

        SpendLimit::query()
            ->where('project_id', $projectId)
            ->where('active', true)
            ->whereDate('starts_on', '<=', $day->toDateString())
            ->whereDate('ends_on', '>=', $day->toDateString())
            ->get()
            ->each(function (SpendLimit $limit) use ($day, &$out): void {
                $reading = $this->limits->read($limit, $day->copy()->startOfDay());
                $utilisation = $reading['utilisation'] ?? null;

                $crossed = null;

                if ($utilisation !== null) {
                    $passed = array_filter(
                        $limit->thresholdPercents(),
                        static fn (int $t): bool => (float) $utilisation * 100 >= $t,
                    );
                    $crossed = $passed === [] ? null : max($passed);
                }

                $out[] = [
                    'id' => (string) $limit->getKey(),
                    'scope' => $limit->scope->value,
                    'name' => (string) ($limit->name ?? $limit->scope->value),
                    'currency' => $limit->currency,
                    'amount' => $reading['amount'] ?? null,
                    'consumed' => $reading['consumed'] ?? null,
                    'utilisation' => $utilisation,
                    'crossed' => $crossed,
                ];
            });

        return $out;
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
                // The bucket is a PLATFORM now; the key keeps its name so every reader still parses.
                'campaign' => AdPlatforms::name((string) ($row['provider'] ?? ''), 'ar'),
                'provider' => (string) ($row['provider'] ?? ''),
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

        $prevSpend = 0.0;
        $prevConversions = 0.0;
        $prevRevenue = 0.0;

        foreach ($blocks as $block) {
            $spend += (float) ($block['totals']['spend'] ?? 0);
            $conversions += (float) ($block['totals']['conversions'] ?? 0);
            $revenue += (float) ($block['totals']['revenue'] ?? 0);

            $prevSpend += (float) ($block['previous']['spend'] ?? 0);
            $prevConversions += (float) ($block['previous']['conversions'] ?? 0);
            $prevRevenue += (float) ($block['previous']['revenue'] ?? 0);
        }

        /*
         * EMAIL-DASHBOARD-UX-001 — a KPI without its movement is half a fact.
         *
         * «41,923 ر.س» tells a reader what was spent and nothing about whether that is the usual
         * amount. The previous window is the same length and immediately before, so the comparison
         * is one a person would make themselves.
         *
         * Null where the previous window is zero: every rise from nothing is infinite, and «up ∞%»
         * is not a movement anybody set a threshold on.
         */
        $change = static function (float $now, float $before): ?float {
            return $before <= 0.0 ? null : round(($now - $before) / $before, 4);
        };

        return [
            'projects' => count($blocks),
            'spend' => round($spend, 2),
            'conversions' => round($conversions, 2),
            'revenue' => round($revenue, 2),
            'previous' => [
                'spend' => round($prevSpend, 2),
                'conversions' => round($prevConversions, 2),
                'revenue' => round($prevRevenue, 2),
            ],
            'change' => [
                'spend' => $change($spend, $prevSpend),
                'conversions' => $change($conversions, $prevConversions),
                'revenue' => $change($revenue, $prevRevenue),
            ],
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
