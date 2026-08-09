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
                'summary_ar' => 'لمن يدير حملاته بنفسه: متابعة الحملات والتقارير.',
                'summary_en' => 'For someone running their own campaigns: campaign tracking and reports.',
                /*
                 * PLAN-PAID-001 — the entry plan is sold, not given away.
                 *
                 * A free tier was the one way into the product that owed nothing, and an application
                 * that owes nothing clears the payment gate by having no payment to verify. Pricing
                 * it means every new workspace in the system arrives the same way: through a settled
                 * payment confirmed by a signed webhook.
                 */
                'price_monthly' => 29,
                // The annual term, priced at ten months for twelve — the same shape as Growth and
                // Scale. The console edits it (PATCH /admin/plans/{plan}); this is only the opening
                // value a fresh database starts from.
                'price_annual' => 290,
                'currency' => 'USD',
                /*
                 * The introductory month, on the entry plan too — PAY-AUDIT-003.
                 *
                 * This plan deliberately had none: the argument was that «البداية» IS the affordable
                 * way in, and an offer on top of it would be a second, cheaper front door to the same
                 * thing. The owner's decision replaces that reasoning — every plan now opens with a
                 * paid first month at an introductory price, and there is no free trial anywhere.
                 *
                 * `trial_fee` is a PRICE, not a token: it is what the first month costs, taken
                 * through the same gateway as any other charge, which is what keeps «no activation
                 * before verified payment» true of the introductory month as well.
                 */
                'trial_fee' => 9,
                'trial_days' => 30,
                'trial_limits' => ['projects' => 3, 'team_members' => 3, 'connections' => 3, 'reports_per_month' => 10],
                'sort_order' => 10,
                // Campaign tracking and reports are what this plan is sold on, so they are data the
                // catalogue carries rather than a claim in a paragraph.
                'features' => [
                    'campaign_tracking' => true,
                    'reports' => true,
                    'support' => 'community',
                    'ai_assist' => false,
                    'white_label' => false,
                ],
                'limits' => ['projects' => 3, 'team_members' => 3, 'connections' => 3, 'reports_per_month' => 10],
            ],
            [
                'code' => 'growth',
                'name' => 'Growth',
                'name_ar' => 'النمو',
                'summary_ar' => 'للفرق والوكالات الصغيرة التي تدير عدة مشاريع وعملاء.',
                'summary_en' => 'For teams and small agencies running several projects and clients.',
                'price_monthly' => 149,
                // Two months' saving on the annual term, stated as the TOTAL actually charged.
                'price_annual' => 1490,
                'currency' => 'USD',
                /*
                 * The paid 7-day trial (PLAN-001).
                 *
                 * A symbolic fee rather than a free trial, and taken through the same gateway as any
                 * other charge — which is what makes starting a trial a PAYMENT event, verifiable by
                 * a signed webhook, and what gives trial-abuse prevention a payment method to
                 * fingerprint. Both numbers are editable from /admin.
                 */
                'trial_fee' => 9,
                'trial_days' => 30,
                // A trial is a look at the product, not a quarter of free capacity.
                'trial_limits' => ['projects' => 3, 'team_members' => 3, 'connections' => 3, 'reports_per_month' => 10],
                'sort_order' => 20,
                // A higher plan includes everything the lower one does. The console renders these as
                // switches now, and a Growth plan without the campaign tracking «البداية» sells would
                // read as an error to any customer comparing the two — because it is one.
                'features' => [
                    'campaign_tracking' => true,
                    'reports' => true,
                    'support' => 'email',
                    'ai_assist' => true,
                    'white_label' => false,
                ],
                'limits' => ['projects' => 25, 'team_members' => 15, 'connections' => 25, 'reports_per_month' => 100],
            ],
            [
                'code' => 'scale',
                'name' => 'Scale',
                'name_ar' => 'التوسع',
                'summary_ar' => 'للوكالات الكبيرة: بلا حدود على المشاريع والفريق والتقارير.',
                'summary_en' => 'For larger agencies: no caps on projects, team or reports.',
                'price_monthly' => 449,
                'price_annual' => 4490,
                'currency' => 'USD',
                'trial_fee' => 9,
                'trial_days' => 30,
                'trial_limits' => ['projects' => 5, 'team_members' => 5, 'connections' => 5, 'reports_per_month' => 20],
                'sort_order' => 30,
                'features' => [
                    'campaign_tracking' => true,
                    'reports' => true,
                    'support' => 'priority',
                    'ai_assist' => true,
                    'white_label' => true,
                ],
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
