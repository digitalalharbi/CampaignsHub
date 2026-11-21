<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUDIENCE-001 — PAID DELIVERY as the platform broke it down (region, age, gender, device …).
 *
 * One kind only: what the ad platform reported it DELIVERED, by one dimension, for one ad set and
 * one day. Targeting settings stay on `external_ad_sets.targeting`, site events on
 * `measurement_dimension_rows`; the three are never merged into one field.
 *
 * Every figure is NULLABLE: a measure the platform did not report is absent, never zero. Money keeps
 * its original amount and currency and where the currency came from; nothing is converted here.
 * `reach` is the platform's daily unique count — it is NOT additive across days or across platforms,
 * and the readers refuse to add it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_breakdown_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('external_account_id')->index();
            $table->string('provider', 32);
            $table->string('dimension', 32);              // region | country | age | gender | device | placement
            $table->string('value', 255);                 // the provider's label, verbatim (never re-mapped to a city)
            $table->string('source_field', 128);          // e.g. meta:insights.breakdowns=region
            $table->uuid('external_campaign_id')->nullable()->index();
            $table->uuid('external_ad_set_id')->nullable()->index();
            $table->string('external_entity_id');          // the provider's ad set id
            $table->date('metric_date');
            $table->decimal('impressions', 24, 6)->nullable();
            $table->decimal('reach', 24, 6)->nullable();
            $table->decimal('clicks', 24, 6)->nullable();
            $table->decimal('spend_original', 24, 6)->nullable();
            $table->char('original_currency', 3)->nullable();
            $table->string('currency_source', 16)->nullable(); // provider_row | account | null (withheld)
            $table->uuid('sync_run_id')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'external_account_id', 'dimension', 'external_entity_id', 'metric_date', 'value'], 'audience_rows_unique');
            $table->index(['tenant_id', 'project_id', 'dimension', 'metric_date'], 'audience_rows_read');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_breakdown_rows');
    }
};
