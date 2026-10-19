<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AGENTS-CLIENT-RULES-001 — what a client wants, written down once, read by every agent before deciding.
 *
 * One row per (project, platform-or-all, KPI). A target without a direction is meaningless (a CPA is good
 * when LOW, a ROAS when HIGH), so the direction is stored rather than inferred from the KPI's name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_agent_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->string('platform', 40)->nullable();      // null = every platform of the project
            $table->string('kpi', 40);                       // cpa, roas, cpl, ctr, cpc, cpm, cpi, conversion_rate
            $table->string('direction', 4);                  // max | min — «at most» / «at least»
            $table->decimal('target_value', 18, 6);
            $table->decimal('min_spend_to_judge', 18, 4)->nullable();
            $table->jsonb('special_days')->nullable();       // e.g. match days: [{date, label}]
            $table->jsonb('forbidden_words')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['project_id', 'platform', 'kpi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_agent_rules');
    }
};
