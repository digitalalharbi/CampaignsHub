<?php

declare(strict_types=1);

use App\Domains\Campaigns\Actions\BackfillCreativeMoneyProvenance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CREATIVE-MONEY-TRUTH-001 — `creative_daily_metrics` had no currency, so its money had no meaning.
 *
 * `daily_metrics` has carried `original_amount` / `original_currency` since FX-001, and its `value`
 * is NULL when no rate can be vouched for. `creative_daily_metrics` was created before that rule and
 * never caught up: `spend` and `revenue` were `decimal DEFAULT 0` with no currency column anywhere.
 *
 * Snapchat reports in the ad account's currency. Production's account is USD and the project reports
 * in SAR, so every stored creative figure was a USD number that the content library rendered under a
 * hard-coded «SAR» — 4,128.93 shown as «4,129 SAR», understating spend by roughly 3.75× and reading
 * as measured fact.
 *
 * ## What happens to the rows already stored
 *
 * Their amounts are NOT discarded — they move to `*_original`, which is what they always were: an
 * unconverted figure in the provider's currency. `spend` and `revenue` become NULL, so every surface
 * says «conversion unavailable» instead of printing the number with the wrong label.
 *
 * `original_currency` is filled ONLY where it can be known: the project must bind exactly one active
 * account of the creative's provider. Where a project binds several, the row's currency is genuinely
 * ambiguous and stays NULL rather than being guessed — an ambiguous currency renders as unavailable,
 * which is true, while a guessed one would be the same class of defect this migration exists to fix.
 *
 * Either way the next sync rewrites these rows with a currency read from the account that answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_daily_metrics', function (Blueprint $table): void {
            // Nullable, because «no rate exists» is an answer and 0 is not it.
            $table->decimal('spend', 24, 6)->nullable()->default(null)->change();
            $table->decimal('revenue', 24, 6)->nullable()->default(null)->change();

            $table->decimal('spend_original', 24, 6)->nullable()->after('spend');
            $table->decimal('revenue_original', 24, 6)->nullable()->after('revenue');
            // One currency per row: a day's figures all come from one account's one response.
            $table->string('original_currency', 3)->nullable()->after('revenue_original');
            $table->string('project_currency', 3)->nullable()->after('original_currency');
        });

        // The data half lives in an action so its idempotence can be TESTED — a migration body
        // cannot be re-run in a test, and «running it twice changes nothing» is a claim about real
        // stored money.
        app(BackfillCreativeMoneyProvenance::class)->execute();
    }

    public function down(): void
    {
        // Put the amounts back where they were before dropping the columns that explain them,
        // so a rollback loses the currency rather than the figures.
        DB::statement(
            'UPDATE creative_daily_metrics
                SET spend = COALESCE(spend, spend_original, 0),
                    revenue = COALESCE(revenue, revenue_original, 0)'
        );

        Schema::table('creative_daily_metrics', function (Blueprint $table): void {
            $table->dropColumn(['spend_original', 'revenue_original', 'original_currency', 'project_currency']);
            $table->decimal('spend', 24, 6)->default(0)->change();
            $table->decimal('revenue', 24, 6)->default(0)->change();
        });
    }
};
