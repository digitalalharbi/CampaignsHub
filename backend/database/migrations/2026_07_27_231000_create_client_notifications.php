<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-facing notification delivery log (email + WhatsApp) for request lifecycle events. Honest by design:
 * with no provider wired, rows are recorded as "awaiting_provider_credentials" and never "sent". Deduplicated
 * per (request, event, channel) so a repeated event does not spam the client. Each row carries a secure deep
 * link back to the request in the client portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUlid('request_id')->constrained('external_requests')->cascadeOnDelete();
            $table->string('event');       // received|info_requested|team_reply|status_changed|approved|in_progress|completed|report_available
            $table->string('channel');     // email|whatsapp
            $table->string('destination');
            // awaiting_provider_credentials|queued|sent|failed|retrying|suppressed
            $table->string('status')->default('awaiting_provider_credentials');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('dedup_key')->index();
            $table->string('deep_link');
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['request_id', 'event', 'channel'], 'client_notifications_dedup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notifications');
    }
};
