<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Subscriptions\Models\PlanCatalogueVersion;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * The plan catalogue — PRICING-CATALOGUE-001.
 *
 * ## Create, never overwrite
 *
 * `platform:provision` runs this on EVERY deploy. It used `updateOrCreate`, so a price, limit or name the owner edited in
 * /admin was silently put back on the next deploy. It now creates a plan only when its code is missing and never touches an
 * existing row: once a plan exists, /admin owns it.
 *
 * ## Two catalogues
 *
 * - **v1, the launch catalogue (USD)** — Starter, Growth, Agency, Enterprise. WITHDRAWN from sale (`is_public = false`) but
 *   active: everyone already on one keeps their plan, price, currency, renewal date and limits until moved deliberately from
 *   /admin. Grandfathered, never re-priced.
 * - **v2, the 2026-11 catalogue (SAR, VAT-exclusive)** — Solo, Team, Agency, Enterprise, the Owner's pricing of 2026-11-01. The
 *   only one new sign-ups see.
 *
 * Codes are unique across catalogues because tenants, grants and subscriptions refer to a plan by code; the names are what a
 * customer reads. A connection (an OAuth grant) is not billable — `connections` is uncapped; ad accounts are.
 */
final class SubscriptionPlanSeeder extends Seeder
{
    public const LEGACY_VERSION = 1;

    public const CURRENT_VERSION = 2;

    public function run(): void
    {
        $legacy = $this->version(self::LEGACY_VERSION, 'Launch catalogue (USD)', 'USD', 'retired');
        $current = $this->version(self::CURRENT_VERSION, '2026-11 catalogue (SAR)', 'SAR', 'published');

        foreach (self::legacyPlans() as $plan) {
            $this->createIfMissing($plan + ['is_public' => false], $legacy);
        }
        foreach (self::currentPlans() as $plan) {
            $this->createIfMissing($plan, $current);
        }
    }

    private function version(int $number, string $label, string $currency, string $status): PlanCatalogueVersion
    {
        return PlanCatalogueVersion::query()->firstOrCreate(
            ['number' => $number],
            // PRICING-VAT-001 — the launch catalogue was sold all-in; the 2026-11 catalogue is priced VAT-exclusive.
            ['label' => $label, 'currency' => $currency, 'status' => $status, 'published_at' => $status === 'published' ? now() : null,
                'prices_include_vat' => $number === self::LEGACY_VERSION],
        );
    }

    /** @param array<string, mixed> $plan */
    private function createIfMissing(array $plan, PlanCatalogueVersion $version): void
    {
        if (SubscriptionPlan::query()->where('code', $plan['code'])->exists()) {
            return;
        }

        SubscriptionPlan::query()->create($plan + [
            'is_active' => true,
            'is_public' => true,
            'catalogue_version_id' => $version->getKey(),
        ]);
    }

    /** @return list<array<string, mixed>> the 2026-11 SAR catalogue */
    public static function currentPlans(): array
    {
        // PRICING-SEATS-001 — `ad_account_replacements`: how many DIFFERENT accounts may take a released seat per 30 days.
        $seats = fn (?int $projects, ?int $adAccounts, ?int $users, ?int $replacements = null) => [
            'projects' => $projects,
            // A client workspace holds the projects; a plan never caps one below the other.
            'clients' => $projects,
            'team_members' => $users,
            'ad_accounts' => $adAccounts,
            'connections' => null,
            'reports_per_month' => null,
            'ad_account_replacements' => $replacements,
        ];

        return [
            [
                'code' => 'solo', 'name' => 'Solo', 'name_ar' => 'Solo',
                'summary_ar' => 'للمعلن الذي يدير أعماله بنفسه.',
                'summary_en' => 'For an advertiser running their own business.',
                'price_monthly' => 199, 'price_annual' => 1990, 'currency' => 'SAR',
                'trial_fee' => 0, 'trial_days' => 0, 'minimum_commitment_months' => 0, 'sort_order' => 110,
                'features' => [
                    'campaign_tracking' => true, 'reports' => true, 'support' => 'email', 'ai_assist' => false, 'white_label' => false,
                    'journeys' => ['self-service'],
                ],
                'limits' => $seats(1, 5, 1, 1), 'trial_limits' => $seats(1, 5, 1, 1),
            ],
            [
                'code' => 'team', 'name' => 'Team', 'name_ar' => 'Team',
                'summary_ar' => 'لعدة مشاريع وفريق: صلاحيات، تقارير متقدمة ومشاركة العملاء.',
                'summary_en' => 'For several projects and a team: roles, advanced reports and client sharing.',
                'price_monthly' => 699, 'price_annual' => 6990, 'currency' => 'SAR',
                'trial_fee' => 0, 'trial_days' => 0, 'minimum_commitment_months' => 0, 'sort_order' => 120,
                'features' => [
                    'campaign_tracking' => true, 'reports' => true, 'support' => 'email', 'ai_assist' => true, 'white_label' => false,
                    'journeys' => ['self-service', 'multi-client'], 'recommended' => true,
                ],
                'limits' => $seats(5, 20, 3, 3), 'trial_limits' => $seats(5, 20, 3, 3),
            ],
            [
                'code' => 'agency_2026', 'name' => 'Agency', 'name_ar' => 'Agency',
                'summary_ar' => 'لإدارة وكالة بعدة عملاء: علامة بيضاء أساسية وتقارير مجدولة.',
                'summary_en' => 'For an agency with many clients: basic white label and scheduled reports.',
                'price_monthly' => 1799, 'price_annual' => 17990, 'currency' => 'SAR',
                'trial_fee' => 0, 'trial_days' => 0, 'minimum_commitment_months' => 0, 'sort_order' => 130,
                'features' => [
                    'campaign_tracking' => true, 'reports' => true, 'support' => 'priority', 'ai_assist' => true, 'white_label' => true,
                    'journeys' => ['multi-client'],
                ],
                'limits' => $seats(20, 80, 10, 10), 'trial_limits' => $seats(20, 80, 10, 10),
            ],
            [
                'code' => 'enterprise_2026', 'name' => 'Enterprise', 'name_ar' => 'Enterprise',
                'summary_ar' => 'حدود ونطاق وعلامة تجارية واتفاقية خدمة حسب العقد.',
                'summary_en' => 'Limits, domain, branding and a service agreement by contract.',
                // Sold by conversation: no published price, no checkout. The column is NOT NULL, so 0 is stored and never shown.
                'contact_sales' => true,
                'price_monthly' => 0, 'price_annual' => null, 'currency' => 'SAR',
                'trial_fee' => 0, 'trial_days' => 0, 'minimum_commitment_months' => 0, 'sort_order' => 140,
                'features' => [
                    'campaign_tracking' => true, 'reports' => true, 'support' => 'priority', 'ai_assist' => true, 'white_label' => true,
                    'journeys' => [],
                ],
                'limits' => $seats(null, null, null),
            ],
        ];
    }

