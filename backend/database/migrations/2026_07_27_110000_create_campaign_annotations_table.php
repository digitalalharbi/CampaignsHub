<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign notes & recommendations (CMC-11). Real, persisted, workflow-governed annotations tied to a
 * campaign — a note ("finding") or a recommendation, each carrying its numeric evidence, platform,
 * KPI, priority, assignee, due date, and a Draft→Reviewed→Approved→Hidden/Rejected status. Only
 * Approved recommendations may ever surface to a client report (enforced at the export layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_annotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('campaign_id')->index();
            $table->string('kind');                 // note | recommendation
            $table->string('status')->default('draft'); // draft|reviewed|approved|hidden|rejected
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('platform')->nullable();
            $table->string('kpi')->nullable();
            $table->text('evidence')->nullable();   // the numeric backing ("ROAS 8.4x vs 4.9x avg")
            $table->string('priority')->default('medium'); // critical|high|medium|low
            $table->string('proposed_action')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['campaign_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_annotations');
    }
};
