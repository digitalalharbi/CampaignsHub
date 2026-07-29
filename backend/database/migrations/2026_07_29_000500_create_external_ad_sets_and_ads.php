<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAMPDET-010 — the ad-set and ad levels the campaign detail could only describe in words.
 *
 * These mirror what every ad platform exposes beneath a campaign: an ad set (Meta) / ad group (Google,
 * TikTok) carrying the targeting and budget, and the ads inside it carrying the creative. Metrics stay
 * in the existing tall daily_metrics table keyed by the owning campaign; these tables hold structure and
 * the per-entity rollups a platform returns, so the UI can show a real hierarchy instead of a promise.
 *
 * Rows are source-attributed: `source_type` records whether a row came from an API sync or demo data,
 * so demo structure can never be mistaken for a live platform pull.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_ad_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('external_campaign_id')->index();
            $table->uuid('unified_campaign_id')->nullable()->index();
            $table->string('provider');
            $table->string('external_id');
            $table->string('name');
            $table->string('status')->default('active');
            $table->string('optimization_goal')->nullable();   // e.g. conversions, link_clicks, reach
            $table->string('bid_strategy')->nullable();
            $table->decimal('daily_budget', 24, 4)->nullable();
            $table->decimal('lifetime_budget', 24, 4)->nullable();
            $table->string('currency', 8)->nullable();
            $table->jsonb('targeting')->nullable();            // as returned by the platform
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->string('source_type')->default('api');     // api | demo
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->foreign('external_campaign_id')->references('id')->on('external_campaigns')->cascadeOnDelete();
            $table->unique(['external_campaign_id', 'external_id']);
        });

        Schema::create('external_ads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('external_ad_set_id')->index();
            $table->uuid('external_campaign_id')->index();
            $table->uuid('unified_campaign_id')->nullable()->index();
            $table->uuid('creative_id')->nullable()->index();   // external_creatives, when known
            $table->string('provider');
            $table->string('external_id');
            $table->string('name');
            $table->string('status')->default('active');
            $table->string('review_status')->nullable();        // approved | pending | rejected
            $table->string('destination_url')->nullable();
            $table->string('source_type')->default('api');
            $table->boolean('is_demo')->default(false)->index();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->foreign('external_ad_set_id')->references('id')->on('external_ad_sets')->cascadeOnDelete();
            $table->unique(['external_ad_set_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_ads');
        Schema::dropIfExists('external_ad_sets');
    }
};
