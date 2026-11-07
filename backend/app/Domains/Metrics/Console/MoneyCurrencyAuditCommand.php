<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Console;

use App\Domains\Metrics\Rates\OfficialPegs;
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\ReportingCurrency;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MONEY-CURRENCY-NEVER-ASSUMED-001 — the guard that makes a currency defect VISIBLE before a client sees it.
 *
 * ## Why it exists
 *
 * OPENAI-ADS-CURRENCY-001 was found by the Owner reading a client link: riyals shown as dollars. Nothing in
 * the product could have said so earlier, because the wrong rows were well-formed — a currency, a rate, a
 * value. This command reads the four conditions that precede or betray that defect, per provider:
 *
 *  1. `accounts_without_currency` — ad accounts stored with no currency (split by whether a project is
 *     actively bound: a bound one is being synced, and its money is being withheld right now);
 *  2. `row_currency_differs_from_account` — stored money whose `original_currency` is not the account's.
 *     Legitimate for a platform that labels rows itself, and exactly what rows written under an assumed
 *     currency look like after the account's real currency arrives (USD rows on a SAR account);
 *  3. `money_rows_without_currency` — money stored with no currency at all, at each of the three grains:
 *     `withheld` (no value — the fail-closed state) and `valued` (a number with no unit: pre-FX history);
 *  4. `pairs_without_rate` — conversions withheld for want of a rate, and whether one resolves today.
 *
 * It also lists, per provider, the account currencies in use and whether each converts to the reporting
 * currency today — so a EUR account is known to be unconvertible before its first row is withheld.
 *
 * ## Read-only, and safe to print
 *
 * SELECTs only; nothing is written, queued or asked of any provider. Ids are the first eight characters;
 * no account, campaign, client or project NAME is read, and no amount is printed — only counts, dates and
 * ISO currency codes.
 */
final class MoneyCurrencyAuditCommand extends Command
{
    protected $signature = 'money:currency-audit
        {--json : One JSON document instead of the table}
        {--sample=10 : Masked account ids listed per finding}';

    protected $description = 'Read-only: per provider, accounts with no currency, stored money whose currency differs from its account, money rows with no currency, and currency pairs with no rate.';

