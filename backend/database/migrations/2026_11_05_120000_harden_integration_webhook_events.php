<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INTEG-WEBHOOK-ENGINE-001 — the webhook inbox grows the columns a delivery needs to be accountable.
 *
 * Additive only: no column is dropped or renamed and no row is rewritten. The one relaxation is that
 * `payload` becomes nullable, because a REJECTED delivery (bad signature, replayed outside the window)
 * is recorded as a fact about the endpoint and must not store the attacker-controlled body in the
 * table a consumer reads as trusted.
 *
 * - `verdict`          what the endpoint decided about the delivery: verified | rejected_signature | rejected_stale | gap
 * - `source`           webhook | reconciliation — a reconciliation gap lives in the inbox beside the deliveries it explains
 * - `correlation_id`   the request's correlation id, so a delivery can be followed into the audit trail and the logs
 * - `event_time`       the provider's own timestamp, read only from a body the signature covers
 * - `entity_key`       `<account>:<entity>:<id>` — which order the event is about, scoped to one store
 * - `attempts`         how many processing attempts the queue has made
 * - `duplicate_count`  redeliveries of the same event, answered 2xx and worked once
 * - `replayed_count`   times an operator re-ran a failed or dead-lettered delivery
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->string('verdict', 32)->nullable();
            $table->string('source', 24)->default('webhook');
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('event_time')->nullable();
            $table->string('entity_key', 191)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->timestampTz('last_duplicate_at')->nullable();
            $table->unsignedSmallInteger('replayed_count')->default(0);
            $table->timestampTz('dead_lettered_at')->nullable();

            $table->index(['provider', 'entity_key', 'event_time'], 'iwe_entity_order_idx');
            $table->index(['status', 'received_at'], 'iwe_status_idx');
        });

        DB::statement('ALTER TABLE integration_webhook_events ALTER COLUMN payload DROP NOT NULL');

        // Rows that predate the column were all verified deliveries: nothing unverified was ever stored.
        DB::table('integration_webhook_events')->whereNull('verdict')->update(['verdict' => 'verified']);
    }

    public function down(): void
    {
        DB::table('integration_webhook_events')->whereNull('payload')->delete();
        DB::statement('ALTER TABLE integration_webhook_events ALTER COLUMN payload SET NOT NULL');

        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->dropIndex('iwe_entity_order_idx');
            $table->dropIndex('iwe_status_idx');
            $table->dropColumn([
                'verdict', 'source', 'correlation_id', 'event_time', 'entity_key', 'attempts',
                'duplicate_count', 'last_duplicate_at', 'replayed_count', 'dead_lettered_at',
            ]);
        });
    }
};
