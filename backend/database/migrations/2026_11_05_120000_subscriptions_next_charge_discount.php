<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADMIN-FULL-CONTROL-001 — complimentary access converted to a discount.
 *
 * The percentage off the NEXT charge only, kept apart from `unit_amount`, which stays the agreed full price every later renewal
 * reads. Cleared when that charge settles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('next_charge_discount_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('next_charge_discount_percent');
        });
    }
};
