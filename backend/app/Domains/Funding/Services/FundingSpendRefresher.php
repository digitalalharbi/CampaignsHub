<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Services\CurrencyConverter;
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
    public function __construct(private readonly CurrencyConverter $fx) {}

    public function refresh(ProjectFunding $funding): int
    {
        $from = $this->countsFrom($funding);
        if ($from === null) {
            return 0;
        }
        $to = $this->countsTo($funding);

        /*
         * Amount and currency are read from the SAME row (a row's own original figure, else its project-currency
         * value), then grouped by currency — two currencies on one account-day are converted separately and
         * never added as if they were one. Estimated and modeled rows are not real spend and are left out.
         */
        $currency = 'CASE WHEN daily_metrics.original_amount IS NOT NULL THEN daily_metrics.original_currency ELSE daily_metrics.project_currency END';
        $perCampaign = DB::table('daily_metrics')
            ->where('daily_metrics.project_id', $funding->project_id)
            ->where('daily_metrics.metric_key', 'spend')
            ->where('daily_metrics.metric_date', '>=', $from->toDateString())
            ->when($to !== null, static fn ($q) => $q->where('daily_metrics.metric_date', '<=', $to->toDateString()))
            ->whereNotIn('daily_metrics.source_type', ['estimated', 'modeled'])
            ->selectRaw('daily_metrics.external_account_id, daily_metrics.provider, daily_metrics.metric_date, daily_metrics.external_campaign_id')
            ->selectRaw("{$currency} AS currency")
            ->selectRaw('MAX(COALESCE(daily_metrics.original_amount, daily_metrics.value)) AS amount')
            ->groupBy('daily_metrics.external_account_id', 'daily_metrics.provider', 'daily_metrics.metric_date', 'daily_metrics.external_campaign_id')
            ->groupByRaw($currency);
        BoundAccountVisibility::apply($perCampaign, 'daily_metrics');

        $parts = DB::query()->fromSub($perCampaign, 'c')
            ->leftJoin('external_accounts as a', 'a.id', '=', 'c.external_account_id')
            ->selectRaw('c.external_account_id, MAX(c.provider) AS provider, c.metric_date, COALESCE(c.currency, MAX(a.currency)) AS currency, SUM(c.amount) AS amount')
            ->groupBy('c.external_account_id', 'c.metric_date', 'c.currency')
            ->get()
            ->groupBy(static fn ($r): string => $r->external_account_id.'|'.Carbon::parse((string) $r->metric_date)->toDateString());

        $now = now();
        $rows = [];
        foreach ($parts as $group) {
            $first = $group->first();
            $date = Carbon::parse((string) $first->metric_date);
            $sar = 0.0;
            $rate = null;
            $missing = false;

            foreach ($group as $part) {
                $cur = $part->currency !== null ? strtoupper((string) $part->currency) : null;
                $rate = $cur === null ? null : $this->fx->resolve($cur, 'SAR', $date);
                if ($rate === null) {
                    $missing = true;

                    continue;
                }
                $sar += (float) $part->amount * $rate['rate'];
            }

            $single = $group->count() === 1;
            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $funding->tenant_id,
                'project_id' => $funding->project_id,
                'external_account_id' => (string) $first->external_account_id,
                'provider' => (string) $first->provider,
                'spend_date' => $date->toDateString(),
                // One currency: the source figure as it was. Several: the SAR total, with no single source currency to claim.
                'original_amount' => round($single ? (float) $first->amount : $sar, 6),
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
            ->where('daily_metrics.value', '>', 0);
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
