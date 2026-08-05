<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMMERCE-001 — what a connected store actually holds: products, customers, orders and the carts
 * that never became orders.
 *
 * ## The store itself is not a new table
 *
 * A Salla or Zid store is an `external_accounts` row with `account_type = 'store'`, behind the same
 * `provider_connections` and the same encrypted credential as an ad account. It is the same fact — an
 * external thing a merchant authorised us to read — and giving it a parallel table would mean a second
 * connection lifecycle, a second revoke path and a second place for a tenant id to be forgotten.
 *
 * ## Every row carries its attribution, because that is the whole point
 *
 * An order that cannot be traced to a click is a number on a dashboard. `utm_*` plus a platform click
 * id plus the landing URL is what lets an order sit under the campaign that produced it — and the
 * columns are all nullable, because most stores pass some of it and no store passes all of it. A null
 * here reads as «مصدر غير معروف», which is true; a default would read as organic, which is a claim.
 *
 * ## Money is `decimal(24,6)`, matching `daily_metrics`
 *
 * Revenue from a store and revenue from an ad platform end up on the same chart. Two precisions is how
 * a total and its own breakdown disagree by fractions of a riyal for ever.
 *
 * ## Cancellations and refunds live on the order
 *
 * Both providers state them as an order-level fact — a status plus an amount — not as a separate
 * document. A `commerce_refunds` table would be modelling a thing neither API returns, and every row
 * in it would be our own invention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('external_account_id')->index();   // the store
            $table->string('provider');                     // salla | zid
            $table->string('external_id');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('status')->default('active');
            $table->decimal('price', 24, 6)->nullable();
            $table->string('currency', 8)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('category')->nullable();
            $table->string('url', 2000)->nullable();
            $table->string('image_url', 2000)->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('external_account_id')->references('id')->on('external_accounts')->cascadeOnDelete();
            $table->unique(['external_account_id', 'external_id']);
        });

        Schema::create('commerce_customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('external_account_id')->index();
            $table->string('provider');
            $table->string('external_id');
            $table->string('name')->nullable();
            /*
             * Contact details are the merchant's customers' personal data, held only because a client
             * report says «عميل عائد» and «أفضل العملاء». They are tenant-scoped like everything else
             * and never leave the tenant that synced them.
             */
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->integer('orders_count')->nullable();
            $table->decimal('total_spent', 24, 6)->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('external_account_id')->references('id')->on('external_accounts')->cascadeOnDelete();
            $table->unique(['external_account_id', 'external_id']);
        });

        Schema::create('commerce_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('external_account_id')->index();
            $table->uuid('commerce_customer_id')->nullable()->index();
            $table->string('provider');
            $table->string('external_id');
            $table->string('reference')->nullable();          // the number the merchant quotes
            $table->string('status')->default('unknown');      // the provider's own fulfilment status
            $table->string('payment_status')->nullable();
            $table->timestampTz('placed_at')->nullable()->index();
            $table->string('currency', 8)->nullable();
            $table->decimal('subtotal', 24, 6)->nullable();
            $table->decimal('shipping_total', 24, 6)->nullable();
            $table->decimal('tax_total', 24, 6)->nullable();
            $table->decimal('discount_total', 24, 6)->nullable();
            $table->decimal('total', 24, 6)->nullable();
            // Cancellation and refund, as the providers state them: a moment and an amount.
            $table->decimal('refunded_total', 24, 6)->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            // Attribution — nullable throughout, and null means unknown rather than organic.
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable()->index();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('click_id')->nullable()->index();
            $table->string('click_id_provider')->nullable();
            $table->string('landing_url', 2000)->nullable();
            $table->string('referrer_url', 2000)->nullable();

            /*
             * What the attribution was resolved TO, and how confident that is.
             *
             * Kept beside the raw attribution rather than replacing it, so a resolver that improves
             * later can re-run over orders it once could not place — and so a client asking «لماذا
             * نُسبت هذه الطلبية لهذه الحملة؟» gets the evidence, not just the answer.
             */
            $table->uuid('external_campaign_id')->nullable()->index();
            $table->uuid('unified_campaign_id')->nullable()->index();
            $table->string('attribution_method')->nullable(); // click_id | utm_campaign | utm_source | none
            $table->timestampTz('attributed_at')->nullable();

            $table->boolean('is_demo')->default(false)->index();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('external_account_id')->references('id')->on('external_accounts')->cascadeOnDelete();
            $table->foreign('commerce_customer_id')->references('id')->on('commerce_customers')->nullOnDelete();
            $table->unique(['external_account_id', 'external_id']);
            // The two questions the funnel asks: this store's orders in a window, and this campaign's.
            $table->index(['tenant_id', 'project_id', 'placed_at']);
        });

        Schema::create('commerce_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('commerce_order_id')->index();
            $table->uuid('commerce_product_id')->nullable()->index();
            $table->string('external_id');
            $table->string('product_external_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('name');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 24, 6)->nullable();
            $table->decimal('total', 24, 6)->nullable();
            $table->timestampsTz();

            $table->foreign('commerce_order_id')->references('id')->on('commerce_orders')->cascadeOnDelete();
            $table->foreign('commerce_product_id')->references('id')->on('commerce_products')->nullOnDelete();
            $table->unique(['commerce_order_id', 'external_id']);
        });

        Schema::create('commerce_abandoned_carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('external_account_id')->index();
            $table->uuid('commerce_customer_id')->nullable()->index();
            $table->string('provider');
            $table->string('external_id');
            $table->timestampTz('abandoned_at')->nullable()->index();
            $table->string('currency', 8)->nullable();
            $table->decimal('total', 24, 6)->nullable();
            $table->integer('items_count')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('checkout_url', 2000)->nullable();

            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('click_id')->nullable();
            $table->string('click_id_provider')->nullable();
            $table->string('landing_url', 2000)->nullable();
            $table->string('referrer_url', 2000)->nullable();

            $table->boolean('is_demo')->default(false)->index();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('external_account_id')->references('id')->on('external_accounts')->cascadeOnDelete();
            $table->unique(['external_account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_abandoned_carts');
        Schema::dropIfExists('commerce_order_items');
        Schema::dropIfExists('commerce_orders');
        Schema::dropIfExists('commerce_customers');
        Schema::dropIfExists('commerce_products');
    }
};
