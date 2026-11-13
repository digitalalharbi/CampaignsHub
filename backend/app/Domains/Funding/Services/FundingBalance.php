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
 * No periods, so nothing overlaps and nothing resets: a renewal adds to the approved side only.
 * Spend in a currency with no dated rate is COUNTED (`spend_without_rate`) and never read as zero —
 * the remaining figure is then marked incomplete instead of looking healthier than it is.
 * The pace is real spend of the last 7 full days; with no spend in that window there is no pace.
 */
final class FundingBalance
{
    public function __construct(private readonly FundingSpendRefresher $refresher) {}

    /** @return array<string, mixed> */
    public function of(ProjectFunding $funding, ?Carbon $today = null): array
    {
        $today ??= Carbon::today('Asia/Riyadh');
        $from = $this->refresher->countsFrom($funding);

        $approved = (float) DB::table('funding_payments')->where('project_id', $funding->project_id)->sum('amount_sar');
        $payments = (int) DB::table('funding_payments')->where('project_id', $funding->project_id)->count();

        $spendQuery = fn () => DB::table('funding_spend_days')->where('project_id', $funding->project_id)
            ->when($from !== null, fn ($q) => $q->where('spend_date', '>=', $from->toDateString()), fn ($q) => $q->whereRaw('false'));

        $spent = (float) $spendQuery()->sum('amount_sar');
        $withoutRate = (int) $spendQuery()->whereNull('amount_sar')->where('original_amount', '>', 0)->count();
        $lastDay = $spendQuery()->max('spend_date');

        $byPlatform = $spendQuery()->whereNotNull('amount_sar')
            ->selectRaw('provider, SUM(amount_sar) AS spent')->groupBy('provider')->orderByDesc('spent')->get()
            ->map(static fn ($r): array => ['provider' => (string) $r->provider, 'spent' => round((float) $r->spent, 2)])->all();

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
            $funding->payment_method === 'direct' => 'direct',
            $payments === 0 => 'no_budget',
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