    public function handle(CurrencyConverter $rates): int
    {
        $sample = max(0, (int) $this->option('sample'));
        $reporting = ReportingCurrency::DEFAULT;
        $today = Carbon::today();

        /** @var array<string, array<string, mixed>> $providers */
        $providers = [];
        $touch = static function (string $provider) use (&$providers): void {
            $providers[$provider] ??= [
                'accounts' => 0,
                'accounts_without_currency' => ['total' => 0, 'bound_to_a_project' => 0, 'sample' => []],
                'account_currencies' => [],
                'row_currency_differs_from_account' => ['accounts' => 0, 'rows' => 0, 'findings' => []],
                'money_rows_without_currency' => [
                    'campaign' => ['withheld' => 0, 'valued' => 0, 'from' => null, 'to' => null],
                    'ad_set_and_ad' => ['withheld' => 0, 'valued' => 0, 'from' => null, 'to' => null],
                    'creative' => ['withheld' => 0, 'valued' => 0, 'from' => null, 'to' => null],
                ],
                'pairs_without_rate' => [],
            ];
        };

        // 1 — accounts, their currencies, and the ones with none.
        $accounts = DB::table('external_accounts as a')
            ->where('a.account_type', 'ad_account')
            ->selectRaw("a.id, a.provider, NULLIF(UPPER(TRIM(a.currency)), '') AS currency")
            ->selectRaw('EXISTS (SELECT 1 FROM project_integration_bindings b WHERE b.external_account_id = a.id AND b.is_active = true) AS bound')
            ->orderBy('a.id')
            ->get();

        foreach ($accounts as $account) {
            $provider = (string) $account->provider;
            $touch($provider);
            $providers[$provider]['accounts']++;

            if ($account->currency === null) {
                $none = &$providers[$provider]['accounts_without_currency'];
                $none['total']++;
                $none['bound_to_a_project'] += $account->bound ? 1 : 0;
                // Bound accounts first in the sample: they are the ones whose money is withheld today.
                if ($account->bound) {
                    array_unshift($none['sample'], $this->mask($account->id));
                } else {
                    $none['sample'][] = $this->mask($account->id);
                }
                unset($none);

                continue;
            }

            $code = (string) $account->currency;
            $providers[$provider]['account_currencies'][$code] ??= [
                'currency' => $code,
                'accounts' => 0,
                'converts_to_'.strtolower($reporting).'_today' => $rates->resolve($code, $reporting, $today) !== null,
                // A legal peg needs no feed: `fx:rates` records it the first night a figure is withheld for it.
                'legal_peg' => $code !== $reporting && OfficialPegs::rate($code, $reporting) !== null,
            ];
            $providers[$provider]['account_currencies'][$code]['accounts']++;
        }

        // 2 — stored campaign-grain money whose currency is not its account's.
        $differs = DB::table('daily_metrics as m')
            ->join('external_accounts as a', 'a.id', '=', 'm.external_account_id')
            ->whereNotNull('m.original_currency')
            ->whereNotNull('m.original_amount')
            ->whereRaw("NULLIF(UPPER(TRIM(a.currency)), '') IS NOT NULL")
            ->whereRaw('UPPER(m.original_currency) <> UPPER(TRIM(a.currency))')
            ->where('m.is_demo', false)
            ->groupBy('m.provider', 'm.external_account_id', 'm.original_currency', 'a.currency')
            ->selectRaw('m.provider, m.external_account_id, UPPER(m.original_currency) AS row_currency, UPPER(TRIM(a.currency)) AS account_currency')
            ->selectRaw('COUNT(*) AS rows, MIN(m.metric_date) AS first_day, MAX(m.metric_date) AS last_day')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        $seen = [];
        foreach ($differs as $row) {
            $provider = (string) $row->provider;
            $touch($provider);
            $finding = &$providers[$provider]['row_currency_differs_from_account'];
            $finding['rows'] += (int) $row->rows;
            if (! isset($seen[$provider][$row->external_account_id])) {
                $seen[$provider][$row->external_account_id] = true;
                $finding['accounts']++;
            }
            if (count($finding['findings']) < $sample) {
                $finding['findings'][] = [
                    'account' => $this->mask($row->external_account_id),
                    'account_currency' => (string) $row->account_currency,
                    'row_currency' => (string) $row->row_currency,
                    'rows' => (int) $row->rows,
                    'from' => $this->day($row->first_day),
                    'to' => $this->day($row->last_day),
                ];
            }
            unset($finding);
        }

        // 3 — money with no currency, at each grain. `withheld` has no value; `valued` is a number with no unit.
        $campaign = DB::table('daily_metrics as m')
            ->join('metric_definitions as d', 'd.key', '=', 'm.metric_key')
            ->where('d.is_currency', true)
            ->whereNull('m.original_currency')
            ->where('m.is_demo', false)
            ->groupBy('m.provider')
            ->selectRaw('m.provider')
            ->selectRaw('COUNT(*) FILTER (WHERE m.value IS NULL AND m.original_amount IS NOT NULL) AS withheld')
            ->selectRaw('COUNT(*) FILTER (WHERE m.value IS NOT NULL AND m.value <> 0) AS valued')
            ->selectRaw('MIN(m.metric_date) FILTER (WHERE (m.value IS NULL AND m.original_amount IS NOT NULL) OR (m.value IS NOT NULL AND m.value <> 0)) AS first_day')
            ->selectRaw('MAX(m.metric_date) FILTER (WHERE (m.value IS NULL AND m.original_amount IS NOT NULL) OR (m.value IS NOT NULL AND m.value <> 0)) AS last_day')
            ->get();

        $wide = 'COUNT(*) FILTER (WHERE (m.spend IS NULL AND m.spend_original IS NOT NULL) OR (m.revenue IS NULL AND m.revenue_original IS NOT NULL)) AS withheld, '
            .'COUNT(*) FILTER (WHERE COALESCE(m.spend, 0) <> 0 OR COALESCE(m.revenue, 0) <> 0) AS valued, '
            .'MIN(m.metric_date) FILTER (WHERE m.spend_original IS NOT NULL OR m.revenue_original IS NOT NULL OR COALESCE(m.spend, 0) <> 0 OR COALESCE(m.revenue, 0) <> 0) AS first_day, '
            .'MAX(m.metric_date) FILTER (WHERE m.spend_original IS NOT NULL OR m.revenue_original IS NOT NULL OR COALESCE(m.spend, 0) <> 0 OR COALESCE(m.revenue, 0) <> 0) AS last_day';

        $entity = DB::table('entity_daily_metrics as m')
            ->whereNull('m.original_currency')
            ->where('m.is_demo', false)
            ->groupBy('m.provider')
            ->selectRaw('m.provider, '.$wide)
            ->get();

        $creative = DB::table('creative_daily_metrics as m')
            ->join('external_creatives as c', 'c.id', '=', 'm.creative_id')
            ->whereNull('m.original_currency')
            ->where('m.is_demo', false)
            ->groupBy('c.provider')
            ->selectRaw('c.provider, '.$wide)
            ->get();

        foreach (['campaign' => $campaign, 'ad_set_and_ad' => $entity, 'creative' => $creative] as $grain => $rows) {
            foreach ($rows as $row) {
                if ((int) $row->withheld === 0 && (int) $row->valued === 0) {
                    continue;
                }
                $provider = (string) $row->provider;
                $touch($provider);
                $providers[$provider]['money_rows_without_currency'][$grain] = [
                    'withheld' => (int) $row->withheld,
                    'valued' => (int) $row->valued,
                    'from' => $this->day($row->first_day),
                    'to' => $this->day($row->last_day),
                ];
            }
        }

        // 4 — conversions withheld for want of a rate, and whether one resolves today for the whole span.
        $pairs = DB::table('daily_metrics as m')
            ->whereNull('m.value')
            ->whereNotNull('m.original_amount')
            ->whereNotNull('m.original_currency')
            ->whereNotNull('m.project_currency')
            ->where('m.is_demo', false)
            ->groupBy('m.provider', 'm.original_currency', 'm.project_currency')
            ->selectRaw('m.provider, UPPER(m.original_currency) AS base, UPPER(m.project_currency) AS quote')
            ->selectRaw('COUNT(*) AS rows, MIN(m.metric_date) AS first_day, MAX(m.metric_date) AS last_day')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        foreach ($pairs as $row) {
            $provider = (string) $row->provider;
            $touch($provider);
            $first = Carbon::parse((string) $row->first_day);
            $last = Carbon::parse((string) $row->last_day);
            $providers[$provider]['pairs_without_rate'][] = [
                'pair' => $row->base.'→'.$row->quote,
                'rows' => (int) $row->rows,
                'from' => $first->toDateString(),
                'to' => $last->toDateString(),
                // A rate on or before the EARLIEST withheld day converts the whole span; one that only
                // reaches the latest day converts part of it. `metrics:renormalise-currency` does the rest.
                'rate_reaches_first_day' => $rates->resolve((string) $row->base, (string) $row->quote, $first) !== null,
                'rate_reaches_last_day' => $rates->resolve((string) $row->base, (string) $row->quote, $last) !== null,
            ];
        }

        ksort($providers);
        foreach ($providers as $provider => $report) {
            ksort($report['account_currencies']);
            $providers[$provider]['account_currencies'] = array_values($report['account_currencies']);
            $providers[$provider]['accounts_without_currency']['sample'] = array_slice($report['accounts_without_currency']['sample'], 0, $sample);
        }

        $totals = [
            'accounts_without_currency' => array_sum(array_map(static fn (array $p): int => $p['accounts_without_currency']['total'], $providers)),
            'bound_accounts_without_currency' => array_sum(array_map(static fn (array $p): int => $p['accounts_without_currency']['bound_to_a_project'], $providers)),
            'rows_whose_currency_differs_from_account' => array_sum(array_map(static fn (array $p): int => $p['row_currency_differs_from_account']['rows'], $providers)),
            'money_rows_without_currency' => array_sum(array_map(
                static fn (array $p): int => array_sum(array_map(static fn (array $g): int => $g['withheld'] + $g['valued'], $p['money_rows_without_currency'])),
                $providers,
            )),
            'pairs_without_rate' => array_sum(array_map(static fn (array $p): int => count($p['pairs_without_rate']), $providers)),
        ];

        $document = [
            'requirement' => 'MONEY-CURRENCY-NEVER-ASSUMED-001',
            'generated_at' => Carbon::now()->toIso8601String(),
            'reporting_currency' => $reporting,
            'read_only' => true,
            'totals' => $totals,
            'providers' => $providers,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('MONEY-CURRENCY-NEVER-ASSUMED-001 — currency audit (read-only). Reporting currency: '.$reporting);

        foreach ($providers as $provider => $p) {
            $this->newLine();
            $this->line("== {$provider} — {$p['accounts']} ad account(s)");
            $none = $p['accounts_without_currency'];
            $this->line("  accounts without a currency: {$none['total']} (bound to a project: {$none['bound_to_a_project']})"
                .($none['sample'] === [] ? '' : '  ['.implode(', ', $none['sample']).']'));
            $this->line('  account currencies: '.($p['account_currencies'] === [] ? '—' : implode(', ', array_map(
                static fn (array $c): string => $c['currency'].'×'.$c['accounts'].($c['converts_to_'.strtolower($reporting).'_today'] ? '' : ($c['legal_peg'] ? ' (no stored rate yet — legal peg, recorded by fx:rates)' : ' (NO RATE)')),
                $p['account_currencies'],
            ))));
            $differs = $p['row_currency_differs_from_account'];
            $this->line("  money rows whose currency differs from the account's: {$differs['rows']} on {$differs['accounts']} account(s)");
            foreach ($differs['findings'] as $f) {
                $this->line("    {$f['account']}  account {$f['account_currency']} / rows {$f['row_currency']}  {$f['rows']} row(s)  {$f['from']} → {$f['to']}");
            }
            foreach ($p['money_rows_without_currency'] as $grain => $g) {
                if ($g['withheld'] + $g['valued'] > 0) {
                    $this->line("  money rows with no currency [{$grain}]: withheld {$g['withheld']}, valued {$g['valued']}  {$g['from']} → {$g['to']}");
                }
            }
            foreach ($p['pairs_without_rate'] as $pair) {
                $this->line("  withheld for want of a rate: {$pair['pair']}  {$pair['rows']} row(s)  {$pair['from']} → {$pair['to']}"
                    .($pair['rate_reaches_first_day'] ? '  (a rate now exists: run metrics:renormalise-currency)' : ($pair['rate_reaches_last_day'] ? '  (a rate exists for part of the span)' : '  (no rate)')));
            }
        }

        $this->newLine();
        $this->line('TOTALS  '.implode('  ', array_map(static fn (string $k, int $v): string => "{$k}={$v}", array_keys($totals), $totals)));

        return self::SUCCESS;
    }

    private function mask(mixed $id): string
    {
        return substr((string) $id, 0, 8);
    }

    private function day(mixed $date): ?string
    {
        return $date === null ? null : Carbon::parse((string) $date)->toDateString();
    }
}
