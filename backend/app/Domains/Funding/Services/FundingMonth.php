<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Funding\Models\FundingMonthSummary;
use App\Domains\Funding\Models\ProjectFunding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FIN-LOOP-001 part 3 — one month of a project budget, in one shape for the live view, the fixed
 * summary and the export: what was there at the start, what was added (each amount with its date,
 * reference and who added it), what was spent and on which platform, and what was left at the end.
 *
 * A closed month is stored once (`freeze`) and read back unchanged; the current month is computed
 * live and says so (`fixed: false`).
 */
final class FundingMonth
{
    public function __construct(private readonly FundingSpendRefresher $refresher) {}

    /** @return array<string, mixed> */
    public function figures(ProjectFunding $funding, string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $project = $funding->project_id;

        $approvedBefore = (float) DB::table('funding_payments')->where('project_id', $project)->where('paid_on', '<', $start->toDateString())->sum('amount_sar');
        $spentBefore = (float) $this->refresher->spendRows($funding)->where('spend_date', '<', $start->toDateString())->sum('amount_sar');

        $added = DB::table('funding_payments as p')->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->where('p.project_id', $project)->whereBetween('p.paid_on', [$start->toDateString(), $end->toDateString()])
            ->orderBy('p.paid_on')->orderBy('p.created_at')
            ->get(['p.paid_on', 'p.kind', 'p.amount', 'p.currency', 'p.amount_sar', 'p.reference', 'p.note', 'p.reason', 'u.name as added_by', 'p.created_at'])
            ->map(static fn ($r): array => [
                'date' => Carbon::parse((string) $r->paid_on)->toDateString(), 'kind' => (string) $r->kind,
                'amount' => (float) $r->amount, 'currency' => (string) $r->currency, 'amount_sar' => (float) $r->amount_sar,
                'reference' => $r->reference, 'note' => $r->note, 'reason' => $r->reason, 'added_by' => $r->added_by,
                'added_at' => Carbon::parse((string) $r->created_at)->toIso8601String(),
            ])->all();

        $spendInMonth = $this->refresher->spendRows($funding)
            ->whereBetween('spend_date', [$start->toDateString(), $end->toDateString()]);
        $byPlatform = (clone $spendInMonth)->whereNotNull('amount_sar')->selectRaw('provider, SUM(amount_sar) AS spent')
            ->groupBy('provider')->orderByDesc('spent')->get()
            ->map(static fn ($r): array => ['provider' => (string) $r->provider, 'spent' => round((float) $r->spent, 2)])->all();
        $spent = (float) (clone $spendInMonth)->sum('amount_sar');
        $withoutRate = (int) (clone $spendInMonth)->whereNull('amount_sar')->where('original_amount', '>', 0)->count();
        $addedTotal = array_sum(array_column($added, 'amount_sar'));
        $prepaid = $funding->payment_method === 'prepaid';

        return [
            'month' => $month,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'payment_method' => $funding->payment_method,
            'currency' => 'SAR',
            'period_start' => $this->refresher->countsFrom($funding)?->toDateString(),
            'period_end' => $this->refresher->countsTo($funding)?->toDateString(),
            'remaining_start' => $prepaid ? round($approvedBefore - $spentBefore, 2) : null,
            'added' => round($addedTotal, 2),
            'spent' => round($spent, 2),
            'remaining_end' => $prepaid ? round($approvedBefore + $addedTotal - $spentBefore - $spent, 2) : null,
            'approved_total' => round($approvedBefore + $addedTotal, 2),
            'by_platform' => $byPlatform,
            'movements' => $added,
            'spend_without_rate' => $withoutRate,
        ];
    }

    /**
     * Days after a month ends before it is fixed. Providers restate recent days (the sync re-reads 14),
     * and an account in a western timezone is still inside the last day when Riyadh is not; fixing on
     * the 1st would keep a figure the next month's live start no longer agrees with.
     */
    public const SETTLE_DAYS = 15;

    /**
     * Stores a CLOSED, SETTLED month once. Returns false when it was already stored, the month has not
     * settled yet, or part of its spend still has no rate (it is tried again the next day).
     */
    public function freeze(ProjectFunding $funding, string $month, ?Carbon $today = null): bool
    {
        $today = Carbon::parse(($today ?? Carbon::today('Asia/Riyadh'))->toDateString());
        if (Carbon::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()->startOfDay()->addDays(self::SETTLE_DAYS)->gte($today)) {
            return false;
        }

        $this->refresher->refresh($funding);
        $figures = $this->figures($funding, $month);
        if ($figures['spend_without_rate'] > 0) {
            return false;
        }

        return DB::table('funding_month_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $funding->tenant_id, 'project_id' => $funding->project_id,
            'month' => $month, 'figures' => json_encode($figures, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]) === 1;
    }

    /** The stored summary when the month is closed and fixed, else the live figures of the month. */
    public function read(ProjectFunding $funding, string $month): array
    {
        $fixed = FundingMonthSummary::query()->where('project_id', $funding->project_id)->where('month', $month)->first();

        return $fixed !== null
            ? $fixed->figures + ['fixed' => true, 'fixed_at' => $fixed->created_at->toIso8601String()]
            : $this->figures($funding, $month) + ['fixed' => false, 'fixed_at' => null];
    }

    /**
     * The months a project has figures for, newest first: from its first counted day to this month.
     *
     * @return list<string>
     */
    public function months(ProjectFunding $funding, ?Carbon $today = null): array
    {
        $from = $this->refresher->countsFrom($funding);
        if ($from === null) {
            return [];
        }
        $today = Carbon::parse(($today ?? Carbon::today('Asia/Riyadh'))->toDateString());
        $months = [];
        for ($m = $today->copy()->startOfMonth(); $m->gte($from->copy()->startOfMonth()) && count($months) < 36; $m->subMonth()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }
}
