<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GA4-INTEGRATION-001 — where a measured day is kept, and why it is not kept with the advertising days.
 *
 * Google Analytics 4 reports what happened on the CLIENT'S OWN SITE, under its own attribution model
 * and in its own calendar. An ad platform reports what IT believes its ads caused. The two are not
 * addable, and the owner's instruction is explicit: «Do not calculate a blended ROAS from incompatible
 * attribution sources.»
 *
 * A separate table is the only version of that rule that holds. `daily_metrics` is summed by campaign
 * and by project in a dozen places; a GA4 row landing there with a `revenue` key would be picked up by
 * every one of them for free, and no amount of care in the reading code would find it again. Here,
 * a query has to ASK for measurement to get it.
 *
 * ## Key/value rather than columns
 *
 * The same shape as `daily_metrics`, for a reason that matters more here: a metric the property did
 * not report arrives as an ABSENT ROW, not a zero. A `decimal(...)->default(0)` column would convert
 * «this property has no ecommerce configured» into «this site earned nothing», which is the exact
 * conversion §«Never convert unavailable into zero» forbids.
 *
 * ## The day is the PROPERTY's day
 *
 * `timezone` is recorded on every row because GA4's date boundary follows the property's configured
 * timezone, not UTC and not the reader's. A property set to Asia/Riyadh closes its day three hours
 * before UTC does, and a row stored without saying which calendar produced it cannot later be
 * reconciled with anything — including itself after a re-sync from a different host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_daily_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            /*
             * The project is NOT nullable: a property's figures are only ever stored for the project
             * it is bound to. A discovered-but-unselected property has no rows here at all, which is
             * «DISCOVERED ≠ SELECTED» expressed as a schema rather than as a convention.
             */
            $table->uuid('project_id')->index();
            /* The `external_accounts` row — one connection, one revoke path, one tenant column. */
            $table->uuid('external_account_id')->index();
            /* The bare GA4 property id, carried for readability in support and in logs. */
            $table->string('property_id');
            $table->date('metric_date');
            $table->string('metric_key');
            $table->decimal('value', 24, 6);
            /*
             * Revenue arrives in the PROPERTY's currency, which is a setting on the property and is
             * frequently not the project's reporting currency. Stored beside the value and never
             * converted here — «Never mix incompatible currencies» is decided where it is displayed,
             * with the original kept.
             */
            $table->string('currency', 3)->nullable();
            $table->string('timezone');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('external_account_id')->references('id')->on('external_accounts')->cascadeOnDelete();

            /*
             * Idempotence, enforced by the database rather than by the sync's good intentions.
             *
             * A re-sync of an overlapping window is normal — GA4 restates a day for up to 48 hours as
             * late events arrive — so the writer upserts on exactly this key and the second run
             * updates the day instead of doubling it.
             */
            $table->unique(['external_account_id', 'metric_date', 'metric_key'], 'measurement_day_metric_unique');
            $table->index(['tenant_id', 'project_id', 'metric_date'], 'measurement_project_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_daily_metrics');
    }
};
