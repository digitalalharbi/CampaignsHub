<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-SNAPSHOT-PROTECT-001 — the copies of a report that a rebuild replaced. Additive: nothing existing moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->foreignUuid('report_id')->constrained('reports')->cascadeOnDelete();
            $table->jsonb('data');
            $table->timestampTz('generated_at');
            $table->timestampsTz();
            $table->unique(['report_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_versions');
    }
};
