<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Taxonomy\Models\TaxonomyDefinition;
use App\Domains\Taxonomy\Models\TaxonomyOption;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Canonical PLATFORM taxonomy: every classification field from the Classification Matrix plus its canonical
 * options, with keys preserved EXACTLY from the current hardcoded values so existing records, reports and
 * filters keep working when the adoption phase switches reads over.
 *
 * Idempotent (updateOrCreate). Runs in platform scope (tenant_id null) so it seeds shared definitions/options
 * visible to every tenant. is_system follows the matrix; a system definition's canonical options are system
 * options too (immutable key — only labels/color/active may change).
 */
final class TaxonomyEngineSeeder extends Seeder
{
    public function run(): void
    {
        // Seed platform-scope rows (tenant_id null). Platform scope also lets updateOrCreate's read bypass the
        // tenant global scope so re-runs update in place instead of colliding.
        app(TenantContext::class)->enterPlatformScope();

        $sortDefinition = 0;
        foreach ($this->matrix() as $entry) {
            $definition = TaxonomyDefinition::updateOrCreate(
                ['key' => $entry['key'], 'tenant_id' => null],
                [
                    'module' => $entry['module'],
                    'scope' => 'platform',
                    'field_type' => $entry['field_type'],
                    'label_ar' => $entry['label_ar'],
                    'label_en' => $entry['label_en'],
                    'description' => $entry['description'] ?? null,
                    'is_system' => $entry['is_system'],
                    'is_active' => true,
                    'allows_custom_options' => ! $entry['is_system'],
                    'allows_multiple' => $entry['field_type'] === 'multi',
                    'maximum_selections' => null,
                    'sort_order' => $sortDefinition++,
                ],
            );

            $sortOption = 0;
            foreach ($entry['options'] as $option) {
                TaxonomyOption::updateOrCreate(
                    [
                        'taxonomy_definition_id' => $definition->getKey(),
                        'tenant_id' => null,
                        'key' => $option['key'],
                    ],
                    [
                        'label_ar' => $option['label_ar'],
                        'label_en' => $option['label_en'],
                        'description' => $option['description'] ?? null,
                        'color' => $option['color'] ?? null,
                        'icon' => $option['icon'] ?? null,
                        'parent_option_id' => null,
                        'sort_order' => $sortOption++,
                        'is_default' => $option['is_default'] ?? false,
                        'is_active' => true,
                        'is_system' => $entry['is_system'],
                        'metadata' => $option['metadata'] ?? null,
                    ],
                );
            }
        }
    }

