<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Requests\Journey\RequestTaxonomy;
use App\Domains\Taxonomy\Models\TaxonomyDefinition;
use App\Domains\Taxonomy\Models\TaxonomyOption;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Seeder;

/**
 * Canonical PLATFORM taxonomy — the single source of truth the SPA reads its option lists from.
 *
 * CRITICAL: every enum-backed definition's option KEYS are EXACTLY the live enum / validator values the
 * backend validates and stores against, so the adoption phase never submits a value the API rejects (422)
 * and never blanks an existing record's filter. The alignment is asserted by TaxonomyAlignmentTest.
 *
 *   campaign.objective      = App\Domains\Campaigns\Enums\CampaignObjective
 *   client.status           = App\Domains\ClientWorkspaces\Enums\ClientStatus
 *   client.service_level    = App\Domains\ClientWorkspaces\Enums\ServiceLevel
 *   client.industry         = App\Domains\ClientWorkspaces\Enums\Industry
 *   client.priority         = ClientTaxonomyController / ClientManagementController Rule::in(low,normal,high)
 *   request.status          = App\Domains\Requests\Services\RequestStatusMachine (the live state machine)
 *   request.priority        = PublicRequestController / RequestActionsController Rule::in(critical,high,medium,low)
 *   request.payment_status  = App\Domains\Requests\Journey\RequestStage::paymentStatus (+ none)
 *   request.service/category/type = App\Domains\Requests\Journey\RequestTaxonomy::tree() (parent-linked)
 *   project.status          = ProjectController::STATUSES
 *   report.type             = ReportController::TYPES (Rule::in)
 *   report.audience         = ReportController store Rule::in(client, internal, executive)
 *   alert.type              = AlertController::TYPES (in:)
 *   alert.severity          = AlertController store Rule::in(info, warning, critical)
 *   alert.channel           = AlertController store channels.* Rule::in(in_app, email, whatsapp)
 *
 * Idempotent (updateOrCreate by key). Runs in platform scope (tenant_id null) so it seeds shared
 * definitions/options visible to every tenant. is_system follows the matrix; a system definition's canonical
 * options are system options too (immutable key — only labels/color/active may change). To keep the engine the
 * true source of truth across re-runs WITHOUT data loss, any stale PLATFORM option under a closed (non-empty)
 * canonical set is DEACTIVATED (never deleted) — tenant-private options (tenant_id != null) are never touched.
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
            $definition = $this->upsertDefinition($entry, $sortDefinition++);

            $sortOption = 0;
            $canonicalKeys = [];
            foreach ($entry['options'] as $option) {
                $this->upsertOption($definition, $option, $sortOption++, parentId: null, isSystem: $entry['is_system']);
                $canonicalKeys[] = $option['key'];
            }

            $this->reconcilePlatformOptions($definition, $canonicalKeys);
        }

        // The hierarchical Service → Category → Type tree (parent-linked), seeded from the canonical
        // RequestTaxonomy so the dependent selects have real data.
        $this->seedRequestTree($sortDefinition);
    }

    /**
     * The flat (non-hierarchical) definitions and their canonical options. The Service→Category→Type tree is
     * seeded separately (see seedRequestTree) because its options carry real cross-definition parent links.
     *
     * @return list<array{key:string, module:string, field_type:string, is_system:bool, label_ar:string, label_en:string, description?:string, options:list<array<string,mixed>>}>
     */
    private function matrix(): array
    {
        return [
            [
                'key' => 'request.objective', 'module' => 'requests', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'الهدف', 'label_en' => 'Objective',
                'options' => $this->requestObjectiveOptions(),
            ],
            [
                'key' => 'request.priority', 'module' => 'requests', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'الأولوية', 'label_en' => 'Priority',
                'description' => 'Rule::in(critical, high, medium, low) — PublicRequestController / RequestActionsController.',
                'options' => $this->requestPriorityOptions(),
            ],
            [
                'key' => 'request.status', 'module' => 'requests', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'الحالة', 'label_en' => 'Status',
                'description' => 'The live request state machine (RequestStatusMachine).',
                'options' => [
                    ['key' => 'new', 'label_ar' => 'جديد', 'label_en' => 'New', 'color' => '#3b82f6', 'icon' => 'sparkles', 'is_default' => true],
                    ['key' => 'under_review', 'label_ar' => 'تحت المراجعة', 'label_en' => 'Under review', 'color' => '#6366f1', 'icon' => 'search'],
                    ['key' => 'waiting_client', 'label_ar' => 'ينتظر العميل', 'label_en' => 'Waiting for client', 'color' => '#f59e0b', 'icon' => 'clock'],
                    ['key' => 'qualified', 'label_ar' => 'مؤهل', 'label_en' => 'Qualified', 'color' => '#14b8a6', 'icon' => 'badge-check'],
                    ['key' => 'approved', 'label_ar' => 'معتمد', 'label_en' => 'Approved', 'color' => '#22c55e', 'icon' => 'thumbs-up'],
                    ['key' => 'in_progress', 'label_ar' => 'قيد التنفيذ', 'label_en' => 'In progress', 'color' => '#0ea5e9', 'icon' => 'play'],
                    ['key' => 'completed', 'label_ar' => 'مكتمل', 'label_en' => 'Completed', 'color' => '#16a34a', 'icon' => 'check-circle'],
                    ['key' => 'rejected', 'label_ar' => 'مرفوض', 'label_en' => 'Rejected', 'color' => '#ef4444', 'icon' => 'x-circle'],
                    ['key' => 'cancelled', 'label_ar' => 'ملغى', 'label_en' => 'Cancelled', 'color' => '#6b7280', 'icon' => 'ban'],
                    ['key' => 'archived', 'label_ar' => 'مؤرشف', 'label_en' => 'Archived', 'color' => '#9ca3af', 'icon' => 'archive'],
                ],
            ],
            [
                'key' => 'request.payment_status', 'module' => 'requests', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'حالة الدفع', 'label_en' => 'Payment status',
                'options' => [
                    ['key' => 'none', 'label_ar' => 'لا يوجد', 'label_en' => 'None', 'color' => '#9ca3af', 'is_default' => true],
                    ['key' => 'pending', 'label_ar' => 'قيد الانتظار', 'label_en' => 'Pending', 'color' => '#f59e0b'],
                    ['key' => 'paid', 'label_ar' => 'مدفوع', 'label_en' => 'Paid', 'color' => '#16a34a'],
                    ['key' => 'failed', 'label_ar' => 'فشل', 'label_en' => 'Failed', 'color' => '#ef4444'],
                    ['key' => 'refunded', 'label_ar' => 'مسترد', 'label_en' => 'Refunded', 'color' => '#6b7280'],
                ],
            ],
            [
                'key' => 'request.source', 'module' => 'requests', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'المصدر', 'label_en' => 'Source',
                'options' => [
                    ['key' => 'public_portal', 'label_ar' => 'البوابة العامة', 'label_en' => 'Public portal'],
                    ['key' => 'client_portal', 'label_ar' => 'بوابة العميل', 'label_en' => 'Client portal'],
                    ['key' => 'team_manual', 'label_ar' => 'إدخال الفريق', 'label_en' => 'Team (manual)'],
                    ['key' => 'referral', 'label_ar' => 'إحالة', 'label_en' => 'Referral'],
                    ['key' => 'import', 'label_ar' => 'استيراد', 'label_en' => 'Import'],
                    ['key' => 'api', 'label_ar' => 'واجهة برمجية', 'label_en' => 'API'],
                    ['key' => 'sales', 'label_ar' => 'المبيعات', 'label_en' => 'Sales'],
                ],
            ],
            [
                'key' => 'client.status', 'module' => 'clients', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'حالة العميل', 'label_en' => 'Client status',
                'description' => 'App\\Domains\\ClientWorkspaces\\Enums\\ClientStatus.',
                'options' => [
                    ['key' => 'prospect', 'label_ar' => 'عميل محتمل', 'label_en' => 'Prospect', 'color' => '#8b5cf6', 'icon' => 'user-plus'],
                    ['key' => 'onboarding', 'label_ar' => 'التهيئة', 'label_en' => 'Onboarding', 'color' => '#0ea5e9', 'icon' => 'rocket'],
                    ['key' => 'active', 'label_ar' => 'نشط', 'label_en' => 'Active', 'color' => '#16a34a', 'icon' => 'check-circle', 'is_default' => true],
                    ['key' => 'needs_attention', 'label_ar' => 'يحتاج انتباه', 'label_en' => 'Needs attention', 'color' => '#f59e0b', 'icon' => 'alert-triangle'],
                    ['key' => 'paused', 'label_ar' => 'متوقف', 'label_en' => 'Paused', 'color' => '#6b7280', 'icon' => 'pause'],
                    ['key' => 'completed', 'label_ar' => 'مكتمل', 'label_en' => 'Completed', 'color' => '#14b8a6', 'icon' => 'flag'],
                    ['key' => 'archived', 'label_ar' => 'مؤرشف', 'label_en' => 'Archived', 'color' => '#9ca3af', 'icon' => 'archive'],
                ],
            ],
            [
                'key' => 'client.service_level', 'module' => 'clients', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'مستوى الخدمة', 'label_en' => 'Service level',
                'description' => 'App\\Domains\\ClientWorkspaces\\Enums\\ServiceLevel.',
                'options' => [
                    ['key' => 'managed_service', 'label_ar' => 'خدمة مُدارة', 'label_en' => 'Managed service', 'icon' => 'shield'],
                    ['key' => 'consulting', 'label_ar' => 'استشارات', 'label_en' => 'Consulting', 'icon' => 'lightbulb'],
                    ['key' => 'reporting_only', 'label_ar' => 'تقارير فقط', 'label_en' => 'Reporting only', 'icon' => 'file-text'],
                    ['key' => 'analytics_only', 'label_ar' => 'تحليلات فقط', 'label_en' => 'Analytics only', 'icon' => 'bar-chart'],
                    ['key' => 'self_service', 'label_ar' => 'خدمة ذاتية', 'label_en' => 'Self service', 'icon' => 'user'],
                ],
            ],
            [
                'key' => 'client.industry', 'module' => 'clients', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'القطاع', 'label_en' => 'Industry',
                'description' => 'App\\Domains\\ClientWorkspaces\\Enums\\Industry.',
                'options' => [
                    ['key' => 'e_commerce', 'label_ar' => 'التجارة الإلكترونية', 'label_en' => 'E-commerce', 'icon' => 'shopping-cart'],
                    ['key' => 'lead_generation', 'label_ar' => 'جذب العملاء المحتملين', 'label_en' => 'Lead generation', 'icon' => 'magnet'],
                    ['key' => 'mobile_app', 'label_ar' => 'تطبيق جوال', 'label_en' => 'Mobile app', 'icon' => 'smartphone'],
                    ['key' => 'b2b', 'label_ar' => 'شركات لشركات (B2B)', 'label_en' => 'B2B', 'icon' => 'briefcase'],
                    ['key' => 'real_estate', 'label_ar' => 'العقارات', 'label_en' => 'Real estate', 'icon' => 'building'],
                    ['key' => 'education', 'label_ar' => 'التعليم', 'label_en' => 'Education', 'icon' => 'graduation-cap'],
                    ['key' => 'healthcare', 'label_ar' => 'الرعاية الصحية', 'label_en' => 'Healthcare', 'icon' => 'heart-pulse'],
                    ['key' => 'events', 'label_ar' => 'الفعاليات', 'label_en' => 'Events', 'icon' => 'calendar'],
                    ['key' => 'local_business', 'label_ar' => 'نشاط تجاري محلي', 'label_en' => 'Local business', 'icon' => 'store'],
                    ['key' => 'government', 'label_ar' => 'القطاع الحكومي', 'label_en' => 'Government', 'icon' => 'landmark'],
                    ['key' => 'custom', 'label_ar' => 'مخصص', 'label_en' => 'Custom', 'icon' => 'plus'],
                ],
            ],
            [
                'key' => 'client.priority', 'module' => 'clients', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'أولوية العميل', 'label_en' => 'Client priority',
                'description' => 'Rule::in(low, normal, high) — ClientManagementController / ClientTaxonomyController.',
                'options' => [
                    ['key' => 'low', 'label_ar' => 'منخفضة', 'label_en' => 'Low', 'color' => '#6b7280'],
                    ['key' => 'normal', 'label_ar' => 'عادية', 'label_en' => 'Normal', 'color' => '#3b82f6', 'is_default' => true],
                    ['key' => 'high', 'label_ar' => 'عالية', 'label_en' => 'High', 'color' => '#f59e0b'],
                ],
            ],
            [
                'key' => 'client.source', 'module' => 'clients', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'مصدر العميل', 'label_en' => 'Client source',
                'options' => [
                    ['key' => 'website', 'label_ar' => 'الموقع', 'label_en' => 'Website'],
                    ['key' => 'referral', 'label_ar' => 'إحالة', 'label_en' => 'Referral'],
                    ['key' => 'direct', 'label_ar' => 'مباشر', 'label_en' => 'Direct'],
                    ['key' => 'event', 'label_ar' => 'فعالية', 'label_en' => 'Event'],
                    ['key' => 'request_portal', 'label_ar' => 'بوابة الطلبات', 'label_en' => 'Request portal'],
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
                'description' => 'App\\Domains\\Campaigns\\Enums\\CampaignObjective.',
                'options' => $this->campaignObjectiveOptions(),
            ],
            [
                'key' => 'campaign.platforms', 'module' => 'campaigns', 'field_type' => 'multi', 'is_system' => false,
                'label_ar' => 'المنصات', 'label_en' => 'Platforms',
                'options' => [
                    ['key' => 'meta', 'label_ar' => 'ميتا', 'label_en' => 'Meta', 'color' => '#1877f2'],
                    ['key' => 'google', 'label_ar' => 'جوجل', 'label_en' => 'Google', 'color' => '#4285f4'],
                    ['key' => 'tiktok', 'label_ar' => 'تيك توك', 'label_en' => 'TikTok', 'color' => '#000000'],
                    ['key' => 'snapchat', 'label_ar' => 'سناب شات', 'label_en' => 'Snapchat', 'color' => '#fffc00'],
                    ['key' => 'x', 'label_ar' => 'إكس', 'label_en' => 'X', 'color' => '#000000'],
                    ['key' => 'linkedin', 'label_ar' => 'لينكدإن', 'label_en' => 'LinkedIn', 'color' => '#0a66c2'],
                    ['key' => 'microsoft', 'label_ar' => 'مايكروسوفت', 'label_en' => 'Microsoft', 'color' => '#00a4ef'],
                    ['key' => 'pinterest', 'label_ar' => 'بينترست', 'label_en' => 'Pinterest', 'color' => '#e60023'],
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
                    ['key' => 'image', 'label_ar' => 'صورة', 'label_en' => 'Image', 'icon' => 'image'],
                    ['key' => 'video', 'label_ar' => 'فيديو', 'label_en' => 'Video', 'icon' => 'video'],
                    ['key' => 'carousel', 'label_ar' => 'دوّار', 'label_en' => 'Carousel', 'icon' => 'gallery-horizontal'],
                    ['key' => 'story', 'label_ar' => 'قصة', 'label_en' => 'Story', 'icon' => 'circle-dashed'],
                    ['key' => 'collection', 'label_ar' => 'مجموعة', 'label_en' => 'Collection', 'icon' => 'layout-grid'],
                    ['key' => 'text', 'label_ar' => 'نص', 'label_en' => 'Text', 'icon' => 'type'],
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
                'description' => 'ProjectController::STATUSES.',
                'options' => [
                    ['key' => 'draft', 'label_ar' => 'مسودة', 'label_en' => 'Draft', 'color' => '#9ca3af', 'is_default' => true],
                    ['key' => 'onboarding', 'label_ar' => 'التهيئة', 'label_en' => 'Onboarding', 'color' => '#0ea5e9'],
                    ['key' => 'active', 'label_ar' => 'نشط', 'label_en' => 'Active', 'color' => '#16a34a'],
                    ['key' => 'paused', 'label_ar' => 'متوقف', 'label_en' => 'Paused', 'color' => '#f59e0b'],
                    ['key' => 'completed', 'label_ar' => 'مكتمل', 'label_en' => 'Completed', 'color' => '#14b8a6'],
                    ['key' => 'archived', 'label_ar' => 'مؤرشف', 'label_en' => 'Archived', 'color' => '#6b7280'],
                ],
            ],
            [
                'key' => 'report.type', 'module' => 'reports', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'نوع التقرير', 'label_en' => 'Report type',
                'description' => 'ReportController::TYPES (Rule::in) — the report builder type select.',
                'options' => [
                    ['key' => 'executive', 'label_ar' => 'تقرير تنفيذي', 'label_en' => 'Executive', 'is_default' => true],
                    ['key' => 'project', 'label_ar' => 'تقرير مشروع', 'label_en' => 'Project'],
                    ['key' => 'campaign', 'label_ar' => 'تقرير حملة', 'label_en' => 'Campaign'],
                    ['key' => 'platform', 'label_ar' => 'تقرير منصة', 'label_en' => 'Platform'],
                    ['key' => 'platform_comparison', 'label_ar' => 'مقارنة منصات', 'label_en' => 'Platform comparison'],
                    ['key' => 'weekly', 'label_ar' => 'تقرير أسبوعي', 'label_en' => 'Weekly'],
                    ['key' => 'monthly', 'label_ar' => 'تقرير شهري', 'label_en' => 'Monthly'],
                    ['key' => 'custom', 'label_ar' => 'تقرير مخصص', 'label_en' => 'Custom'],
                ],
            ],
            [
                'key' => 'report.audience', 'module' => 'reports', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'جمهور التقرير', 'label_en' => 'Report audience',
                'description' => 'ReportController store — Rule::in(client, internal, executive).',
                'options' => [
                    ['key' => 'client', 'label_ar' => 'العميل', 'label_en' => 'Client', 'is_default' => true,
                        'description' => 'رسوم أكثر ونصوص أقل، توصيات معتمدة فقط، بلا تفاصيل تقنية.'],
                    ['key' => 'internal', 'label_ar' => 'فريق الأداء', 'label_en' => 'Internal',
                        'description' => 'كل المقاييس والحسابات والتشخيص وتوصيات Draft — لا يُشارك مع العميل.'],
                    ['key' => 'executive', 'label_ar' => 'الإدارة التنفيذية', 'label_en' => 'Executive',
                        'description' => 'ملخص شديد الاختصار: الميزانية والنتائج والعائد والقرارات.'],
                ],
            ],
            [
                'key' => 'alert.type', 'module' => 'alerts', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'نوع التنبيه', 'label_en' => 'Alert type',
                'description' => 'AlertController::TYPES (in:) — the alert rule type select.',
                'options' => [
                    ['key' => 'budget_risk', 'label_ar' => 'خطر الميزانية', 'label_en' => 'Budget risk', 'color' => '#f59e0b', 'icon' => 'wallet', 'is_default' => true],
                    ['key' => 'cpa_increase', 'label_ar' => 'ارتفاع CPA', 'label_en' => 'CPA increase', 'color' => '#ef4444', 'icon' => 'trending-up'],
                    ['key' => 'cpl_increase', 'label_ar' => 'ارتفاع CPL', 'label_en' => 'CPL increase', 'color' => '#ef4444', 'icon' => 'trending-up'],
                    ['key' => 'roas_drop', 'label_ar' => 'انخفاض ROAS', 'label_en' => 'ROAS drop', 'color' => '#ef4444', 'icon' => 'trending-down'],
                    ['key' => 'no_results', 'label_ar' => 'إنفاق بلا نتائج', 'label_en' => 'No results', 'color' => '#f59e0b', 'icon' => 'circle-slash'],
                    ['key' => 'sync_failure', 'label_ar' => 'فشل المزامنة', 'label_en' => 'Sync failure', 'color' => '#dc2626', 'icon' => 'refresh-cw-off'],
                    ['key' => 'token_expiry', 'label_ar' => 'انتهاء التوكن', 'label_en' => 'Token expiry', 'color' => '#f59e0b', 'icon' => 'key-round'],
                    ['key' => 'report_failed', 'label_ar' => 'فشل التقرير', 'label_en' => 'Report failed', 'color' => '#dc2626', 'icon' => 'file-x'],
                    ['key' => 'sla_warning', 'label_ar' => 'تحذير SLA', 'label_en' => 'SLA warning', 'color' => '#f59e0b', 'icon' => 'clock-alert'],
                ],
            ],
            [
                'key' => 'alert.severity', 'module' => 'alerts', 'field_type' => 'single', 'is_system' => true,
                'label_ar' => 'الخطورة', 'label_en' => 'Severity',
                'description' => 'AlertController store — Rule::in(info, warning, critical).',
                'options' => [
                    ['key' => 'info', 'label_ar' => 'معلومة', 'label_en' => 'Info', 'color' => '#3b82f6', 'icon' => 'info'],
                    ['key' => 'warning', 'label_ar' => 'تحذير', 'label_en' => 'Warning', 'color' => '#f59e0b', 'icon' => 'alert-triangle', 'is_default' => true],
                    ['key' => 'critical', 'label_ar' => 'حرِج', 'label_en' => 'Critical', 'color' => '#dc2626', 'icon' => 'alert-octagon'],
                ],
            ],
            [
                'key' => 'alert.channel', 'module' => 'alerts', 'field_type' => 'multi', 'is_system' => true,
                'label_ar' => 'القنوات', 'label_en' => 'Channels',
                'description' => 'AlertController store — channels.* Rule::in(in_app, email, whatsapp).',
                'options' => [
                    ['key' => 'in_app', 'label_ar' => 'داخل التطبيق', 'label_en' => 'In-app', 'is_default' => true],
                    ['key' => 'email', 'label_ar' => 'البريد', 'label_en' => 'Email'],
                    ['key' => 'whatsapp', 'label_ar' => 'واتساب', 'label_en' => 'WhatsApp'],
                ],
            ],
            [
                'key' => 'file.category', 'module' => 'files', 'field_type' => 'single', 'is_system' => false,
                'label_ar' => 'فئة الملف', 'label_en' => 'File category',
                'description' => 'Tenant-manageable file/attachment classification (no backend hard-validation).',
                'options' => [
                    ['key' => 'creative', 'label_ar' => 'تصميم', 'label_en' => 'Creative'],
                    ['key' => 'report', 'label_ar' => 'تقرير', 'label_en' => 'Report'],
                    ['key' => 'contract', 'label_ar' => 'عقد', 'label_en' => 'Contract'],
                    ['key' => 'invoice', 'label_ar' => 'فاتورة', 'label_en' => 'Invoice'],
                    ['key' => 'brief', 'label_ar' => 'موجز', 'label_en' => 'Brief'],
                    ['key' => 'asset', 'label_ar' => 'أصل', 'label_en' => 'Asset'],
                    ['key' => 'other', 'label_ar' => 'أخرى', 'label_en' => 'Other', 'is_default' => true],
                ],
            ],
        ];
    }

    /**
     * Seed the Service → Category → Type tree from the canonical RequestTaxonomy with real parent_option_id
     * links (category.parent = its service option; type.parent = its category option). Keys are stable slugs
     * that encode the full path, so the same label under two services never collides.
     */
    private function seedRequestTree(int &$sortDefinition): void
    {
        $serviceDef = $this->upsertDefinition([
            'key' => 'request.service', 'module' => 'requests', 'field_type' => 'single', 'is_system' => true,
            'label_ar' => 'الخدمة', 'label_en' => 'Service',
        ], $sortDefinition++);

        $categoryDef = $this->upsertDefinition([
            'key' => 'request.category', 'module' => 'requests', 'field_type' => 'single', 'is_system' => false,
            'label_ar' => 'الفئة', 'label_en' => 'Category',
            'description' => 'Dependent on the selected service (parent_option_id → request.service option).',
        ], $sortDefinition++);

        $typeDef = $this->upsertDefinition([
            'key' => 'request.type', 'module' => 'requests', 'field_type' => 'single', 'is_system' => false,
            'label_ar' => 'النوع', 'label_en' => 'Type',
            'description' => 'Dependent on the selected category (parent_option_id → request.category option).',
        ], $sortDefinition++);

        $serviceSort = 0;
        $categorySort = 0;
        $typeSort = 0;
        $serviceKeys = [];
        $categoryKeys = [];
        $typeKeys = [];

        foreach (RequestTaxonomy::tree() as $serviceLabel => $service) {
            $serviceKey = $this->slug($serviceLabel);
            $serviceOption = $this->upsertOption($serviceDef, [
                'key' => $serviceKey,
                'label_ar' => $this->serviceLabelAr[$serviceLabel] ?? $serviceLabel,
                'label_en' => $serviceLabel,
                'metadata' => ['module' => $service['module']],
            ], $serviceSort++, parentId: null, isSystem: true);
            $serviceKeys[] = $serviceKey;

            foreach ($service['categories'] as $categoryLabel => $types) {
                $categoryKey = $serviceKey.'__'.$this->slug($categoryLabel);
                $categoryOption = $this->upsertOption($categoryDef, [
                    'key' => $categoryKey,
                    'label_ar' => $this->categoryLabelAr[$categoryLabel] ?? $categoryLabel,
                    'label_en' => $categoryLabel,
                    'metadata' => ['service_key' => $serviceKey],
                ], $categorySort++, parentId: $serviceOption->getKey(), isSystem: false);
                $categoryKeys[] = $categoryKey;

                foreach ($types as $typeLabel) {
                    $typeKey = $categoryKey.'__'.$this->slug($typeLabel);
                    $this->upsertOption($typeDef, [
                        'key' => $typeKey,
                        'label_ar' => $typeLabel,
                        'label_en' => $typeLabel,
                        'metadata' => ['service_key' => $serviceKey, 'category_key' => $categoryKey],
                    ], $typeSort++, parentId: $categoryOption->getKey(), isSystem: false);
                    $typeKeys[] = $typeKey;
                }
            }
        }

        $this->reconcilePlatformOptions($serviceDef, $serviceKeys);
        $this->reconcilePlatformOptions($categoryDef, $categoryKeys);
        $this->reconcilePlatformOptions($typeDef, $typeKeys);
    }

    /**
     * @param  array{key:string, module:string, field_type:string, is_system:bool, label_ar:string, label_en:string, description?:string}  $entry
     */
    private function upsertDefinition(array $entry, int $sortOrder): TaxonomyDefinition
    {
        return TaxonomyDefinition::updateOrCreate(
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
                'sort_order' => $sortOrder,
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $option
     */
    private function upsertOption(TaxonomyDefinition $definition, array $option, int $sortOrder, ?string $parentId, bool $isSystem): TaxonomyOption
    {
        return TaxonomyOption::updateOrCreate(
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
                'parent_option_id' => $parentId,
                'sort_order' => $sortOrder,
                'is_default' => $option['is_default'] ?? false,
                'is_active' => true,
                'is_system' => $isSystem,
                'metadata' => $option['metadata'] ?? null,
            ],
        );
    }

    /**
     * Converge the PLATFORM (tenant_id null) option set for a definition onto the canonical keys WITHOUT data
     * loss: any platform option whose key is no longer canonical is DEACTIVATED (hidden from the effective set)
     * rather than deleted. Tenant-private options (tenant_id != null) are never touched. A no-op on a fresh DB
     * and on re-runs where nothing has drifted — which keeps the seed idempotent.
     *
     * @param  list<string>  $canonicalKeys
     */
    private function reconcilePlatformOptions(TaxonomyDefinition $definition, array $canonicalKeys): void
    {
        if ($canonicalKeys === []) {
            return; // open, tenant-managed vocabulary — nothing to converge.
        }

        TaxonomyOption::withoutGlobalScope(TenantScope::class)
            ->where('taxonomy_definition_id', $definition->getKey())
            ->whereNull('tenant_id')
            ->where('is_active', true)
            ->whereNotIn('key', $canonicalKeys)
            ->update(['is_active' => false]);
    }

    /**
     * Request priority — matches PublicRequestController / RequestActionsController Rule::in.
     *
     * @return list<array<string,mixed>>
     */
    private function requestPriorityOptions(): array
    {
        return [
            ['key' => 'critical', 'label_ar' => 'حرجة', 'label_en' => 'Critical', 'color' => '#dc2626'],
            ['key' => 'high', 'label_ar' => 'عالية', 'label_en' => 'High', 'color' => '#f59e0b'],
            ['key' => 'medium', 'label_ar' => 'متوسطة', 'label_en' => 'Medium', 'color' => '#3b82f6', 'is_default' => true],
            ['key' => 'low', 'label_ar' => 'منخفضة', 'label_en' => 'Low', 'color' => '#6b7280'],
        ];
    }

    /**
     * The request-intake objective vocabulary (RequestConversionService recognises exactly these when mapping a
     * qualified request onto a campaign). Free-text at intake, so this is a non-system suggestion list.
     *
     * @return list<array<string,mixed>>
     */
    private function requestObjectiveOptions(): array
    {
        return [
            ['key' => 'sales', 'label_ar' => 'المبيعات', 'label_en' => 'Sales'],
            ['key' => 'leads', 'label_ar' => 'العملاء المحتملون', 'label_en' => 'Leads'],
            ['key' => 'awareness', 'label_ar' => 'الوعي', 'label_en' => 'Awareness'],
            ['key' => 'traffic', 'label_ar' => 'الزيارات', 'label_en' => 'Traffic'],
            ['key' => 'engagement', 'label_ar' => 'التفاعل', 'label_en' => 'Engagement'],
            ['key' => 'app_installs', 'label_ar' => 'تثبيت التطبيق', 'label_en' => 'App installs'],
            ['key' => 'video_views', 'label_ar' => 'مشاهدات الفيديو', 'label_en' => 'Video views'],
            ['key' => 'store_visits', 'label_ar' => 'زيارات المتجر', 'label_en' => 'Store visits'],
            ['key' => 'custom', 'label_ar' => 'مخصص', 'label_en' => 'Custom'],
        ];
    }

    /**
     * campaign.objective — KEYS are exactly CampaignObjective::values(). Each option carries objective-
     * appropriate KPI / funnel / report-template config in metadata so the campaign engine can derive its KPI
     * set, funnel stage and default report template. ROAS is never primary for awareness/traffic/engagement,
     * and leads are never the metric for awareness.
     *
     * @return list<array<string,mixed>>
     */
    private function campaignObjectiveOptions(): array
    {
        return [
            ['key' => 'awareness', 'label_ar' => 'الوعي', 'label_en' => 'Awareness', 'color' => '#8b5cf6', 'icon' => 'megaphone',
                'metadata' => ['kpi' => ['reach', 'impressions', 'cpm', 'frequency'], 'funnel' => 'awareness', 'template' => 'brand']],
            ['key' => 'traffic', 'label_ar' => 'الزيارات', 'label_en' => 'Traffic', 'color' => '#0ea5e9', 'icon' => 'mouse-pointer-click',
                'metadata' => ['kpi' => ['clicks', 'ctr', 'cpc', 'sessions'], 'funnel' => 'consideration', 'template' => 'traffic']],
            ['key' => 'engagement', 'label_ar' => 'التفاعل', 'label_en' => 'Engagement', 'color' => '#14b8a6', 'icon' => 'heart',
                'metadata' => ['kpi' => ['engagements', 'eng_rate', 'cpe'], 'funnel' => 'consideration', 'template' => 'engagement']],
            ['key' => 'leads', 'label_ar' => 'العملاء المحتملون', 'label_en' => 'Leads', 'color' => '#f59e0b', 'icon' => 'user-plus',
                'metadata' => ['kpi' => ['leads', 'cpl', 'conv_rate'], 'funnel' => 'conversion', 'template' => 'lead_gen']],
            ['key' => 'app_installs', 'label_ar' => 'تثبيت التطبيق', 'label_en' => 'App installs', 'color' => '#6366f1', 'icon' => 'smartphone',
                'metadata' => ['kpi' => ['installs', 'cpi', 'install_rate'], 'funnel' => 'conversion', 'template' => 'app']],
            ['key' => 'sales', 'label_ar' => 'المبيعات', 'label_en' => 'Sales', 'color' => '#16a34a', 'icon' => 'shopping-bag',
                'metadata' => ['kpi' => ['roas', 'cpa', 'revenue'], 'funnel' => 'conversion', 'template' => 'performance']],
            ['key' => 'conversions', 'label_ar' => 'التحويلات', 'label_en' => 'Conversions', 'color' => '#22c55e', 'icon' => 'target',
                'metadata' => ['kpi' => ['conversions', 'cpa', 'conv_rate', 'roas'], 'funnel' => 'conversion', 'template' => 'performance']],
            ['key' => 'other', 'label_ar' => 'أخرى', 'label_en' => 'Other', 'color' => '#6b7280', 'icon' => 'circle-dashed',
                'metadata' => ['kpi' => ['impressions', 'clicks'], 'funnel' => 'custom', 'template' => 'custom']],
        ];
    }

    /** Stable slug for a tree label: lowercase, non-alphanumerics collapsed to underscores. */
    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($value)) ?? '';

        return trim($slug, '_');
    }

    /** @var array<string,string> Arabic labels for the tree's service nodes. */
    private array $serviceLabelAr = [
        'Paid Advertising Management' => 'إدارة الإعلانات المدفوعة',
        'Influencer & UGC Management' => 'إدارة المؤثرين والمحتوى',
        'Analytics' => 'التحليلات',
        'Tracking' => 'التتبع',
        'Reporting' => 'التقارير',
        'Integrations' => 'التكاملات',
        'Consulting' => 'الاستشارات',
        'Custom' => 'مخصص',
    ];

    /** @var array<string,string> Arabic labels for the tree's category nodes (label is unique enough to key on). */
    private array $categoryLabelAr = [
        'New Campaign' => 'حملة جديدة',
        'Existing Campaign Management' => 'إدارة حملة قائمة',
        'Performance Optimization' => 'تحسين الأداء',
        'Account Audit' => 'تدقيق الحساب',
        'Tracking Setup' => 'إعداد التتبع',
        'Reporting' => 'التقارير',
        'Data Integration' => 'ربط البيانات',
        'Consultation' => 'استشارة',
        'Influencer Campaign' => 'حملة مؤثرين',
        'UGC Production' => 'إنتاج المحتوى',
        'Data Analysis' => 'تحليل البيانات',
        'Custom Request' => 'طلب مخصص',
    ];
}
