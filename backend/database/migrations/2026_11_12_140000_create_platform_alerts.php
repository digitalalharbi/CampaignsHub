<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLATFORM-PAYMENT-ALERTS-001 — what the platform owner is told about a customer stuck on the way in.
 *
 * A NEW table, additive and reversible. It is not a payment ledger: every row points at the record that proves it
 * (`subscription_payments`, `registration_requests`, `mail_deliveries`) and only copies the facts that were true
 * when it was raised. No existing row is read differently and none is rewritten.
 *
 * Platform-owned by construction: there is no `tenant_id`. `subject_tenant_id` names the workspace the alert is
 * ABOUT, so the owner can open it — it is never an owner, a scope or a recipient.
 *
 * `dedup_key` is unique, which is what makes «once per attempt» structural rather than remembered: a sweep that
 * runs every fifteen minutes cannot raise the same alert twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // checkout_abandoned | payment_unverified | payment_activation_mismatch | settlement_missing | customer_blocking_error
            $table->string('kind', 40);
            // critical | warning — decides the colour, nothing else.
            $table->string('severity', 16);
            $table->string('dedup_key', 160)->unique();
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->foreignUuid('subject_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->jsonb('facts');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('emailed_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['kind', 'first_seen_at']);
            $table->index(['acknowledged_at', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_alerts');
    }
};
