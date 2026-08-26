<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Console;

use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\ReportingCurrency;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MONEY-USD-002 — re-normalise historical money into the reporting currency, from the originals.
 *
 * `MONEY-USD-001` changed what NEW rows normalise into. It deliberately left history alone: a
 * constant must not silently rewrite stored figures. This is the deliberate operation that does.
 *
 * ## It converts from the ORIGINAL, never from `value`
 *
 * The single rule that makes this safe to re-run. `value` is already a converted figure; converting
 * it again would apply a second rate to a number that has had one — and the result looks entirely
 * plausible, which is what makes double conversion so expensive to discover. Every row is recomputed
 * from `original_amount` + `original_currency`, which FX-001 preserves on every monetary row
 * including same-currency ones. Run it once or five times and the answer is identical.
 *
 * ## Rows already in the reporting currency are not touched
 *
 * Not «recomputed to the same value» — skipped, so an already-correct row cannot be disturbed by a
 * rate that has since moved, and the report says how many were left alone.
 *
 * ## The rate is the one for the metric's own date
 *
 * `CurrencyConverter::resolve` looks up the rate on or before `metric_date`. Using today's rate for
 * a figure from March would make a historical total drift every time this ran, and two runs a week
 * apart would disagree about the past.
 *
 * ## No rate, no figure
 *
 * FX-001's rule, unchanged: `value` stays null, the original survives, and the row converts itself
 * the day a rate exists. A row that cannot be converted is COUNTED and reported, never approximated
 * and never silently dropped — a total that quietly omits it is the defect this whole line of work
 * exists to prevent.
 */
final class RenormaliseReportingCurrency extends Command
{
    protected $signature = 'metrics:renormalise-currency
        {--project= : limit to one project}
        {--apply : write the changes; without this the command only reports what it would do}
        {--chunk=500 : rows per batch}';

    protected $description = 'Re-normalise stored money into each project\'s reporting currency, from the preserved originals.';

    public function handle(CurrencyConverter $rates, ReportingCurrency $reporting): int
    {
        $apply = (bool) $this->option('apply');
        $target = ReportingCurrency::DEFAULT;

        $examined = 0;
        $already = 0;
        $converted = 0;
        $withheld = 0;
        $noOriginal = 0;
        /** @var array<string,int> $missingPairs */
        $missingPairs = [];

        DB::table('daily_metrics')
            ->when($this->option('project'), fn ($q, $p) => $q->where('project_id', $p))
            ->whereNotNull('original_currency')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($rows) use (
                $rates, $target, $apply, &$examined, &$already, &$converted, &$withheld, &$noOriginal, &$missingPairs
            ) {
                foreach ($rows as $row) {
                    $examined++;

                    if (strtoupper((string) $row->project_currency) === $target) {
                        // Already in the reporting currency. Left exactly as it is.
                        $already++;

                        continue;
                    }

                    if ($row->original_amount === null) {
                        // Nothing to recompute from. Recomputing from `value` would double-convert.
                        $noOriginal++;

                        continue;
                    }

                    $from = strtoupper((string) $row->original_currency);
                    $resolved = $rates->resolve($from, $target, Carbon::parse((string) $row->metric_date));

                    if ($resolved === null) {
                        $withheld++;
                        $missingPairs["{$from}->{$target}"] = ($missingPairs["{$from}->{$target}"] ?? 0) + 1;

                        if ($apply) {
                            DB::table('daily_metrics')->where('id', $row->id)->update([
                                'value' => null,
                                'converted_amount' => null,
                                'exchange_rate' => null,
                                'project_currency' => $target,
                            ]);
                        }

                        continue;
                    }

                    $amount = (float) $row->original_amount * $resolved['rate'];
                    $converted++;

                    if ($apply) {
                        DB::table('daily_metrics')->where('id', $row->id)->update([
                            'value' => $amount,
                            'converted_amount' => $amount,
                            'exchange_rate' => $resolved['rate'],
                            'project_currency' => $target,
                        ]);
                    }
                }
            });

        $this->line(($apply ? 'APPLIED' : 'DRY RUN — nothing written').", target {$target}");
        $this->table(
            ['examined', 'already in target', 'converted', 'withheld (no rate)', 'no original to use'],
            [[$examined, $already, $converted, $withheld, $noOriginal]],
        );

        foreach ($missingPairs as $pair => $count) {
            $this->warn("no rate for {$pair} on {$count} row(s) — their money stays withheld until one exists");
        }

        return self::SUCCESS;
    }
}
