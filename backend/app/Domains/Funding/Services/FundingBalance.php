<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Support\BudgetFx;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Spend\SpendLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FIN-LOOP-001 — the live answer to «how much was approved, how much is spent, how much is left».
 *
 *   remaining = Σ approved amounts (SAR) − Σ real spend (SAR) since the first counted day
 *
 * The spend counted is that of the budget's PERIOD: from its stated start (else the first day of real spend) to its
 * stated end (else open). A renewal adds to the approved side only; a correction is a signed entry, never an edit.
 * Spend in a currency with no dated rate is COUNTED (`spend_without_rate`) and never read as zero —
 * the remaining figure is then marked incomplete instead of looking healthier than it is.
 * The pace is real spend of the last 7 full days; with no spend in that window there is no pace.
 */
final class FundingBalance
{
    public function __construct(
        private readonly FundingSpendRefresher $refresher,
        private readonly FundingPlan $plans,
        private readonly FundingMonth $months,
        private readonly BudgetFx $fx,
        private readonly SourceFreshness $freshness,
        private readonly SpendLedger $ledger,
    ) {}

    /**
     * The remaining day by day, for the chart: renewals show as steps up, spend as the slope down.
     * At most the last 180 days; each point carries the amount added that day, if any.
     *
     * @return list<array{date: string, approved: float, spent: float, remaining: float|null, added: float}>
     */
    public function series(ProjectFunding $funding, ?Carbon $today = null): array
    {
        // Plain calendar dates on one clock: Riyadh's «today», compared day by day with stored dates.
        $today = Carbon::parse(($today ?? Carbon::today('Asia/Riyadh'))->toDateString());
        $from = $this->refresher->countsFrom($funding);
        if ($from === null) {
            return [];
        }

        $added = DB::table('funding_payments')->where('project_id', $funding->project_id)
            ->selectRaw('paid_on, SUM(amount_sar) AS a')->groupBy('paid_on')->pluck('a', 'paid_on')
            ->mapWithKeys(static fn ($v, $k): array => [Carbon::parse((string) $k)->toDateString() => (float) $v])->all();
        $spent = $this->refresher->spendRows($funding)
            ->selectRaw('spend_date, SUM(amount_sar) AS s')->groupBy('spend_date')->pluck('s', 'spend_date')
            ->mapWithKeys(static fn ($v, $k): array => [Carbon::parse((string) $k)->toDateString() => (float) $v])->all();

        $start = $from->copy()->max($today->copy()->subDays(179));
        $approved = array_sum(array_filter($added, static fn ($v, $d): bool => $d < $start->toDateString(), ARRAY_FILTER_USE_BOTH));
        $spentCum = array_sum(array_filter($spent, static fn ($v, $d): bool => $d < $start->toDateString(), ARRAY_FILTER_USE_BOTH));
        $prepaid = $funding->payment_method === 'prepaid' && $funding->payment_mode !== 'credit';

        $points = [];
        for ($d = $start->copy(); $d->lte($today); $d->addDay()) {
            $key = $d->toDateString();
            $approved += $added[$key] ?? 0.0;
            $spentCum += $spent[$key] ?? 0.0;
            $points[] = ['date' => $key, 'approved' => round($approved, 2), 'spent' => round($spentCum, 2),
                'remaining' => $prepaid ? round($approved - $spentCum, 2) : null, 'added' => round($added[$key] ?? 0.0, 2)];
        }

        return $points;
    }

