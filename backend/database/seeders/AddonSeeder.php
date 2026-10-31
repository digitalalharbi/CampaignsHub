<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Subscriptions\Addons\Addon;
use Illuminate\Database\Seeder;

/**
 * PRICING-ADDONS-001 — the add-ons catalogue, created once; /admin owns every row afterwards (this never overwrites).
 *
 * Capacity add-ons are sold: they extend the cap through the path that enforces it. The feature packs are listed as «coming
 * soon» with NO price — their price and quotas are set from /admin once their running cost is measured, and an add-on that is not
 * ready is never requested or charged.
 */
final class AddonSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::catalogue() as $i => $addon) {
            Addon::query()->firstOrCreate(['code' => $addon['code']], $addon + ['sort_order' => ($i + 1) * 10, 'currency' => 'SAR', 'is_active' => true]);
        }
    }

    /** @return list<array<string, mixed>> */
    public static function catalogue(): array
    {
        return [
            ['code' => 'extra_ad_accounts_5', 'kind' => 'capacity', 'name' => '5 more ad accounts', 'name_ar' => '5 حسابات إعلانية إضافية',
                'summary_en' => 'Five more ad-account seats on your plan.', 'summary_ar' => 'خمسة مقاعد حسابات إعلانية إضافية على باقتك.',
                'price_monthly' => 99, 'grants' => ['limits' => ['ad_accounts' => 5]], 'readiness' => 'available'],
            ['code' => 'extra_projects_5', 'kind' => 'capacity', 'name' => '5 more projects', 'name_ar' => '5 مشاريع إضافية',
                'summary_en' => 'Five more projects (and the client workspaces to hold them).', 'summary_ar' => 'خمسة مشاريع إضافية ومساحات العملاء التي تحملها.',
                'price_monthly' => 149, 'grants' => ['limits' => ['projects' => 5, 'clients' => 5]], 'readiness' => 'available'],
            ['code' => 'extra_users_3', 'kind' => 'capacity', 'name' => '3 more users', 'name_ar' => '3 مستخدمين إضافيين',
                'summary_en' => 'Three more team members.', 'summary_ar' => 'ثلاثة أعضاء فريق إضافيين.',
                'price_monthly' => 79, 'grants' => ['limits' => ['team_members' => 3]], 'readiness' => 'available'],
            ['code' => 'ai_intelligence', 'kind' => 'feature', 'name' => 'AI Intelligence', 'name_ar' => 'الذكاء الاصطناعي',
                'summary_en' => 'AI analysis, recommendations and AI reports.', 'summary_ar' => 'تحليلات ذكية وتوصيات وتقارير بالذكاء الاصطناعي.',
                'price_monthly' => null, 'grants' => null, 'readiness' => 'coming_soon'],
            ['code' => 'client_reports_pro', 'kind' => 'feature', 'name' => 'Client Reports Pro', 'name_ar' => 'تقارير العملاء الاحترافية',
                'summary_en' => 'White label, a custom domain, advanced and scheduled reports.', 'summary_ar' => 'علامة بيضاء ونطاق مخصص وتقارير متقدمة ومجدولة.',
                'price_monthly' => null, 'grants' => null, 'readiness' => 'coming_soon'],
            ['code' => 'automation_pro', 'kind' => 'feature', 'name' => 'Automation Pro', 'name_ar' => 'الأتمتة الاحترافية',
                'summary_en' => 'Automatic rules, alerts and conditional runs.', 'summary_ar' => 'قواعد تلقائية وتنبيهات وتشغيل مشروط.',
                'price_monthly' => null, 'grants' => null, 'readiness' => 'coming_soon'],
            ['code' => 'advanced_analytics', 'kind' => 'feature', 'name' => 'Advanced Analytics', 'name_ar' => 'التحليلات المتقدمة',
                'summary_en' => 'Profitability, advanced attribution and performance forecasts.', 'summary_ar' => 'الربحية والإسناد المتقدم وتوقعات الأداء.',
                'price_monthly' => null, 'grants' => null, 'readiness' => 'coming_soon'],
        ];
    }
}
