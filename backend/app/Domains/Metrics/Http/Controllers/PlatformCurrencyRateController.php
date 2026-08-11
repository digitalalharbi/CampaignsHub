<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Rates\CurrencyRateFeed;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * FX-FEED-001 — the platform operator's view of where rates come from, and the way to enter one.
 *
 * ## Why this is on the platform console and not in a tenant's settings
 *
 * A rate is not one customer's opinion. The same USD→SAR quote converts every tenant's spend on the
 * same day, and a tenant able to set it could move their own reported ROAS by editing a number. It
 * belongs to whoever runs the deployment, next to the other supply-side settings.
 *
 * ## Entering a rate by hand is a first-class path, not a fallback
 *
 * An operator IS a legitimate source — a treasury desk publishes rates on paper long before anybody
 * buys an API. What matters is that the figure is ATTRIBUTABLE, so a hand-entered rate is stamped
 * `manual:<email>` and audited. A conversion made at it can then be traced to a person and a moment,
 * which is the whole point of storing `rate_source` rather than just a number.
 */
final class PlatformCurrencyRateController extends Controller
{
    public function __construct(private readonly CurrencyRateFeed $feed) {}

    /** GET /admin/fx/rates — the feed's state, what it is costing, and the rates on file. */
    public function index(): JsonResponse
    {
        $rates = CurrencyRate::query()
            ->orderByDesc('rate_date')
            ->orderBy('base_currency')
            ->limit(100)
            ->get(['base_currency', 'quote_currency', 'rate', 'rate_date', 'source'])
            ->map(static fn (CurrencyRate $r): array => [
                'base' => $r->base_currency,
                'quote' => $r->quote_currency,
                'rate' => (float) $r->rate,
                'rate_date' => Carbon::parse((string) $r->rate_date)->toDateString(),
                'source' => $r->source,
            ])
            ->all();

        return ApiResponse::success([
            'feed' => $this->feed->status(),
            /*
             * The conversions this deployment has already failed to make.
             *
             * Shown first because it is the only figure that makes the decision concrete: «USD→SAR,
             * 412 figures withheld since June» is an argument for buying a feed. «No feed configured»
             * is a checkbox nobody actions.
             */
            'unmet_pairs' => $this->feed->unmetPairs(),
            'rates' => $rates,
        ], 'Currency rates.');
    }

    /** POST /admin/fx/rates — record one rate, by hand, attributably. */
    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'base' => ['required', 'string', 'size:3', 'alpha'],
            'quote' => ['required', 'string', 'size:3', 'alpha'],
            // Strictly positive: a zero rate converts a real amount into «earned nothing», which is
            // the exact figure every fail-closed decision upstream exists to avoid printing.
            'rate' => ['required', 'numeric', 'gt:0'],
            // A rate cannot be published for a day that has not happened. Allowing it would let a
            // typo silently become the answer for every future conversion, since the lookup takes
            // the nearest quote on or before the metric's date.
            'rate_date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $base = strtoupper($data['base']);
        $quote = strtoupper($data['quote']);

        if ($base === $quote) {
            return ApiResponse::error(
                'A currency is already at par with itself; no rate is needed.',
                errors: ['quote' => ['Same as base.']],
                status: 422,
            );
        }

        $rate = $this->feed->record(
            base: $base,
            quote: $quote,
            rate: (float) $data['rate'],
            date: Carbon::parse($data['rate_date']),
            source: 'manual:'.$request->user()->email,
        );

        $audit->log(
            action: 'fx.rate.recorded',
            entityType: CurrencyRate::class,
            entityId: (string) $rate->getKey(),
            after: [
                'base' => $base, 'quote' => $quote,
                'rate' => (float) $data['rate'], 'rate_date' => $rate->rate_date,
            ],
        );

        return ApiResponse::success([
            'base' => $base,
            'quote' => $quote,
            'rate' => (float) $rate->rate,
            'rate_date' => Carbon::parse((string) $rate->rate_date)->toDateString(),
            'source' => $rate->source,
        ], 'Rate recorded.', status: 201);
    }
}