    /**
     * The canonical matrix. Each entry is one taxonomy_definition and its canonical options. Tenant-manageable
     * fields (request.type, client.tags, campaign.regions/audiences/conversion_events/tags) ship with no
     * canonical options — tenants add their own.
     *
     * @return list<array{key:string, module:string, field_type:string, is_system:bool, label_ar:string, label_en:string, description?:string, options:list<array<string,mixed>>}>
     */
    private function matrix(): array
    {
        return [
            [
                'key' => 'request.service', 'module' => 'requests', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'الخدمة', 'label_en' => 'Service',
                'options' => [
                    ['key' => 'paid_advertising', 'label_ar' => 'الإعلانات المدفوعة', 'label_en' => 'Paid advertising'],
                    ['key' => 'influencer_ugc', 'label_ar' => 'المؤثرون والمحتوى', 'label_en' => 'Influencer / UGC'],
                    ['key' => 'analytics', 'label_ar' => 'التحليلات', 'label_en' => 'Analytics'],
                    ['key' => 'tracking', 'label_ar' => 'التتبع', 'label_en' => 'Tracking'],
                    ['key' => 'reporting', 'label_ar' => 'التقارير', 'label_en' => 'Reporting'],
                    ['key' => 'integrations', 'label_ar' => 'التكاملات', 'label_en' => 'Integrations'],
                    ['key' => 'consulting', 'label_ar' => 'الاستشارات', 'label_en' => 'Consulting'],
                    ['key' => 'custom', 'label_ar' => 'مخصص', 'label_en' => 'Custom'],
                ],
            ],
            [
                'key' => 'request.category', 'module' => 'requests', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'الفئة', 'label_en' => 'Category',
                'options' => [
                    ['key' => 'new_campaign', 'label_ar' => 'حملة جديدة', 'label_en' => 'New campaign'],
                    ['key' => 'existing_management', 'label_ar' => 'إدارة حملة قائمة', 'label_en' => 'Existing management'],
                    ['key' => 'optimization', 'label_ar' => 'تحسين الأداء', 'label_en' => 'Optimization'],
                    ['key' => 'account_audit', 'label_ar' => 'تدقيق الحساب', 'label_en' => 'Account audit'],
                    ['key' => 'tracking_setup', 'label_ar' => 'إعداد التتبع', 'label_en' => 'Tracking setup'],
                    ['key' => 'reporting', 'label_ar' => 'التقارير', 'label_en' => 'Reporting'],
                    ['key' => 'data_integration', 'label_ar' => 'ربط البيانات', 'label_en' => 'Data integration'],
                    ['key' => 'consultation', 'label_ar' => 'استشارة', 'label_en' => 'Consultation'],
                ],
            ],
            [
                'key' => 'request.type', 'module' => 'requests', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'النوع', 'label_en' => 'Type',
                'description' => 'Tenant-manageable request types (per category).',
                'options' => [],
            ],
            [
                'key' => 'request.objective', 'module' => 'requests', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'الهدف', 'label_en' => 'Objective',
                'options' => $this->objectiveOptions(),
            ],
            [
                'key' => 'request.priority', 'module' => 'requests', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'الأولوية', 'label_en' => 'Priority',
                'options' => $this->priorityOptions(),
            ],
            [
                'key' => 'request.status', 'module' => 'requests', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'الحالة', 'label_en' => 'Status',
                'options' => [
                    ['key' => 'draft', 'label_ar' => 'مسودة', 'label_en' => 'Draft'],
                    ['key' => 'contact_verification', 'label_ar' => 'التحقق من التواصل', 'label_en' => 'Contact verification'],
                    ['key' => 'submitted', 'label_ar' => 'تم الإرسال', 'label_en' => 'Submitted'],
                    ['key' => 'under_review', 'label_ar' => 'تحت المراجعة', 'label_en' => 'Under review'],
                    ['key' => 'waiting_for_information', 'label_ar' => 'بانتظار معلومات', 'label_en' => 'Waiting for information'],
                    ['key' => 'qualified', 'label_ar' => 'مؤهل', 'label_en' => 'Qualified'],
                    ['key' => 'proposal_sent', 'label_ar' => 'تم إرسال العرض', 'label_en' => 'Proposal sent'],
                    ['key' => 'awaiting_client_approval', 'label_ar' => 'بانتظار موافقة العميل', 'label_en' => 'Awaiting client approval'],
                    ['key' => 'payment_pending', 'label_ar' => 'بانتظار الدفع', 'label_en' => 'Payment pending'],
                    ['key' => 'paid', 'label_ar' => 'مدفوع', 'label_en' => 'Paid'],
                    ['key' => 'onboarding', 'label_ar' => 'التهيئة', 'label_en' => 'Onboarding'],
                    ['key' => 'in_progress', 'label_ar' => 'قيد التنفيذ', 'label_en' => 'In progress'],
                    ['key' => 'client_review', 'label_ar' => 'مراجعة العميل', 'label_en' => 'Client review'],
                    ['key' => 'completed', 'label_ar' => 'مكتمل', 'label_en' => 'Completed'],
                    ['key' => 'archived', 'label_ar' => 'مؤرشف', 'label_en' => 'Archived'],
                    ['key' => 'rejected', 'label_ar' => 'مرفوض', 'label_en' => 'Rejected'],
                    ['key' => 'cancelled', 'label_ar' => 'ملغى', 'label_en' => 'Cancelled'],
                    ['key' => 'payment_failed', 'label_ar' => 'فشل الدفع', 'label_en' => 'Payment failed'],
                    ['key' => 'refunded', 'label_ar' => 'مسترد', 'label_en' => 'Refunded'],
                    ['key' => 'on_hold', 'label_ar' => 'معلق', 'label_en' => 'On hold'],
                ],
            ],
            [
                'key' => 'request.payment_status', 'module' => 'requests', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'حالة الدفع', 'label_en' => 'Payment status',
                'options' => [
                    ['key' => 'none', 'label_ar' => 'لا يوجد', 'label_en' => 'None', 'is_default' => true],
                    ['key' => 'pending', 'label_ar' => 'قيد الانتظار', 'label_en' => 'Pending'],
                    ['key' => 'paid', 'label_ar' => 'مدفوع', 'label_en' => 'Paid'],
                    ['key' => 'failed', 'label_ar' => 'فشل', 'label_en' => 'Failed'],
                    ['key' => 'refunded', 'label_ar' => 'مسترد', 'label_en' => 'Refunded'],
                ],
            ],
            [
                'key' => 'request.source', 'module' => 'requests', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'المصدر', 'label_en' => 'Source',
                'options' => [
                    ['key' => 'website', 'label_ar' => 'الموقع', 'label_en' => 'Website'],
                    ['key' => 'referral', 'label_ar' => 'إحالة', 'label_en' => 'Referral'],
                    ['key' => 'direct', 'label_ar' => 'مباشر', 'label_en' => 'Direct'],
                    ['key' => 'campaign', 'label_ar' => 'حملة', 'label_en' => 'Campaign'],
                    ['key' => 'other', 'label_ar' => 'أخرى', 'label_en' => 'Other'],
                ],
            ],
            [
                'key' => 'client.status', 'module' => 'clients', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'حالة العميل', 'label_en' => 'Client status',
                'options' => [
                    ['key' => 'prospect', 'label_ar' => 'عميل محتمل', 'label_en' => 'Prospect'],
                    ['key' => 'onboarding', 'label_ar' => 'التهيئة', 'label_en' => 'Onboarding'],
                    ['key' => 'active', 'label_ar' => 'نشط', 'label_en' => 'Active'],
                    ['key' => 'needs_attention', 'label_ar' => 'يحتاج انتباه', 'label_en' => 'Needs attention'],
                    ['key' => 'paused', 'label_ar' => 'متوقف', 'label_en' => 'Paused'],
                    ['key' => 'completed', 'label_ar' => 'مكتمل', 'label_en' => 'Completed'],
                    ['key' => 'archived', 'label_ar' => 'مؤرشف', 'label_en' => 'Archived'],
                ],
            ],
            [
                'key' => 'client.service_level', 'module' => 'clients', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'مستوى الخدمة', 'label_en' => 'Service level',
                'options' => [
                    ['key' => 'basic', 'label_ar' => 'أساسي', 'label_en' => 'Basic'],
                    ['key' => 'standard', 'label_ar' => 'قياسي', 'label_en' => 'Standard'],
                    ['key' => 'premium', 'label_ar' => 'مميز', 'label_en' => 'Premium'],
                    ['key' => 'enterprise', 'label_ar' => 'مؤسسي', 'label_en' => 'Enterprise'],
                ],
            ],
            [
                'key' => 'client.industry', 'module' => 'clients', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'القطاع', 'label_en' => 'Industry',
                'options' => [
                    ['key' => 'ecommerce', 'label_ar' => 'التجارة الإلكترونية', 'label_en' => 'E-commerce'],
                    ['key' => 'saas', 'label_ar' => 'البرمجيات كخدمة', 'label_en' => 'SaaS'],
                    ['key' => 'education', 'label_ar' => 'التعليم', 'label_en' => 'Education'],
                    ['key' => 'health', 'label_ar' => 'الصحة', 'label_en' => 'Health'],
                    ['key' => 'real_estate', 'label_ar' => 'العقارات', 'label_en' => 'Real estate'],
                    ['key' => 'food', 'label_ar' => 'الأغذية', 'label_en' => 'Food'],
                    ['key' => 'travel', 'label_ar' => 'السفر', 'label_en' => 'Travel'],
                    ['key' => 'other', 'label_ar' => 'أخرى', 'label_en' => 'Other'],
                ],
            ],
            [
                'key' => 'client.priority', 'module' => 'clients', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'أولوية العميل', 'label_en' => 'Client priority',
                'options' => $this->priorityOptions(),
            ],
            [
                'key' => 'client.source', 'module' => 'clients', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'مصدر العميل', 'label_en' => 'Client source',
                'options' => [
                    ['key' => 'website', 'label_ar' => 'الموقع', 'label_en' => 'Website'],
                    ['key' => 'referral', 'label_ar' => 'إحالة', 'label_en' => 'Referral'],
                    ['key' => 'direct', 'label_ar' => 'مباشر', 'label_en' => 'Direct'],
                    ['key' => 'event', 'label_ar' => 'فعالية', 'label_en' => 'Event'],
                    ['key' => 'other', 'label_ar' => 'أخرى', 'label_en' => 'Other'],
                ],
            ],
            [
                'key' => 'client.tags', 'module' => 'clients', 'field_type' => 'multi', 'is_system' => false,
                'label_ar' => 'وسوم العميل', 'label_en' => 'Client tags',
                'description' => 'Tenant-manageable client tags.',
                'options' => [],
            ],
            [
                'key' => 'campaign.objective', 'module' => 'campaigns', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'هدف الحملة', 'label_en' => 'Campaign objective',
                'options' => $this->objectiveOptions(),
            ],
            [
                'key' => 'campaign.platforms', 'module' => 'campaigns', 'field_type' => 'multi', 'is_system' => false,
                'label_ar' => 'المنصات', 'label_en' => 'Platforms',
                'options' => [
                    ['key' => 'meta', 'label_ar' => 'ميتا', 'label_en' => 'Meta'],
                    ['key' => 'google', 'label_ar' => 'جوجل', 'label_en' => 'Google'],
                    ['key' => 'tiktok', 'label_ar' => 'تيك توك', 'label_en' => 'TikTok'],
                    ['key' => 'snapchat', 'label_ar' => 'سناب شات', 'label_en' => 'Snapchat'],
                    ['key' => 'x', 'label_ar' => 'إكس', 'label_en' => 'X'],
                    ['key' => 'linkedin', 'label_ar' => 'لينكدإن', 'label_en' => 'LinkedIn'],
                    ['key' => 'microsoft', 'label_ar' => 'مايكروسوفت', 'label_en' => 'Microsoft'],
                    ['key' => 'pinterest', 'label_ar' => 'بينترست', 'label_en' => 'Pinterest'],
                ],
            ],
            [
                'key' => 'campaign.regions', 'module' => 'campaigns', 'field_type' => 'multi', 'is_system' => false,
                'label_ar' => 'المناطق', 'label_en' => 'Regions',
                'description' => 'Tenant-manageable target regions.',
                'options' => [],
            ],
            [
                'key' => 'campaign.audiences', 'module' => 'campaigns', 'field_type' => 'multi', 'is_system' => false,
                'label_ar' => 'الجماهير', 'label_en' => 'Audiences',
                'description' => 'Tenant-manageable audiences.',
                'options' => [],
            ],
            [
                'key' => 'campaign.conversion_events', 'module' => 'campaigns', 'field_type' => 'multi', 'is_system' => false,
                'label_ar' => 'أحداث التحويل', 'label_en' => 'Conversion events',
                'description' => 'Tenant-manageable conversion events.',
                'options' => [],
            ],
            [
                'key' => 'campaign.creative_types', 'module' => 'campaigns', 'field_type' => 'multi', 'is_system' => false,
                'label_ar' => 'أنواع الإبداع', 'label_en' => 'Creative types',
                'options' => [
                    ['key' => 'image', 'label_ar' => 'صورة', 'label_en' => 'Image'],
                    ['key' => 'video', 'label_ar' => 'فيديو', 'label_en' => 'Video'],
                    ['key' => 'carousel', 'label_ar' => 'دوّار', 'label_en' => 'Carousel'],
                    ['key' => 'story', 'label_ar' => 'قصة', 'label_en' => 'Story'],
                    ['key' => 'collection', 'label_ar' => 'مجموعة', 'label_en' => 'Collection'],
                    ['key' => 'text', 'label_ar' => 'نص', 'label_en' => 'Text'],
                ],
            ],
            [
                'key' => 'campaign.tags', 'module' => 'campaigns', 'field_type' => 'multi', 'is_system' => false,
                'label_ar' => 'وسوم الحملة', 'label_en' => 'Campaign tags',
                'description' => 'Tenant-manageable campaign tags.',
                'options' => [],
            ],
            [
                'key' => 'integration.category', 'module' => 'integrations', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'فئة التكامل', 'label_en' => 'Integration category',
                'options' => [
                    ['key' => 'advertising', 'label_ar' => 'الإعلانات', 'label_en' => 'Advertising'],
                    ['key' => 'analytics', 'label_ar' => 'التحليلات', 'label_en' => 'Analytics'],
                    ['key' => 'stores', 'label_ar' => 'المتاجر', 'label_en' => 'Stores'],
                    ['key' => 'files', 'label_ar' => 'الملفات', 'label_en' => 'Files'],
                    ['key' => 'messaging', 'label_ar' => 'المراسلة', 'label_en' => 'Messaging'],
                    ['key' => 'payment', 'label_ar' => 'المدفوعات', 'label_en' => 'Payment'],
                    ['key' => 'other', 'label_ar' => 'أخرى', 'label_en' => 'Other'],
                ],
            ],
            [
                'key' => 'project.status', 'module' => 'projects', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'حالة المشروع', 'label_en' => 'Project status',
                'options' => [
                    ['key' => 'draft', 'label_ar' => 'مسودة', 'label_en' => 'Draft'],
                    ['key' => 'onboarding', 'label_ar' => 'التهيئة', 'label_en' => 'Onboarding'],
                    ['key' => 'active', 'label_ar' => 'نشط', 'label_en' => 'Active'],
                    ['key' => 'paused', 'label_ar' => 'متوقف', 'label_en' => 'Paused'],
                    ['key' => 'completed', 'label_ar' => 'مكتمل', 'label_en' => 'Completed'],
                    ['key' => 'archived', 'label_ar' => 'مؤرشف', 'label_en' => 'Archived'],
                ],
            ],
        ];
    }

