<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — approve or hide an attention item for client reports.
 *
 * One row per (project, item). Absent means «no decision»: a client-safe item shows, an
 * operator-internal one does not. Additive; nothing existing is read differently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_attention_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->string('item_key', 32);
            $table->string('decision', 16);
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_attention_decisions');
    }
};
