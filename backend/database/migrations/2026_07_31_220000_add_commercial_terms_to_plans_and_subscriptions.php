<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a plan COSTS, and what a subscription to it is actually doing (PLAN-001).
 *
 * The catalogue could previously express one price per month and nothing else — no annual term, no
 * trial, no statement of which plans a visitor may even choose. Everything downstream of it (the
 * pricing page, the registration policy, the payment amount, the renewal date) therefore had to
 * invent its own answer, and the contract requires those answers to come from one editable place.
 *
 * The trial columns sit on the PLAN rather than on a global setting because the contract makes the
 * trial's fee, length and limits per-plan and editable from /admin. A trial with no fee is expressed
 * by `trial_fee = 0`, and a plan with no trial by `trial_days = 0` — both are statements, not gaps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            // Arabic-first product: the plan's name is shown to customers, so it has both.
            $table->string('name_ar')->nullable()->after('name');
            $table->text('summary_ar')->nullable()->after('name_ar');
            $table->text('summary_en')->nullable()->after('summary_ar');

            /*
             * The annual price is the TOTAL for a year, not a monthly equivalent.
             *
             * A discount expressed as "SAR 416/month billed annually" is a presentation of this
             * number; storing the presentation instead would mean the amount actually charged had to
             * be recomputed from a rounded figure. Null means the plan is not sold annually.
             */
            $table->decimal('price_annual', 15, 2)->nullable()->after('price_monthly');

            // The paid trial. A symbolic fee, taken through the same gateway as everything else,
            // which is what makes the trial a PAYMENT event and therefore verifiable by webhook.
            $table->decimal('trial_fee', 15, 2)->default(0)->after('price_annual');
            $table->unsignedSmallInteger('trial_days')->default(0)->after('trial_fee');
            // Limits that apply DURING the trial, when they are tighter than the plan's own.
            $table->jsonb('trial_limits')->nullable()->after('limits');

            /*
             * Whether a visitor may choose this plan when signing up.
             *
             * Separate from `is_active`: a plan that has been withdrawn from sale must keep working
             * for everyone already on it, and conflating the two would either strand those customers
             * or keep offering something we no longer sell.
             */
            $table->boolean('is_public')->default(false)->after('is_active');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_public');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            // Which term this subscription is on. The plan holds both prices; this says which one
            // was bought, and therefore what renewal costs and when it falls due.
            $table->string('billing_interval', 16)->default('monthly')->after('status');

            /*
             * What this subscriber actually pays, captured when they were sold the plan.
             *
             * Without it, a subscription is only a pointer at a catalogue row, so editing a price in
             * /admin would silently re-price everyone already on that plan at their next renewal.
             * The console needs to be able to change a price — the contract requires it — and this
             * is what makes that safe: the catalogue governs what NEW customers are quoted, and this
             * column governs what an existing one owes.
             */
            $table->decimal('unit_amount', 15, 2)->nullable()->after('billing_interval');
            $table->string('currency', 3)->nullable()->after('unit_amount');

            $table->timestampTz('trial_ends_at')->nullable()->after('current_period_end');
            /*
             * How long a failed renewal keeps working before the account is suspended.
             *
             * A column rather than a global constant because grace is a commercial decision that
             * differs per customer, and because "when does this specific account stop working?" must
             * be answerable by looking at the row rather than by re-deriving it from config that may
             * have changed since.
             */
            $table->timestampTz('grace_ends_at')->nullable()->after('trial_ends_at');

            /*
             * The customer's explicit agreement that the trial converts into a paid subscription.
             *
             * The contract requires consent to auto-conversion to be explicit, and a boolean would
             * not record WHEN it was given. A null here means no trial may convert — the charge
             * would be one nobody agreed to.
             */
            $table->timestampTz('auto_convert_consent_at')->nullable()->after('grace_ends_at');
            $table->boolean('cancel_at_period_end')->default(false)->after('auto_convert_consent_at');

            // Set by the adapter that owns this subscription. No card data — identifiers the provider
            // issued, which is the only part of a payment method this system is allowed to hold.
            $table->string('provider', 32)->nullable()->after('cancel_at_period_end');
            $table->string('provider_customer_id')->nullable()->after('provider');
            $table->string('provider_subscription_id')->nullable()->after('provider_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'name_ar', 'summary_ar', 'summary_en', 'price_annual',
                'trial_fee', 'trial_days', 'trial_limits', 'is_public', 'sort_order',
            ]);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn([
                'billing_interval', 'unit_amount', 'currency', 'trial_ends_at', 'grace_ends_at',
                'auto_convert_consent_at', 'cancel_at_period_end',
                'provider', 'provider_customer_id', 'provider_subscription_id',
            ]);
        });
    }
};
