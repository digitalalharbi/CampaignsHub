<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Fx;

use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingSpendRefresher;
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * FX-ENGINE-001 — «إعادة حساب الأرقام المحوّلة» for a chosen period.
 *
 * Re-converts every stored converted figure FROM ITS PRESERVED ORIGINAL at the rate now in force for its own date:
 * ad rows (`daily_metrics`), the entity cards (`entity_daily_metrics`) and every budget's spend (`FundingSpendRefresher`).
 * RAW platform numbers (`original_amount`, `*_original`, `original_currency`) are NEVER written — only the converted
 * columns. A figure with no rate becomes withheld (null), never zero and never the raw number.
 *
 * Dry run by default: the summary of differences is returned and nothing is written. The period is capped so a click
 * cannot become heavy server work; the writes are chunked.
 *
 * PROJECT-LIFECYCLE-001 §7 (N4) — `onlyWithheld` is the RELEASE {@see RateLedger} runs after every recorded rate: it fills the
 * figures that were withheld for want of a rate (value NULL) and are convertible now, and touches nothing else. Re-pricing a
 * figure already converted stays the admin's explicit «إعادة حساب» (FX-ENGINE-001); leaving a convertible day NULL is what
 * made the campaigns page and the clients list miss spend the budgets page counted.
 */
final class FxRecompute
{
    public const MAX_DAYS = 93;

    private const EPSILON = 0.000001;

    public function __construct(private readonly CurrencyConverter $rates) {}

    /**
     * @return array{
     *   from: string, to: string, applied: bool, examined: int, changed: int, released: int, withheld: int, unchanged: int,
     *   by_currency: list<array{currency: string, rows: int, changed: int, before: float, after: float}>,
     *   entity: array{examined: int, changed: int}, budgets_refreshed: int
     * }
     */
    public function run(Carbon $from, Carbon $to, bool $apply, bool $onlyWithheld = false): array
    {
        if ($to->lt($from)) {
            throw new InvalidArgumentException('The period ends before it starts.');
        }
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('Choose a period of at most '.self::MAX_DAYS.' days.');
        }

        $sum = ['examined' => 0, 'changed' => 0, 'released' => 0, 'withheld' => 0, 'unchanged' => 0];
        $byCurrency = [];
        $projects = [];

