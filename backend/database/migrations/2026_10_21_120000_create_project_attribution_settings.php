<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ATTRIB-CREDIT-001 — which fractional attribution models a project has chosen, and with what rules.
 *
 * One row per project, and only when someone chose: there is no row for a project that never opened Attribution
 * settings, which is how «off until activated» stays true without a flag that could drift from the module switch.
 * The stored values are the normalised configuration (weights as shares of one), so the same choice always reads
 * the same, and `version` is the rule version they were validated under.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_attribution_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->jsonb('models');
            $table->unsignedSmallInteger('lookback_days');
            $table->unsignedSmallInteger('half_life_days');
            $table->jsonb('weights');
            $table->string('version', 32);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_attribution_settings');
    }
};
