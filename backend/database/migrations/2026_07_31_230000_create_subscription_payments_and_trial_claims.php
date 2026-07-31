<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own money, and the trials it has already given away (PAY-002, PAY-004).
 *
 * `subscription_payments` is deliberately NOT the existing `payments` table. That one records what a
 * TENANT invoices its own clients; this one records what a tenant pays CampaignsHub. The contract
 * keeps the revenue streams separate, and one table for both would make "revenue" a number that
 * answers neither question. They share the adapters and the invariants, not the ledger.
 *
 * A row here can belong to a REGISTRATION (the trial fee, taken before any tenant exists) or to a
 * SUBSCRIPTION (a renewal). Both columns are nullable and exactly one is set — which is why this is
 * not simply hung off `subscriptions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Which TERM the applicant asked for.
         *
         * The plan holds both prices; this says which one they chose, and therefore what the trial
         * converts into. Without it the conversion would have to guess, and guessing between a month
         * and a year is a twelve-fold error in either direction.
         */
        Schema::table('registration_requests', function (Blueprint $table): void {
            $table->string('billing_interval', 16)->nullable()->after('plan_code');
        });

        Schema::create('subscription_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Exactly one of these. A trial fee is owed by an applicant who has no tenant yet; a
            // renewal is owed by a workspace that does.
            $table->foreignUuid('registration_request_id')->nullable()
                ->constrained('registration_requests')->nullOnDelete();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            // What this charge IS, so a refund of a trial fee is not mistaken for a refund of a year.
            $table->string('purpose', 32); // trial | subscription | reactivation
            $table->string('plan_code', 64)->nullable();
            $table->string('billing_interval', 16)->nullable();

            $table->string('provider', 32);
            $table->string('provider_session_id')->nullable();
            $table->string('provider_payment_id')->nullable();
            // Where the customer goes to pay. Kept so a retried checkout hands back the SAME page
            // rather than opening a second one at the gateway.
            $table->text('checkout_url')->nullable();

            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);

            /*
             * pending → processing → paid | failed | refunded | disputed.
             *
             * It starts `pending` and ONLY a verified webhook moves it to `paid`. Returning from the
             * gateway's page is not a status: the browser can be closed, faked, or replayed.
             */
            $table->string('status', 24)->default('pending');

            /*
             * The reference we hand the gateway and read back off the event.
             *
             * Unique, and generated per charge — this is what makes a retried checkout return the
             * SAME payment instead of opening a second one, which is how a customer gets billed twice
             * for one thing.
             */
            $table->string('idempotency_key')->unique();

            $table->text('error')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'purpose']);
            $table->index('provider_payment_id');
        });

        /*
         * One trial per identity (PAY-004).
         *
         * Every value is HASHED. The question this table answers is "has this been seen before?",
         * which needs no plaintext — and a table of customer emails, phone numbers and card
         * fingerprints is exactly the thing not to keep in recoverable form.
         *
         * `kind` says WHICH identity: email, phone, company, payment_method. A trial writes one row
         * per identity it can establish, so a second attempt is caught by whichever of them repeats.
         */
        Schema::create('trial_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 24);
            $table->char('value_hash', 64);

            // Kept for the review queue: an operator settling a suspicious case needs to see what the
            // earlier trial was, without the identity itself being readable.
            $table->foreignUuid('registration_request_id')->nullable()
                ->constrained('registration_requests')->nullOnDelete();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();

            $table->timestampsTz();
        });

        // The lookup this table exists for, and the constraint that makes a duplicate impossible
        // rather than merely detected.
        DB::statement('CREATE UNIQUE INDEX trial_claims_identity_unique ON trial_claims (kind, value_hash)');
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_claims');
        Schema::dropIfExists('subscription_payments');
        Schema::table('registration_requests', function (Blueprint $table): void {
            $table->dropColumn('billing_interval');
        });
    }
};
