<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-LOOP-001 part 3 — «ملخص الشهر»: one fixed reference per project per closed month.
 *
 * Written once after the month ends and never changed, so it stays the agreed figure even if a
 * provider restates a day later. The live figures keep moving; this row does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_month_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->char('month', 7);              // YYYY-MM
            $table->jsonb('figures');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_month_summaries');
    }
};
