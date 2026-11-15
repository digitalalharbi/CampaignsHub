<?php

declare(strict_types=1);

/*
 * NOTIF-V2-EXTERNAL-001 / Q2 — the external service-report email (Owner spec §7–§13), VISUAL FIRST: the words are labels,
 * chips and one short line each; the figures, bars and charts carry the meaning. Calm, achievements first; no request, no
 * deadline, none of the forbidden words. A workspace overrides texts through its settings. The wording of the small print is
 * the SHORT form (Owner, 2026-11-15, «ابعد عن أي نصوص») — see docs/notifications-v2/Q2-notes.md.
 */
return [
    'sender_via' => ':workspace عبر CampaignsHub',
    'subject' => [
        'structure' => ':project — :workspace | :custom · CampaignsHub',
        'digest_periodic' => 'ملخص :period',
        'digest_month_end' => 'ملخص الشهر: :highlight',
        'digest_month_start' => 'بداية شهر جديد',
        'target_reached' => 'تحقق الهدف: :highlight',
        'performance_improved' => 'تحسّن في الأداء: :highlight',
        'record_reached' => 'رقم قياسي جديد: :highlight',
        'campaign_launched' => 'حملة جديدة انطلقت',
        'budget_renewed' => 'تحديث الميزانية',
        'continuity_planning' => 'تخطيط الاستمرارية',
        'confirm' => ':workspace تدعوك لاستلام تقارير :term :project',
    ],
    'title' => [
        'digest_periodic' => 'ملخص :term', 'digest_periodic_finance' => 'ملخص الإنفاق', 'digest_month_end' => 'ملخص الشهر', 'digest_month_start' => 'بداية الشهر',
        'target_reached' => 'تحقق الهدف', 'performance_improved' => 'تحسّن الأداء', 'record_reached' => 'رقم قياسي', 'campaign_launched' => 'حملة جديدة',
        'budget_renewed' => 'تحديث الميزانية', 'continuity_planning' => 'تخطيط الاستمرارية',
    ],
    'badge' => ':term: :project',
    'managed_by' => 'تديره: :workspace',
    'greeting_named' => 'مرحبًا :name،',
    'greeting' => 'مرحبًا،',
    'privacy' => 'مخصصة لك بصفتك :role — بيانات :project فقط',
    'copy_band' => 'نسخة للاطلاع',
    'test_banner' => 'تقرير اختبار',
    'updating' => 'قيد التحديث — يصلك في الملخص القادم',
    'months' => ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
    'section' => ['trend' => 'الإنفاق', 'platforms' => 'المنصات', 'top_campaigns' => 'أبرز الحملات', 'top_platforms' => 'أبرز المنصات', 'suggestions' => 'اقتراحات للنمو', 'achievements' => 'إنجازات', 'best' => 'الأفضل', 'follow' => 'يحتاج متابعة'],
    'objective' => [
        'sales' => 'المبيعات', 'leads' => 'العملاء المحتملون', 'traffic' => 'الزيارات', 'awareness' => 'الوعي والوصول', 'app' => 'التطبيق', 'unknown' => 'أخرى',
    ],
    'metric' => [
        'spend' => 'الإنفاق', 'result' => 'النتائج', 'cost_per_result' => 'تكلفة النتيجة', 'purchases' => 'الطلبات', 'conversions' => 'التحويلات',
        'revenue' => 'الإيرادات', 'roas' => 'العائد', 'leads' => 'العملاء المحتملون', 'form_leads' => 'نماذج العملاء',
        'clicks' => 'النقرات', 'landing_page_views' => 'زيارات الصفحة', 'reach' => 'الوصول', 'impressions' => 'مرات الظهور', 'video_views' => 'المشاهدات',
        'installs' => 'التثبيتات', 'engagements' => 'التفاعلات', 'previous' => 'السابقة', 'current' => 'الحالية', 'share' => 'الحصة',
    ],
    'status' => ['on_track' => 'على المسار', 'opportunity' => 'فرصة تحسين', 'needs_follow_up' => 'يحتاج متابعة'],
    'budget' => [
        'left' => 'المتبقي من الميزانية', 'lasts_until' => 'تكفي حتى :date', 'equivalent' => 'ما يعادل :amount', 'none' => 'بدون ميزانية',
    ],
    'achievement' => ':label +:pct',
    'chart_alt' => [
        'donut' => 'توزيع الإنفاق حسب المنصة: :items. الإجمالي :total.',
        'trend' => 'اتجاه الإنفاق اليومي من :from إلى :to: الإجمالي :total، أعلى يوم :max.',
        'lock' => 'خاص',
        'ring' => 'نسبة استخدام الميزانية :pct.',
    ],
    'signature_default' => 'فريق :workspace',
    'why' => 'سبب وصولها: اشتراكك في تقارير :project منذ :date',
    'manage' => 'إدارة أو إلغاء الاشتراك',
    'contact' => 'طلب تواصل',
    'footprint' => 'تقرير آلي من CampaignsHub',
    'footprint_link' => 'campaignshub.io',
    'disclaimer_default' => 'تقرير آلي من :workspace للاطلاع فقط ولا يتطلب إجراءً. الأرقام وقت الإنشاء وقد تُحدَّث، والمرجع المالي هو الفواتير الرسمية. الاقتراحات والتقديرات استرشادية.',
    'created_at' => ':time',
    'sources' => 'المصادر: :sources',
    'reference' => 'المرجع: :ref',
    'fx' => 'أسعار الصرف: :rates',
    'fx_rate' => '1 :from = :rate :to (بتاريخ :date)',
    'no_data' => 'لا توجد أرقام لهذه الفترة.',
    'roles_fallback' => 'مستلم',
    'confirm' => [
        'title' => ':workspace تدعوك لاستلام تقارير :term :project',
        'body' => 'تقارير خدمة دورية عن :project تصلك من :workspace. لن تصلك أي رسالة قبل تأكيدك، ويمكنك إلغاء الاشتراك في أي وقت.',
        'button' => 'تأكيد الاشتراك',
        'ignore' => 'إن لم تكن تتوقع هذه الدعوة فتجاهلها، ولن يصلك شيء.',
    ],
    'unsubscribe' => [
        'title' => 'إدارة الاشتراك',
        'intro' => 'اختر ما يناسبك:',
        'type' => 'إيقاف هذا النوع من الرسائل',
        'project' => 'إيقاف رسائل هذا المشروع',
        'reduce' => 'تقليل عدد الرسائل',
        'all' => 'إيقاف جميع الرسائل',
        'copy_intro' => 'وصلتك نسخة من هذه التقارير.',
        'copy_stop' => 'إيقاف النسخ',
        'done_type' => 'تم. لن يصلك هذا النوع من الرسائل، وتبقى بقية اشتراكاتك كما هي.',
        'done_project' => 'تم. لن تصلك رسائل هذا المشروع.',
        'done_reduce' => 'تم. ستصلك الرسائل بتكرار أقل.',
        'done_all' => 'تم. لن تصلك أي رسالة.',
        'done_copy' => 'تم. لن تصلك نسخ هذه التقارير.',
        'nothing' => 'لا يوجد ما يتغير في هذا الاشتراك.',
        'invalid' => 'هذا الرابط غير صالح.',
    ],
];
