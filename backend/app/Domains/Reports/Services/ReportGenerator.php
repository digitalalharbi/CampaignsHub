<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Disclaimers\Services\DisclaimerResolver;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportAnnotation;
use App\Domains\Reports\Support\ReportScope;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Snapshots a report's data from the SAME metrics aggregation the dashboard/analytics use, so a
 * completed report is reproducible and exportable without recomputing. Writes the snapshot + KPIs +
 * a short auto-generated executive summary into report.data.
 */
final class ReportGenerator
{
    public function __construct(
        private readonly MetricsAggregator $agg,
        private readonly ReportTemplateEngine $template,
        private readonly CreativeRankingService $ranking,
        private readonly DisclaimerResolver $disclaimers,
        private readonly ReportObservations $observations,
        private readonly DataFreshnessService $freshness,
    ) {}

    public function generate(Report $report): array
    {
        app(TenantContext::class)->setTenantId((string) $report->tenant_id);
        app(ProjectContext::class)->setProjectId((string) $report->project_id);

        $to = $report->period_end ? Carbon::parse($report->period_end) : Carbon::today();
        $from = $report->period_start ? Carbon::parse($report->period_start) : $to->copy()->subDays(29);

        /*
         * The report's own scope (§14.5), applied ONCE to the engine every figure below comes from.
         *
         * Applying it here rather than per section is what makes «excluded means excluded» true: the
         * KPIs, the platform table, the campaign ranking, the timeseries, the funnel and the budget
         * pacing all read the same bounded engine, so a campaign taken out of the scope leaves every
         * card, chart and table at once. A scope honoured by four of those seven and forgotten by
         * three would be worse than none, because the totals would no longer equal their own parts.
         *
         * An unbounded scope returns the singleton unchanged, so a report that names no scope behaves
         * exactly as it did before this existed.
         */
        $scope = ReportScope::fromArray($report->scope);
        $agg = $scope->applyTo($this->agg);

        // A scope may narrow the window, never widen it past the period the report was created for.
        if ($scope->from !== null && Carbon::parse($scope->from)->greaterThan($from)) {
            $from = Carbon::parse($scope->from);
        }
        if ($scope->to !== null && Carbon::parse($scope->to)->lessThan($to)) {
            $to = Carbon::parse($scope->to);
        }

        $totals = $agg->totals($from, $to);
        $len = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($len - 1);
        $previous = $agg->totals($prevFrom, $prevTo);
        $delta = [];
        foreach ($totals as $k => $v) {
            $p = $previous[$k] ?? null;
            $delta[$k] = is_numeric($v) && is_numeric($p) && $p != 0 ? round(($v - $p) / abs($p), 4) : null;
        }

        $platforms = $agg->byProvider($from, $to);
        $campaigns = $agg->byCampaign($from, $to);

        /*
         * What this report is FOR, and therefore what it may claim (§14.6).
         *
         * An operator's own choice outranks the inference — they know something the data does not —
         * but nothing else does, and the inference now reads the campaigns' declared objectives
         * rather than guessing backwards from their outcomes. See {@see ReportObjectiveLens}.
         */
        $lens = $report->campaign_objective
            ? new ReportObjectiveLens($report->campaign_objective)
            : ReportObjectiveLens::infer($campaigns);
        $objective = $lens->value();
        $providerList = array_values(array_map(fn ($p) => $p['provider'], $platforms));

        // Initialise the slide layout once (from the objective + connected platforms) if not authored yet.
        $config = $report->config;
        if (empty($config['slides'])) {
            $config = $this->template->defaultConfig($objective, $providerList);
            $report->forceFill(['config' => $config, 'campaign_objective' => $objective])->saveQuietly();
        }

        $topCreatives = $this->ranking->rank($objective, $campaigns);

        $data = [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'currency' => $report->currency,
            'objective' => $objective,
            'platform_order' => $config['platform_order'] ?? $providerList,
            'metric_set' => $config['metric_set'] ?? $this->template->metricSet($objective),
            'kpis' => $totals,
            'previous' => $previous,
            'delta' => $delta,
            /*
             * Which base metrics any platform actually SENT over this window.
             *
             * The pivot coalesces to 0, so `kpis['reach'] === 0` means either «nobody was reached» or
             * «no connected platform reports reach at all» — and a report is the one surface where
             * that difference is read by somebody who cannot ask. Without this map an objective-aware
             * layout would faithfully print «الوصول 0» on a brand report whose platforms simply do
             * not publish the figure.
             */
            'reported' => $agg->reportedKeys($from, $to),
            // Per platform, because «reported» is a fact about a connector: Meta publishes reach and
            // X does not, and the scope-wide map above would let an X page print a reach of zero.
            'reported_by_platform' => $agg->reportedKeysByProvider($from, $to),
            'timeseries' => $agg->timeseries($from, $to),
            'platform_series' => $agg->timeseriesByProvider($from, $to),
            'platforms' => $platforms,
            'best' => $this->leaders($lens, $platforms, $campaigns, $report->currency),
            'campaigns' => $campaigns,
            'top_creatives' => $topCreatives,
            'creative_level' => 'campaign', // ad-level arrives once connectors provide it
            'platform_notes' => $this->platformNotes($lens, $platforms, $report->currency),
            /*
             * `funnel` stays the stage LIST, and the spend it is derived from rides beside it.
             *
             * `MetricsAggregator::funnel()` began returning `['stages' => …, 'spend' => …]` when the
             * funnel was made to state its own spend (UNIFIED-002), and storing that whole array here
             * would have silently emptied the funnel slide of every report: `InteractiveReport` maps
             * over `data.funnel`, and an object is not an array. Unpacked at the call site rather
             * than reverted, because the spend is exactly what made the funnel reconcilable with the
             * dashboard.
             */
            'funnel' => ($adFunnel = $agg->funnel($from, $to))['stages'],
            'funnel_spend' => $adFunnel['spend'],
            'budget' => $agg->budgetPacing($from, $to, Carbon::today()),
            /*
             * Spend and results split by marketing path, with Direct and Blended apart
             * (REPORT-OBJECTIVE-001/003).
             *
             * `kpis` above is the whole scope rolled together, and its `cpa` is therefore a BLENDED
             * cost per order: it divides every campaign's spend by the orders the sales campaigns
             * produced. That is the right number for «what did this programme cost me» and the wrong
             * one for «what does an order cost», and until this key existed a report had no way to
             * say which one it was showing.
             *
             * Built from the same `daily_metrics` the rest of the snapshot comes from — not a second
             * source — so the report's Direct CPA and the dashboard's agree by construction.
             */
            'objective_performance' => $scope->objectivePerformance()->build($from, $to),
            /*
             * The same split for the PREVIOUS window — §14.7's comparison, done honestly.
             *
             * Without it the comparison table put this period's DIRECT cost per order beside last
             * period's BLENDED one, because `previous` only ever held the rolled-up totals. Two
             * different scopes under one heading is the exact confusion `objective_performance`
             * exists to prevent, and a client reading «75 vs 87» would have seen an improvement that
             * is partly an artefact of which campaigns each figure counted.
             */
            'objective_performance_previous' => $scope->objectivePerformance()->build($prevFrom, $prevTo),
            'summary' => $this->executiveSummary($lens, $totals, $delta, $platforms, $campaigns, $report->currency),
            /*
             * The professional analysis — §14.7.
             *
             * Filled in AFTER `$data` is assembled, because every detector reads the snapshot rather
             * than the database: an observation is a statement about the figures this report is
             * showing, and computing it from a second query is how a note comes to contradict the
             * chart printed above it.
             */
            'observations' => [],
            // Structured two-column content: findings (left) + recommendations (right). Cards, not prose.
            'findings' => $this->tagAnnotations($this->findings($lens, $totals, $delta, $platforms, $campaigns, $report->currency), 'finding', $report),
            'recommendations' => ($recs = $this->tagAnnotations($this->recommendations($lens, $platforms, $campaigns, $report->currency), 'recommendation', $report)),
            // Client "Next Steps" — built ONLY from approved recommendations (action/priority/owner/due).
            'next_steps' => $this->nextSteps($recs),
            /*
             * How old these figures are, travelling WITH them (§14.7, §14.10).
             *
             * A report that quotes a month of spend from a source that stopped syncing four days
             * before the period ended is confidently wrong, and a reader has no way to tell. The
             * observations engine reads this too, so «قد تكون بعض المؤشرات غير مكتملة» is a
             * conclusion drawn from the sync state rather than a disclaimer printed on everything.
             */
            'freshness' => $this->freshnessFor($report, $from, $to),
            'audience' => $report->audience ?? 'client',
            'slides' => $config['slides'] ?? [],
            // Effective disclaimer/methodology copy, snapshotted so a shared report is self-contained
            // and reproducible even if the org later edits its notes.
            'disclaimer' => $this->disclaimers->resolve(
                (string) $report->tenant_id,
                DB::table('projects')->where('id', $report->project_id)->value('client_workspace_id'),
                (string) $report->project_id,
            ),
        ];

        // Canonical-snapshot metadata: every export format renders from this exact data, and the
        // checksum lets the print pipeline verify it rendered the snapshot it was given.
        // Now that every figure is in place, read them back and say what happened (§14.7).
        $data['observations'] = $this->observations->build($lens, $data);

        $data['data_version'] = 1;
        $data['tenant_id'] = (string) $report->tenant_id;
        $data['project_id'] = (string) $report->project_id;
        $data['timezone'] = $report->timezone;
        $data['attribution_window'] = $report->attribution_window;
        $data['data_source'] = $report->data_source;
        $data['mode'] = $report->config['mode'] ?? 'snapshot';
        $data['generated_at'] = Carbon::now()->toIso8601String();
        $data['checksum'] = ExportReadinessGate::checksum($data);

        app(ProjectContext::class)->forget();

        return $data;
    }

