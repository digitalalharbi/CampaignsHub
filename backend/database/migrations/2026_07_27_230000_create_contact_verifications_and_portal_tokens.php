<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-portal identity primitives:
 *  - request_contact_verifications: OTP / magic-code challenges for a phone or email destination (contact
 *    verification before submit, and portal login). Only the code HASH is stored; delivery is recorded
 *    honestly (awaiting_provider_credentials when no SMS/WhatsApp/mail provider is wired).
 *  - client_portal_tokens: an httpOnly-cookie session for the external client, scoped to a verified
 *    contact (email/phone) + tenant — never stored in localStorage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_contact_verifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('channel');          // sms | whatsapp | email
            $table->string('destination');      // E.164 phone or email address
            $table->string('purpose')->default('contact_verify'); // contact_verify | portal_login
            $table->char('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('delivery_status')->default('awaiting_provider_credentials'); // awaiting_provider_credentials|sent|failed
            $table->timestampTz('expires_at');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('consumed_at')->nullable(); // a verification token is single-use
            $table->timestampTz('last_sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['destination', 'purpose']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('client_portal_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->string('contact_email')->nullable()->index();
            $table->string('contact_phone', 32)->nullable()->index();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_tokens');
        Schema::dropIfExists('request_contact_verifications');
    }
};
