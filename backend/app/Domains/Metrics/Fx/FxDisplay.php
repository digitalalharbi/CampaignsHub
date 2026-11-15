<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Fx;

use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;

/**
 * FX-APPLY-001 (builder G) — how a figure leaves the backend for a reader: ONE place that turns an amount in its own currency
 * into the {@see FxConversion} shape in the reader's display currency, and a list of such amounts into ONE total.
 *
 * Every conversion goes through {@see FxEngine::convert()}; nothing here reads a rate. Raw platform numbers are never
 * modified — the original travels inside every result. A figure whose currency nobody stated is not guessed: it is
 * withheld through {@see MoneyGuard::unconverted()} so the daily check counts the bypass.
 */
final class FxDisplay
{
    /** @var array<string, string> display currency per workspace, for this instance (one request) */
    private array $displayMemo = [];

    public function __construct(private readonly FxEngine $fx, private readonly FxPolicy $policy) {}

    /**
     * One instance per HTTP request, so a list of 200 rows resolves each rate and the display currency once (the converter
     * keeps a per-instance cache). A new request — and every test request — gets a fresh one, so an edited rate is read.
     */
    public static function forRequest(): self
    {
        $attrs = request()->attributes;
        $own = $attrs->get('fx.display');
        if (! $own instanceof self) {
            $own = app(self::class);
            $attrs->set('fx.display', $own);
        }

        return $own;
    }

    /** The currency a workspace reads money in (USD or SAR) — {@see FxPolicy::displayCurrencyFor()}. */
    public function currencyFor(?string $tenantId): string
    {
        return $this->displayMemo[$tenantId ?? ''] ??= $this->policy->displayCurrencyFor($tenantId);
    }

    /** The display currency of the workspace that owns a project. */
    public function currencyForProject(?string $projectId): string
    {
        $tenantId = $projectId === null ? null : Project::withoutGlobalScopes()->whereKey($projectId)->value('tenant_id');

        return $this->currencyFor($tenantId === null ? null : (string) $tenantId);
    }

    /**
     * One amount, in its own currency, as the reader's display currency. Null amount → null (nothing to show).
     *
     * @return array<string, mixed>|null the FxConversion shape (`via: fx.convert`), or the withheld guard shape
     */
    public function one(float|int|string|null $amount, ?string $from, Carbon|string|null $date, string $to, string $where = 'fx.display'): ?array
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        $code = self::code($from);
        if ($code === null) {
            return MoneyGuard::unconverted($where, (float) $amount, null, $to);
        }

        return MoneyGuard::serialize($this->fx->convert($amount, $code, $to, $date ?? Carbon::today()));
    }

    /**
     * Many amounts (each in its own currency, on its own date) as ONE total in `$to`.
     *
     * - Every part is converted on its own date by FxEngine; the total is the sum of the converted parts.
     * - Any part withheld (no rate, or no currency) withholds the TOTAL: `converted_amount: null` — never a partial sum read
     *   as the whole, never zero, never the raw numbers added across currencies.
     * - `includes_converted` is true when any part was in another currency than `$to` («يشمل عملات محوّلة»).
     *
     * @param  iterable<array{amount: float|int|string|null, currency: ?string, date?: Carbon|string|null}>  $parts
     * @return array{currency: string, converted_amount: ?float, withheld: bool, withheld_parts: int, withheld_currencies: list<string>,
     *   includes_converted: bool, currencies: list<string>, stale: bool, parts: int, rates: list<array<string, mixed>>,
     *   pending_label: ?string, rates_page: ?string, via: string}
     */
    public function total(iterable $parts, string $to): array
    {
        $to = strtoupper($to);
        $sum = 0.0;
        $n = 0;
        $withheld = [];
        $withheldParts = 0;
        $currencies = [];
        $stale = false;
        $rates = [];

        foreach ($parts as $p) {
            if (! isset($p['amount']) || $p['amount'] === '') {
                continue;
            }
            $n++;
            $code = self::code($p['currency'] ?? null);
            if ($code === null) {
                $withheldParts++;
                $withheld['?'] = true;

                continue;
            }
            $currencies[$code] = true;
            $c = $this->fx->convert($p['amount'], $code, $to, $p['date'] ?? Carbon::today());
            if ($c->withheld || $c->converted === null) {
                $withheldParts++;
                $withheld[$code] = true;

                continue;
            }
            $sum += $c->converted;
            $stale = $stale || $c->stale;
            if ($code !== $to) {
                $key = $code.'|'.$c->rateDate;
                $rates[$key] ??= ['from' => $code, 'to' => $to, 'rate' => $c->rate, 'rate_date' => $c->rateDate, 'kind' => $c->kind, 'source' => $c->source, 'recorded_by' => $c->recordedBy, 'stale' => $c->stale];
            }
        }

        $codes = array_keys($currencies);
        sort($codes);
        $missing = array_values(array_filter(array_keys($withheld), static fn (string $c): bool => $c !== '?'));
        $isWithheld = $withheldParts > 0;

        return [
            'currency' => $to,
            'converted_amount' => $isWithheld ? null : round($sum, 6),
            'withheld' => $isWithheld,
            'withheld_parts' => $withheldParts,
            'withheld_currencies' => $missing,
            'includes_converted' => array_diff($codes, [$to]) !== [],
            'currencies' => $codes,
            'stale' => $stale,
            'parts' => $n,
            'rates' => array_values($rates),
            'pending_label' => $isWithheld ? ($missing === [] ? 'بانتظار سعر صرف' : 'بانتظار سعر صرف ('.implode('، ', $missing).')') : null,
            'rates_page' => $isWithheld ? FxConversion::RATES_PAGE : null,
            'via' => 'fx.convert',
        ];
    }

    private static function code(?string $currency): ?string
    {
        if ($currency === null) {
            return null;
        }
        $c = strtoupper(trim($currency));

        return preg_match('/^[A-Z]{3}$/', $c) === 1 ? $c : null;
    }
}
