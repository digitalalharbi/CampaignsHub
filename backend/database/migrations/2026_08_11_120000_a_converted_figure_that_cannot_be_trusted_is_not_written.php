<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FX-001 — the reporting currency becomes real, and a conversion nobody can vouch for is withheld.
 *
 * ## What was actually wrong
 *
 * `daily_metrics` has carried `original_currency`, `project_currency`, `original_amount`,
 * `converted_amount` and `exchange_rate` since C3.1, and NOTHING populated them:
 * `AccountMetricsSyncer::ingest()` built every metric from `value` alone. So a USD ad account's spend
 * was written as a bare number and summed into a SAR dashboard as though it were riyals — a 3.75×
 * understatement presented with no hint that anything had been assumed.
 *
 * The FX machinery to fix it also already existed — `currency_rates`, `CurrencyRate`,
 * `CurrencyConverter` — and had exactly one caller: a test.
 *
 * ## Why `value` becomes nullable
 *
 * This is the fail-closed half, and it is the reason this migration exists at all rather than the
 * change being pure code. When no rate can be found for a date, there are three things the pipeline
 * could write and two of them are lies:
 *
 *  - `0` — reads as «this campaign spent nothing», which is the most damaging number in the product.
 *  - the unconverted figure — a USD amount silently added to a SAR total, i.e. the original defect.
 *  - `NULL` — «there is a monetary fact here and no trustworthy figure for it».
 *
 * NULL is the only honest one, and it also behaves correctly in aggregate: PostgreSQL's `SUM` skips
 * NULLs rather than poisoning the total. The original amount and currency are still written beside
 * it, so nothing is lost and the row can be converted later once a rate exists.
 *
 * A withheld figure must be VISIBLE, not merely absent — `MetricsAggregator::currencyCoverage()`
 * counts these rows so a screen can say the total is incomplete instead of quietly under-reporting.
 *
 * ## `rate_date` and `rate_source`
 *
 * `exchange_rate` alone cannot be audited. The rate used is looked up as of the metric date
 * (nearest on-or-before), so the date that rate actually CAME FROM is not derivable from the row —
 * a figure converted at Thursday's rate for a Saturday with no quote looks identical to one
 * converted at Saturday's. Recording both the date and where the rate came from makes a historical
 * conversion checkable by somebody who was not there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_metrics', function (Blueprint $table): void {
            $table->date('rate_date')->nullable()->after('exchange_rate');
            $table->string('rate_source')->nullable()->after('rate_date');
        });

        // `value` carries a default of 0, and a nullable column that still defaults to 0 would put
        // the very figure this change exists to prevent back into any row written without one.
        DB::statement('ALTER TABLE daily_metrics ALTER COLUMN value DROP NOT NULL');
        DB::statement('ALTER TABLE daily_metrics ALTER COLUMN value DROP DEFAULT');
    }

    public function down(): void
    {
        // A NULL cannot become NOT NULL again on its own. Rows whose conversion was withheld are set
        // to their ORIGINAL amount rather than to zero: reverting the schema should not invent a
        // «spent nothing» that never happened, and the original figure is at least a true number in
        // a stated currency.
        DB::statement('UPDATE daily_metrics SET value = COALESCE(original_amount, 0) WHERE value IS NULL');
        DB::statement('ALTER TABLE daily_metrics ALTER COLUMN value SET DEFAULT 0');
        DB::statement('ALTER TABLE daily_metrics ALTER COLUMN value SET NOT NULL');

        Schema::table('daily_metrics', fn (Blueprint $table) => $table->dropColumn(['rate_date', 'rate_source']));
    }
};
