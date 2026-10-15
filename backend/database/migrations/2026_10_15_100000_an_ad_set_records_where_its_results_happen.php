<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEAD-DESTINATION-001 — an ad set states where its results happen.
 *
 * Meta's `destination_type` (`WEBSITE`, `ON_AD`, `WHATSAPP`, `MESSENGER`, `INSTAGRAM_DIRECT`,
 * `PHONE_CALL`) is the only field that tells a sales-objective WhatsApp buy from a website one — the
 * campaign's objective is `OUTCOME_SALES` for both. It was never requested, so a click-to-WhatsApp
 * campaign reported as generic Sales with purchase KPIs it can never have. Stored as the platform
 * spelt it; the classification is derived from it and never written over it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('external_ad_sets', 'destination_type')) {
            return;
        }

        Schema::table('external_ad_sets', function (Blueprint $table): void {
            $table->string('destination_type', 64)->nullable()->after('bid_strategy');
        });
    }

    public function down(): void
    {
        Schema::table('external_ad_sets', function (Blueprint $table): void {
            $table->dropColumn('destination_type');
        });
    }
};
