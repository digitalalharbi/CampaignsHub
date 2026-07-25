<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C3.1 — Audit of each metrics sync attempt (per account/window). Records status, timing, upserted
 * count and errors so late-attribution resyncs and connector failures are observable and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('connection_id')->nullable()->index();
            $table->uuid('external_account_id')->nullable();
            $table->string('provider');
            $table->string('status')->default('pending'); // pending | running | success | partial | failed
            $table->date('window_start');
            $table->date('window_end');
            $table->unsignedInteger('metrics_upserted')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['tenant_id', 'project_id', 'status']);
            $table->index(['external_account_id', 'window_start', 'window_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_sync_runs');
    }
};
