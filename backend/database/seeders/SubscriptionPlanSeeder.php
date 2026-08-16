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
                'summary_ar' => 'للفرد أو المتجر الذي يدير حملاته بنفسه.',
                'summary_en' => 'For an individual or a store running their own campaigns.',
                /*
                 * PLAN-PAID-001 — the entry plan is sold, not given away.
                 *
                 * A free tier was the one way into the product that owed nothing, and an application
                 * that owes nothing clears the payment gate by having no payment to verify. Pricing
                 * it means every new workspace in the system arrives the same way: through a settled
                 * payment confirmed by a signed webhook.
                 */
                'price_monthly' => 19,
                // The annual term, priced at ten months for twelve — the same shape as Growth and
                // Scale. The console edits it (PATCH /admin/plans/{plan}); this is only the opening
                // value a fresh database starts from.
                'price_annual' => 190,
                'currency' => 'USD',
                /*
                 * No introductory offer on this plan — the owner's pricing of 2026-08-09.
                 *
                 * Not a free period either: it is sold at its own price from the first day. The
                 * introductory month belongs to Growth alone, which is also the only plan carrying a
                 * minimum commitment — the two are one offer and neither exists without the other.
                 */
                'trial_fee' => 0,
                'trial_days' => 0,
                'minimum_commitment_months' => 0,
                'trial_limits' => ['projects' => 3, 'clients' => 1, 'team_members' => 3, 'connections' => 3, 'ad_accounts' => 3, 'reports_per_month' => 10],
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
                'limits' => ['projects' => 3, 'clients' => 1, 'team_members' => 3, 'connections' => 3, 'ad_accounts' => 3, 'reports_per_month' => 10],
            ],
            [
                'code' => 'growth',
                'name' => 'Growth',
                'name_ar' => 'النمو',
                'summary_ar' => 'لعدة مشاريع أو فريق صغير.',
                'summary_en' => 'For several projects or a small team.',
                'price_monthly' => 49,
                // Two months' saving on the annual term, stated as the TOTAL actually charged.
                'price_annual' => 490,
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
                /*
                 * What stands behind the introductory price — SUB-COMMIT-001.
                 *
                 * Month one at 9, months two and three at the full price. Without it the offer is an
                 * arbitrage: subscribe at 9, cancel on day 29, repeat. Editable from `/admin` beside
                 * the two prices, because the discount and the commitment are one decision.
                 */
                'minimum_commitment_months' => 3,
                // A trial is a look at the product, not a quarter of free capacity.
                'trial_limits' => ['projects' => 3, 'clients' => 1, 'team_members' => 3, 'connections' => 3, 'ad_accounts' => 3, 'reports_per_month' => 10],
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
                'limits' => ['projects' => 25, 'clients' => 5, 'team_members' => 15, 'connections' => 25, 'ad_accounts' => 25, 'reports_per_month' => 100],
            ],
            [
                'code' => 'agency',
                'name' => 'Agency',
                'name_ar' => 'الوكالة',
                'summary_ar' => 'لإدارة عدة عملاء وفريق وتقارير العملاء.',
                'summary_en' => 'For managing several clients, a team, and client reports.',
                'price_monthly' => 99,
                'price_annual' => 990,
                'currency' => 'USD',
                /*
                 * No introductory offer on this plan — the owner's pricing of 2026-08-09.
                 *
                 * Not a free period either: it is sold at its own price from the first day. The
                 * introductory month belongs to Growth alone, which is also the only plan carrying a
                 * minimum commitment — the two are one offer and neither exists without the other.
                 */
                'trial_fee' => 0,
                'trial_days' => 0,
                'minimum_commitment_months' => 0,
                'trial_limits' => ['projects' => 5, 'clients' => 2, 'team_members' => 5, 'connections' => 5, 'ad_accounts' => 5, 'reports_per_month' => 20],
                'sort_order' => 30,
                'features' => [
                    'campaign_tracking' => true,
                    'reports' => true,
                    'support' => 'priority',
                    'ai_assist' => true,
                    'white_label' => true,
                ],
                // null == unlimited. Scale is the most permissive plan and the no-subscription default.
                'limits' => ['projects' => null, 'clients' => null, 'team_members' => null, 'connections' => null, 'ad_accounts' => null, 'reports_per_month' => null],
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'name_ar' => 'المؤسسات',
                'summary_ar' => 'احتياجات خاصة: حدود ومتطلبات تُتفق عليها معك.',
                'summary_en' => 'Particular needs: limits and requirements agreed with you.',
                /*
                 * Quoted, not priced — LAUNCH-PRICING-001.
                 *
                 * `contact_sales` is what makes the zeroes below mean «no published price» rather
                 * than «free». Nothing quotes this plan and nothing can check out on it; the card
                 * offers a conversation instead of a button. Recording the absence as a fact keeps
                 * «there is no free tier» a real rule rather than one with an exception in it.
                 */
                'contact_sales' => true,
                /*
                 * Held back from signup — the owner's decision of 2026-08-09.
                 *
                 * Enterprise exists in the catalogue and in `/admin`, ready for the day there is a
                 * sales conversation to have, and it is not offered on the signup screen today. That
                 * is what `is_public` is FOR: a plan that is real but not on sale. Setting it here
                 * rather than filtering the card out in the interface means one answer to «may
                 * somebody buy this?» — `isOffered()` refuses it at checkout too, so it cannot be
                 * reached by typing the code into a URL either.
                 */
                'is_public' => false,
                'price_monthly' => 0,
                'price_annual' => null,
                'currency' => 'USD',
                'trial_fee' => 0,
                'trial_days' => 0,
                'minimum_commitment_months' => 0,
                'sort_order' => 40,
                'features' => [
                    'campaign_tracking' => true,
                    'reports' => true,
                    'support' => 'priority',
                    'ai_assist' => true,
                    'white_label' => true,
                ],
                // Agreed per customer, so nothing is published as a cap.
                'limits' => ['projects' => null, 'clients' => null, 'team_members' => null, 'connections' => null, 'ad_accounts' => null, 'reports_per_month' => null],
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
