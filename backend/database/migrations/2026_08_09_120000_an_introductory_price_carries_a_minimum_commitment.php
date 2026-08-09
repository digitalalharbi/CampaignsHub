<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUB-COMMIT-001 — an introductory price that cannot be taken and dropped the same week.
 *
 * A paid first month at a symbolic price is an offer, and an offer with nothing behind it is an
 * arbitrage: subscribe at 9, cancel on day 29, repeat. The commitment is what makes the discount a
 * real commercial term rather than a hole — the customer gets the cheap month and agrees to the two
 * that follow at the ordinary price.
 *
 * Two columns, and they are deliberately in different places:
 *
 *   - `subscription_plans.minimum_commitment_months` is the OFFER's term, editable from `/admin`
 *     beside the intro price and the regular price, because they are one commercial decision.
 *   - `subscriptions.commitment_ends_at` is THIS customer's date, fixed when they paid. Editing the
 *     plan later must not move a commitment somebody already agreed to, for exactly the reason
 *     `unit_amount` is captured on the subscription rather than read from the catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            // 0 = no commitment. Annual is sold outright and never acquires one.
            $table->unsignedTinyInteger('minimum_commitment_months')->default(0)->after('trial_days');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            /*
             * Null means "no commitment", not "expired".
             *
             * A past date is a commitment that has been served and is a different state from never
             * having had one — the first can be shown to a customer as «your commitment ended on…»,
             * and collapsing them would lose that.
             */
            $table->timestampTz('commitment_ends_at')->nullable()->after('trial_ends_at');

            /*
             * The terms were shown and agreed, with a time.
             *
             * `auto_convert_consent_at` already records consent to the conversion; this records
             * consent to the COMMITMENT, which is a different promise — one is «you may charge me
             * when the month ends», the other is «I will be here for three of them». Recorded when
             * rather than merely that, so a dispute can be answered with a date.
             */
            $table->timestampTz('commitment_consent_at')->nullable()->after('auto_convert_consent_at');
        });

        /*
         * The consent is given during the APPLICATION, before there is a subscription to hang it on.
         *
         * So it is recorded here first and copied onto the subscription when the payment settles —
         * the same shape as the plan and the billing interval, which are also chosen by an applicant
         * who does not yet have an account.
         */
        Schema::table('registration_requests', function (Blueprint $table) {
            $table->timestampTz('commitment_consent_at')->nullable()->after('mobile_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('minimum_commitment_months');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['commitment_ends_at', 'commitment_consent_at']);
        });

        Schema::table('registration_requests', function (Blueprint $table) {
            $table->dropColumn('commitment_consent_at');
        });
    }
};