    /**
     * How current the figures are, and whether any source failed.
     *
     * Read for the report's OWN window rather than «now»: a monthly report closed three weeks ago is
     * not stale because nothing has synced since — it is finished. What matters is whether the
     * sources were keeping up while the period it covers was running.
     *
     * @return array<string,mixed>
     */
    private function freshnessFor(Report $report, Carbon $from, Carbon $to): array
    {
        $state = $this->freshness->state(
            (string) $report->tenant_id,
            [(string) $report->project_id],
            $from,
            $to,
            null,
            // The clock the sync is judged against is the end of the window, not today.
            Carbon::now()->min($to->copy()->endOfDay()),
        );

        return [
            'state' => $state['state'] ?? 'unknown',
            'last_sync_at' => $state['last_sync_at'] ?? null,
            'missing_days' => $state['missing_days'] ?? null,
            'sync_failed' => (bool) ($state['sync_failed'] ?? false),
            'sources' => array_values(array_map(
                static fn (array $s): array => [
                    'name' => $s['name'] ?? $s['provider'] ?? null,
                    'provider' => $s['provider'] ?? null,
                    'state' => $s['state'] ?? 'unknown',
                    'last_sync_at' => $s['last_sync_at'] ?? null,
                ],
                $state['sources'] ?? [],
            )),
            'failing' => array_values(array_filter(array_map(
                static fn (array $s): ?array => ($s['state'] ?? null) === 'failed'
                    ? ['name' => $s['name'] ?? $s['provider'], 'provider' => $s['provider']]
                    : null,
                $state['sources'] ?? [],
            ))),
        ];
    }

