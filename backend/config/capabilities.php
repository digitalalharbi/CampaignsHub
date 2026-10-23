<?php

declare(strict_types=1);

/*
 * CAP-REGISTRY-001 — the product's sellable modules, in ONE place.
 *
 * Each module answers two separate questions and the code keeps them apart:
 *
 *   ENTITLED  — may this workspace have it at all (plan, a module it was given, a console grant)?
 *   ACTIVATED — is it switched on for the part of the workspace being looked at?
 *
 * `activation`:
 *   default_on  — once entitled it is on until someone switches it off lower down.
 *   explicit    — OFF until someone with settings.manage switches it ON. Advanced tracking and attribution
 *                 never switch themselves on, however they came to be entitled.
 *
 * `default_entitled` is how the modules that exist today stay with the customers who already have them:
 * a workspace is not asked to buy what it was using yesterday. A module that is new (false here) needs a
 * plan, a module or a grant first.
 *
 * `legacy_module` maps a module onto a value the tenant's `enabled_modules` column already carries.
 */
return [
    // Operator kill switch (comma-separated module keys). A listed module is off for everyone, whatever else says.
    'disabled' => array_values(array_filter(array_map('trim', explode(',', (string) env('CAPABILITIES_DISABLED', ''))))),

    'modules' => [
        'advertising_management' => ['activation' => 'default_on', 'default_entitled' => true, 'label' => ['en' => 'Advertising management', 'ar' => 'إدارة الإعلانات']],
        'campaign_analytics' => ['activation' => 'default_on', 'default_entitled' => true, 'label' => ['en' => 'Campaign analytics', 'ar' => 'تحليلات الحملات']],
        'premium_client_reports' => ['activation' => 'default_on', 'default_entitled' => true, 'label' => ['en' => 'Premium client reports', 'ar' => 'تقارير العملاء المتقدمة']],
        'commerce_intelligence' => ['activation' => 'default_on', 'default_entitled' => true, 'label' => ['en' => 'Commerce intelligence', 'ar' => 'ذكاء المتاجر']],
        'ga4_intelligence' => ['activation' => 'default_on', 'default_entitled' => true, 'label' => ['en' => 'GA4 intelligence', 'ar' => 'ذكاء GA4']],
        'smart_links' => ['activation' => 'default_on', 'default_entitled' => true, 'label' => ['en' => 'Smart links', 'ar' => 'الروابط الذكية']],
        'influencer_intelligence' => ['activation' => 'default_on', 'default_entitled' => false, 'legacy_module' => 'influencer_marketing', 'label' => ['en' => 'Influencer intelligence', 'ar' => 'ذكاء المؤثرين']],
        'attribution_intelligence' => ['activation' => 'explicit', 'default_entitled' => false, 'label' => ['en' => 'Attribution intelligence', 'ar' => 'ذكاء الإسناد']],
        'marketing_impact' => ['activation' => 'explicit', 'default_entitled' => false, 'label' => ['en' => 'Marketing impact', 'ar' => 'أثر التسويق']],
        'link_in_bio' => ['activation' => 'explicit', 'default_entitled' => false, 'label' => ['en' => 'Link in bio', 'ar' => 'صفحات الروابط']],
        'forms_hub' => ['activation' => 'default_on', 'default_entitled' => true, 'label' => ['en' => 'Forms & conversion hub', 'ar' => 'النماذج ومركز التحويلات']],
        'advanced_tracking' => ['activation' => 'explicit', 'default_entitled' => false, 'label' => ['en' => 'Advanced tracking', 'ar' => 'التتبع المتقدم']],
    ],
];
