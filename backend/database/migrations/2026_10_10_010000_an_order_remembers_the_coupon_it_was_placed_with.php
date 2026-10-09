<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ATTR-EVIDENCE-INFLUENCER-COUPON-001 — the coupon code a store order was placed with.
 *
 * A creator's discount code on an order is attribution evidence of its own kind: weaker than a
 * platform's click id (the platform proved the click), stronger than a bare utm_source (a word
 * anybody can type). Until now the code was discarded at import, so the ledger could never say
 * «this order came through the creator's code». Nullable: an order without a coupon is the common
 * case, and absence is absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->string('coupon_code', 64)->nullable()->after('utm_term');
            $table->index(['project_id', 'coupon_code']);
        });
    }

    public function down(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'coupon_code']);
            $table->dropColumn('coupon_code');
        });
    }
};
