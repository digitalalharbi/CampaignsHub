<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Support\BudgetFx;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Spend\SpendDay;
use App\Domains\Metrics\Spend\SpendLedger;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FIN-LOOP-001 — copies a project's real daily spend into `funding_spend_days`, one row per account per day.
 *
 * The source is the synced `daily_metrics` spend of the project's SELECTED accounts only (the same
 * deny-by-default rule every figure in the product follows). Within one account and day a campaign is
 * counted once even if a provider stored it under several attribution windows.
 *
 * The write is an upsert on (project, account, day): running this after every sync, after a reconnect,
 * or twice in a row leaves exactly one row per key with the latest figure — never a second deduction
 * (F07). A row is never removed when an account is later deselected: that money was spent.
 */
final class FundingSpendRefresher
{
    public function __construct(private readonly BudgetFx $fx) {}

    public function refresh(ProjectFunding $funding): int
    {
        $from = $this->countsFrom($funding);
        if ($from === null) {
            return 0;
        }
        $to = $this->countsTo($funding);

        /*
         * PROJECT-LIFECYCLE-001 rule 9 — the rows are THE spend definition every total reads ({@see SpendLedger::accountDays()}):
         * a row's own original figure and currency, one figure per campaign and day, the selected accounts only, estimated and
         * modeled rows left out. Two currencies on one account-day are converted separately and never added as if one.
         */
        $parts = collect(SpendLedger::accountDays([(string) $funding->project_id], $from, $to))
            ->groupBy(static fn (SpendDay $r): string => $r->accountId.'|'.$r->date);

        $now = now();
        $rows = [];
        foreach ($parts as $group) {
            $first = $group->first();
            $date = Carbon::parse($first->date);
            $sar = 0.0;
            $rate = null;
            $missing = false;

            foreach ($group as $part) {
                $cur = $part->currency !== null ? strtoupper((string) $part->currency) : null;
                $rate = $cur === null ? null : $this->fx->rate($cur, 'SAR', $date);
                if ($rate === null) {
                    $missing = true;

                    continue;
                }
                $sar += $part->amount * $rate['rate'];
            }

            $single = $group->count() === 1;
            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $funding->tenant_id,
                'project_id' => $funding->project_id,
                'external_account_id' => $first->accountId,
                'provider' => $first->provider,
                'spend_date' => $date->toDateString(),
                // One currency: the source figure as it was. Several: the SAR total, with no single source currency to claim.
                'original_amount' => round($single ? $first->amount : $sar, 6),
                'original_currency' => $single && $first->currency !== null ? strtoupper((string) $first->currency) : ($single ? null : 'SAR'),
                'amount_sar' => $missing ? null : round($sar, 2),
                'rate' => $single && ! $missing ? $rate['rate'] : null,
                'rate_date' => $single && ! $missing ? $rate['rate_date'] : null,
                'rate_source' => $missing ? null : ($single ? $rate['source'] : 'mixed'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('funding_spend_days')->upsert(
                $chunk,
                ['project_id', 'external_account_id', 'spend_date'],
                ['provider', 'original_amount', 'original_currency', 'amount_sar', 'rate', 'rate_date', 'rate_source', 'updated_at'],
            );
        }

        return count($rows);
    }

    /**
     * The first day whose spend counts: the period's stated start; else the first day of REAL spend of the project's
     * selected accounts — so a campaign that ran for a week before the budget was typed in is counted from its first
     * day (BUDGET-PERIOD-001) — and, for a project with no spend yet, the first approved amount's date.
     */
    public function countsFrom(ProjectFunding $funding): ?Carbon
    {
        if ($funding->starts_on !== null) {
            return Carbon::parse($funding->starts_on->toDateString());
        }

        $spend = DB::table('daily_metrics')
            ->where('daily_metrics.project_id', $funding->project_id)
            ->where('daily_metrics.metric_key', 'spend')
            ->whereNotIn('daily_metrics.source_type', ['estimated', 'modeled'])
            // PROJECT-LIFECYCLE-001 §7 — the first day of REAL spend, converted or not: a day withheld for want of a rate is still
            // spend, so an open-ended budget counts from it (and names it as excluded) instead of silently starting later.
            ->whereRaw('COALESCE(daily_metrics.original_amount, daily_metrics.value) > 0');
        BoundAccountVisibility::apply($spend, 'daily_metrics');
        $firstSpend = $spend->min('daily_metrics.metric_date');

        $firstPaid = DB::table('funding_payments')->where('project_id', $funding->project_id)->min('paid_on');

        $days = array_filter([$firstSpend, $firstPaid], static fn ($d): bool => $d !== null);

        return $days === [] ? null : Carbon::parse((string) min(array_map(static fn ($d): string => Carbon::parse((string) $d)->toDateString(), $days)));
    }

    /** The last day whose spend counts; null while the period is open. */
    public function countsTo(ProjectFunding $funding): ?Carbon
    {
        return $funding->ends_on === null ? null : Carbon::parse($funding->ends_on->toDateString());
    }

    /**
     * The spend rows that belong to this budget: its project, inside its period. The one definition every figure
     * (remaining, the monthly summary, the line chart, the export) reads, so none of them can disagree.
     */
    public function spendRows(ProjectFunding $funding): Builder
    {
        $from = $this->countsFrom($funding);
        $to = $this->countsTo($funding);

        return DB::table('funding_spend_days')->where('project_id', $funding->project_id)
            ->when($from !== null, static fn ($q) => $q->where('spend_date', '>=', $from->toDateString()), static fn ($q) => $q->whereRaw('false'))
            ->when($to !== null, static fn ($q) => $q->where('spend_date', '<=', $to->toDateString()));
    }
}