    /**
     * The leader board, ranked on the metric this report's money was buying (§14.6).
     *
     * The previous version crowned a ROAS champion and a lowest-CPA platform on EVERY report. On a
     * brand campaign every platform's ROAS is null, `sortByDesc` on a column of nulls returns
     * whichever row happens to be first, and the report then named a «best platform (ROAS)» that had
     * earned nothing — a made-up winner of a competition nobody entered.
     *
     * The two legacy keys stay in the payload so older readers keep working, and they are populated
     * only where they mean something. Everywhere else they are null, which renders as «—».
     *
     * @return array<string,mixed>
     */
    private function leaders(ReportObjectiveLens $lens, array $platforms, array $campaigns, string $currency): array
    {
        $metric = $lens->rankingMetric();
        $rated = collect($platforms)->filter(fn ($p) => ($p[$metric['key']] ?? null) !== null && (float) ($p['spend'] ?? 0) > 0);
        $leader = ($metric['lower_is_better'] ? $rated->sortBy($metric['key']) : $rated->sortByDesc($metric['key']))->first();

        return [
            'basis' => ['key' => $metric['key'], 'label_ar' => $metric['label_ar']],
            'platform' => $leader['provider'] ?? null,
            'platform_value' => $leader ? $lens->formatRanking((float) $leader[$metric['key']], $currency) : null,
            'campaign' => $campaigns[0]['campaign_name'] ?? null,
            'platform_by_roas' => $lens->judgesOnRevenue()
                ? (collect($platforms)->filter(fn ($p) => $p['roas'] !== null)->sortByDesc('roas')->first()['provider'] ?? null)
                : null,
            'platform_by_cpa' => $lens->judgesOnCostPerResult()
                ? (collect($platforms)->filter(fn ($p) => $p['cpa'] !== null)->sortBy('cpa')->first()['provider'] ?? null)
                : null,
            'platform_by_results' => $lens->judgesOnCostPerResult()
                ? (collect($platforms)->sortByDesc('conversions')->first()['provider'] ?? null)
                : null,
        ];
    }

