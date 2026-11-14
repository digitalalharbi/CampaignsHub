<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Funding\Models\ProjectFunding;
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
    public function __construct(private readonly FundingSpendRefresher $refresher) {}

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
        $prepaid = $funding->payment_method === 'prepaid';

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

        $weekStart = $today->copy()->subDays(7)->toDateString();
        $weekEnd = $today->copy()->subDay()->toDateString();
        $week = $spendQuery()->whereBetween('spend_date', [$weekStart, $weekEnd]);
        $weekDays = (int) (clone $week)->count();
        $pace = $weekDays > 0 ? round((float) (clone $week)->sum('amount_sar') / 7, 2) : null;

        $prepaid = $funding->payment_method === 'prepaid';
        $remaining = $prepaid ? round($approved - $spent, 2) : null;

        $daysLeft = ($remaining !== null && $pace !== null && $pace > 0 && $remaining > 0)
            ? (int) floor($remaining / $pace) : null;

        $status = match (true) {
            $funding->payment_method === 'unset' => 'unset',
            $funding->voided_at !== null => 'void',
            $funding->payment_method === 'direct' => 'direct',
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
            'used_ratio' => ($prepaid && $approved > 0) ? round($spent / $approved, 4) : null,
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
