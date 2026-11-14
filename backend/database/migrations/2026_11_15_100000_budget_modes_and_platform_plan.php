<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUDGET-MODES-001 — a budget has a payment mode and, optionally, a plan per platform. ADDITIVE ONLY.
 *
 *  - `project_funding.payment_mode`  direct (default: the budget is a prepaid balance) | credit (served on credit:
 *    spend is recorded, the budget amount, if any, is only a ceiling, and the month's spend is what is invoiced later).
 *    Every existing budget becomes `direct` through the column default; nothing is rewritten.
 *  - `project_funding.platform_plan` nullable JSON: [{provider, percent, amount|null}] — the planned share of each platform.
 *    Null = no plan (every existing budget). A change of it is a ledger entry, never a silent edit.
 *  - `funding_notices.state` is widened (16 → 40) so a state can name a platform or a month. Widening drops nothing.
 *
 * Existing `payment_method` keeps its meaning (a budget that is tracked at all); it is not reused for the mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_funding', function (Blueprint $table): void {
            $table->string('payment_mode', 12)->default('direct');
            $table->jsonb('platform_plan')->nullable();
        });

        Schema::table('funding_notices', function (Blueprint $table): void {
            $table->string('state', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_funding', function (Blueprint $table): void {
            $table->dropColumn(['payment_mode', 'platform_plan']);
        });
        // `funding_notices.state` stays wide: narrowing it could truncate real rows.
    }
};
