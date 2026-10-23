<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FORMS-DISPATCH-001 — where a lead's conversion is reported, and the ledger of every attempt.
 *
 * `conversion_destinations` is the project's configuration: WHICH measurement or advertising endpoint hears about a
 * lead (GA4, Meta's conversions API, a server-side GTM container), for one form or for all of the project's forms.
 * Its secrets are encrypted at rest and are never returned by any endpoint. A destination starts in `test`: it builds
 * and records the exact payload it WOULD send and sends nothing. `live` is a separate, explicit, audited step.
 *
 * `conversion_deliveries` is the ledger. One row per (destination, event id) — the unique key is the deduplication,
 * and the event id is the same one the browser's pixel is given, so a platform that receives both counts one
 * conversion. A row says what happened to it: queued, sent, failed (with the response and the reason), skipped
 * because the visitor had not consented, or recorded in test mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_destinations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            // null = every form of the project
            $table->uuid('lead_form_id')->nullable()->index();
            $table->string('kind', 24);
            $table->string('name', 120);
            // test | live | off
            $table->string('status', 8)->default('test');
            // Non-secret settings: measurement id, pixel id, endpoint, the platform's own event name.
            $table->json('config');
            // Encrypted. Never returned.
            $table->text('secrets')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampTz('went_live_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('conversion_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('conversion_destination_id');
            $table->uuid('lead_form_id')->nullable()->index();
            $table->uuid('lead_id')->nullable()->index();
            $table->string('event_name', 40);
            // The deduplication id the browser's tag receives too.
            $table->string('event_id', 64);
            // queued | sent | failed | skipped_consent | test
            $table->string('status', 16)->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('error', 300)->nullable();
            // Identifiers are hashed before they are stored; the payload here is the one that is (or would be) sent.
            $table->json('payload')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampsTz();

            $table->unique(['conversion_destination_id', 'event_id'], 'conversion_deliveries_dedupe');
            $table->index(['tenant_id', 'project_id', 'status', 'created_at']);
            $table->foreign('conversion_destination_id')->references('id')->on('conversion_destinations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_deliveries');
        Schema::dropIfExists('conversion_destinations');
    }
};
