<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AGENTS-ROUTINES-001 — which scheduled agent routines a project has switched on.
 *
 * A row per (project, routine). Absent or disabled means the routine does not run: each run spends the
 * tenant's own model tokens, so nothing is on by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_routines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->string('routine', 40);
            $table->boolean('enabled')->default(false);
            $table->timestampTz('last_run_at')->nullable();
            $table->uuid('last_thread_id')->nullable();
            $table->string('last_outcome', 60)->nullable();   // queued | skipped_busy | no_ai_credential
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'routine']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_routines');
    }
};
