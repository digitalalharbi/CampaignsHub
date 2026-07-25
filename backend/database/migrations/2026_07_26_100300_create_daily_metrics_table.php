<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C3.1 — The metrics fact table. One row per (account, campaign, metric, date, attribution window),
 * normalized to the project currency/timezone while preserving the original values and provenance.
 * The natural-key unique index makes ingestion an idempotent upsert (safe re-sync / late attribution).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Scope / provenance.
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('connection_id')->nullable();
            $table->uuid('external_account_id');
            $table->uuid('external_campaign_id');
            $table->uuid('unified_campaign_id')->nullable(); // set once the external campaign is linked
            $table->string('provider');                      // meta | google | tiktok | snapchat | sandbox | ...

            // What + when.
            $table->string('metric_key');                    // references metric_definitions.key
            $table->date('metric_date');
            $table->decimal('value', 24, 6)->default(0);     // normalized numeric value (project currency for money)

            // Currency normalization (monetary metrics only).
            $table->char('original_currency', 3)->nullable();
            $table->char('project_currency', 3)->nullable();
            $table->decimal('original_amount', 24, 6)->nullable();
            $table->decimal('converted_amount', 24, 6)->nullable();
            $table->decimal('exchange_rate', 24, 12)->nullable();

            // Timezone provenance (the source's day boundary vs the project's).
            $table->string('original_timezone')->nullable();
            $table->string('project_timezone')->nullable();

            // Attribution + freshness + raw payload.
            $table->string('attribution_window')->default('default'); // e.g. 7d_click_1d_view
            $table->string('source_type')->default('api');            // api | manual | estimated | modeled
            $table->timestampTz('data_freshness_at')->nullable();     // when the source last reported it
            $table->uuid('sync_run_id')->nullable();
            $table->jsonb('raw')->nullable();

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('sync_run_id')->references('id')->on('metric_sync_runs')->nullOnDelete();

            // Idempotency: exactly one row per campaign/metric/day/attribution window/account.
            // attribution_window is NOT NULL (default) so the unique index never sees NULLs.
            $table->unique(
                ['external_account_id', 'external_campaign_id', 'metric_key', 'metric_date', 'attribution_window'],
                'daily_metrics_natural_key',
            );

            // Query paths: by project+date, by campaign, by unified campaign, by platform.
            $table->index(['tenant_id', 'project_id', 'metric_date']);
            $table->index(['external_campaign_id', 'metric_date']);
            $table->index(['unified_campaign_id', 'metric_date']);
            $table->index(['provider', 'metric_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_metrics');
    }
};
