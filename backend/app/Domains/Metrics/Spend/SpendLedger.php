<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Spend;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Fx\FxConversion;
use App\Domains\Metrics\Fx\FxEngine;
use App\Domains\Projects\Lifecycle\LiveProjects;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PROJECT-LIFECYCLE-001 §7, rule 9 — THE one aggregation of real ad spend over a period, in any currency, through FxEngine.
 *
 * ## Why this exists (the defect it closes)
 *
 * Five pages answered «how much did this client spend» five ways: the budgets page converted each account-day from its
 * ORIGINAL currency at refresh time (`funding_spend_days`); the clients list, the projects summary and the campaigns page
 * summed `daily_metrics.value` — a figure converted ONCE, at ingest, so a day stored before its rate existed stayed
 * `NULL` (silently dropped) until somebody pressed «إعادة حساب»; and the dashboards divided the platforms' campaign budgets
 * by a different spend again. Same client, same days, three numbers.
 *
 * Here every figure is read the same way:
 *  - the rows are the project's selected accounts' real daily spend (`BoundAccountVisibility`; estimated and modeled rows
 *    are not spend), one figure per campaign and day (a provider storing several attribution windows is counted once) —
 *    exactly the rows the budget ledger is built from ({@see accountDays()} is the budget refresher's own query);
 *  - each account-day is converted from its ORIGINAL amount and currency by {@see FxEngine::convert()} for its own date
 *    (manual, pegged, cross through USD, stale and expired rules are the engine's — none is re-decided here);
 *  - only LIVE projects are read (N1's shared scope; rule 5) — an archived or deleted project is never in a total, and
 *    the CLIENT's state is never read (rule 4).
 *
 * ## A missing rate never zeroes anything
 *
 * A part with no rate is EXCLUDED from the total and NAMED: «سعر صرف غير متوفر» with its currency, its account and its
 * original amount, plus one explicit statement. The rest is still the total. A total is `null` only when nothing at all
 * could be converted. A rate older than the stale window, or past its validity, is USED and listed with
 * «سعر قديم — بتاريخ …».
 */
final class SpendLedger
{
    public const EXCLUDED_AR = 'سعر صرف غير متوفر';

    public const EXCLUDED_EN = 'Exchange rate unavailable';

    public function __construct(private readonly FxEngine $fx) {}

    /**
     * The real daily spend, one row per (project, account, day, currency): the ONE row definition. The budget refresher builds
     * `funding_spend_days` from it and every total below sums it, so the two can never be counted differently.
     *
     * @param  list<string>  $projectIds
     * @return list<SpendDay>
     */
    public static function accountDays(array $projectIds, ?Carbon $from, ?Carbon $to): array
    {
        if ($projectIds === []) {
            return [];
        }

        /*
         * Amount and currency are read from the SAME row (a row's own original figure, else its project-currency value), then
         * grouped by currency — two currencies on one account-day are converted separately and never added as one.
         */
        $currency = 'CASE WHEN daily_metrics.original_amount IS NOT NULL THEN daily_metrics.original_currency ELSE daily_metrics.project_currency END';
        $perCampaign = DB::table('daily_metrics')
            ->whereIn('daily_metrics.project_id', $projectIds)
            ->where('daily_metrics.metric_key', 'spend')
            ->when($from !== null, static fn ($q) => $q->where('daily_metrics.metric_date', '>=', $from->toDateString()))
            ->when($to !== null, static fn ($q) => $q->where('daily_metrics.metric_date', '<=', $to->toDateString()))
            ->whereNotIn('daily_metrics.source_type', ['estimated', 'modeled'])
            ->selectRaw('daily_metrics.project_id, daily_metrics.external_account_id, daily_metrics.provider, daily_metrics.metric_date, daily_metrics.external_campaign_id')
            ->selectRaw("{$currency} AS currency")
            ->selectRaw('MAX(COALESCE(daily_metrics.original_amount, daily_metrics.value)) AS amount')
            ->groupBy('daily_metrics.project_id', 'daily_metrics.external_account_id', 'daily_metrics.provider', 'daily_metrics.metric_date', 'daily_metrics.external_campaign_id')
            ->groupByRaw($currency);
        BoundAccountVisibility::apply($perCampaign, 'daily_metrics');

        return DB::query()->fromSub($perCampaign, 'c')
            ->leftJoin('external_accounts as a', 'a.id', '=', 'c.external_account_id')
            ->selectRaw('c.project_id, c.external_account_id, MAX(c.provider) AS provider, c.metric_date, UPPER(COALESCE(c.currency, MAX(a.currency))) AS currency, SUM(c.amount) AS amount')
            ->groupBy('c.project_id', 'c.external_account_id', 'c.metric_date', 'c.currency')
            ->get()
            ->map(static fn (object $r): SpendDay => new SpendDay(
                projectId: (string) $r->project_id,
                accountId: (string) $r->external_account_id,
                provider: (string) $r->provider,
                date: Carbon::parse((string) $r->metric_date)->toDateString(),
                currency: $r->currency === null || $r->currency === '' ? null : (string) $r->currency,
                amount: (float) $r->amount,
            ))
            ->values()
            ->all();
    }

    /**
     * The spend of these projects over [from, to] (either end open), in `$currency`.
     *
     * @param  list<string>  $projectIds
     * @param  bool  $liveOnly  false only for a budget's own ledger (a budget keeps its history; the budgets list itself only
     *                          shows live projects)
     * @return array<string, mixed> see {@see shape()}
     */
    public function read(array $projectIds, ?Carbon $from, ?Carbon $to, string $currency, bool $liveOnly = true): array
    {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Not a currency code: '.$currency);
        }
        $ids = array_values(array_unique(array_map('strval', $projectIds)));
        $ids = $liveOnly ? LiveProjects::filter($ids) : $ids;

        $total = 0.0;
        $converted = 0;
        $byProject = [];
        $currenciesByProject = [];
        $excluded = [];
        $stale = [];

        foreach (self::accountDays($ids, $from, $to) as $row) {
            if ($row->amount == 0.0) {
                continue; // nothing to convert, nothing withheld
            }
            $c = $row->currency === null ? null : $this->convert($row->amount, $row->currency, $currency, $row->date);

            if ($c === null || $c->withheld || $c->converted === null) {
                $key = $row->projectId.'|'.$row->accountId.'|'.($row->currency ?? '?');
                $excluded[$key] ??= [
                    'project_id' => $row->projectId, 'account_id' => $row->accountId, 'account_name' => null,
                    'provider' => $row->provider, 'currency' => $row->currency, 'original_amount' => 0.0, 'days' => 0,
                    'first_day' => $row->date, 'last_day' => $row->date,
                ];
                $excluded[$key]['original_amount'] += $row->amount;
                $excluded[$key]['days']++;
                $excluded[$key]['first_day'] = min($excluded[$key]['first_day'], $row->date);
                $excluded[$key]['last_day'] = max($excluded[$key]['last_day'], $row->date);

                continue;
            }

            $total += $c->converted;
            $converted++;
            $byProject[$row->projectId] = ($byProject[$row->projectId] ?? 0.0) + $c->converted;
            $currenciesByProject[$row->projectId][$row->currency] = true;
            if ($c->stale) {
                $sk = $row->projectId.'|'.$row->currency.'|'.$c->rateDate;
                $stale[$sk] ??= ['project_id' => $row->projectId, 'currency' => (string) $row->currency, 'rate_date' => $c->rateDate,
                    'expired' => $c->expired, 'label' => $c->staleLabel(), 'label_en' => $c->staleLabel(false), 'days' => 0];
                $stale[$sk]['days']++;
            }
        }

        $excluded = $this->named(array_values($excluded));

        return self::shape($currency, $from, $to, $converted > 0 ? $total : ($excluded === [] ? 0.0 : null), $byProject,
            array_map(static fn (array $c): array => array_keys($c), $currenciesByProject), $excluded, array_values($stale));
    }

    /**
     * The same reading narrowed to some of its projects (a client's, on a page that read every client at once).
     *
     * @param  array<string, mixed>  $reading  {@see read()}
     * @param  list<string>  $projectIds
     * @return array<string, mixed>
     */
    public static function slice(array $reading, array $projectIds): array
    {
        $keep = array_flip(array_map('strval', $projectIds));
        $byProject = array_intersect_key((array) $reading['by_project'], $keep);
        $excluded = array_values(array_filter((array) $reading['excluded'], static fn (array $e): bool => isset($keep[$e['project_id']])));
        $stale = array_values(array_filter((array) $reading['stale'], static fn (array $s): bool => isset($keep[$s['project_id']])));
        $currencies = array_intersect_key((array) $reading['currencies_by_project'], $keep);
        $amount = $byProject === [] ? ($excluded === [] ? 0.0 : null) : array_sum($byProject);

        return self::shape((string) $reading['currency'], $reading['from'] === null ? null : Carbon::parse((string) $reading['from']),
            $reading['to'] === null ? null : Carbon::parse((string) $reading['to']), $amount, $byProject, $currencies, $excluded, $stale);
    }

    /**
     * @param  array<string, float>  $byProject
     * @param  array<string, list<string>>  $currenciesByProject
     * @param  list<array<string, mixed>>  $excluded
     * @param  list<array<string, mixed>>  $stale
     * @return array<string, mixed>
     */
    private static function shape(string $currency, ?Carbon $from, ?Carbon $to, ?float $amount, array $byProject, array $currenciesByProject, array $excluded, array $stale): array
    {
        $currencies = $currenciesByProject === [] ? [] : array_values(array_unique(array_merge(...array_values($currenciesByProject))));
        sort($currencies);
        $statement = self::statement($excluded, true);

        return [
            'currency' => $currency,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            // null ONLY when every part was excluded; a part with no rate never makes the rest zero.
            'amount' => $amount === null ? null : round($amount, 2),
            'complete' => $excluded === [],
            'partial' => $excluded !== [] && $amount !== null,
            'currencies' => $currencies,
            'includes_converted' => array_diff($currencies, [$currency]) !== [],
            'by_project' => array_map(static fn (float $v): float => round($v, 2), $byProject),
            'currencies_by_project' => $currenciesByProject,
            'excluded' => $excluded,
            'excluded_label' => $excluded === [] ? null : self::EXCLUDED_AR,
            'statement' => $statement,
            'statement_en' => self::statement($excluded, false),
            'stale' => $stale,
            'stale_label' => $stale === [] ? null : (string) $stale[0]['label'],
            'rates_page' => $excluded === [] ? null : FxConversion::RATES_PAGE,
            'via' => 'fx.convert',
        ];
    }

    /** @param  list<array<string, mixed>>  $excluded */
    private static function statement(array $excluded, bool $ar): ?string
    {
        if ($excluded === []) {
            return null;
        }
        $parts = array_map(static function (array $e) use ($ar): string {
            $cur = $e['currency'] ?? ($ar ? 'عملة غير معروفة' : 'unknown currency');
            $amount = number_format((float) $e['original_amount'], 2, '.', ',');
            $name = (string) ($e['account_name'] ?? '');

            return $ar
                ? "{$cur} — {$name} ({$amount} {$cur}، ".self::days((int) $e['days']).')'
                : "{$cur} — {$name} ({$amount} {$cur}, {$e['days']} day(s))";
        }, $excluded);

        return $ar
            ? self::EXCLUDED_AR.': '.implode('؛ ', $parts).' — مستبعد من الإجمالي.'
            : self::EXCLUDED_EN.': '.implode('; ', $parts).' — excluded from the total.';
    }

    /** Arabic counted noun: يوم واحد · يومان · 3–10 أيام · 11+ يومًا. */
    private static function days(int $n): string
    {
        return match (true) {
            $n === 1 => 'يوم واحد',
            $n === 2 => 'يومان',
            $n >= 3 && $n <= 10 => $n.' أيام',
            default => $n.' يومًا',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $excluded
     * @return list<array<string, mixed>>
     */
    private function named(array $excluded): array
    {
        if ($excluded === []) {
            return [];
        }
        $names = DB::table('external_accounts')->whereIn('id', array_values(array_unique(array_column($excluded, 'account_id'))))
            ->pluck('name', 'id')->all();

        return array_map(static fn (array $e): array => ['account_name' => (string) ($names[$e['account_id']] ?? $e['provider']),
            'original_amount' => round((float) $e['original_amount'], 2)] + $e, $excluded);
    }

    private function convert(float $amount, string $from, string $to, string $date): ?FxConversion
    {
        try {
            return $this->fx->convert($amount, $from, $to, $date);
        } catch (InvalidArgumentException) {
            return null; // not a currency code: the unit is unknown, so the part is excluded and named
        }
    }
}
