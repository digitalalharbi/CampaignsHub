<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * The global plan catalogue (starter | growth | scale). Idempotent (upsert by code) and structural — safe in
 * every environment. `limits` are the per-metric caps; `scale` is the most permissive (unlimited where the cap
 * is null), which is also the default a tenant with no subscription falls back to.
 */
final class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'name_ar' => 'البداية',
                'summary_ar' => 'لمن يدير حملاته بنفسه ويحتاج أساسيات المتابعة والتقارير.',
                'summary_en' => 'For someone running their own campaigns who needs the basics.',
                'price_monthly' => 0,
                // Free plans are not sold on an annual term — there is nothing to bill for a year.
                'price_annual' => null,
                'currency' => 'SAR',
                // A free plan starts free. No trial fee, because there is nothing to trial INTO.
                'trial_fee' => 0,
                'trial_days' => 0,
                'sort_order' => 10,
                'features' => ['support' => 'community', 'ai_assist' => false, 'white_label' => false],
                'limits' => ['projects' => 3, 'team_members' => 3, 'connections' => 3, 'reports_per_month' => 10],
            ],
            [
                'code' => 'growth',
                'name' => 'Growth',
                'name_ar' => 'النمو',
                'summary_ar' => 'للفرق والوكالات الصغيرة التي تدير عدة مشاريع وعملاء.',
                'summary_en' => 'For teams and small agencies running several projects and clients.',
                'price_monthly' => 499,
                // Two months' saving on the annual term, stated as the TOTAL actually charged.
                'price_annual' => 4990,
                'currency' => 'SAR',
                /*
                 * The paid 7-day trial (PLAN-001).
                 *
                 * A symbolic fee rather than a free trial, and taken through the same gateway as any
                 * other charge — which is what makes starting a trial a PAYMENT event, verifiable by
                 * a signed webhook, and what gives trial-abuse prevention a payment method to
                 * fingerprint. Both numbers are editable from /admin.
                 */
                'trial_fee' => 9,
                'trial_days' => 7,
                // A trial is a look at the product, not a quarter of free capacity.
                'trial_limits' => ['projects' => 3, 'team_members' => 3, 'connections' => 3, 'reports_per_month' => 10],
                'sort_order' => 20,
                'features' => ['support' => 'email', 'ai_assist' => true, 'white_label' => false],
                'limits' => ['projects' => 25, 'team_members' => 15, 'connections' => 25, 'reports_per_month' => 100],
            ],
            [
                'code' => 'scale',
                'name' => 'Scale',
                'name_ar' => 'التوسع',
                'summary_ar' => 'للوكالات الكبيرة: بلا حدود على المشاريع والفريق والتقارير.',
                'summary_en' => 'For larger agencies: no caps on projects, team or reports.',
                'price_monthly' => 1499,
                'price_annual' => 14990,
                'currency' => 'SAR',
                'trial_fee' => 9,
                'trial_days' => 7,
                'trial_limits' => ['projects' => 5, 'team_members' => 5, 'connections' => 5, 'reports_per_month' => 20],
                'sort_order' => 30,
                'features' => ['support' => 'priority', 'ai_assist' => true, 'white_label' => true],
                // null == unlimited. Scale is the most permissive plan and the no-subscription default.
                'limits' => ['projects' => null, 'team_members' => null, 'connections' => null, 'reports_per_month' => null],
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['code' => $plan['code']],
                // Every seeded plan is on sale. `is_public` is what a withdrawn plan turns off, and
                // it is separate from `is_active` so existing customers keep working.
                $plan + ['is_active' => true, 'is_public' => true],
            );
        }
    }
}
