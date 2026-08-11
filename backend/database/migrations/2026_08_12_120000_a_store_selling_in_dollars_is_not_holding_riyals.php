<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COMMERCE-FX-001 — the rule FX-001 established for ad money, applied to store money.
 *
 * ## The same defect, one table over
 *
 * FX-001 closed the gap where a USD ad account's spend was summed into a SAR dashboard as though it
 * were riyals. Commerce was left with the identical hole: `commerce_orders` records the provider's
 * `currency` per row and every read — the funnel's revenue, `netRevenue()`, the best-seller table's
 * `SUM(total)`, the attribution report's store-confirmed figure — adds `total` across rows without
 * ever looking at it. A merchant selling in dollars through one shop and riyals through another had
 * both added together, and the sum was a number in no currency at all.
 *
 * It is worse here than it was for ads, because a store total is the figure a client recognises: it
 * is compared against the merchant's own dashboard, and a wrong one is disputed rather than believed.
 *
 * ## Converted once, at import — the same decision, for the same reason
 *
 * The existing amount columns become the REPORTING-currency figures, and the provider's own numbers
 * move into `original_*` beside them. That way the six read paths above are correct without one of
 * them being edited, exactly as `daily_metrics.value` made the ad screens correct by construction. A
 * seventh reader added next month is right by default; under a scheme that added parallel converted
 * columns it would be wrong by default, and wrong in the silent direction.
 *
 * `currency` therefore now names the currency the row's amounts ARE in, and `original_currency` names
 * the one the merchant charged in. Both are kept on every order, including same-currency ones, where
 * the honest record is «SAR 500 at 1.0» rather than «no currency involved».
 *
 * ## Fail-closed, and what that costs
 *
 * No rate → the converted amounts are NULL. Not 0, which reads as a sale that earned nothing; not the
 * unconverted figure, which is the defect itself. `SUM` skips NULLs, so a withheld order silently
 * shortens a total — the funnel's coverage block therefore COUNTS these orders, and a total that is
 * short says so. The provider's figures survive in `original_*`, so an order converts itself the day
 * a rate for its date exists and the import is idempotent enough to do it on the next sweep.
 *
 * ## What is NOT converted
 *
 * `commerce_products.price` is a catalogue price and `commerce_customers.total_spent` is a lifetime
 * figure the platform computed; neither is ever summed across shops. Converting a shop's shelf price
 * into riyals would misstate what the merchant actually charges. `commerce_customers` gains a
 * `currency` column instead, so a lifetime total is never a bare number with no currency attached.
 *
 * ## Existing rows
 *
 * Backfilled honestly rather than assumed correct. A row already in the project's reporting currency
 * is stamped `identity` at rate 1 — it was right and stays right. A row in any other currency is
 * WITHHELD: its stored amount was never converted, no rate for its date is known here, and leaving it
 * in place would preserve exactly the defect this migration exists to end. The next sweep restores it.
 */