    /** @return list<array<string, mixed>> the launch catalogue, as it was sold — kept so a fresh install matches production */
    public static function legacyPlans(): array
    {
        return [
            [
                'code' => 'starter', 'name' => 'Starter', 'name_ar' => 'البداية',
                'summary_ar' => 'للفرد أو المتجر الذي يدير حملاته بنفسه.',
                'summary_en' => 'For an individual or a store running their own campaigns.',
                'price_monthly' => 19, 'price_annual' => 190, 'currency' => 'USD',
                'trial_fee' => 0, 'trial_days' => 0, 'minimum_commitment_months' => 0, 'sort_order' => 10,
                'trial_limits' => ['projects' => 3, 'clients' => 1, 'team_members' => 3, 'connections' => 3, 'ad_accounts' => 3, 'reports_per_month' => 10],
                'features' => ['campaign_tracking' => true, 'reports' => true, 'support' => 'community', 'ai_assist' => false, 'white_label' => false],
                'limits' => ['projects' => 3, 'clients' => 1, 'team_members' => 3, 'connections' => 3, 'ad_accounts' => 3, 'reports_per_month' => 10],
            ],
            [
                'code' => 'growth', 'name' => 'Growth', 'name_ar' => 'النمو',
                'summary_ar' => 'لعدة مشاريع أو فريق صغير.',
                'summary_en' => 'For several projects or a small team.',
                'price_monthly' => 49, 'price_annual' => 490, 'currency' => 'USD',
                'trial_fee' => 9, 'trial_days' => 30, 'minimum_commitment_months' => 3, 'sort_order' => 20,
                'trial_limits' => ['projects' => 3, 'clients' => 1, 'team_members' => 3, 'connections' => 3, 'ad_accounts' => 3, 'reports_per_month' => 10],
                'features' => ['campaign_tracking' => true, 'reports' => true, 'support' => 'email', 'ai_assist' => true, 'white_label' => false],
                'limits' => ['projects' => 25, 'clients' => 5, 'team_members' => 15, 'connections' => 25, 'ad_accounts' => 25, 'reports_per_month' => 100],
            ],
            [
                'code' => 'agency', 'name' => 'Agency (launch)', 'name_ar' => 'الوكالة (الإطلاق)',
                'summary_ar' => 'لإدارة عدة عملاء وفريق وتقارير العملاء.',
                'summary_en' => 'For managing several clients, a team, and client reports.',
                'price_monthly' => 99, 'price_annual' => 990, 'currency' => 'USD',
                'trial_fee' => 0, 'trial_days' => 0, 'minimum_commitment_months' => 0, 'sort_order' => 30,
                'trial_limits' => ['projects' => 5, 'clients' => 2, 'team_members' => 5, 'connections' => 5, 'ad_accounts' => 5, 'reports_per_month' => 20],
                'features' => ['campaign_tracking' => true, 'reports' => true, 'support' => 'priority', 'ai_assist' => true, 'white_label' => true],
                'limits' => ['projects' => null, 'clients' => null, 'team_members' => null, 'connections' => null, 'ad_accounts' => null, 'reports_per_month' => null],
            ],
            [
                'code' => 'enterprise', 'name' => 'Enterprise (launch)', 'name_ar' => 'المؤسسات (الإطلاق)',
                'summary_ar' => 'احتياجات خاصة: حدود ومتطلبات تُتفق عليها معك.',
                'summary_en' => 'Particular needs: limits and requirements agreed with you.',
                'contact_sales' => true,
                'price_monthly' => 0, 'price_annual' => null, 'currency' => 'USD',
                'trial_fee' => 0, 'trial_days' => 0, 'minimum_commitment_months' => 0, 'sort_order' => 40,
                'features' => ['campaign_tracking' => true, 'reports' => true, 'support' => 'priority', 'ai_assist' => true, 'white_label' => true],
                'limits' => ['projects' => null, 'clients' => null, 'team_members' => null, 'connections' => null, 'ad_accounts' => null, 'reports_per_month' => null],
            ],
        ];
    }
}
