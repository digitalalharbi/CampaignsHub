<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creative data layer (CMC-8). A unified `external_creatives` row per ad creative synced from a
 * platform (image/video/carousel/text) and its wide `creative_daily_metrics`. Real, source-attributed
 * data — thumbnail/preview URLs are nullable and NEVER fabricated (the UI shows "preview unavailable"
 * when the platform doesn't provide one). Demo rows are `is_demo` and flow through the same tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_creatives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('campaign_id')->nullable()->index();       // unified campaign
            $table->uuid('external_campaign_id')->nullable()->index();
            $table->string('provider');                              // meta|google|tiktok|snapchat|sandbox
            $table->string('external_creative_id');
            $table->string('external_ad_id')->nullable();
            $table->string('name');
            $table->string('client_display_name')->nullable();
            $table->string('format')->default('image');             // image|video|carousel|text
            $table->string('thumbnail_url')->nullable();            // never fabricated
            $table->string('preview_url')->nullable();
            $table->string('destination_url')->nullable();
            $table->string('status')->default('active');
            $table->string('source_type')->default('api');
            $table->timestampTz('last_synced_at')->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'provider', 'external_creative_id']);
        });

        Schema::create('creative_daily_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('creative_id')->index();
            $table->uuid('campaign_id')->nullable()->index();
            $table->date('metric_date');
            $table->decimal('spend', 24, 6)->default(0);
            $table->decimal('impressions', 24, 6)->default(0);
            $table->decimal('clicks', 24, 6)->default(0);
            $table->decimal('conversions', 24, 6)->default(0);
            $table->decimal('revenue', 24, 6)->default(0);
            $table->decimal('video_views', 24, 6)->default(0);
            $table->decimal('video_completions', 24, 6)->default(0);
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampsTz();

            $table->foreign('creative_id')->references('id')->on('external_creatives')->cascadeOnDelete();
            $table->unique(['creative_id', 'metric_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_daily_metrics');
        Schema::dropIfExists('external_creatives');
    }
};