    /** @return array<string, mixed> */
    public function of(ProjectFunding $funding, ?Carbon $today = null): array
    {
        $today ??= Carbon::today('Asia/Riyadh');
        $from = $this->refresher->countsFrom($funding);

        $approved = (float) DB::table('funding_payments')->where('project_id', $funding->project_id)->sum('amount_sar');
        $payments = (int) DB::table('funding_payments')->where('project_id', $funding->project_id)->whereIn('kind', ['initial', 'top_up'])->count();

        $to = $this->refresher->countsTo($funding);
        $spendQuery = fn () => $this->refresher->spendRows($funding);

        $spent = (float) $spendQuery()->sum('amount_sar');
        $withoutRate = (int) $spendQuery()->whereNull('amount_sar')->where('original_amount', '>', 0)->count();
        $lastDay = $spendQuery()->max('spend_date');

        // Each platform's own part. A platform with days that have no dated rate says so in its own row
        // (`unconverted_days`) — its figure is then a floor, never read as the whole of its spend.
        $byPlatform = $spendQuery()
            ->selectRaw('provider, COALESCE(SUM(amount_sar), 0) AS spent')
            ->selectRaw('SUM(CASE WHEN amount_sar IS NULL AND original_amount > 0 THEN 1 ELSE 0 END) AS unconverted')
            ->groupBy('provider')->orderByDesc('spent')->get()
            ->map(static fn ($r): array => ['provider' => (string) $r->provider, 'spent' => round((float) $r->spent, 2), 'unconverted_days' => (int) $r->unconverted])->all();

        // BUDGET-CURRENCY-001 — a platform's own spend is shown in USD (one unit for platforms), totals stay in SAR. The unit is the SAR
        // figure over the latest USD→SAR rate; with no rate there is no USD figure, never a guessed one. Days with no dated rate keep
        // their OWN currency and amount, so the page can say «بدون سعر صرف» next to them.
        $usdRate = $this->fx->rate('USD', 'SAR', $today)['rate'] ?? null;
        $toUsd = static fn (?float $sar): ?float => ($sar === null || $usdRate === null || $usdRate <= 0) ? null : round($sar / $usdRate, 2);
        $unconverted = $spendQuery()->whereNull('amount_sar')->where('original_amount', '>', 0)
            ->selectRaw('provider, original_currency AS currency, SUM(original_amount) AS amount')->groupBy('provider', 'original_currency')->get()
            ->groupBy('provider')->map(static fn ($g): array => $g->map(static fn ($r): array => ['currency' => (string) ($r->currency ?? ''), 'amount' => round((float) $r->amount, 2)])->values()->all())->all();
        $byPlatform = array_map(static fn (array $p): array => $p + ['spent_usd' => $toUsd($p['spent']), 'unconverted' => $unconverted[$p['provider']] ?? []], $byPlatform);

        $weekStart = $today->copy()->subDays(7)->toDateString();
        $weekEnd = $today->copy()->subDay()->toDateString();
        $week = $spendQuery()->whereBetween('spend_date', [$weekStart, $weekEnd]);
        $weekDays = (int) (clone $week)->count();
        $pace = $weekDays > 0 ? round((float) (clone $week)->sum('amount_sar') / 7, 2) : null;

        $tracked = $funding->payment_method === 'prepaid';
        // BUDGET-MODES-001 — a credit budget is NOT a prepaid balance: nothing «remains», the amount (if any) is a ceiling.
        $credit = $tracked && $funding->payment_mode === 'credit';
        $prepaid = $tracked && ! $credit;
        $remaining = $prepaid ? round($approved - $spent, 2) : null;
        $ceiling = ($credit && $approved > 0) ? round($approved, 2) : null;
        $ceilingRatio = $ceiling !== null ? round($spent / $ceiling, 4) : null;

        $planRows = is_array($funding->platform_plan) && $funding->platform_plan !== []
            ? $this->plans->withAmounts($funding->platform_plan, $approved) : null;
        $comparison = null;
        if ($planRows !== null) {
            $weekBy = $spendQuery()->whereBetween('spend_date', [$weekStart, $weekEnd])->whereNotNull('amount_sar')
                ->selectRaw('provider, SUM(amount_sar) AS s')->groupBy('provider')->pluck('s', 'provider')
                ->map(static fn ($v): float => (float) $v)->all();
            $comparison = array_map(static fn (array $r): array => $r + ['planned_usd' => $toUsd($r['planned']), 'spent_usd' => $toUsd($r['spent'])],
                $this->plans->compare($planRows, $byPlatform, $weekBy, $from, $to, $today));
        }
        $invoiceMonths = $credit ? $this->months->invoiceMonths($funding, $today) : null;
        $thisMonth = $invoiceMonths === null ? null : round((float) array_sum(array_map(static fn (array $m): float => $m['month'] === $today->format('Y-m') ? $m['spent'] : 0.0, $invoiceMonths)), 2);

        // BUDGET-RUNOUT-001 — the estimated run-out day from the LAST 3 full days' spend (a short window: it must follow a
        // change of pace). Only a prepaid balance runs out; with no spend in those days there is no estimate.
        $threeStart = $today->copy()->subDays(3)->toDateString();
        $pace3 = round((float) $spendQuery()->whereBetween('spend_date', [$threeStart, $weekEnd])->sum('amount_sar') / 3, 2);
        $runOutDate = ($remaining !== null && $remaining > 0 && $pace3 > 0)
            ? Carbon::parse($today->toDateString())->addDays((int) floor($remaining / $pace3))->toDateString() : null;

        $platforms = array_values(array_unique(array_merge(
            array_column($byPlatform, 'provider'),
            $planRows === null ? [] : array_column($planRows, 'provider'),
        )));
        $freshness = $this->freshness->forProject((string) $funding->project_id, $platforms === [] ? null : $platforms);

        $daysLeft = ($remaining !== null && $pace !== null && $pace > 0 && $remaining > 0)
            ? (int) floor($remaining / $pace) : null;

        $status = $this->statusOf($funding, $approved, $spent, $withoutRate, $credit, $remaining, $ceiling, $ceilingRatio);

        /*
         * PROJECT-LIFECYCLE-001 §7 — what the budget's spend could NOT include, and why, in the one reading every page shares
         * (`SpendLedger`, through FxEngine): each excluded part with its currency, account and original amount, the explicit
         * «مستبعد من الإجمالي» statement, and every old rate used («سعر قديم — بتاريخ …»). Read over the budget's own period;
         * the budget keeps its history, so this ledger is not narrowed to live projects (the budgets list itself is).
         */
        $reading = $from === null ? null : $this->ledger->read([(string) $funding->project_id], $from, $to, 'SAR', liveOnly: false);
        $fx = $reading === null ? null : array_intersect_key($reading, array_flip(['amount', 'complete', 'partial', 'excluded', 'excluded_label', 'statement', 'statement_en', 'stale', 'stale_label', 'rates_page', 'currencies', 'includes_converted']));

        return [
            'project_id' => $funding->project_id,
            'client_workspace_id' => $funding->client_workspace_id,
            'payment_method' => $funding->payment_method,
            'payment_mode' => $funding->payment_mode,
            'counts_from' => $from?->toDateString(),
            'period_start' => $from?->toDateString(),
            'period_end' => $to?->toDateString(),
            'period_start_stated' => $funding->starts_on !== null,
            'voided_at' => $funding->voided_at?->toIso8601String(),
            'void_reason' => $funding->void_reason,
            'alert_floor' => round((float) $funding->alert_floor, 2),
            'currency' => 'SAR',
            'approved' => round($approved, 2),
            'payments' => $payments,
            'spent' => round($spent, 2),
            'remaining' => $remaining,
            'used_ratio' => (($prepaid || $credit) && $approved > 0) ? round($spent / $approved, 4) : null,
            // credit: spend so far, the optional ceiling with its own ratio, and what is ready to invoice (closed months).
            'to_date' => $credit ? round($spent, 2) : null,
            'this_month' => $thisMonth,
            'usd_rate' => $usdRate === null ? null : round($usdRate, 6),
            'ceiling' => $ceiling,
            'ceiling_ratio' => $ceilingRatio,
            'to_invoice' => $invoiceMonths === null ? null : round((float) array_sum(array_map(static fn (array $m): float => $m['closed'] ? $m['spent'] : 0.0, $invoiceMonths)), 2),
            'planned' => $planRows !== null,
            'platform_plan' => $planRows,
            'plan' => $comparison,
            'plan_alerts' => $comparison === null ? 0 : count($this->plans->alerts($comparison)),
            'pace_per_day' => $pace,
            'days_left' => $daysLeft,
            'pace_3d' => $pace3 > 0 ? $pace3 : null,
            'run_out_date' => $runOutDate,
            'freshness' => $freshness,
            'spend_without_rate' => $withoutRate,
            'complete' => $withoutRate === 0,
            'last_spend_day' => $lastDay,
            'by_platform' => $byPlatform,
            'status' => $status,
            'fx' => $fx,
        ];
    }