    /**
     * Auto strengths/weaknesses per platform, measured against the average of the metric that matters
     * for this report (suggestions — the user approves before a client sees them).
     *
     * Comparing a platform to an average of ONE is the trap here: with a single connected platform
     * `avg` equals its own value, so it was always «at or above average» and every report shipped a
     * strength that says nothing. A comparison needs somebody to compare with.
     */
    private function platformNotes(ReportObjectiveLens $lens, array $platforms, string $currency): array
    {
        $metric = $lens->rankingMetric();
        $rated = array_values(array_filter($platforms, fn ($p) => ($p[$metric['key']] ?? null) !== null && (float) ($p['spend'] ?? 0) > 0));
        $average = count($rated) > 1 ? $this->avg($rated, $metric['key']) : null;

        $notes = [];
        foreach ($platforms as $p) {
            $strengths = [];
            $weaknesses = [];
            $value = $p[$metric['key']] ?? null;
            if ($value !== null && $average !== null) {
                $better = $metric['lower_is_better'] ? (float) $value <= $average : (float) $value >= $average;
                $sentence = sprintf('%s %s المتوسط (%s).', $metric['label_ar'], $better ? 'أفضل من' : 'دون', $lens->formatRanking((float) $value, $currency));
                $better ? $strengths[] = $sentence : $weaknesses[] = $sentence;
            }
            $notes[$p['provider']] = ['strengths' => $strengths, 'weaknesses' => $weaknesses];
        }

        return $notes;
    }

    private function avg(array $rows, string $key): ?float
    {
        $vals = array_filter(array_map(fn ($r) => $r[$key] ?? null, $rows), fn ($v) => $v !== null);

        return $vals === [] ? null : array_sum($vals) / count($vals);
    }

