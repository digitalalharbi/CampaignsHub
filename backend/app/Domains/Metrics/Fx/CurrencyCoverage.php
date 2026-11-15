<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Fx;

use App\Domains\Metrics\Services\CurrencyConverter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FX-ENGINE-001 — the currencies ACTUALLY used by connected ad accounts, and whether each one can be converted today.
 *
 * Read from the accounts themselves, never from a configured list: a currency nobody listed shows up the moment an
 * account is linked in it. Per currency: how many accounts use it, the last recorded rate to USD (with its date, kind
 * and who recorded it), a status — `valid` ✓ · `stale` ⚠ (older than 7 days) · `missing` ✗ — and how many stored money
 * figures are withheld because of it. Read-only: the ad accounts are never touched.
 */
final class CurrencyCoverage
{
    public function __construct(private readonly FxEngine $fx) {}

    /**
     * @return list<array{
     *   currency: string, accounts: int, providers: list<string>, status: string,
     *   rate: ?float, rate_date: ?string, kind: ?string, source: ?string, recorded_by: ?string, withheld: int
     * }>
     */
    public function rows(?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        $used = DB::table('external_accounts')
            ->whereNotNull('currency')->where('currency', '<>', '')
            ->where('account_type', 'ad_account')
            ->selectRaw('UPPER(currency) AS currency, COUNT(*) AS accounts, STRING_AGG(DISTINCT provider, \',\') AS providers')
            ->groupByRaw('UPPER(currency)')
            ->get();

        $withheld = $this->withheldByCurrency();

        $rows = [];
        foreach ($used as $u) {
            $currency = (string) $u->currency;
            $c = $this->fx->convert(1, $currency, CurrencyConverter::BASE, $today);
            $rows[] = [
                'currency' => $currency,
                'accounts' => (int) $u->accounts,
                'providers' => array_values(array_filter(explode(',', (string) $u->providers))),
                'status' => match (true) {
                    $currency === CurrencyConverter::BASE => 'base',
                    $c->withheld => 'missing',
                    $c->stale => 'stale',
                    default => 'valid',
                },
                'rate' => $c->rate,
                'rate_date' => $c->rateDate,
                'kind' => $c->kind,
                'source' => $c->source,
                'recorded_by' => $c->recordedBy,
                'withheld' => $withheld[$currency] ?? 0,
            ];
        }

        // Withheld figures in a currency no live account uses any more still cost a number: list them too.
        foreach ($withheld as $currency => $count) {
            if ($count > 0 && ! in_array($currency, array_column($rows, 'currency'), true)) {
                $c = $this->fx->convert(1, $currency, CurrencyConverter::BASE, $today);
                $rows[] = ['currency' => $currency, 'accounts' => 0, 'providers' => [], 'status' => $c->withheld ? 'missing' : ($c->stale ? 'stale' : 'valid'),
                    'rate' => $c->rate, 'rate_date' => $c->rateDate, 'kind' => $c->kind, 'source' => $c->source, 'recorded_by' => $c->recordedBy, 'withheld' => $count];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [self::rank($a['status']), -$a['withheld'], -$a['accounts']] <=> [self::rank($b['status']), -$b['withheld'], -$b['accounts']]);

        return $rows;
    }

    /** @return array<string, int> currency => stored money figures withheld (ads, entity cards, store orders) */
    public function withheldByCurrency(): array
    {
        $out = [];
        $add = static function (iterable $rows) use (&$out): void {
            foreach ($rows as $r) {
                $k = strtoupper((string) $r->currency);
                $out[$k] = ($out[$k] ?? 0) + (int) $r->n;
            }
        };

        $add(DB::table('daily_metrics')->whereNull('value')->whereNotNull('original_amount')->whereNotNull('original_currency')
            ->selectRaw('original_currency AS currency, COUNT(*) AS n')->groupBy('original_currency')->get());

        if (Schema::hasTable('entity_daily_metrics')) {
            $add(DB::table('entity_daily_metrics')->whereNotNull('original_currency')
                ->where(static fn ($q) => $q->where(static fn ($s) => $s->whereNull('spend')->whereNotNull('spend_original'))
                    ->orWhere(static fn ($s) => $s->whereNull('revenue')->whereNotNull('revenue_original')))
                ->selectRaw('original_currency AS currency, COUNT(*) AS n')->groupBy('original_currency')->get());
        }

        $add(DB::table('commerce_orders')->whereNull('total')->whereNotNull('original_total')->whereNotNull('original_currency')
            ->selectRaw('original_currency AS currency, COUNT(*) AS n')->groupBy('original_currency')->get());

        return $out;
    }

    private static function rank(string $status): int
    {
        return ['missing' => 0, 'stale' => 1, 'valid' => 2, 'base' => 3][$status] ?? 4;
    }
}