    /**
     * The budget's status word. ONE definition, read by {@see of()} and by {@see summaries()}, so the batched reading can never
     * name a different status than the full one.
     */
    private function statusOf(ProjectFunding $funding, float $approved, float $spent, int $withoutRate, bool $credit, ?float $remaining, ?float $ceiling, ?float $ceilingRatio): string
    {
        return match (true) {
            $funding->payment_method === 'unset' => 'unset',
            $funding->voided_at !== null => 'void',
            $funding->payment_method === 'direct' => 'direct',
            $credit && $withoutRate > 0 => 'incomplete',
            $credit && $ceiling !== null && $spent > $ceiling => 'credit_over',
            $credit && $ceilingRatio !== null && $ceilingRatio >= FundingPlan::NEAR => 'credit_near',
            $credit => 'credit',
            $approved <= 0 => 'no_budget',
            $remaining < 0 => 'over',
            // Spend with no dated rate is not in `spent`, so the remaining is better than the truth: never «ok».
            $withoutRate > 0 => 'incomplete',
            // Nothing spent is never «low»: a small budget under the alert floor has lost nothing yet.
            $spent > 0 && $remaining < (float) $funding->alert_floor => 'low',
            default => 'ok',
        };
    }

    /**
     * PERF-BOOTSTRAP-001 — {@see of()} for MANY budgets, restricted to the figures {@see ProjectBudgetReader::summarise()} draws.
     *
     * `of()` is a dozen statements per budget (period start, payments, six spend aggregates, the FX reading), and the agency
     * dashboard reads one per project: 120 projects was 1,440 statements and most of a second. This asks the same questions for
     * every budget at once — one per table — and answers per project exactly what `of()` answers for the keys it returns:
     * approved, spent, remaining, used ratio, the 3-day pace, the run-out day, status, period, last spend day and the FX reading
     * (still `SpendLedger`, still through FxEngine — {@see SpendLedger::readEach()}). The platform split, the plan comparison, the
     * freshness and the invoice months are not part of a summary and are not computed here.
     *
     * `FundingBalanceSummariesTest` holds it equal to `of()` on every payment method and mode.
     *
     * @param  iterable<ProjectFunding>  $fundings
     * @return array<string, array<string, mixed>> project id => the subset of `of()`
     */
    public function summaries(iterable $fundings, ?Carbon $today = null): array
    {
        $today ??= Carbon::today('Asia/Riyadh');
        $list = [];
        foreach ($fundings as $funding) {
            $list[(string) $funding->project_id] = $funding;
        }
        if ($list === []) {
            return [];
        }
        $ids = array_keys($list);

        // Payments: the approved total, the count of deposits, and the first approved day — one grouped statement.
        $paid = DB::table('funding_payments')->whereIn('project_id', $ids)
            ->selectRaw("project_id, COALESCE(SUM(amount_sar), 0) AS approved, COUNT(*) FILTER (WHERE kind IN ('initial', 'top_up')) AS payments, MIN(paid_on) AS first_paid")
            ->groupBy('project_id')->get()->keyBy(static fn (object $r): string => (string) $r->project_id)->all();

        // The first day of REAL spend, only for the budgets with no stated start — `countsFrom()`'s query, grouped by project.
        $open = array_keys(array_filter($list, static fn (ProjectFunding $f): bool => $f->starts_on === null));
        $firstSpend = [];
        if ($open !== []) {
            $spend = DB::table('daily_metrics')
                ->whereIn('daily_metrics.project_id', $open)
                ->where('daily_metrics.metric_key', 'spend')
                ->whereNotIn('daily_metrics.source_type', ['estimated', 'modeled'])
                ->whereRaw('COALESCE(daily_metrics.original_amount, daily_metrics.value) > 0');
            BoundAccountVisibility::apply($spend, 'daily_metrics');
            $firstSpend = $spend->selectRaw('daily_metrics.project_id, MIN(daily_metrics.metric_date) AS d')
                ->groupBy('daily_metrics.project_id')->pluck('d', 'daily_metrics.project_id')->all();
        }

        /** @var array<string, array{0: ?Carbon, 1: ?Carbon}> $windows */
        $windows = [];
        foreach ($list as $id => $funding) {
            if ($funding->starts_on !== null) {
                $from = Carbon::parse($funding->starts_on->toDateString());
            } else {
                $days = array_filter([$firstSpend[$id] ?? null, $paid[$id]->first_paid ?? null], static fn ($d): bool => $d !== null);
                $from = $days === [] ? null : Carbon::parse((string) min(array_map(static fn ($d): string => Carbon::parse((string) $d)->toDateString(), $days)));
            }
            $windows[$id] = [$from, $this->refresher->countsTo($funding)];
        }

        // The spend rows inside each budget's own window, aggregated per project in one statement.
        $weekStart = $today->copy()->subDays(7)->toDateString();
        $weekEnd = $today->copy()->subDay()->toDateString();
        $threeStart = $today->copy()->subDays(3)->toDateString();
        $bounded = array_filter($windows, static fn (array $w): bool => $w[0] !== null);
        $spend = [];
        if ($bounded !== []) {
            $values = implode(', ', array_fill(0, count($bounded), '(?::uuid, ?::date, ?::date)'));
            $bindings = [$weekStart, $weekEnd, $weekStart, $weekEnd, $threeStart, $weekEnd];
            foreach ($bounded as $id => [$from, $to]) {
                array_push($bindings, $id, $from->toDateString(), $to?->toDateString());
            }
            $rows = DB::select(
                'select s.project_id, COALESCE(SUM(s.amount_sar), 0) AS spent,'
                .' COUNT(*) FILTER (WHERE s.amount_sar IS NULL AND s.original_amount > 0) AS without_rate,'
                .' MAX(s.spend_date) AS last_day,'
                .' COUNT(*) FILTER (WHERE s.spend_date BETWEEN ? AND ?) AS week_days,'
                .' COALESCE(SUM(s.amount_sar) FILTER (WHERE s.spend_date BETWEEN ? AND ?), 0) AS week_sum,'
                .' COALESCE(SUM(s.amount_sar) FILTER (WHERE s.spend_date BETWEEN ? AND ?), 0) AS three_sum'
                .' from funding_spend_days s'
                ." join (values {$values}) AS b(project_id, f, t) on b.project_id = s.project_id"
                .' where s.spend_date >= b.f and (b.t is null or s.spend_date <= b.t)'
                .' group by s.project_id',
                $bindings,
            );
            foreach ($rows as $r) {
                $spend[(string) $r->project_id] = $r;
            }
        }

        // The FX reading of every budget's own period, through SpendLedger (FxEngine) — one account-days query for all of them.
        $readings = $this->ledger->readEach(
            array_map(static fn (array $w): array => [$w[0], $w[1]], array_filter($windows, static fn (array $w): bool => $w[0] !== null)),
            'SAR',
            liveOnly: false,
        );

        $out = [];
        foreach ($list as $id => $funding) {
            [$from, $to] = $windows[$id];
            $approved = (float) ($paid[$id]->approved ?? 0);
            $s = $spend[$id] ?? null;
            $spent = (float) ($s->spent ?? 0);
            $withoutRate = (int) ($s->without_rate ?? 0);
            $weekDays = (int) ($s->week_days ?? 0);
            $pace3 = round((float) ($s->three_sum ?? 0) / 3, 2);

            $tracked = $funding->payment_method === 'prepaid';
            $credit = $tracked && $funding->payment_mode === 'credit';
            $prepaid = $tracked && ! $credit;
            $remaining = $prepaid ? round($approved - $spent, 2) : null;
            $ceiling = ($credit && $approved > 0) ? round($approved, 2) : null;
            $ceilingRatio = $ceiling !== null ? round($spent / $ceiling, 4) : null;
            $runOutDate = ($remaining !== null && $remaining > 0 && $pace3 > 0)
                ? Carbon::parse($today->toDateString())->addDays((int) floor($remaining / $pace3))->toDateString() : null;
            $reading = $readings[$id] ?? null;

            $out[$id] = [
                'project_id' => $funding->project_id,
                'client_workspace_id' => $funding->client_workspace_id,
                'payment_method' => $funding->payment_method,
                'payment_mode' => $funding->payment_mode,
                'period_start' => $from?->toDateString(),
                'period_end' => $to?->toDateString(),
                'approved' => round($approved, 2),
                'spent' => round($spent, 2),
                'remaining' => $remaining,
                'used_ratio' => (($prepaid || $credit) && $approved > 0) ? round($spent / $approved, 4) : null,
                'pace_per_day' => $weekDays > 0 ? round((float) ($s->week_sum ?? 0) / 7, 2) : null,
                'pace_3d' => $pace3 > 0 ? $pace3 : null,
                'run_out_date' => $runOutDate,
                'spend_without_rate' => $withoutRate,
                'complete' => $withoutRate === 0,
                'last_spend_day' => $s->last_day ?? null,
                'status' => $this->statusOf($funding, $approved, $spent, $withoutRate, $credit, $remaining, $ceiling, $ceilingRatio),
                'fx' => $reading === null ? null : array_intersect_key($reading, array_flip(['amount', 'complete', 'partial', 'excluded', 'excluded_label', 'statement', 'statement_en', 'stale', 'stale_label', 'rates_page', 'currencies', 'includes_converted'])),
            ];
        }

        return $out;
    }
}
