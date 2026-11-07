<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRICING-VAT-001 — a subscription charge carries its VAT, and its invoice says what was collected.
 *
 * - `plan_catalogue_versions.prices_include_vat`: the launch catalogue (edition 1) was sold at all-in prices, so what it charges
 *   is unchanged and its VAT is shown as INCLUDED in that amount. The 2026-11 SAR catalogue is priced VAT-exclusive (Owner,
 *   2026-11-01): its charge is the price plus VAT.
 * - `subscription_payments.net_amount` / `tax_amount` / `tax_treatment`: the split, captured when the charge is opened, so the
 *   invoice is built from what was asked for — never recomputed on top of it.
 *
 * No existing payment, invoice or subscription is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_catalogue_versions', function (Blueprint $table): void {
            $table->boolean('prices_include_vat')->default(true);
        });
        DB::table('plan_catalogue_versions')->where('number', '>=', 2)->update(['prices_include_vat' => false]);

        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->decimal('net_amount', 15, 2)->nullable();
            $table->decimal('tax_amount', 15, 2)->nullable();
            $table->string('tax_treatment', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->dropColumn(['net_amount', 'tax_amount', 'tax_treatment']);
        });
        Schema::table('plan_catalogue_versions', function (Blueprint $table): void {
            $table->dropColumn('prices_include_vat');
        });
    }
};
