<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WEBHOOK-001 — every event a provider pushed us, recorded once and only once.
 *
 * ## The unique index IS the idempotency guarantee
 *
 * `(provider, fingerprint)` is unique, and the receiver inserts BEFORE it does any work. Providers
 * retry: Meta redelivers for 36 hours until it gets a 2xx, and a receiver that processed on every
 * delivery would double-count a purchase every time our own response was slow. Making the database
 * refuse the second insert is the only version of this that survives two workers racing on the same
 * redelivery — an application-level "have we seen this?" check has a window between the read and the
 * write, and that window is exactly when a retry storm arrives.
 *
 * `fingerprint` rather than the provider's event id, because not every provider sends one. It is the
 * event id when there is one and a hash of the raw body when there is not, which de-duplicates an
 * identical redelivery either way.
 *
 * ## `signature_verified` is a column, not a filter
 *
 * The receiver REFUSES an unverified delivery — nothing unsigned is ever stored as data. The column
 * exists so that the one case that is deliberately stored unverified, a provider whose scheme is not
 * yet confirmed, can never be mistaken for a verified one by a consumer reading this table later.
 *
 * ## No tenant scope on the insert path, and why the column is nullable
 *
 * A webhook arrives with no session and no tenant header; the tenant is DERIVED from the connection
 * the provider's account id resolves to. When it resolves to nothing — an account nobody here has
 * connected, or a delivery that arrived before the connection was recorded — the row is kept with a
 * null tenant and an `unmatched` status rather than being dropped, because a payload we cannot place
 * is evidence, and dropping it is how a mis-registered webhook URL stays invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('kind');                         // advertising | commerce
            $table->string('topic')->nullable();            // the provider's own event name
            $table->string('external_event_id')->nullable();
            $table->string('fingerprint', 64);

            // Derived, never taken from the request body.
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('provider_connection_id')->nullable()->index();
            $table->uuid('external_account_id')->nullable()->index();

            $table->jsonb('payload');
            $table->boolean('signature_verified')->default(false);

            // received | processed | unmatched | ignored | failed
            $table->string('status')->default('received');
            $table->text('error')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'fingerprint']);
            $table->index(['provider', 'status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_webhook_events');
    }
};
