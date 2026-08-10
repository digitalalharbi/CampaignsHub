<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-TOKEN-001 — the saved payment method a renewal can actually charge.
 *
 * ## What this table is for
 *
 * A renewal today opens an INVOICE — a page somebody has to visit and pay. Nobody visits it, so the
 * period lapses, `markPastDue` fires, and a customer who fully intended to keep paying is told their
 * account is past due. That is not a dunning problem; it is the absence of unattended billing.
 *
 * Charging without the customer present needs a token the gateway issued for their card, and that
 * token is the only thing here that can move money. Everything else on the row exists so a person
 * can recognise which card it is.
 *
 * ## What is NEVER stored
 *
 * No PAN. No CVC. No expiry beyond the month and year the gateway itself publishes for display. The
 * token is the credential and it is encrypted at rest; the brand and last four digits are labels,
 * and they are the only card-ish thing a human here will ever see. A schema that cannot hold a card
 * number is a stronger guarantee than a policy that says nobody will put one there.
 *
 * ## Why `provider` is on the row
 *
 * A token is meaningless to any gateway but the one that minted it. Charging a Moyasar token through
 * Stripe is not an error that fails cleanly — it is a lookup against the wrong vault, and the answer
 * is «no such token», which reads as «the customer's card was declined».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payment_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();

            $table->string('provider', 40);

            /*
             * The token, encrypted at rest by the model's cast.
             *
             * Long, because providers differ and several publish opaque strings well past 255. A
             * column too short to hold a real token truncates it silently and every renewal fails
             * with a declined card that was never declined.
             */
            $table->text('provider_token');
            $table->string('provider_customer_id')->nullable();

            // Labels, for a human. Never used to authorise anything.
            $table->string('brand', 40)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedSmallInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();

            /*
             * One default per tenant is enforced in the service rather than by a partial unique
             * index, because «exactly one» has to survive the moment BETWEEN unsetting the old
             * default and setting the new one — a constraint would reject the intermediate state and
             * the swap would need a transaction to work around its own guard.
             */
            $table->boolean('is_default')->default(false);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('detached_at')->nullable();
            $table->timestamps();

            // The lookup every renewal does: this tenant's usable method for this gateway.
            $table->index(['tenant_id', 'provider', 'detached_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payment_methods');
    }
};