        DB::table('daily_metrics')
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('original_amount')->whereNotNull('original_currency')
            ->when($onlyWithheld, static fn ($q) => $q->whereNull('value'))
            ->orderBy('id')
            ->select(['id', 'project_id', 'metric_date', 'value', 'original_amount', 'original_currency', 'project_currency'])
            ->chunkById(500, function ($rows) use ($apply, &$sum, &$byCurrency, &$projects): void {
                foreach ($rows as $row) {
                    $sum['examined']++;
                    $currency = strtoupper((string) $row->original_currency);
                    $target = strtoupper((string) ($row->project_currency ?: ReportingCurrency::DEFAULT));
                    $resolved = $this->rates->resolve($currency, $target, Carbon::parse((string) $row->metric_date));
                    $old = $row->value === null ? null : (float) $row->value;
                    $new = $resolved === null ? null : (float) $row->original_amount * $resolved['rate'];

                    $bucket = &$byCurrency[$currency];
                    $bucket ??= ['currency' => $currency, 'rows' => 0, 'changed' => 0, 'before' => 0.0, 'after' => 0.0];
                    $bucket['rows']++;
                    $bucket['before'] += $old ?? 0.0;
                    $bucket['after'] += $new ?? 0.0;

                    $same = ($old === null && $new === null) || ($old !== null && $new !== null && abs($old - $new) < self::EPSILON);
                    if ($same) {
                        $sum['unchanged']++;
                        unset($bucket);

                        continue;
                    }

                    $bucket['changed']++;
                    unset($bucket);
                    $sum['changed']++;
                    if ($old === null) {
                        $sum['released']++;
                    } elseif ($new === null) {
                        $sum['withheld']++;
                    }
                    $projects[(string) $row->project_id] = true;

                    if ($apply) {
                        // Only the converted columns. `original_amount` / `original_currency` are the platform's own and stay.
                        DB::table('daily_metrics')->where('id', $row->id)->update([
                            'value' => $new,
                            'converted_amount' => $new,
                            'exchange_rate' => $resolved['rate'] ?? null,
                            'rate_date' => $resolved['rate_date'] ?? null,
                            'rate_source' => $resolved['source'] ?? null,
                            'project_currency' => $target,
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

        $entity = $this->entities($from, $to, $apply, $onlyWithheld);

        $budgets = 0;
        if ($apply) {
            $refresher = app(FundingSpendRefresher::class);
            $context = app(TenantContext::class);
            $previous = $context->tenantId();
            ProjectFunding::withoutGlobalScopes()
                ->when($projects !== [], static fn ($q) => $q->whereIn('project_id', array_keys($projects)))
                ->when($projects === [] && $entity['changed'] === 0, static fn ($q) => $q->whereRaw('1 = 0'))
                ->get()
                ->each(function (ProjectFunding $f) use ($refresher, $context, &$budgets): void {
                    // Each budget is refreshed inside its own workspace, as the workspace's own refresh would run it.
                    $context->setTenantId((string) $f->tenant_id);
                    $refresher->refresh($f);
                    $budgets++;
                });
            $context->setTenantId($previous);
        }

        $rows = array_values(array_map(static fn (array $b): array => ['before' => round($b['before'], 2), 'after' => round($b['after'], 2)] + $b, $byCurrency));
        usort($rows, static fn (array $a, array $b): int => $b['changed'] <=> $a['changed']);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'applied' => $apply,
            ...$sum,
            'by_currency' => $rows,
            'entity' => $entity,
            'budgets_refreshed' => $budgets,
        ];
    }

    /** @return array{examined: int, changed: int} */
    private function entities(Carbon $from, Carbon $to, bool $apply, bool $onlyWithheld = false): array
    {
        if (! Schema::hasTable('entity_daily_metrics')) {
            return ['examined' => 0, 'changed' => 0];
        }

        $examined = 0;
        $changed = 0;

        DB::table('entity_daily_metrics')
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('original_currency')
            ->where(static fn ($q) => $q->whereNotNull('spend_original')->orWhereNotNull('revenue_original'))
            ->orderBy('id')
            ->select(['id', 'metric_date', 'spend', 'spend_original', 'revenue', 'revenue_original', 'original_currency', 'project_currency'])
            ->chunkById(500, function ($rows) use ($apply, &$examined, &$changed): void {
                foreach ($rows as $row) {
                    $examined++;
                    $target = strtoupper((string) ($row->project_currency ?: ReportingCurrency::DEFAULT));
                    $r = $this->rates->resolve((string) $row->original_currency, $target, Carbon::parse((string) $row->metric_date));
                    $update = [];
                    foreach (['spend', 'revenue'] as $key) {
                        $original = $row->{$key.'_original'};
                        if ($original === null) {
                            continue;
                        }
                        $new = $r === null ? null : (float) $original * $r['rate'];
                        $old = $row->{$key} === null ? null : (float) $row->{$key};
                        if ($onlyWithheld && $old !== null) {
                            continue; // a release fills what was withheld; it never re-prices a converted figure
                        }
                        if (! (($old === null && $new === null) || ($old !== null && $new !== null && abs($old - $new) < self::EPSILON))) {
                            $update[$key] = $new;
                        }
                    }
                    if ($update === []) {
                        continue;
                    }
                    $changed++;
                    if ($apply) {
                        DB::table('entity_daily_metrics')->where('id', $row->id)->update($update + ['project_currency' => $target]);
                    }
                }
            });

        return ['examined' => $examined, 'changed' => $changed];
    }
}
