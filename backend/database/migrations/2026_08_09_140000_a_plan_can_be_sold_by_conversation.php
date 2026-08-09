<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise is quoted, not priced — LAUNCH-PRICING-001.
 *
 * `price_monthly` is NOT NULL, so a plan without a published price could only have been expressed as
 * `0.00`, and 0.00 already means something else here: free. «There is no free tier» is a rule this
 * product tests for, and a contact-sales plan priced at zero would either break that test or force it
 * to be weakened — which is how a real rule becomes a decorative one.
 *
 * So the absence of a price is stated as a fact of its own. A `contact_sales` plan publishes no
 * figure, cannot be chosen at checkout, and is offered as a conversation.
 *
 * Also renames `scale` to `agency`. Checked first rather than assumed: `tenants.subscription_plan` is
 * only ever displayed or grouped by — never compared against a literal — and plan codes share no
 * namespace with `account_type` or the portals, both of which already use the word «agency» for
 * different things. So the rename is safe, and `agency` is what the plan is actually for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('contact_sales')->default(false)->after('is_public');
        });

        // Existing subscriptions point at the plan by id, so the rename carries them with it.
        DB::table('subscription_plans')->where('code', 'scale')->update(['code' => 'agency']);
        DB::table('tenants')->where('subscription_plan', 'scale')->update(['subscription_plan' => 'agency']);
        DB::table('registration_requests')->where('plan_code', 'scale')->update(['plan_code' => 'agency']);
        DB::table('subscription_payments')->where('plan_code', 'scale')->update(['plan_code' => 'agency']);
    }

    public function down(): void
    {
        DB::table('subscription_payments')->where('plan_code', 'agency')->update(['plan_code' => 'scale']);
        DB::table('registration_requests')->where('plan_code', 'agency')->update(['plan_code' => 'scale']);
        DB::table('tenants')->where('subscription_plan', 'agency')->update(['subscription_plan' => 'scale']);
        DB::table('subscription_plans')->where('code', 'agency')->update(['code' => 'scale']);

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('contact_sales');
        });
    }
};
