<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Contracts;

use App\Domains\Metrics\Rates\CurrencyRateFeed;
use Illuminate\Support\Carbon;

/**
 * FX-FEED-001 — what a published rate source has to be able to answer.
 *
 * Deliberately narrow. A source is asked for specific PAIRS on a specific DATE and answers with the
 * rate and the name it publishes under; it does not decide which currencies matter, when to run, or
 * what to do with a gap. Those belong to {@see CurrencyRateFeed}, so a
 * second source added later cannot quietly bring a second policy with it.
 *
 * No implementation ships in this repository. Choosing a publisher is a commercial decision — see
 * `config/fx.php` — and a default here would make it on the operator's behalf.
 */
interface CurrencyRateSource
{
    /** Stable key stored on every rate this source produces, so a figure can be traced back to it. */
    public function key(): string;

    /** Human name for the /admin surface. */
    public function label(): string;

    /**
     * Whether this source has everything it needs to be called at all.
     *
     * Separate from being *selected*: a source can be configured as the driver and still be missing
     * its API key, and «no feed chosen» and «feed chosen but unusable» send an operator to two
     * different places.
     */
    public function isConfigured(): bool;

    /**
     * The rates for these pairs as of this date.
     *
     * A pair the source cannot answer is OMITTED, never returned as zero or as a stale value silently
     * re-dated — the caller records what came back and leaves the rest missing, which is what makes
     * the withheld figures downstream honest.
     *
     * @param  list<array{base: string, quote: string}>  $pairs
     * @return list<array{base: string, quote: string, rate: float, rate_date: string}>
     */
    public function fetch(array $pairs, Carbon $date): array;
}
