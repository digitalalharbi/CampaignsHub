<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted findings & recommendations with a real approval lifecycle (Draft → Reviewed → Approved,
 * plus Hidden/Rejected). Auto-generated items start Draft; only Approved ones reach a client/executive
 * report. The stable annotation_id (hash of type+title+platform) lets regeneration preserve review
 * state without losing the human decision. Every transition is audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_annotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('report_id')->index();
            $table->string('annotation_id', 24)->index(); // stable content id
            $table->string('type'); // finding|recommendation
            $table->text('text_ar')->nullable();
            $table->text('text_en')->nullable();
            $table->string('platform')->nullable();
            $table->uuid('campaign_id')->nullable();
            $table->string('kpi')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->string('source')->nullable();
            $table->string('priority')->default('normal');
            $table->text('proposed_action')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status')->default('draft'); // draft|reviewed|approved|hidden|rejected
            $table->boolean('is_ai_generated')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('report_id')->references('id')->on('reports')->cascadeOnDelete();
            $table->unique(['report_id', 'annotation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_annotations');
    }
};