    /**
     * Left column: short, card-shaped findings (icon/title/detail/severity/platform/kpi) — derived from
     * the numbers, never fabricated. Max 5.
     *
     * @return list<array<string,mixed>>
     */
    private function findings(ReportObjectiveLens $lens, array $t, array $delta, array $platforms, array $campaigns, string $currency): array
    {
        $out = [];
        $metric = $lens->rankingMetric();

        /*
         * Ranked on what this report's money was buying, and only among platforms that HAVE the
         * figure.
         *
         * The previous version opened every report with «أعلى ROAS على …» and «أقل تكلفة نتيجة على
         * …». On a brand report both are claims about a return nobody was buying, printed above the
         * fold, in the section a client reads first.
         */
        $rated = collect($platforms)->filter(fn ($p) => ($p[$metric['key']] ?? null) !== null && (float) ($p['spend'] ?? 0) > 0);
        if ($rated->isNotEmpty()) {
            $best = ($metric['lower_is_better'] ? $rated->sortBy($metric['key']) : $rated->sortByDesc($metric['key']))->first();
            $out[] = ['severity' => 'positive', 'title' => "أفضل {$metric['label_ar']} على {$best['provider']}", 'platform' => $best['provider'],
                'kpi' => $metric['label_ar'], 'value' => $lens->formatRanking((float) $best[$metric['key']], $currency),
                'detail' => 'أفضل أداء على المؤشر الذي تُقاس به هذه الحملات.'];

            // «Below average» needs somebody to be below it: with one platform there is no average.
            if ($rated->count() > 1) {
                $worst = ($metric['lower_is_better'] ? $rated->sortByDesc($metric['key']) : $rated->sortBy($metric['key']))->first();
                $out[] = ['severity' => 'warning', 'title' => "{$worst['provider']} دون المتوسط", 'platform' => $worst['provider'],
                    'kpi' => $metric['label_ar'], 'value' => $lens->formatRanking((float) $worst[$metric['key']], $currency),
                    'detail' => 'يحتاج مراجعة الاستهداف والمحتوى.'];
            }
        }

        /*
         * «Spending without conversions» is a finding only where conversions were the point.
         *
         * On an awareness report every campaign spends without conversions — that is what awareness
         * money does — and flagging it critical would fill a brand report with alarms about it
         * working as intended.
         */
        if ($lens->judgesOnCostPerResult()) {
            $burner = collect($campaigns)->first(fn ($c) => ($c['spend'] ?? 0) > 3000 && ($c['conversions'] ?? 0) < 2);
            if ($burner) {
                $out[] = ['severity' => 'critical', 'title' => "حملة «{$burner['campaign_name']}» تنفق دون تحويلات", 'platform' => $burner['provider'] ?? null,
                    'kpi' => 'الإنفاق', 'value' => number_format((float) $burner['spend']).' '.$currency, 'detail' => 'مرشحة للإيقاف أو مراجعة التتبع.'];
            }
        }

        // Revenue growth is a finding about revenue, and only a sales report is judged on it.
        if ($lens->judgesOnRevenue() && isset($delta['revenue']) && $delta['revenue'] > 0.1) {
            $out[] = ['severity' => 'positive', 'title' => 'نمو الإيرادات مقابل الفترة السابقة', 'platform' => null,
                'kpi' => 'الإيرادات', 'value' => '+'.number_format((float) $delta['revenue'] * 100, 0).'%', 'detail' => 'اتجاه إيجابي في العائد.'];
        }

        return array_slice($out, 0, 5);
    }

