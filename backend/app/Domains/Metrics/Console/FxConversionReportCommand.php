<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Console;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Fx\FxEngine;
use App\Domains\Metrics\Services\ReportingCurrency;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FX-APPLY-001 (builder G) — the post-deploy production check for the currency engine. READ-ONLY and count-only.
 *
 * For every ad account whose currency is not USD: its currency, the platform's RAW spend (sum of the untouched originals) and
 * the CONVERTED USD spend (the stored `value`, converted by the engine at ingest) per month, plus what stands between them —
 * rows withheld for want of a rate, rows whose stored original currency differs from the account's own (the «253 $» shape:
 * a EUR account's spend stored as dollars), rows stored with a converted figure and no rate, and rows whose stored rate differs
 * from the rate the engine would choose today for that date (what «إعادة حساب» would change). Then each project's total
 * before (raw originals added across currencies — the bypass) and after (converted).
 *
 * Accounts and projects are printed by an ANONYMOUS index (1, 2, 3…) — never an id, a name, an email or a client. It writes
 * nothing and calls no ad platform. Bounded: one month per query, grouped in SQL; one rate lookup per currency and day.
 */
final class FxConversionReportCommand extends Command
{
    protected $signature = 'fx:conversion-report
        {--months=2026-10,2026-11 : comma-separated YYYY-MM months}
        {--json : print JSON instead of tables}';

    protected $description = 'Count-only, read-only: raw vs converted USD spend for every non-USD ad account and each project total (anonymous indexes).';

