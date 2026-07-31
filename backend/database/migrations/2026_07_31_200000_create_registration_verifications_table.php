<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verification challenges for an applicant who has no account yet (SIGNUP-002, SIGNUP-005).
 *
 * `email_verifications` cannot serve this: it has a foreign key to `users`, and the entire point of
 * the gated path is that no user row exists until the application has cleared every gate. Proving an
 * email address is one of the things that has to happen BEFORE that, so the challenge has to hang off
 * the request rather than off an account.
 *
 * Both channels live in one table because they are the same object — a secret we sent, a hash we
 * kept, and a deadline — and splitting them would mean two expiry policies to keep in step. Only the
 * hash is stored: a leaked database must not hand out working verification links or codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_verifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUuid('registration_request_id')
                ->constrained('registration_requests')->cascadeOnDelete();

            // 'email' — a long random link token. 'mobile' — a six-digit code the applicant types.
            $table->string('channel', 16);
            $table->char('token_hash', 64)->index();

            /*
             * How many times the applicant has guessed.
             *
             * Meaningless for an emailed link and essential for a six-digit code: without it, an OTP
             * is a four-hour window in which 999,999 attempts will certainly succeed. The rate
             * limiter in front of the endpoint is not enough on its own, because it is keyed by IP.
             */
            $table->unsignedSmallInteger('attempts')->default(0);

            // Honest delivery. No mail or SMS provider is wired, so nothing is ever recorded as
            // 'sent' — see RegistrationVerificationService.
            $table->string('delivery_status', 40)->default('awaiting_provider_credentials');

            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });

        // "Is there a live challenge on this channel?" — asked on every resend and every check.
        DB::statement('CREATE INDEX registration_verifications_live_index
            ON registration_verifications (registration_request_id, channel)
            WHERE consumed_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_verifications');
    }
};
