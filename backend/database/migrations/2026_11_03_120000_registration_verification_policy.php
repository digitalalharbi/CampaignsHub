<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIGNUP-VERIFY-POLICY-001 — the platform's switches for signup verification, and the fixed answer each application got.
 *
 * Purely additive and nullable, and it writes no row. A null platform policy reads as both channels ON, and a null
 * `effective_policy` reads as both channels required, so deploying this changes nothing for any application, pending or
 * finished, and touches no verified or payment fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->json('registration_verification_policy')->nullable();
        });

        Schema::table('registration_requests', function (Blueprint $table): void {
            // The switches as they stood when this application was opened. Null means opened before they existed.
            $table->json('effective_policy')->nullable();
            // The approval as a fact of its own, so a gate re-entered later does not silently un-approve the application.
            $table->timestamp('approved_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            // Not a verification: the opposite. Says the address was never proved because policy did not ask, so nothing
            // may treat it as proof of possession. `email_verified_at` stays the only claim of proof.
            $table->timestamp('email_unproven_by_policy_at')->nullable();
        });

        // Append-only. `registration_verifications` deletes a challenge on resend, so it cannot count what was sent.
        Schema::create('registration_send_log', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('registration_request_id')->nullable()->index();
            $table->string('channel', 16);
            // reserved, then the provider's outcome; or blocked_global_cap / blocked_application_cap, with nothing sent.
            $table->string('outcome', 48);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_send_log');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('email_unproven_by_policy_at');
        });

        Schema::table('registration_requests', function (Blueprint $table): void {
            $table->dropColumn(['effective_policy', 'approved_at']);
        });

        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->dropColumn('registration_verification_policy');
        });
    }
};
