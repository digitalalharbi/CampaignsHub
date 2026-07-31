<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Telling people what happened to their account and their money (NOTIF-SUB-001).
 *
 * Deliberately NOT `app_notifications`. That table is tenant-scoped with a NOT NULL foreign key, and
 * the first person who ever needs to hear from us is an APPLICANT — somebody whose trial fee is owed
 * before any tenant exists. A nullable `tenant_id` bolted onto the existing table would have made
 * every tenant-scoped read of it fail-open, which is the one thing that layer must never do.
 *
 * So this is its own ledger, addressed by EMAIL rather than by membership, and it records what was
 * actually attempted rather than what was intended. Three states matter and are kept apart:
 *
 *   - `awaiting_credentials` — no mail provider is configured. Nothing was sent, and nothing pretends
 *     otherwise.
 *   - `sandbox` — a provider IS configured but it is a local one (log, array). Something happened,
 *     and it did not reach a human being.
 *   - `sent` — a real transport accepted it.
 *
 * Collapsing those three into "sent" is the exact dishonesty the contract forbids, and it is easy to
 * do by accident because all three are the happy path from the caller's point of view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Who it is about. All three are nullable: an applicant has no tenant and no user, and a
            // suspended workspace has no registration request.
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('registration_request_id')->nullable()
                ->constrained('registration_requests')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Where it goes. Held even when a user row exists, because an address can change after a
            // message was sent and the ledger has to say where it actually went.
            $table->string('to_email');
            $table->string('locale', 5)->default('ar');

            // trial_started | trial_ending | trial_converted | payment_confirmed | renewal_failed |
            // past_due | suspended | reactivated | registration_approved | registration_rejected
            $table->string('event', 48);
            $table->string('channel', 16)->default('email');

            // Rendered at dispatch, not at send: the message a customer received must not silently
            // change because a template was edited afterwards.
            $table->string('subject', 300);
            $table->text('body');

            /*
             * awaiting_credentials | sandbox | sent | failed | queued
             *
             * Starts `queued`. Everything else is what the transport actually did.
             */
            $table->string('status', 32)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->nullable();

            // What the template was given, kept so a message can be explained after the fact.
            $table->jsonb('context')->nullable();

            /*
             * What OCCASION this message is about — not merely what kind of message it is.
             *
             * `renewal_failed:{subscription}:{period}` rather than `renewal_failed`, because next
             * month's failure is a different occasion and the customer must hear about it. The key is
             * built by the caller, which is the only thing that knows what makes two events the same.
             */
            $table->string('dedup_key');

            $table->timestampsTz();

            $table->index(['tenant_id', 'event']);
            $table->index('status');
        });

        /*
         * One message per occasion.
         *
         * The lifecycle sweep is safe to run twice by design, and a customer receiving "your card was
         * refused" twice a day for a week is how a correct system becomes an unbearable one. The
         * uniqueness is at the database rather than in a remembered check — and it is on the OCCASION,
         * so next month's failure is still delivered.
         */
        DB::statement('CREATE UNIQUE INDEX subscription_notifications_dedup
            ON subscription_notifications (dedup_key)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_notifications');
    }
};
