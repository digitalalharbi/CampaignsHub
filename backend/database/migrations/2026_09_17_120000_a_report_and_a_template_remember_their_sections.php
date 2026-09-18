<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-SECTION-MODEL-001 — the operator's section choices, saved where the report and the template are.
 *
 * Nullable and sparse: null means «nobody chose», which resolves to the audience's defaults at read
 * time. Every report that exists today therefore keeps a sensible section set without a backfill, and
 * a section added to the registry later gets its default on old reports instead of a stored guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->jsonb('section_settings')->nullable();
        });

        Schema::table('report_scope_templates', function (Blueprint $table): void {
            $table->jsonb('section_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('section_settings');
        });

        Schema::table('report_scope_templates', function (Blueprint $table): void {
            $table->dropColumn('section_settings');
        });
    }
};