    /**
     * Right column: actionable recommendations. Suggestions only — the user approves before a client
     * sees them (feature-flagged elsewhere). Max 5.
     *
     * @return list<array<string,mixed>>
     */
    private function recommendations(ReportObjectiveLens $lens, array $platforms, array $campaigns, string $currency): array
    {
        $out = [];
        $metric = $lens->rankingMetric();
        $rated = collect($platforms)->filter(fn ($p) => ($p[$metric['key']] ?? null) !== null && (float) ($p['spend'] ?? 0) > 0);

        /*
         * «Spend more here» has to name the thing that is going well, and it must be a thing this
         * money was buying. A ROAS above 1 is the right gate for a sales report and no gate at all
         * for a brand one, where the figure does not exist.
         */
        if ($rated->isNotEmpty()) {
            $best = ($metric['lower_is_better'] ? $rated->sortBy($metric['key']) : $rated->sortByDesc($metric['key']))->first();
            $worthScaling = ! $lens->judgesOnRevenue() || (float) $best['roas'] > 1;
            if ($worthScaling) {
                $out[] = ['severity' => 'positive', 'title' => "زيادة ميزانية {$best['provider']} تدريجيًا", 'platform' => $best['provider'],
                    'action' => 'scale', 'detail' => "أفضل {$metric['label_ar']} — وسّع بحذر مع مراقبة مرحلة التعلّم.", 'kpi' => $metric['label_ar']];
            }

            if ($rated->count() > 1) {
                $worst = ($metric['lower_is_better'] ? $rated->sortByDesc($metric['key']) : $rated->sortBy($metric['key']))->first();
                $out[] = ['severity' => 'warning', 'title' => "تحسين استهداف {$worst['provider']}", 'platform' => $worst['provider'],
                    'action' => 'optimize', 'detail' => "أضعف {$metric['label_ar']} — راجع الجمهور والمحتوى والصفحة.", 'kpi' => $metric['label_ar']];
            }
        }

        if ($lens->judgesOnCostPerResult()) {
            $burner = collect($campaigns)->first(fn ($c) => ($c['spend'] ?? 0) > 3000 && ($c['conversions'] ?? 0) < 2);
            if ($burner) {
                $out[] = ['severity' => 'critical', 'title' => "إيقاف مؤقت ومراجعة «{$burner['campaign_name']}»", 'platform' => $burner['provider'] ?? null,
                    'action' => 'pause', 'detail' => 'إنفاق دون تحويلات — تحقق من التتبع قبل الاستمرار.', 'kpi' => 'الإنفاق'];
            }
            $topConv = collect($campaigns)->sortByDesc('conversions')->first();
            if ($topConv && ($topConv['conversions'] ?? 0) > 0) {
                $out[] = ['severity' => 'positive', 'title' => "توسيع ما ينجح في «{$topConv['campaign_name']}»", 'platform' => $topConv['provider'] ?? null,
                    'action' => 'expand', 'detail' => 'أعلى نتائج — كرّر الزوايا الرابحة على جماهير مشابهة.', 'kpi' => 'النتائج'];
            }
        }

        return array_slice($out, 0, 5);
    }

    /**
     * Stamp each auto-generated finding/recommendation with a stable id + approval status. Findings are
     * factual observations (client-visible). Recommendations are AI-generated and start as `draft`;
     * only `approved` ones reach a client (approval ids live in report.config). Demo reports are
     * pre-approved so the demo client view is complete.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function tagAnnotations(array $items, string $type, Report $report): array
    {
        return array_map(function ($item) use ($type, $report) {
            $aid = substr(hash('sha256', $type.'|'.($item['title'] ?? '').'|'.($item['platform'] ?? '')), 0, 12);
            $isRec = $type === 'recommendation';
            $priority = ($item['severity'] ?? '') === 'critical' ? 'high' : (($item['severity'] ?? '') === 'warning' ? 'medium' : 'normal');
            $evidence = ['kpi' => $item['kpi'] ?? null, 'value' => $item['value'] ?? null, 'platform' => $item['platform'] ?? null];

            // Persist the annotation, PRESERVING any human review decision across regeneration. New
            // AI recommendations start Draft (Approved for demo so the demo client view is complete);
            // findings are factual observations (Reviewed, client-visible).
            $ann = ReportAnnotation::withoutGlobalScopes()->firstOrNew([
                'report_id' => $report->id, 'annotation_id' => $aid,
            ]);
            if (! $ann->exists) {
                $ann->forceFill([
                    'tenant_id' => $report->tenant_id, 'type' => $type,
                    'text_ar' => $item['title'] ?? null, 'platform' => $item['platform'] ?? null,
                    'kpi' => $item['kpi'] ?? null, 'evidence' => $evidence, 'source' => 'auto',
                    'priority' => $priority, 'proposed_action' => $item['detail'] ?? null,
                    'is_ai_generated' => true, 'is_demo' => (bool) $report->is_demo,
                    'status' => $isRec ? ($report->is_demo ? 'approved' : 'draft') : 'reviewed',
                ])->save();
            } else {
                // Refresh the text/evidence but keep the lifecycle fields.
                $ann->forceFill(['text_ar' => $item['title'] ?? null, 'evidence' => $evidence, 'priority' => $priority])->save();
            }

            return $item + [
                'id' => $aid,
                'type' => $type,
                'is_ai_generated' => true,
                'status' => $ann->status,
                'priority' => $priority,
                'owner' => 'فريق الأداء',
                'due' => 'الأسبوع القادم',
                'evidence' => $evidence,
            ];
        }, $items);
    }

    /**
     * Client-facing next steps — approved recommendations only, presented as action items. Never
     * exposes internal-only fields (evidence json / ai flag / confidence).
     *
     * @param  list<array<string,mixed>>  $recommendations
     * @return list<array<string,mixed>>
     */
    private function nextSteps(array $recommendations): array
    {
        return array_values(array_map(
            fn ($r) => [
                'action' => $r['title'] ?? '',
                'reason' => $r['detail'] ?? '',
                'platform' => $r['platform'] ?? null,
                'kpi' => $r['kpi'] ?? null,
                'priority' => $r['priority'] ?? 'normal',
                'owner' => $r['owner'] ?? 'فريق الأداء',
                'due' => $r['due'] ?? null,
            ],
            array_filter($recommendations, fn ($r) => ($r['status'] ?? 'draft') === 'approved'),
        ));
    }

