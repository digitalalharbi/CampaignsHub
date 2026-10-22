<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ATTRIB-PRESETS-001 — a preset on the setting, and every change kept as a version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_attribution_settings', function (Blueprint $table): void {
            $table->string('preset', 16)->default('basic');
            $table->jsonb('advanced')->nullable();
        });

        Schema::create('project_attribution_setting_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->unsignedInteger('number');
            $table->string('preset', 16);
            $table->jsonb('models');
            $table->unsignedSmallInteger('lookback_days');
            $table->unsignedSmallInteger('half_life_days');
            $table->jsonb('weights');
            $table->jsonb('advanced')->nullable();
            $table->string('version', 32);
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['tenant_id', 'project_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_attribution_setting_versions');
        Schema::table('project_attribution_settings', function (Blueprint $table): void {
            $table->dropColumn(['preset', 'advanced']);
        });
    }
};
