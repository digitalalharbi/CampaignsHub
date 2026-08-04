<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PLAN-PAID-001 — the free plan is withdrawn, and «البداية» is sold.
 *
 * Starter used to be 0 SAR with no annual term, which made it the one way into the product that
 * skipped the payment gate entirely: an application on a free plan owes nothing, so nothing verifies,
 * so a workspace appears. Every other guarantee in the registration path — a signed webhook, a
 * settled payment row, `ProvisionWorkspace` refusing an unpaid application — was reachable only by
 * the customers who happened to pick a paid plan.
 *
 * The prices go in the CATALOGUE rather than in a constant, because the console edits them
 * (`PATCH /admin/plans/{plan}`) and a literal anywhere else would be a second, disagreeing answer.
 * 990 for the year is the same two-months-free shape Growth and Scale already carry.
 *
 * Written as an update rather than a re-seed so an environment whose owner has already tuned the
 * limits or the copy keeps them: only the commercial terms move.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('subscription_plans')->where('code', 'starter')->first();

        if ($row === null) {
            return; // A fresh database gets the priced plan from the seeder instead.
        }

        $features = json_decode((string) ($row->features ?? '{}'), true);
        $features = is_array($features) ? $features : [];

        DB::table('subscription_plans')->where('code', 'starter')->update([
            'price_monthly' => 99,
            'price_annual' => 990,
            'currency' => 'SAR',
            'summary_ar' => 'لمن يدير حملاته بنفسه: متابعة الحملات والتقارير.',
            'summary_en' => 'For someone running their own campaigns: campaign tracking and reports.',
            /*
             * What the plan INCLUDES, stated as data.
             *
             * The brief names campaign tracking and reports specifically, and a plan whose price is
             * enforced from the catalogue while its contents live in a paragraph is a plan whose
             * contents nothing can check.
             */
            'features' => json_encode($features + [
                'campaign_tracking' => true,
                'reports' => true,
                'support' => 'community',
                'ai_assist' => false,
                'white_label' => false,
            ]),
            'updated_at' => now(),
        ]);
    }

    /**
     * Back to free.
     *
     * Kept honest rather than convenient: rolling this back re-opens the unpaid route into the
     * product, which is exactly what `up()` closes.
     */
    public function down(): void
    {
        DB::table('subscription_plans')->where('code', 'starter')->update([
            'price_monthly' => 0,
            'price_annual' => null,
            'updated_at' => now(),
        ]);
    }
};