    /** A few plain-language findings derived from the numbers (not fabricated). */
    private function executiveSummary(ReportObjectiveLens $lens, array $t, array $delta, array $platforms, array $campaigns, string $currency): array
    {
        $out = [];
        $metric = $lens->rankingMetric();

        /*
         * The opening sentence names the money and the ONE figure this money is judged on.
         *
         * It used to name ROAS on every report, so a brand month opened with «إجمالي الإنفاق 40,000
         * SAR بعائد ROAS —». A dash is honest about the value and dishonest about the question: it
         * says the return is unknown, when in fact no return was being bought.
         */
        $headline = $t[$metric['key']] ?? null;
        $out[] = $headline !== null
            ? sprintf('إجمالي الإنفاق %s %s خلال الفترة، ومتوسط %s %s.', number_format((float) ($t['spend'] ?? 0)), $currency, $metric['label_ar'], $lens->formatRanking((float) $headline, $currency))
            : sprintf('إجمالي الإنفاق %s %s خلال الفترة.', number_format((float) ($t['spend'] ?? 0)), $currency);

        $rated = collect($platforms)->filter(fn ($p) => ($p[$metric['key']] ?? null) !== null && (float) ($p['spend'] ?? 0) > 0);
        if ($rated->isNotEmpty()) {
            $best = ($metric['lower_is_better'] ? $rated->sortBy($metric['key']) : $rated->sortByDesc($metric['key']))->first();
            $out[] = sprintf('أفضل %s على منصة %s (%s).', $metric['label_ar'], $best['provider'], $lens->formatRanking((float) $best[$metric['key']], $currency));
        }

        if ($lens->judgesOnCostPerResult()) {
            $burner = collect($campaigns)->first(fn ($c) => ($c['spend'] ?? 0) > 3000 && ($c['conversions'] ?? 0) < 2);
            if ($burner) {
                $out[] = sprintf('تنبيه: حملة «%s» تنفق دون تحويلات تُذكر — يُنصح بمراجعتها.', $burner['campaign_name'] ?? '—');
            }
        }

        /*
         * A scope with several objectives says so, rather than picking one of them to lead with.
         *
         * This is the sentence that stops a reader averaging a brand budget and a sales budget in
         * their head, which is what a single blended headline invites.
         */
        if ($lens->isMixed()) {
            $out[] = 'تضم هذه الفترة حملات بأهداف مختلفة، لذلك تُعرض مؤشرات كل مسار على حدة ولا تُدمج تكلفة النتيجة أو العائد بينها.';
        }

        return $out;
    }
}
