<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRICING-VAT-002 — the VAT on a subscription is a term of its contract, and the console manages it for new sales only.
 *
 * - `plan_catalogue_versions.tax_treatment`: the treatment new sales of an edition are made under (one of the legal treatments in
 *   TaxTreatment; the legacy 5% is never offered), managed from the console beside `prices_include_vat`.
 * - `subscriptions.tax_treatment` / `prices_include_vat`: captured when a plan is sold, like `unit_amount` and `currency`, so a
 *   console change never re-taxes somebody already subscribed. Existing subscriptions are given EXACTLY the terms they are charged
 *   under today (their edition's), so nothing anybody pays changes here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_catalogue_versions', function (Blueprint $table): void {
            $table->string('tax_treatment', 32)->default('basic_15');
        });
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('tax_treatment', 32)->nullable();
            $table->boolean('prices_include_vat')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE subscriptions s SET tax_treatment = COALESCE(v.tax_treatment, 'basic_15'), prices_include_vat = COALESCE(v.prices_include_vat, true)
            FROM subscription_plans p LEFT JOIN plan_catalogue_versions v ON v.id = p.catalogue_version_id
            WHERE p.id = s.plan_id
        SQL);
        DB::table('subscriptions')->whereNull('tax_treatment')->update(['tax_treatment' => 'basic_15', 'prices_include_vat' => true]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['tax_treatment', 'prices_include_vat']);
        });
        Schema::table('plan_catalogue_versions', function (Blueprint $table): void {
            $table->dropColumn('tax_treatment');
        });
    }
};
