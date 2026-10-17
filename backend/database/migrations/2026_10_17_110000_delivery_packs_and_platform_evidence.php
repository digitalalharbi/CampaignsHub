<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WD-PACK-001, WD-EVIDENCE-001 — the weekly delivery pack and the platform evidence beside it.
 *
 * A pack WRAPS what already exists — a report snapshot, its annotations (the draft and approve
 * lifecycle), an invoice — and records which pieces this client receives and whether the Owner has
 * approved the whole. It owns no figures of its own. Evidence is a file the Owner attaches during review:
 * trust beside the data, never a source of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('report_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft');          // draft | approved | sent
            $table->jsonb('components')->nullable();              // which pieces this client receives
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'project_id', 'period_start']);
        });

        Schema::create('platform_evidences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->string('provider');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->timestampTz('captured_at')->nullable();      // when the screenshot was taken, as the Owner states it
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->text('note')->nullable();
            $table->boolean('include_in_report')->default(true);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'client_workspace_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_evidences');
        Schema::dropIfExists('delivery_packs');
    }
};