    public function handle(FxEngine $fx): int
    {
        $months = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('months')))));
        foreach ($months as $m) {
            if (preg_match('/^\d{4}-\d{2}$/', $m) !== 1) {
                $this->error("Bad month: {$m} (expected YYYY-MM).");

                return self::INVALID;
            }
        }

        $accounts = DB::table('external_accounts')
            ->where('account_type', 'ad_account')
            ->whereNotNull('currency')->where('currency', '<>', '')
            ->whereRaw('UPPER(currency) <> ?', [ReportingCurrency::DEFAULT])
            ->orderBy('id')
            ->get(['id', 'currency']);

        $accountIndex = [];
        foreach ($accounts as $i => $a) {
            $accountIndex[(string) $a->id] = ['n' => $i + 1, 'currency' => strtoupper((string) $a->currency)];
        }

        $out = ['generated_at' => now()->toIso8601String(), 'reporting_currency' => ReportingCurrency::DEFAULT, 'accounts' => [], 'projects' => [], 'months' => $months];
        $projectIndex = [];

        foreach ($months as $month) {
            $from = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
            $to = $from->copy()->endOfMonth();

            // Per account: raw originals, converted value, and the diagnostics — all in one grouped query.
            $rows = $accountIndex === [] ? collect() : $this->visibleSpend()
                ->where('metric_key', 'spend')
                ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
                ->whereIn('external_account_id', array_keys($accountIndex))
                ->groupBy('external_account_id')
                ->select('external_account_id')
                ->selectRaw('COALESCE(SUM(original_amount), 0) AS raw')
                ->selectRaw('COALESCE(SUM(value), 0) AS converted')
                ->selectRaw('COUNT(*) AS rows_total')
                ->selectRaw('COUNT(*) FILTER (WHERE value IS NULL AND original_amount IS NOT NULL) AS withheld')
                ->selectRaw('COALESCE(SUM(original_amount) FILTER (WHERE value IS NULL AND original_amount IS NOT NULL), 0) AS withheld_raw')
                ->selectRaw('COUNT(*) FILTER (WHERE value IS NOT NULL AND original_currency IS NOT NULL AND UPPER(original_currency) <> UPPER(project_currency) AND exchange_rate IS NULL) AS stored_without_rate')
                ->selectRaw('STRING_AGG(DISTINCT UPPER(original_currency), \',\') AS stored_currencies')
                ->get()->keyBy('external_account_id');

            // The rows whose stored original currency is not the account's own currency (label drift).
            foreach ($accountIndex as $id => $meta) {
                $r = $rows->get($id);
                if ($r === null) {
                    continue;
                }
                $stored = array_values(array_filter(explode(',', (string) $r->stored_currencies)));
                $drift = (int) $this->visibleSpend()
                    ->where('metric_key', 'spend')->where('external_account_id', $id)
                    ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
                    ->where(static fn ($q) => $q->whereNull('original_currency')->orWhereRaw('UPPER(original_currency) <> ?', [$meta['currency']]))
                    ->count();

                $out['accounts'][] = [
                    'account' => $meta['n'],
                    'currency' => $meta['currency'],
                    'month' => $month,
                    'raw_spend' => round((float) $r->raw, 2),
                    'converted_usd' => round((float) $r->converted, 2),
                    'rows' => (int) $r->rows_total,
                    'withheld_rows' => (int) $r->withheld,
                    'withheld_raw' => round((float) $r->withheld_raw, 2),
                    'stored_without_rate' => (int) $r->stored_without_rate,
                    'stored_currency_not_account_currency' => $drift,
                    'stored_currencies' => $stored,
                    'rate_differs_from_engine_today' => $this->rateDrift($fx, $id, $from, $to),
                ];
            }

            // Per project: before (originals added across currencies) and after (converted), over EVERY account (USD too).
            $projects = $this->visibleSpend()
                ->where('metric_key', 'spend')
                ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
                ->groupBy('project_id')
                ->select('project_id')
                ->selectRaw('COALESCE(SUM(original_amount), 0) AS before_total')
                ->selectRaw('COALESCE(SUM(value), 0) AS after_total')
                ->selectRaw('COUNT(*) FILTER (WHERE value IS NULL AND original_amount IS NOT NULL) AS withheld')
                ->selectRaw('COUNT(DISTINCT UPPER(original_currency)) AS currencies')
                ->orderBy('project_id')
                ->get();
            foreach ($projects as $p) {
                $key = (string) $p->project_id;
                $projectIndex[$key] ??= count($projectIndex) + 1;
                if ((int) $p->currencies <= 1 && (int) $p->withheld === 0 && abs((float) $p->before_total - (float) $p->after_total) < 0.005) {
                    continue; // a single-currency USD project: before = after, nothing to show
                }
                $out['projects'][] = [
                    'project' => $projectIndex[$key],
                    'month' => $month,
                    'currencies' => (int) $p->currencies,
                    'before_raw_added' => round((float) $p->before_total, 2),
                    'after_converted_usd' => round((float) $p->after_total, 2),
                    'withheld_rows' => (int) $p->withheld,
                ];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Non-USD ad accounts: '.count($accountIndex).' · reporting currency '.ReportingCurrency::DEFAULT.' · read-only, anonymous indexes.');
        $this->table(
            ['account', 'currency', 'month', 'raw spend', 'converted USD', 'rows', 'withheld', 'withheld raw', 'no rate stored', 'stored≠account ccy', 'stored ccy', 'rate≠engine'],
            array_map(static fn (array $a): array => [
                $a['account'], $a['currency'], $a['month'], $a['raw_spend'], $a['converted_usd'], $a['rows'], $a['withheld_rows'], $a['withheld_raw'],
                $a['stored_without_rate'], $a['stored_currency_not_account_currency'], implode(',', $a['stored_currencies']), $a['rate_differs_from_engine_today'],
            ], $out['accounts']),
        );
        $this->table(
            ['project', 'month', 'currencies', 'before (raw added)', 'after (converted USD)', 'withheld rows'],
            array_map(static fn (array $p): array => array_values($p), $out['projects']),
        );

        return self::SUCCESS;
    }

    /**
     * ACCOUNT-SCOPE-ISOLATION-001 — the rows a project SHOWS (its actively bound accounts), so «after» is the total the screen
     * carries and a deselected account's history does not move a project's before/after.
     */
    private function visibleSpend(): Builder
    {
        return BoundAccountVisibility::apply(DB::table('daily_metrics'), 'daily_metrics');
    }

    /**
     * How many converted rows carry a stored rate different from the one the engine chooses TODAY for the row's own date — what
     * «إعادة حساب» on the rates page would change. One engine lookup per currency and day (bounded by the month).
     */
    private function rateDrift(FxEngine $fx, string $accountId, Carbon $from, Carbon $to): int
    {
        $pairs = $this->visibleSpend()
            ->where('metric_key', 'spend')->where('external_account_id', $accountId)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('value')->whereNotNull('exchange_rate')->whereNotNull('original_currency')
            ->groupBy('original_currency', 'project_currency', 'metric_date', 'exchange_rate')
            ->selectRaw('UPPER(original_currency) AS c, UPPER(project_currency) AS t, metric_date, exchange_rate, COUNT(*) AS n')
            ->get();

        $drift = 0;
        foreach ($pairs as $p) {
            $now = $fx->convert(1, (string) $p->c, (string) $p->t, Carbon::parse((string) $p->metric_date));
            if ($now->rate === null || abs($now->rate - (float) $p->exchange_rate) > 1e-9 * max(1.0, abs($now->rate))) {
                $drift += (int) $p->n;
            }
        }

        return $drift;
    }
}
