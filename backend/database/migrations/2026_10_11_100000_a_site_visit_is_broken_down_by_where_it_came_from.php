<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GA4-ANALYTICS-PRODUCT-001 — what the site measured, broken down by where the visit came from,
 * where it landed, what device and country, new or returning, and which events fired.
 *
 * One row per property, day, breakdown and dimension value(s); the metrics are a JSON bag because
 * the event breakdown measures different things from the session breakdowns. `dimension_2` is ''
 * rather than null when a breakdown has one dimension, so the unique key holds on PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_dimension_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('external_account_id')->index();
            $table->string('property_id');
            $table->date('metric_date');
            $table->string('breakdown', 32);
            $table->string('dimension_1', 512);
            $table->string('dimension_2', 512)->default('');
            $table->jsonb('metrics');
            $table->string('currency', 3)->nullable();
            $table->string('timezone');
            $table->timestampsTz();

            $table->unique(['external_account_id', 'metric_date', 'breakdown', 'dimension_1', 'dimension_2'], 'measurement_dimension_rows_key');
            $table->index(['project_id', 'breakdown', 'metric_date']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('external_account_id')->references('id')->on('external_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_dimension_rows');
    }
};
