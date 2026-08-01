<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a mid-term plan change needs to be arithmetic rather than guesswork (PAY-002).
 *
 * Two things were missing. The first is `current_period_start`: proration is a fraction of a period,
 * and a period with only an end is a period whose length has to be assumed. Assuming it from the
 * billing interval is wrong the moment a period was ever extended — a grace period, an exceptional
 * concession from the review queue — and being wrong here means charging a customer the wrong money.
 *
 * The second is somewhere to record a change that has been AGREED but not yet applied. A downgrade
 * takes effect at the end of the period the customer has already paid for; without these columns the
 * only way to honour that would be to apply it immediately and quietly keep the difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestampTz('current_period_start')->nullable()->after('status');

            // The agreed-but-not-yet-effective change. Null on a subscription with nothing pending,
            // which is almost all of them.
            $table->uuid('scheduled_plan_id')->nullable()->after('plan_id');
            $table->string('scheduled_billing_interval', 16)->nullable()->after('scheduled_plan_id');
            $table->decimal('scheduled_unit_amount', 12, 2)->nullable()->after('scheduled_billing_interval');
            $table->timestampTz('scheduled_change_at')->nullable()->after('scheduled_unit_amount');

            $table->foreign('scheduled_plan_id')->references('id')->on('subscription_plans')->nullOnDelete();
        });

        /*
         * Backfill the start from the end and the interval.
         *
         * This is the assumption the column exists to stop making — but it is the only information
         * that exists for rows written before it, and leaving them null would make every existing
         * subscription un-prorateable. New periods set it explicitly from here on.
         */
        DB::statement("
            UPDATE subscriptions
               SET current_period_start = CASE
                     WHEN billing_interval = 'annual' THEN current_period_end - INTERVAL '1 year'
                     ELSE current_period_end - INTERVAL '1 month'
                   END
             WHERE current_period_start IS NULL
               AND current_period_end IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['scheduled_plan_id']);
            $table->dropColumn([
                'current_period_start',
                'scheduled_plan_id',
                'scheduled_billing_interval',
                'scheduled_unit_amount',
                'scheduled_change_at',
            ]);
        });
    }
};
