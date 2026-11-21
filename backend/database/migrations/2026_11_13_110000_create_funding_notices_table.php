<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-LOOP-001 — one notice per project, per budget cycle, per state.
 *
 * A cycle is the number of approved amounts so far (append-only, so it only grows): a renewal starts a new cycle, so «running low» can be said
 * again about the new money and never twice about the same money, however often the spend is re-read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->unsignedInteger('cycle');
            $table->string('state', 16);                   // added | low | over
            $table->decimal('remaining', 18, 2)->nullable(); // SAR, at the moment it was raised
            $table->unsignedInteger('recipients')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'cycle', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_notices');
    }
};
