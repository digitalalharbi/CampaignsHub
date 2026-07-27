<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use Illuminate\Database\Seeder;

/** Canonical (non-tenant) catalog of request service types + lifecycle statuses. Idempotent. */
class RequestCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['key' => 'paid_campaign_launch', 'module' => 'paid_media', 'name_ar' => 'إطلاق حملة إعلانية مدفوعة', 'name_en' => 'Launch a paid campaign'],
            ['key' => 'manage_existing_campaign', 'module' => 'paid_media', 'name_ar' => 'إدارة حملة قائمة', 'name_en' => 'Manage an existing campaign'],
            ['key' => 'performance_optimization', 'module' => 'paid_media', 'name_ar' => 'تحسين الأداء', 'name_en' => 'Performance optimization'],
            ['key' => 'ad_account_audit', 'module' => 'analytics', 'name_ar' => 'تدقيق حساب إعلاني', 'name_en' => 'Ad-account audit'],
            ['key' => 'tracking_setup', 'module' => 'tracking', 'name_ar' => 'إعداد التتبع والتحويلات', 'name_en' => 'Tracking & conversions setup'],
            ['key' => 'connect_data_source', 'module' => 'integration', 'name_ar' => 'ربط منصة أو مصدر بيانات', 'name_en' => 'Connect a platform or data source'],
            ['key' => 'build_report', 'module' => 'reporting', 'name_ar' => 'إنشاء تقرير', 'name_en' => 'Build a report'],
            ['key' => 'data_analysis', 'module' => 'analytics', 'name_ar' => 'تحليل بيانات', 'name_en' => 'Data analysis'],
            ['key' => 'consulting', 'module' => 'consulting', 'name_ar' => 'استشارة', 'name_en' => 'Consulting'],
            ['key' => 'custom', 'module' => 'custom', 'name_ar' => 'طلب مخصص', 'name_en' => 'Custom request'],
            ['key' => 'influencer_ugc', 'module' => 'influencer_marketing', 'name_ar' => 'حملة مؤثرين أو UGC', 'name_en' => 'Influencer / UGC campaign'],
        ];
        foreach ($types as $i => $t) {
            RequestType::updateOrCreate(['key' => $t['key']], $t + ['sort' => $i, 'is_active' => true]);
        }

        $statuses = [
            ['key' => 'new', 'name_ar' => 'جديد', 'name_en' => 'New'],
            ['key' => 'under_review', 'name_ar' => 'تحت المراجعة', 'name_en' => 'Under Review'],
            ['key' => 'waiting_client', 'name_ar' => 'ينتظر العميل', 'name_en' => 'Waiting for Client', 'pauses_sla' => true],
            ['key' => 'qualified', 'name_ar' => 'مؤهل', 'name_en' => 'Qualified'],
            ['key' => 'approved', 'name_ar' => 'معتمد', 'name_en' => 'Approved'],
            ['key' => 'in_progress', 'name_ar' => 'قيد التنفيذ', 'name_en' => 'In Progress'],
            ['key' => 'completed', 'name_ar' => 'مكتمل', 'name_en' => 'Completed', 'is_terminal' => true],
            ['key' => 'rejected', 'name_ar' => 'مرفوض', 'name_en' => 'Rejected', 'is_terminal' => true, 'is_client_visible' => false],
            ['key' => 'cancelled', 'name_ar' => 'ملغى', 'name_en' => 'Cancelled', 'is_terminal' => true],
            ['key' => 'archived', 'name_ar' => 'مؤرشف', 'name_en' => 'Archived', 'is_terminal' => true, 'is_client_visible' => false],
        ];
        foreach ($statuses as $i => $s) {
            RequestStatus::updateOrCreate(['key' => $s['key']], $s + ['sort' => $i]);
        }
    }
}
