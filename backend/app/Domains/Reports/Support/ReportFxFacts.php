<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Reports\Models\Report;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FX-APPLY-001 (builder G) — what an exported file must say about its money: the currency its figures are IN, which original
 * currencies were converted into it, at which rate, on which dates, by which kind of rate, and whether anything was withheld.
 *
 * The figures in a report were converted once, at ingest, by the same engine (`daily_metrics.value`, rate and source kept
 * beside the original). This reads ONLY those stored facts — no figure, no name — so the file's header and its «original
 * currency» column agree with the screen without converting anything a second time.
 */
final class ReportFxFacts
{
    /**
     * @return array{currency: string, includes_converted: bool, stale: bool, withheld_rows: int,
     *   by_currency: list<array{currency: ?string, rows: int, withheld: int, min_rate: ?float, max_rate: ?float, first: ?string, last: ?string, sources: list<string>, stale: int}>,
     *   by_provider: array<string, list<string>>, header: string}
     */
    public static function for(Report $report, ?string $from = null, ?string $to = null): array
    {
        $from ??= optional($report->period_start)->toDateString();
        $to ??= optional($report->period_end)->toDateString();
        $staleDays = CurrencyConverter::staleAfterDays();

        $q = static fn () => DB::table('daily_metrics')
            ->where('tenant_id', $report->tenant_id)
            ->when($report->project_id !== null, static fn ($w) => $w->where('project_id', $report->project_id))
            ->when($from !== null && $to !== null, static fn ($w) => $w->whereBetween('metric_date', [$from, $to]))
            ->whereIn('metric_key', ['spend', 'revenue'])
            ->whereNotNull('original_amount');

        $by = $q()
            ->selectRaw('UPPER(original_currency) AS currency, COUNT(*) AS n')
            ->selectRaw('COUNT(*) FILTER (WHERE value IS NULL) AS withheld')
            ->selectRaw('MIN(exchange_rate) FILTER (WHERE value IS NOT NULL) AS min_rate, MAX(exchange_rate) FILTER (WHERE value IS NOT NULL) AS max_rate')
            ->selectRaw('MIN(rate_date) FILTER (WHERE value IS NOT NULL) AS first_rate, MAX(rate_date) FILTER (WHERE value IS NOT NULL) AS last_rate')
            ->selectRaw("STRING_AGG(DISTINCT SPLIT_PART(COALESCE(rate_source, ''), ':', 1), ',') FILTER (WHERE value IS NOT NULL) AS sources")
            ->selectRaw("COUNT(*) FILTER (WHERE value IS NOT NULL AND rate_date IS NOT NULL AND rate_source NOT LIKE 'official-peg%' AND rate_source <> 'identity' AND metric_date - rate_date > {$staleDays}) AS stale")
            ->groupByRaw('UPPER(original_currency)')
            ->get()
            ->map(static fn ($r): array => [
                'currency' => $r->currency !== null ? (string) $r->currency : null,
                'rows' => (int) $r->n,
                'withheld' => (int) $r->withheld,
                'min_rate' => $r->min_rate !== null ? (float) $r->min_rate : null,
                'max_rate' => $r->max_rate !== null ? (float) $r->max_rate : null,
                'first' => $r->first_rate !== null ? Carbon::parse((string) $r->first_rate)->toDateString() : null,
                'last' => $r->last_rate !== null ? Carbon::parse((string) $r->last_rate)->toDateString() : null,
                'sources' => array_values(array_filter(explode(',', (string) $r->sources))),
                'stale' => (int) $r->stale,
            ])->sortBy(static fn (array $r): string => (string) $r['currency'])->values()->all();

        $byProvider = [];
        foreach ($q()->selectRaw('provider, UPPER(original_currency) AS currency')->distinct()->get() as $r) {
            $byProvider[(string) $r->provider][] = $r->currency !== null ? (string) $r->currency : '?';
        }
        foreach ($byProvider as $p => $codes) {
            $codes = array_values(array_unique($codes));
            sort($codes);
            $byProvider[$p] = $codes;
        }

        $reporting = ReportingCurrency::DEFAULT;

        return [
            'currency' => $reporting,
            'includes_converted' => collect($by)->contains(static fn (array $r): bool => $r['currency'] !== $reporting && $r['rows'] > $r['withheld']),
            'stale' => collect($by)->sum('stale') > 0,
            'withheld_rows' => (int) collect($by)->sum('withheld'),
            'by_currency' => $by,
            'by_provider' => $byProvider,
            'header' => self::header($reporting, $by),
        ];
    }

    /**
     * «Figures in USD · EUR→USD 1.12 (2026-11-09) manual · SAR→USD 0.266667 pegged · withheld: none».
     *
     * @param  list<array{currency: ?string, rows: int, withheld: int, min_rate: ?float, max_rate: ?float, first: ?string, last: ?string, sources: list<string>, stale: int}>  $by
     */
    public static function header(string $reporting, array $by): string
    {
        $parts = ["Figures in {$reporting} / الأرقام بعملة {$reporting}"];
        $withheld = [];
        foreach ($by as $r) {
            $code = $r['currency'] ?? '?';
            if ($r['withheld'] > 0) {
                $withheld[] = "{$code} ({$r['withheld']})";
            }
            if ($code === $reporting || $r['min_rate'] === null) {
                continue;
            }
            $rate = self::rate($r['min_rate']).($r['max_rate'] !== null && abs($r['max_rate'] - $r['min_rate']) > 1e-9 ? '–'.self::rate($r['max_rate']) : '');
            $days = $r['first'] === $r['last'] ? (string) $r['first'] : "{$r['first']}…{$r['last']}";
            $parts[] = "{$code}→{$reporting} {$rate} ({$days}) ".implode('/', $r['sources']).($r['stale'] > 0 ? ' ⚠ stale' : '');
        }
        $parts[] = $withheld === [] ? 'withheld: none / لا مبالغ محجوبة' : 'withheld (no rate) / محجوب بانتظار سعر صرف: '.implode(', ', $withheld);

        return implode(' · ', $parts);
    }

    private static function rate(float $r): string
    {
        return rtrim(rtrim(number_format($r, 6, '.', ''), '0'), '.');
    }
}
