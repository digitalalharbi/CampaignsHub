<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-BUDGET-001 (consistency pass, builder H) — a spend limit is a threshold OF the project's approved budget.
 *
 *  - `project_funding_id` — the budget it belongs to (scope project / platform / account / campaign).
 *  - `converted_at` + `legacy_snapshot` — when `funding:convert-spend-limits` linked it, and the row exactly as it was
 *    before, kept read-only as the record. Nothing is deleted. Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spend_limits', function (Blueprint $table): void {
            $table->uuid('project_funding_id')->nullable()->index();
            $table->timestampTz('converted_at')->nullable();
            $table->jsonb('legacy_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spend_limits', function (Blueprint $table): void {
            $table->dropIndex(['project_funding_id']);
            $table->dropColumn(['project_funding_id', 'converted_at', 'legacy_snapshot']);
        });
    }
};
