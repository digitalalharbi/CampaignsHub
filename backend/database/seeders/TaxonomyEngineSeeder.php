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

        // The paid-media service vertical: the tenant-manageable `request.paid_service` hierarchical
        // multi-select (10 categories → ~90 services) that the homepage + intake bind to.
        $this->seedPaidServices($sortDefinition);
    }

    /**
     * Seed `request.paid_service` — the hierarchical, multi-select, tenant-manageable (is_system=false,
     * allows_custom_options=true) catalog the paid-media homepage/selector reads. Category options are roots;
     * each service is a child (parent_option_id → its category). Every option is stamped is_public=true (the ONLY
     * publicly-served set). Every service carries `metadata.required_field_rules` (the dynamic intake fields it
     * requires) + `metadata.description_en`; the 8 homepage-popular services also carry `metadata.popular=true`
     * (kept internal — never exposed publicly). Idempotent (updateOrCreate); re-running adds/removes nothing.
     */
    private function seedPaidServices(int &$sortDefinition): void
    {
        $definition = $this->upsertDefinition([
            'key' => 'request.paid_service', 'module' => 'requests', 'field_type' => 'multi', 'is_system' => false,
            'label_ar' => 'الخدمات الإعلانية المدفوعة', 'label_en' => 'Paid-media services',
            'description' => 'Hierarchical, multi-select, tenant-manageable catalog (categories → services). '
                .'Each service metadata carries `needs` (dynamic intake fields) and optional `popular`.',
        ], $sortDefinition++);

        $sort = 0;
        $canonicalKeys = [];

        foreach ($this->paidServiceCategories() as $category) {
            $categoryOption = $this->upsertOption($definition, [
                'key' => $category['key'],
                'label_ar' => $category['label_ar'],
                'label_en' => $category['label_en'],
                'description' => $category['description'] ?? null,
                'icon' => $category['icon'],
                'color' => $category['color'],
                'is_public' => true,
                'metadata' => ['is_category' => true, 'required_field_rules' => $category['required_field_rules']],
            ], $sort++, parentId: null, isSystem: false);
            $canonicalKeys[] = $category['key'];

            foreach ($category['services'] as $service) {
                // `required_field_rules` drives the dynamic intake fields; `description_en` supplies the EN copy
                // the public catalog needs alongside the Arabic `description` column. `popular` is kept internal
                // (never exposed publicly — the homepage's featured strip is derived from sort_order).
                $metadata = [
                    'required_field_rules' => $category['required_field_rules'],
                    'description_en' => $service['description_en'],
                ];
                if (in_array($service['key'], self::PAID_SERVICE_POPULAR, true)) {
                    $metadata['popular'] = true;
                }

                // The single `custom_request` key is a protected SYSTEM option (its key is immutable) that the
                // intake maps to a free-text field — it is not anonymous taxonomy creation.
                $isSystem = $service['key'] === 'custom_request';

                $this->upsertOption($definition, [
                    'key' => $service['key'],
                    'label_ar' => $service['label_ar'],
                    'label_en' => $service['label_en'],
                    'description' => $service['description'],
                    'icon' => $service['icon'],
                    'color' => $category['color'],
                    'is_public' => true,
                    'metadata' => $metadata,
                ], $sort++, parentId: $categoryOption->getKey(), isSystem: $isSystem);
                $canonicalKeys[] = $service['key'];
            }
        }

        $this->reconcilePlatformOptions($definition, $canonicalKeys);
    }

    /**
     * The 8 homepage-popular service keys (surfaced in the first viewport): new campaign, existing management,
     * improve performance, ad-account audit, pixel/tracking, GA4, campaign analysis, professional report.
     *
     * @var list<string>
     */
    private const PAID_SERVICE_POPULAR = [
        'new_campaign', 'existing_management', 'improve_performance', 'ad_account_audit',
        'meta_pixel', 'ga4', 'campaign_performance_analysis', 'weekly_report',
    ];

    /**
     * The 10 paid-media categories and their ~90 services (keys EXACTLY per PAID_MEDIA_SERVICES_SPEC.md).
     * `required_field_rules` is defined once per category (the dynamic intake fields every service under it
     * requires) and copied onto each service's metadata by seedPaidServices(). Each service carries both a
     * Arabic `description` (column) and an English `description_en` (metadata) for the bilingual public catalog.
     *
     * @return list<array{key:string, label_ar:string, label_en:string, icon:string, color:string, description?:string, required_field_rules:list<string>, services:list<array{key:string, label_ar:string, label_en:string, description:string, description_en:string, icon:string}>}>
     */
    private function paidServiceCategories(): array
    {
        return [
            [
                'key' => 'launch_manage', 'label_ar' => 'إدارة وإطلاق الحملات', 'label_en' => 'Launch & Management',
                'icon' => 'rocket', 'color' => '#0ea5e9',
                'required_field_rules' => ['budget', 'objective', 'platforms', 'regions', 'creatives'],
                'services' => [
                    ['key' => 'new_campaign', 'label_ar' => 'إطلاق حملة جديدة', 'label_en' => 'Launch a new campaign', 'description' => 'إطلاق حملة إعلانية مدفوعة جديدة من التخطيط حتى التشغيل.', 'description_en' => 'Launch a new paid campaign from planning to go-live.', 'icon' => 'rocket'],
                    ['key' => 'existing_management', 'label_ar' => 'إدارة حملات قائمة', 'label_en' => 'Manage existing campaigns', 'description' => 'تولّي إدارة حملات إعلانية قائمة وتشغيلها ومتابعتها.', 'description_en' => 'Take over and run your existing live campaigns.', 'icon' => 'settings'],
                    ['key' => 'full_monthly_management', 'label_ar' => 'الإدارة الشهرية الكاملة', 'label_en' => 'Full monthly management', 'description' => 'إدارة شهرية شاملة لجميع الحملات والمنصات.', 'description_en' => 'Full monthly management across all campaigns and platforms.', 'icon' => 'calendar-check'],
                    ['key' => 'multi_platform_management', 'label_ar' => 'إدارة متعددة المنصات', 'label_en' => 'Multi-platform management', 'description' => 'إدارة موحّدة للحملات عبر عدة منصات إعلانية.', 'description_en' => 'Unified campaign management across several ad platforms.', 'icon' => 'layers'],
                    ['key' => 'ad_account_restructure', 'label_ar' => 'إعادة هيكلة الحساب الإعلاني', 'label_en' => 'Ad account restructure', 'description' => 'إعادة تنظيم بنية الحساب والحملات والمجموعات الإعلانية.', 'description_en' => 'Reorganize account, campaign and ad-set structure.', 'icon' => 'network'],
                    ['key' => 'budget_pacing', 'label_ar' => 'ضبط وتيرة الإنفاق', 'label_en' => 'Budget pacing', 'description' => 'ضبط توزيع الميزانية ووتيرة الإنفاق خلال فترة الحملة.', 'description_en' => 'Control budget distribution and spend pacing over time.', 'icon' => 'gauge'],
                    ['key' => 'seasonal_campaigns', 'label_ar' => 'الحملات الموسمية', 'label_en' => 'Seasonal campaigns', 'description' => 'تجهيز وإدارة حملات المواسم والعروض والمناسبات.', 'description_en' => 'Prepare and run seasonal, sale and event campaigns.', 'icon' => 'calendar-heart'],
                    ['key' => 'product_launch_campaigns', 'label_ar' => 'حملات إطلاق المنتجات', 'label_en' => 'Product launch campaigns', 'description' => 'حملات مخصصة لإطلاق منتج أو خدمة جديدة.', 'description_en' => 'Dedicated campaigns for launching a new product.', 'icon' => 'package-plus'],
                ],
            ],
            [
                'key' => 'optimization', 'label_ar' => 'التحسين والأداء', 'label_en' => 'Optimization',
                'icon' => 'trending-up', 'color' => '#16a34a',
                'required_field_rules' => ['objective', 'platforms', 'current_performance'],
                'services' => [
                    ['key' => 'improve_performance', 'label_ar' => 'تحسين الأداء', 'label_en' => 'Improve performance', 'description' => 'تحسين أداء الحملات القائمة ورفع كفاءتها.', 'description_en' => 'Improve and raise the efficiency of live campaigns.', 'icon' => 'trending-up'],
                    ['key' => 'reduce_cpa_cpl', 'label_ar' => 'خفض تكلفة الاكتساب/العميل', 'label_en' => 'Reduce CPA / CPL', 'description' => 'خفض تكلفة التحويل أو العميل المحتمل.', 'description_en' => 'Lower your cost per acquisition or per lead.', 'icon' => 'trending-down'],
                    ['key' => 'improve_roas', 'label_ar' => 'تحسين العائد على الإنفاق', 'label_en' => 'Improve ROAS', 'description' => 'رفع العائد على الإنفاق الإعلاني.', 'description_en' => 'Increase return on ad spend.', 'icon' => 'badge-dollar-sign'],
                    ['key' => 'raise_conversion_rate', 'label_ar' => 'رفع معدل التحويل', 'label_en' => 'Raise conversion rate', 'description' => 'تحسين معدل التحويل عبر الحملات والصفحات.', 'description_en' => 'Improve conversion rate across campaigns and pages.', 'icon' => 'percent'],
                    ['key' => 'audience_targeting', 'label_ar' => 'تحسين استهداف الجمهور', 'label_en' => 'Audience targeting', 'description' => 'تحسين شرائح الجمهور والاستهداف.', 'description_en' => 'Refine audience segments and targeting.', 'icon' => 'users'],
                    ['key' => 'budget_allocation', 'label_ar' => 'توزيع الميزانية', 'label_en' => 'Budget allocation', 'description' => 'إعادة توزيع الميزانية على الحملات الأعلى أداءً.', 'description_en' => 'Reallocate budget to the best-performing campaigns.', 'icon' => 'wallet'],
                    ['key' => 'ad_creative_testing', 'label_ar' => 'اختبار الإبداعات', 'label_en' => 'Ad creative testing', 'description' => 'اختبار وتجربة الإبداعات الإعلانية.', 'description_en' => 'Test and experiment with ad creatives.', 'icon' => 'flask-conical'],
                    ['key' => 'weak_results_analysis', 'label_ar' => 'تحليل النتائج الضعيفة', 'label_en' => 'Weak results analysis', 'description' => 'تشخيص أسباب ضعف نتائج الحملات ومعالجتها.', 'description_en' => 'Diagnose and fix underperforming campaigns.', 'icon' => 'search-x'],
                    ['key' => 'sales_drop_recovery', 'label_ar' => 'معالجة تراجع المبيعات', 'label_en' => 'Sales drop recovery', 'description' => 'استعادة الأداء بعد تراجع المبيعات.', 'description_en' => 'Recover performance after a sales drop.', 'icon' => 'life-buoy'],
                ],
            ],
            [
                'key' => 'audit_analysis', 'label_ar' => 'التدقيق والتحليل', 'label_en' => 'Audit & Analysis',
                'icon' => 'search-check', 'color' => '#6366f1',
                'required_field_rules' => ['period', 'platforms', 'kpis', 'previous_reports'],
                'services' => [
                    ['key' => 'ad_account_audit', 'label_ar' => 'تدقيق الحساب الإعلاني', 'label_en' => 'Ad account audit', 'description' => 'تدقيق شامل للحساب الإعلاني وبنيته وأدائه.', 'description_en' => 'Full audit of the ad account, structure and performance.', 'icon' => 'search-check'],
                    ['key' => 'campaign_performance_analysis', 'label_ar' => 'تحليل أداء الحملات', 'label_en' => 'Campaign performance analysis', 'description' => 'تحليل معمّق لأداء الحملات ومؤشراتها.', 'description_en' => 'In-depth analysis of campaign performance and KPIs.', 'icon' => 'bar-chart-4'],
                    ['key' => 'customer_journey_analysis', 'label_ar' => 'تحليل رحلة العميل', 'label_en' => 'Customer journey analysis', 'description' => 'تحليل رحلة العميل عبر نقاط التواصل.', 'description_en' => 'Analyze the customer journey across touchpoints.', 'icon' => 'route'],
                    ['key' => 'funnel_analysis', 'label_ar' => 'تحليل القمع', 'label_en' => 'Funnel analysis', 'description' => 'تحليل قمع التحويل واكتشاف نقاط التسرّب.', 'description_en' => 'Analyze the conversion funnel and find drop-offs.', 'icon' => 'filter'],
                    ['key' => 'creative_analysis', 'label_ar' => 'تحليل الإبداعات', 'label_en' => 'Creative analysis', 'description' => 'تحليل أداء الإبداعات الإعلانية.', 'description_en' => 'Analyze ad creative performance.', 'icon' => 'image'],
                    ['key' => 'platform_comparison', 'label_ar' => 'مقارنة المنصات', 'label_en' => 'Platform comparison', 'description' => 'مقارنة أداء المنصات الإعلانية.', 'description_en' => 'Compare performance across ad platforms.', 'icon' => 'git-compare'],
                    ['key' => 'budget_spend_analysis', 'label_ar' => 'تحليل الإنفاق والميزانية', 'label_en' => 'Budget & spend analysis', 'description' => 'تحليل الإنفاق وكفاءة الميزانية.', 'description_en' => 'Analyze spend and budget efficiency.', 'icon' => 'coins'],
                    ['key' => 'tracking_attribution_analysis', 'label_ar' => 'تحليل التتبع والإسناد', 'label_en' => 'Tracking & attribution analysis', 'description' => 'تحليل دقة التتبع ونماذج الإسناد.', 'description_en' => 'Analyze tracking accuracy and attribution models.', 'icon' => 'crosshair'],
                    ['key' => 'paid_plan_review', 'label_ar' => 'مراجعة الخطة الإعلانية', 'label_en' => 'Paid plan review', 'description' => 'مراجعة الخطة الإعلانية المدفوعة وتوصياتها.', 'description_en' => 'Review the paid media plan and its recommendations.', 'icon' => 'clipboard-check'],
                ],
            ],
            [
                'key' => 'measurement_tracking', 'label_ar' => 'القياس والتتبع', 'label_en' => 'Measurement & Tracking',
                'icon' => 'activity', 'color' => '#f59e0b',
                'required_field_rules' => ['site_url', 'platform', 'gtm', 'events', 'store_or_app'],
                'services' => [
                    ['key' => 'meta_pixel', 'label_ar' => 'ربط بكسل ميتا', 'label_en' => 'Meta pixel', 'description' => 'إعداد وربط بكسل ميتا وأحداثه.', 'description_en' => 'Set up and connect the Meta pixel and its events.', 'icon' => 'circle-dot'],
                    ['key' => 'tiktok_pixel', 'label_ar' => 'ربط بكسل تيك توك', 'label_en' => 'TikTok pixel', 'description' => 'إعداد وربط بكسل تيك توك وأحداثه.', 'description_en' => 'Set up and connect the TikTok pixel and its events.', 'icon' => 'circle-dot'],
                    ['key' => 'snapchat_pixel', 'label_ar' => 'ربط بكسل سناب شات', 'label_en' => 'Snapchat pixel', 'description' => 'إعداد وربط بكسل سناب شات وأحداثه.', 'description_en' => 'Set up and connect the Snapchat pixel and its events.', 'icon' => 'circle-dot'],
                    ['key' => 'google_ads_conversions', 'label_ar' => 'تحويلات إعلانات جوجل', 'label_en' => 'Google Ads conversions', 'description' => 'إعداد تتبع تحويلات إعلانات جوجل.', 'description_en' => 'Set up Google Ads conversion tracking.', 'icon' => 'goal'],
                    ['key' => 'ga4', 'label_ar' => 'إعداد GA4', 'label_en' => 'GA4 setup', 'description' => 'إعداد Google Analytics 4 والأحداث.', 'description_en' => 'Set up Google Analytics 4 and events.', 'icon' => 'line-chart'],
                    ['key' => 'gtm', 'label_ar' => 'إعداد Google Tag Manager', 'label_en' => 'GTM setup', 'description' => 'إعداد Google Tag Manager والوسوم.', 'description_en' => 'Set up Google Tag Manager and tags.', 'icon' => 'tags'],
                    ['key' => 'conversion_api', 'label_ar' => 'واجهة التحويلات API', 'label_en' => 'Conversion API', 'description' => 'إعداد واجهة التحويلات من جهة الخادم (CAPI).', 'description_en' => 'Set up the server-side Conversions API (CAPI).', 'icon' => 'webhook'],
                    ['key' => 'server_side_tracking', 'label_ar' => 'التتبع من جهة الخادم', 'label_en' => 'Server-side tracking', 'description' => 'تفعيل التتبع من جهة الخادم لدقة أعلى.', 'description_en' => 'Enable server-side tracking for higher accuracy.', 'icon' => 'server'],
                    ['key' => 'store_events', 'label_ar' => 'أحداث المتجر', 'label_en' => 'Store events', 'description' => 'إعداد أحداث المتجر الإلكتروني (شراء، إضافة للسلة…).', 'description_en' => 'Set up e-commerce store events (purchase, add-to-cart…).', 'icon' => 'shopping-cart'],
                    ['key' => 'app_events', 'label_ar' => 'أحداث التطبيق', 'label_en' => 'App events', 'description' => 'إعداد أحداث تطبيق الجوال وتتبعها.', 'description_en' => 'Set up and track mobile app events.', 'icon' => 'smartphone'],
                    ['key' => 'event_quality_testing', 'label_ar' => 'اختبار جودة الأحداث', 'label_en' => 'Event quality testing', 'description' => 'اختبار جودة الأحداث ومطابقتها.', 'description_en' => 'Test event quality and matching.', 'icon' => 'shield-check'],
                    ['key' => 'tracking_troubleshoot', 'label_ar' => 'إصلاح مشاكل التتبع', 'label_en' => 'Tracking troubleshoot', 'description' => 'تشخيص وإصلاح مشاكل التتبع والأحداث.', 'description_en' => 'Diagnose and fix tracking and event issues.', 'icon' => 'wrench'],
                    ['key' => 'utm_setup', 'label_ar' => 'إعداد وسوم UTM', 'label_en' => 'UTM setup', 'description' => 'توحيد وإعداد وسوم UTM للحملات.', 'description_en' => 'Standardize and set up UTM tags for campaigns.', 'icon' => 'link'],
                    ['key' => 'attribution_setup', 'label_ar' => 'إعداد الإسناد', 'label_en' => 'Attribution setup', 'description' => 'إعداد نموذج الإسناد ونوافذ التحويل.', 'description_en' => 'Set up the attribution model and conversion windows.', 'icon' => 'git-merge'],
                ],
            ],
            [
                'key' => 'integrations', 'label_ar' => 'التكاملات', 'label_en' => 'Integrations',
                'icon' => 'plug', 'color' => '#14b8a6',
                'required_field_rules' => ['platform', 'accounts', 'data_sources'],
                'services' => [
                    ['key' => 'ad_accounts', 'label_ar' => 'ربط الحسابات الإعلانية', 'label_en' => 'Ad accounts', 'description' => 'ربط الحسابات الإعلانية بالمنصة.', 'description_en' => 'Connect your ad accounts to the platform.', 'icon' => 'plug'],
                    ['key' => 'ecommerce_store', 'label_ar' => 'ربط المتجر الإلكتروني', 'label_en' => 'E-commerce store', 'description' => 'ربط المتجر الإلكتروني ومزامنة بياناته.', 'description_en' => 'Connect your e-commerce store and sync its data.', 'icon' => 'store'],
                    ['key' => 'salla', 'label_ar' => 'ربط سلة', 'label_en' => 'Salla', 'description' => 'ربط متجر سلة ومزامنة الطلبات والأحداث.', 'description_en' => 'Connect a Salla store and sync orders and events.', 'icon' => 'shopping-bag'],
                    ['key' => 'zid', 'label_ar' => 'ربط زد', 'label_en' => 'Zid', 'description' => 'ربط متجر زد ومزامنة الطلبات والأحداث.', 'description_en' => 'Connect a Zid store and sync orders and events.', 'icon' => 'shopping-bag'],
                    ['key' => 'shopify', 'label_ar' => 'ربط Shopify', 'label_en' => 'Shopify', 'description' => 'ربط متجر Shopify ومزامنة بياناته.', 'description_en' => 'Connect a Shopify store and sync its data.', 'icon' => 'shopping-bag'],
                    ['key' => 'woocommerce', 'label_ar' => 'ربط WooCommerce', 'label_en' => 'WooCommerce', 'description' => 'ربط متجر WooCommerce ومزامنة بياناته.', 'description_en' => 'Connect a WooCommerce store and sync its data.', 'icon' => 'shopping-bag'],
                    ['key' => 'crm', 'label_ar' => 'ربط نظام CRM', 'label_en' => 'CRM', 'description' => 'ربط نظام إدارة العملاء (CRM).', 'description_en' => 'Connect your CRM system.', 'icon' => 'contact'],
                    ['key' => 'google_analytics', 'label_ar' => 'ربط Google Analytics', 'label_en' => 'Google Analytics', 'description' => 'ربط Google Analytics بمصادر البيانات.', 'description_en' => 'Connect Google Analytics to your data sources.', 'icon' => 'line-chart'],
                    ['key' => 'google_drive', 'label_ar' => 'ربط Google Drive', 'label_en' => 'Google Drive', 'description' => 'ربط Google Drive للملفات والتقارير.', 'description_en' => 'Connect Google Drive for files and reports.', 'icon' => 'hard-drive'],
                    ['key' => 'data_sources', 'label_ar' => 'ربط مصادر البيانات', 'label_en' => 'Data sources', 'description' => 'ربط مصادر بيانات إضافية للمنصة.', 'description_en' => 'Connect additional data sources to the platform.', 'icon' => 'database'],
                    ['key' => 'unified_dashboard', 'label_ar' => 'لوحة موحدة', 'label_en' => 'Unified dashboard', 'description' => 'توحيد البيانات في لوحة واحدة.', 'description_en' => 'Unify your data into a single dashboard.', 'icon' => 'layout-dashboard'],
                    ['key' => 'sync_error_handling', 'label_ar' => 'معالجة أخطاء المزامنة', 'label_en' => 'Sync error handling', 'description' => 'معالجة أخطاء المزامنة والتكاملات.', 'description_en' => 'Handle sync and integration errors.', 'icon' => 'refresh-cw-off'],
                ],
            ],
            [
                'key' => 'strategy_planning', 'label_ar' => 'الاستراتيجية والتخطيط', 'label_en' => 'Strategy & Planning',
                'icon' => 'map', 'color' => '#8b5cf6',
                'required_field_rules' => ['objective', 'budget', 'platforms', 'funnel'],
                'services' => [
                    ['key' => 'ad_strategy', 'label_ar' => 'استراتيجية إعلانية', 'label_en' => 'Ad strategy', 'description' => 'بناء استراتيجية إعلانية متكاملة.', 'description_en' => 'Build an integrated advertising strategy.', 'icon' => 'map'],
                    ['key' => 'media_plan', 'label_ar' => 'خطة إعلامية', 'label_en' => 'Media plan', 'description' => 'إعداد خطة إعلامية وتوزيع القنوات.', 'description_en' => 'Prepare a media plan and channel mix.', 'icon' => 'calendar-range'],
                    ['key' => 'budget_sizing', 'label_ar' => 'تحديد حجم الميزانية', 'label_en' => 'Budget sizing', 'description' => 'تحديد حجم الميزانية المناسب للأهداف.', 'description_en' => 'Size the right budget for your goals.', 'icon' => 'calculator'],
                    ['key' => 'platform_selection', 'label_ar' => 'اختيار المنصات', 'label_en' => 'Platform selection', 'description' => 'اختيار المنصات الأنسب للأهداف والجمهور.', 'description_en' => 'Choose the best platforms for goals and audience.', 'icon' => 'list-checks'],
                    ['key' => 'campaign_objectives', 'label_ar' => 'تحديد أهداف الحملات', 'label_en' => 'Campaign objectives', 'description' => 'تحديد أهداف الحملات ومواءمتها مع القمع.', 'description_en' => 'Define campaign objectives aligned to the funnel.', 'icon' => 'target'],
                    ['key' => 'kpi_definition', 'label_ar' => 'تحديد مؤشرات الأداء', 'label_en' => 'KPI definition', 'description' => 'تحديد مؤشرات الأداء الرئيسية للقياس.', 'description_en' => 'Define the key performance indicators to measure.', 'icon' => 'gauge-circle'],
                    ['key' => 'marketing_funnel', 'label_ar' => 'بناء القمع التسويقي', 'label_en' => 'Marketing funnel', 'description' => 'تصميم القمع التسويقي ومراحله.', 'description_en' => 'Design the marketing funnel and its stages.', 'icon' => 'filter'],
                    ['key' => 'retargeting_plan', 'label_ar' => 'خطة إعادة الاستهداف', 'label_en' => 'Retargeting plan', 'description' => 'وضع خطة إعادة استهداف الجمهور.', 'description_en' => 'Build an audience retargeting plan.', 'icon' => 'repeat'],
                    ['key' => 'acquisition_plan', 'label_ar' => 'خطة الاكتساب', 'label_en' => 'Acquisition plan', 'description' => 'وضع خطة اكتساب عملاء جدد.', 'description_en' => 'Build a new-customer acquisition plan.', 'icon' => 'user-plus'],
                    ['key' => 'product_launch_plan', 'label_ar' => 'خطة إطلاق منتج', 'label_en' => 'Product launch plan', 'description' => 'خطة تسويقية لإطلاق منتج جديد.', 'description_en' => 'Marketing plan for a new product launch.', 'icon' => 'party-popper'],
                ],
            ],
            [
                'key' => 'reporting_dashboards', 'label_ar' => 'التقارير ولوحات البيانات', 'label_en' => 'Reporting & Dashboards',
                'icon' => 'bar-chart-3', 'color' => '#3b82f6',
                'required_field_rules' => ['period', 'audience', 'language', 'format', 'data_sources'],
                'services' => [
                    ['key' => 'weekly_report', 'label_ar' => 'تقرير أسبوعي', 'label_en' => 'Weekly report', 'description' => 'إعداد تقرير أداء أسبوعي احترافي.', 'description_en' => 'Produce a professional weekly performance report.', 'icon' => 'file-text'],
                    ['key' => 'monthly_report', 'label_ar' => 'تقرير شهري', 'label_en' => 'Monthly report', 'description' => 'إعداد تقرير أداء شهري شامل.', 'description_en' => 'Produce a comprehensive monthly report.', 'icon' => 'file-text'],
                    ['key' => 'executive_report', 'label_ar' => 'تقرير تنفيذي', 'label_en' => 'Executive report', 'description' => 'تقرير تنفيذي مختصر للإدارة.', 'description_en' => 'A concise executive report for leadership.', 'icon' => 'briefcase'],
                    ['key' => 'live_dashboard', 'label_ar' => 'لوحة مباشرة', 'label_en' => 'Live dashboard', 'description' => 'لوحة بيانات مباشرة ومحدّثة.', 'description_en' => 'A live, continuously updated dashboard.', 'icon' => 'layout-dashboard'],
                    ['key' => 'custom_report', 'label_ar' => 'تقرير مخصص', 'label_en' => 'Custom report', 'description' => 'تقرير مخصص حسب المتطلبات.', 'description_en' => 'A custom report tailored to requirements.', 'icon' => 'file-cog'],
                    ['key' => 'platform_comparison_report', 'label_ar' => 'تقرير مقارنة المنصات', 'label_en' => 'Platform comparison report', 'description' => 'تقرير يقارن أداء المنصات.', 'description_en' => 'A report comparing platform performance.', 'icon' => 'git-compare'],
                    ['key' => 'client_reports', 'label_ar' => 'تقارير العملاء', 'label_en' => 'Client reports', 'description' => 'تقارير دورية موجّهة للعملاء.', 'description_en' => 'Recurring client-facing reports.', 'icon' => 'users'],
                    ['key' => 'report_scheduling', 'label_ar' => 'جدولة التقارير', 'label_en' => 'Report scheduling', 'description' => 'جدولة إرسال التقارير تلقائياً.', 'description_en' => 'Schedule automated report delivery.', 'icon' => 'calendar-clock'],
                ],
            ],
            [
                'key' => 'creatives', 'label_ar' => 'الإبداعات', 'label_en' => 'Creatives',
                'icon' => 'palette', 'color' => '#ec4899',
                'required_field_rules' => ['platforms', 'assets', 'period'],
                'services' => [
                    ['key' => 'creative_audit', 'label_ar' => 'تدقيق الإبداعات', 'label_en' => 'Creative audit', 'description' => 'تدقيق الإبداعات الإعلانية وجودتها.', 'description_en' => 'Audit ad creatives and their quality.', 'icon' => 'palette'],
                    ['key' => 'ad_performance_analysis', 'label_ar' => 'تحليل أداء الإعلانات', 'label_en' => 'Ad performance analysis', 'description' => 'تحليل أداء الإعلانات على مستوى الإبداع.', 'description_en' => 'Analyze ad performance at the creative level.', 'icon' => 'bar-chart-4'],
                    ['key' => 'top_creatives', 'label_ar' => 'أفضل الإبداعات', 'label_en' => 'Top creatives', 'description' => 'تحديد أفضل الإبداعات أداءً.', 'description_en' => 'Identify the best-performing creatives.', 'icon' => 'award'],
                    ['key' => 'angles_hooks', 'label_ar' => 'الزوايا والخطافات', 'label_en' => 'Angles & hooks', 'description' => 'اقتراح زوايا وخطافات إعلانية.', 'description_en' => 'Suggest ad angles and hooks.', 'icon' => 'sparkles'],
                    ['key' => 'creative_testing_plan', 'label_ar' => 'خطة اختبار الإبداعات', 'label_en' => 'Creative testing plan', 'description' => 'وضع خطة اختبار للإبداعات.', 'description_en' => 'Build a creative testing plan.', 'icon' => 'flask-conical'],
                    ['key' => 'ugc_suggestions', 'label_ar' => 'اقتراحات محتوى UGC', 'label_en' => 'UGC suggestions', 'description' => 'اقتراح أفكار محتوى من صناعة المستخدم.', 'description_en' => 'Suggest user-generated content ideas.', 'icon' => 'video'],
                    ['key' => 'creative_performance_link', 'label_ar' => 'ربط أداء الإبداع', 'label_en' => 'Creative performance link', 'description' => 'ربط أداء الحملة بالإبداعات المستخدمة.', 'description_en' => 'Link campaign performance to the creatives used.', 'icon' => 'link-2'],
                    ['key' => 'creative_fatigue', 'label_ar' => 'إجهاد الإبداع', 'label_en' => 'Creative fatigue', 'description' => 'رصد إجهاد الإبداعات واقتراح التجديد.', 'description_en' => 'Detect creative fatigue and suggest refreshes.', 'icon' => 'battery-low'],
                ],
            ],
            [
                'key' => 'objective_services', 'label_ar' => 'خدمات حسب الهدف', 'label_en' => 'Objective services',
                'icon' => 'target', 'color' => '#ef4444',
                'required_field_rules' => ['objective', 'budget', 'platforms'],
                'services' => [
                    ['key' => 'sales', 'label_ar' => 'المبيعات', 'label_en' => 'Sales', 'description' => 'حملات موجّهة لزيادة المبيعات.', 'description_en' => 'Campaigns aimed at increasing sales.', 'icon' => 'shopping-bag'],
                    ['key' => 'leads', 'label_ar' => 'العملاء المحتملون', 'label_en' => 'Leads', 'description' => 'حملات لجذب العملاء المحتملين.', 'description_en' => 'Campaigns to generate leads.', 'icon' => 'user-plus'],
                    ['key' => 'awareness_reach', 'label_ar' => 'الوعي والوصول', 'label_en' => 'Awareness & reach', 'description' => 'حملات لزيادة الوعي والوصول.', 'description_en' => 'Campaigns to grow awareness and reach.', 'icon' => 'megaphone'],
                    ['key' => 'traffic', 'label_ar' => 'الزيارات', 'label_en' => 'Traffic', 'description' => 'حملات لزيادة الزيارات للموقع أو المتجر.', 'description_en' => 'Campaigns to drive site or store traffic.', 'icon' => 'mouse-pointer-click'],
                    ['key' => 'engagement', 'label_ar' => 'التفاعل', 'label_en' => 'Engagement', 'description' => 'حملات لزيادة التفاعل مع المحتوى.', 'description_en' => 'Campaigns to increase content engagement.', 'icon' => 'heart'],
                    ['key' => 'app_installs', 'label_ar' => 'تثبيت التطبيق', 'label_en' => 'App installs', 'description' => 'حملات لزيادة تثبيت التطبيق.', 'description_en' => 'Campaigns to increase app installs.', 'icon' => 'smartphone'],
                    ['key' => 'video_views', 'label_ar' => 'مشاهدات الفيديو', 'label_en' => 'Video views', 'description' => 'حملات لزيادة مشاهدات الفيديو.', 'description_en' => 'Campaigns to increase video views.', 'icon' => 'play'],
                    ['key' => 'store_visits_events', 'label_ar' => 'زيارات المتجر والأحداث', 'label_en' => 'Store visits & events', 'description' => 'حملات لزيارات المتجر والفعاليات.', 'description_en' => 'Campaigns for store visits and events.', 'icon' => 'map-pin'],
                    ['key' => 'retargeting', 'label_ar' => 'إعادة الاستهداف', 'label_en' => 'Retargeting', 'description' => 'حملات إعادة استهداف الزوّار والعملاء.', 'description_en' => 'Campaigns to retarget visitors and customers.', 'icon' => 'repeat'],
                ],
            ],
            [
                'key' => 'consulting_training', 'label_ar' => 'الاستشارات والتدريب', 'label_en' => 'Consulting & Training',
                'icon' => 'graduation-cap', 'color' => '#f97316',
                'required_field_rules' => ['topic', 'challenges', 'schedule', 'files'],
                'services' => [
                    ['key' => 'media_buying_consult', 'label_ar' => 'استشارة شراء إعلامي', 'label_en' => 'Media buying consult', 'description' => 'جلسة استشارية في الشراء الإعلامي.', 'description_en' => 'A consulting session on media buying.', 'icon' => 'message-circle'],
                    ['key' => 'performance_review_session', 'label_ar' => 'جلسة مراجعة الأداء', 'label_en' => 'Performance review session', 'description' => 'جلسة لمراجعة الأداء ووضع التوصيات.', 'description_en' => 'A session to review performance and recommend actions.', 'icon' => 'clipboard-check'],
                    ['key' => 'platform_selection_consult', 'label_ar' => 'استشارة اختيار المنصات', 'label_en' => 'Platform selection consult', 'description' => 'استشارة لاختيار المنصات المناسبة.', 'description_en' => 'Consulting on choosing the right platforms.', 'icon' => 'list-checks'],
                    ['key' => 'tracking_consult', 'label_ar' => 'استشارة التتبع', 'label_en' => 'Tracking consult', 'description' => 'استشارة في إعداد التتبع والقياس.', 'description_en' => 'Consulting on tracking and measurement setup.', 'icon' => 'crosshair'],
                    ['key' => 'team_training', 'label_ar' => 'تدريب الفريق', 'label_en' => 'Team training', 'description' => 'تدريب الفريق على إدارة الحملات المدفوعة.', 'description_en' => 'Train your team on paid campaign management.', 'icon' => 'graduation-cap'],
                    ['key' => 'pre_launch_review', 'label_ar' => 'مراجعة ما قبل الإطلاق', 'label_en' => 'Pre-launch review', 'description' => 'مراجعة الجاهزية قبل إطلاق الحملة.', 'description_en' => 'Review readiness before launching a campaign.', 'icon' => 'shield-check'],
                    ['key' => 'custom_request', 'label_ar' => 'طلب مخصص', 'label_en' => 'Custom request', 'description' => 'طلب خدمة مخصصة غير مدرجة.', 'description_en' => 'Request a custom service not listed here.', 'icon' => 'plus'],
                ],
            ],
        ];
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
                // Fail-closed: every option is explicitly stamped, so only the paid-media catalog (which passes
                // is_public=true) is ever publicly served; re-runs keep non-public options non-public.
                'is_public' => $option['is_public'] ?? false,
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