return new class extends Migration
{
    /** The reporting currency of each project, resolved the way {@see ReportingCurrency} resolves it. */
    private const REPORTING = <<<'SQL'
        SELECT p.id AS project_id, UPPER(COALESCE(NULLIF(w.default_currency, ''), 'SAR')) AS reporting
        FROM projects p
        LEFT JOIN client_workspaces w ON w.id = p.client_workspace_id
    SQL;

    public function up(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->string('original_currency', 8)->nullable()->after('currency');
            $table->decimal('original_subtotal', 24, 6)->nullable()->after('original_currency');
            $table->decimal('original_shipping_total', 24, 6)->nullable()->after('original_subtotal');
            $table->decimal('original_tax_total', 24, 6)->nullable()->after('original_shipping_total');
            $table->decimal('original_discount_total', 24, 6)->nullable()->after('original_tax_total');
            $table->decimal('original_total', 24, 6)->nullable()->after('original_discount_total');
            $table->decimal('original_refunded_total', 24, 6)->nullable()->after('original_total');
            $table->decimal('exchange_rate', 24, 12)->nullable()->after('original_refunded_total');
            $table->date('rate_date')->nullable()->after('exchange_rate');
            $table->string('rate_source')->nullable()->after('rate_date');
        });

        Schema::table('commerce_order_items', function (Blueprint $table): void {
            // No currency of their own: a line is in its order's currency, at its order's rate.
            $table->decimal('original_unit_price', 24, 6)->nullable()->after('unit_price');
            $table->decimal('original_total', 24, 6)->nullable()->after('total');
        });

        Schema::table('commerce_abandoned_carts', function (Blueprint $table): void {
            $table->string('original_currency', 8)->nullable()->after('currency');
            $table->decimal('original_total', 24, 6)->nullable()->after('total');
            $table->decimal('exchange_rate', 24, 12)->nullable()->after('original_total');
            $table->date('rate_date')->nullable()->after('exchange_rate');
            $table->string('rate_source')->nullable()->after('rate_date');
        });

        Schema::table('commerce_customers', function (Blueprint $table): void {
            // Labelled, never converted — see the class docblock.
            $table->string('currency', 8)->nullable()->after('country');
        });

        $this->backfillOrders();
        $this->backfillItems();
        $this->backfillCarts();

        DB::statement(<<<'SQL'
            UPDATE commerce_customers AS c
            SET currency = UPPER(a.currency)
            FROM external_accounts AS a
            WHERE a.id = c.external_account_id AND a.currency IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // The provider's own figures go back into the amount columns. A withheld row recovers its real
        // number rather than the zero it never had.
        DB::statement(<<<'SQL'
            UPDATE commerce_orders SET
                currency = COALESCE(original_currency, currency),
                subtotal = COALESCE(original_subtotal, subtotal),
                shipping_total = COALESCE(original_shipping_total, shipping_total),
                tax_total = COALESCE(original_tax_total, tax_total),
                discount_total = COALESCE(original_discount_total, discount_total),
                total = COALESCE(original_total, total),
                refunded_total = COALESCE(original_refunded_total, refunded_total)
        SQL);

        DB::statement(<<<'SQL'
            UPDATE commerce_order_items SET
                unit_price = COALESCE(original_unit_price, unit_price),
                total = COALESCE(original_total, total)
        SQL);

        DB::statement(<<<'SQL'
            UPDATE commerce_abandoned_carts SET
                currency = COALESCE(original_currency, currency),
                total = COALESCE(original_total, total)
        SQL);

        Schema::table('commerce_orders', fn (Blueprint $t) => $t->dropColumn([
            'original_currency', 'original_subtotal', 'original_shipping_total', 'original_tax_total',
            'original_discount_total', 'original_total', 'original_refunded_total',
            'exchange_rate', 'rate_date', 'rate_source',
        ]));

        Schema::table('commerce_order_items', fn (Blueprint $t) => $t->dropColumn(['original_unit_price', 'original_total']));

        Schema::table('commerce_abandoned_carts', fn (Blueprint $t) => $t->dropColumn([
            'original_currency', 'original_total', 'exchange_rate', 'rate_date', 'rate_source',
        ]));

        Schema::table('commerce_customers', fn (Blueprint $t) => $t->dropColumn('currency'));
    }

    private function backfillOrders(): void
    {
        DB::statement(<<<SQL
            UPDATE commerce_orders AS o SET
                original_currency = COALESCE(NULLIF(UPPER(o.currency), ''), rc.reporting),
                original_subtotal = o.subtotal,
                original_shipping_total = o.shipping_total,
                original_tax_total = o.tax_total,
                original_discount_total = o.discount_total,
                original_total = o.total,
                original_refunded_total = o.refunded_total,
                currency = rc.reporting,
                exchange_rate = CASE WHEN COALESCE(NULLIF(UPPER(o.currency), ''), rc.reporting) = rc.reporting THEN 1 END,
                rate_date = CASE WHEN COALESCE(NULLIF(UPPER(o.currency), ''), rc.reporting) = rc.reporting
                    THEN COALESCE(o.placed_at::date, o.created_at::date) END,
                rate_source = CASE WHEN COALESCE(NULLIF(UPPER(o.currency), ''), rc.reporting) = rc.reporting THEN 'identity' END,
                subtotal = CASE WHEN COALESCE(NULLIF(UPPER(o.currency), ''), rc.reporting) = rc.reporting THEN o.subtotal END,
                shipping_total = CASE WHEN COALESCE(NULLIF(UPPER(o.currency), ''), rc.reporting) = rc.reporting THEN o.shipping_total END,
                tax_total = CASE WHEN COALESCE(NULLIF(UPPER(o.currency), ''), rc.reporting) = rc.reporting THEN o.tax_total END,
                discount_total = CASE WHEN COALESCE(NULLIF(UPPER(o.currency), ''), rc.reporting) = rc.reporting THEN o.discount_total END,
                total = CASE WHEN COALESCE(NULLIF(UPPER(o.currency), ''), rc.reporting) = rc.reporting THEN o.total END,
                refunded_total = CASE WHEN COALESCE(NULLIF(UPPER(o.currency), ''), rc.reporting) = rc.reporting THEN o.refunded_total END
            FROM ({$this->reporting()}) AS rc
            WHERE rc.project_id = o.project_id
        SQL);
    }

    /** Run after the orders, so a line follows its own order's verdict rather than guessing again. */
    private function backfillItems(): void
    {
        DB::statement(<<<'SQL'
            UPDATE commerce_order_items AS i SET
                original_unit_price = i.unit_price,
                original_total = i.total,
                unit_price = CASE WHEN o.exchange_rate IS NOT NULL THEN i.unit_price END,
                total = CASE WHEN o.exchange_rate IS NOT NULL THEN i.total END
            FROM commerce_orders AS o
            WHERE o.id = i.commerce_order_id
        SQL);
    }

    private function backfillCarts(): void
    {
        DB::statement(<<<SQL
            UPDATE commerce_abandoned_carts AS c SET
                original_currency = COALESCE(NULLIF(UPPER(c.currency), ''), rc.reporting),
                original_total = c.total,
                currency = rc.reporting,
                exchange_rate = CASE WHEN COALESCE(NULLIF(UPPER(c.currency), ''), rc.reporting) = rc.reporting THEN 1 END,
                rate_date = CASE WHEN COALESCE(NULLIF(UPPER(c.currency), ''), rc.reporting) = rc.reporting
                    THEN COALESCE(c.abandoned_at::date, c.created_at::date) END,
                rate_source = CASE WHEN COALESCE(NULLIF(UPPER(c.currency), ''), rc.reporting) = rc.reporting THEN 'identity' END,
                total = CASE WHEN COALESCE(NULLIF(UPPER(c.currency), ''), rc.reporting) = rc.reporting THEN c.total END
            FROM ({$this->reporting()}) AS rc
            WHERE rc.project_id = c.project_id
        SQL);
    }

    private function reporting(): string
    {
        return self::REPORTING;
    }
};
