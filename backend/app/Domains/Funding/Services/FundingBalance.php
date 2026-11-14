<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Metrics\Services\CurrencyConverter;
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
        private readonly CurrencyConverter $fx,
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
        $usdRate = $this->fx->resolve('USD', 'SAR', $today)['rate'] ?? null;
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

        $daysLeft = ($remaining !== null && $pace !== null && $pace > 0 && $remaining > 0)
            ? (int) floor($remaining / $pace) : null;

        $status = match (true) {
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
            $remaining < (float) $funding->alert_floor => 'low',
            default => 'ok',
        };

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
            'usd_rate' => $usdRate,
            'ceiling' => $ceiling,
            'ceiling_ratio' => $ceilingRatio,
            'to_invoice' => $invoiceMonths === null ? null : round((float) array_sum(array_map(static fn (array $m): float => $m['closed'] ? $m['spent'] : 0.0, $invoiceMonths)), 2),
            'planned' => $planRows !== null,
            'platform_plan' => $planRows,
            'plan' => $comparison,
            'plan_alerts' => $comparison === null ? 0 : count($this->plans->alerts($comparison)),
            'pace_per_day' => $pace,
            'days_left' => $daysLeft,
            'spend_without_rate' => $withoutRate,
            'complete' => $withoutRate === 0,
            'last_spend_day' => $lastDay,
            'by_platform' => $byPlatform,
            'status' => $status,
        ];
    }
}
