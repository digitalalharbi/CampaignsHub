<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — approve or hide an attention item for client reports.
 *
 * One row per (report, period, item). An approval belongs to the figures it was given against: it
 * never carries into the next period's report, or into another window of the same live link — the
 * operator decides again. Absent means «no decision»: a client-safe item shows, an operator-internal
 * one does not. Additive; nothing existing is read differently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_attention_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('report_id');
            $table->date('period_from');
            $table->date('period_to');
            $table->string('item_key', 32);
            $table->string('decision', 16);
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['report_id', 'period_from', 'period_to', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_attention_decisions');
    }
};
