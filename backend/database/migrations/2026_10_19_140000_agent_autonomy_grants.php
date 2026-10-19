<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AGENTS-AUTONOMY-001 — an owner's grant that lets ONE narrow class of approved-looking proposals run
 * without waiting, for one project. Revoking keeps the row (revoked_at), so the history of who allowed
 * what, and when, survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_autonomy_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->string('action_class', 40);   // pause | budget_decrease
            $table->unsignedBigInteger('granted_by');
            $table->timestampTz('granted_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->jsonb('ladder_at_grant')->nullable();   // the measured record the owner saw
            $table->timestampsTz();
            $table->index(['project_id', 'action_class']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_autonomy_grants');
    }
};
