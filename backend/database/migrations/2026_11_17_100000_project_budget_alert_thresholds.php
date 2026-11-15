<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-BUDGET-001 (consistency pass, builder H) — the approved project budget carries its own alert steps.
 * Null = the default 50/75/90/100. Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_funding', function (Blueprint $table): void {
            $table->jsonb('alert_thresholds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_funding', function (Blueprint $table): void {
            $table->dropColumn('alert_thresholds');
        });
    }
};