    /**
     * Shared four-level priority set.
     *
     * @return list<array<string,mixed>>
     */
    private function priorityOptions(): array
    {
        return [
            ['key' => 'critical', 'label_ar' => 'حرجة', 'label_en' => 'Critical', 'color' => '#dc2626'],
            ['key' => 'high', 'label_ar' => 'عالية', 'label_en' => 'High', 'color' => '#f59e0b'],
            ['key' => 'medium', 'label_ar' => 'متوسطة', 'label_en' => 'Medium', 'color' => '#3b82f6', 'is_default' => true],
            ['key' => 'low', 'label_ar' => 'منخفضة', 'label_en' => 'Low', 'color' => '#6b7280'],
        ];
    }

    /**
     * The objective set shared by request.objective and campaign.objective. Dependent config (kpi/funnel/
     * template) lives in metadata so the campaign engine can derive KPIs/funnel stage/report template.
     *
     * @return list<array<string,mixed>>
     */
    private function objectiveOptions(): array
    {
        return [
            ['key' => 'sales', 'label_ar' => 'المبيعات', 'label_en' => 'Sales', 'metadata' => ['kpi' => ['roas', 'cpa', 'revenue'], 'funnel' => 'conversion', 'template' => 'performance']],
            ['key' => 'leads', 'label_ar' => 'العملاء المحتملون', 'label_en' => 'Leads', 'metadata' => ['kpi' => ['cpl', 'leads', 'conv_rate']]],
            ['key' => 'awareness', 'label_ar' => 'الوعي', 'label_en' => 'Awareness', 'metadata' => ['kpi' => ['reach', 'impressions', 'cpm'], 'funnel' => 'awareness', 'template' => 'brand']],
            ['key' => 'traffic', 'label_ar' => 'الزيارات', 'label_en' => 'Traffic', 'metadata' => ['kpi' => ['clicks', 'ctr', 'cpc']]],
            ['key' => 'engagement', 'label_ar' => 'التفاعل', 'label_en' => 'Engagement', 'metadata' => ['kpi' => ['engagements', 'eng_rate']]],
            ['key' => 'app_installs', 'label_ar' => 'تثبيت التطبيق', 'label_en' => 'App installs', 'metadata' => ['kpi' => ['installs', 'cpi']]],
            ['key' => 'video_views', 'label_ar' => 'مشاهدات الفيديو', 'label_en' => 'Video views', 'metadata' => ['kpi' => ['views', 'vtr', 'cpv']]],
            ['key' => 'store_visits', 'label_ar' => 'زيارات المتجر', 'label_en' => 'Store visits'],
            ['key' => 'custom', 'label_ar' => 'مخصص', 'label_en' => 'Custom'],
        ];
    }
}
