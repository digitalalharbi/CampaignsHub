<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTEG-RAW-001 — keep what the platform actually said, beside what we made of it.
 *
 * The brief asks for raw AND normalised data, with the source of every number and the time it was
 * last synced. `daily_metrics` is the normalised half and has been for a while. This is the other
 * half, and it exists for three reasons that only show up after something has gone wrong:
 *
 * 1. **Disputes.** «لماذا الرقم مختلف عن لوحة سناب شات؟» is answerable from the payload we were
 *    handed, and unanswerable from a normalised row alone.
 * 2. **Mapping bugs.** A mis-mapped field — Snapchat's swipes read as impressions, a micro amount
 *    divided twice — is invisible in the normalised table, because the wrong number looks exactly
 *    like a right one. The payload is the only place the truth survives.
 * 3. **Re-derivation.** When a mapping is fixed, the window can be recomputed from what we already
 *    hold instead of asking a rate-limited API for a year of history again.
 *
 * ## Retention is deliberate, not accidental
 *
 * These rows are large and grow with every sync, so they are pruned on a schedule
 * (`integrations:prune-raw`) rather than kept for ever. Keeping them for ever is the failure mode
 * where a payload table quietly becomes the biggest thing in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_raw_payloads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('external_account_id')->nullable()->index();
            $table->uuid('sync_run_id')->nullable()->index();
            $table->string('provider');

            // What was asked for: `ad_accounts`, `campaigns`, `insights`.
            $table->string('resource');

            // The window the payload answers for, so a re-derivation knows what it is re-deriving.
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();

            /*
             * The platform's own body, unchanged.
             *
             * `jsonb` rather than text because the whole point is being able to ask questions of it
             * later — "which rows carried an action type we do not map yet?" is a query, not a grep.
             */
            $table->jsonb('payload');

            // How many rows we made of it. A payload with 400 rows that produced 0 metrics is the
            // signature of a mapping bug, and this column is what makes that visible without opening
            // the payload.
            $table->integer('normalised_rows')->default(0);

            $table->timestampTz('fetched_at');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('external_account_id')->references('id')->on('external_accounts')->nullOnDelete();

            // The two questions actually asked of this table: "what did we get for this account
            // lately", and "what is old enough to prune".
            $table->index(['tenant_id', 'provider', 'fetched_at']);
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_raw_payloads');
    }
};
