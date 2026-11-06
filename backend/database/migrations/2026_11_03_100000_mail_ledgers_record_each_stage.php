<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EMAIL-DELIVERY-LEDGER-001 (§17.4.4) — each stage has its own moment, and `delivered` its own evidence.
 *
 * Additive on purpose. `status` keeps its stored words because `delivery_status` columns, the
 * operational readiness verdict and the frontend contract already read them; `MailDeliveryState` is
 * the one translation into the words a reader sees, and `sent` there reads `accepted`.
 *
 * `delivered_at` is empty until somebody has evidence: SMTP says «I took it» and nothing after that, so
 * no job may ever write it. `delivery_evidence` is required with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_deliveries', function (Blueprint $table): void {
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->string('delivery_evidence', 240)->nullable();
            // The transport's Message-ID: what lets a person find this message in the provider's own log.
            $table->string('provider_message_id', 190)->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
        });

        Schema::table('digest_sends', function (Blueprint $table): void {
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->string('delivery_evidence', 240)->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            // The span this send covers, so a retry rebuilds the SAME report and not «now minus a week».
            $table->timestampTz('window_from')->nullable();
            $table->timestampTz('window_to')->nullable();
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::table('subscription_notifications', function (Blueprint $table): void {
            $table->timestampTz('delivered_at')->nullable();
            $table->string('delivery_evidence', 240)->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->string('failure_code', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_notifications', function (Blueprint $table): void {
            $table->dropColumn(['delivered_at', 'delivery_evidence', 'provider_message_id', 'failure_code']);
        });

        Schema::table('digest_sends', function (Blueprint $table): void {
            $table->dropIndex(['status', 'next_attempt_at']);
            $table->dropColumn(['attempted_at', 'accepted_at', 'delivered_at', 'delivery_evidence', 'provider_message_id', 'failure_code', 'next_attempt_at', 'window_from', 'window_to']);
        });

        Schema::table('mail_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['attempted_at', 'accepted_at', 'delivered_at', 'delivery_evidence', 'provider_message_id', 'failure_code', 'next_attempt_at']);
        });
    }
};
