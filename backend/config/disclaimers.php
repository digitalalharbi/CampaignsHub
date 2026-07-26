<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Performance notice / disclaimer & methodology — SYSTEM DEFAULTS
|--------------------------------------------------------------------------
| Approved bilingual copy for CampaignsHub. These are the immutable system
| defaults; organizations, clients and projects may override any section via
| the `disclaimers` table (priority: project → client → organization → system).
| Two levels of copy are provided (full / short) so surfaces never repeat the
| long text where a compact note belongs. See App\Domains\Disclaimers.
*/

return [
    'version' => 1,

    'locale_default' => 'ar',

    // Which sections are shown by default. Overrides may toggle these off per scope.
    'enabled' => [
        'full' => true,
        'short' => true,
        'freshness' => true,
        'methodology' => true,
        'objectives' => true,
    ],

    'sections' => [
        // Full disclaimer — interactive reports, client-shared reports, PDF, final report preview.
        'full' => [
            'ar' => 'ملاحظة توضيحية: توفر لوحة التقارير تصورًا تفاعليًا لمؤشرات الأداء بهدف دعم المتابعة والتحسين المستمر. وقد تختلف النتائج الظاهرة بحسب الفترة الزمنية، والمنصة، ومصدر البيانات، ونموذج الإسناد، ومعايير العرض المختارة؛ لذلك يجب قراءة المؤشرات في سياق أهداف الحملة، ومرحلة التعلّم، وتحديثات المحتوى والجمهور والميزانية، ومعايير الأداء المعتمدة لكل منصة. تُدار الحملات وتُحسّن وفق منهجية قائمة على الأداء والبيانات المتاحة، ولا تمثل المؤشرات الحالية ضمانًا لنتائج مستقبلية ثابتة.',
            'en' => 'Important Notice: This reporting dashboard provides an interactive view of performance indicators to support ongoing monitoring and optimization. Reported results may vary depending on the selected date range, advertising platform, data source, attribution model, and display criteria. Metrics should therefore be interpreted in the context of the campaign objectives, learning phase, content, audience and budget updates, and the performance standards applied by each platform. Campaigns are managed and optimized using a performance-based, data-informed approach, and current indicators do not guarantee consistent future results.',
        ],

        // Short disclaimer — dashboard, analytics, charts, live report links, platform pages.
        'short' => [
            'ar' => 'ملاحظة: تُدار الحملات وفق منهجية قائمة على الأداء، وقد تختلف النتائج بحسب الفترة والمنصة ومصدر البيانات ونموذج الإسناد ومرحلة تعلّم الحملة.',
            'en' => 'Note: Campaigns are managed using a performance-based approach. Results may vary by period, platform, data source, attribution model, and campaign learning phase.',
        ],

        // Freshness / attribution note — next to last-sync or inside a data-info tooltip.
        'freshness' => [
            'ar' => 'قد تتغير بعض النتائج بعد اكتمال معالجة المنصة للبيانات أو بسبب التحويلات المتأخرة واختلاف نوافذ الإسناد بين المنصات.',
            'en' => 'Some results may change after the platform finishes processing data, or due to late conversions and differing attribution windows across platforms.',
        ],

        // Performance-based methodology description.
        'methodology' => [
            'ar' => 'تعتمد إدارة الحملات على المتابعة المستمرة للبيانات، واختبار المحتوى والجمهور والمنصات، وتحسين توزيع الميزانية وفق مؤشرات الأداء وأهداف كل حملة. وقد تتغير قرارات التوسع أو التخفيض أو الإيقاف بحسب جودة البيانات ومرحلة التعلّم والنتائج الفعلية.',
            'en' => 'Campaign management relies on continuous data monitoring; testing content, audiences and platforms; and optimizing budget allocation according to performance indicators and each campaign\'s objectives. Decisions to scale, reduce or pause may change based on data quality, learning phase, and actual results.',
        ],

        // Objective-specific addenda (shown on matching objective reports only).
        'objectives' => [
            'sales' => [
                'ar' => 'قد تختلف المبيعات والإيرادات بين المنصة والمتجر أو مصدر التحويل نتيجة اختلاف نوافذ الإسناد ومعالجة الطلبات والإلغاءات والاستردادات.',
                'en' => 'Sales and revenue may differ between the platform and the store or conversion source due to differing attribution windows, order processing, cancellations, and refunds.',
            ],
            'awareness' => [
                'ar' => 'أرقام الوصول تخص كل منصة على حدة، ولا يمثل مجموع الوصول بين المنصات وصولًا فريدًا للأفراد.',
                'en' => 'Reach figures are per platform; the sum of reach across platforms does not represent unique individuals.',
            ],
            'leads' => [
                'ar' => 'عدد العملاء المحتملين لا يعكس بالضرورة عدد العملاء المؤهلين أو المبيعات النهائية ما لم يتم ربط بيانات CRM والتحقق من الجودة.',
                'en' => 'The number of leads does not necessarily reflect qualified leads or final sales unless CRM data is connected and quality is verified.',
            ],
            'traffic' => [
                'ar' => 'النقرات لا تساوي بالضرورة جلسات الموقع، وقد تختلف البيانات نتيجة سرعة الصفحة وإعدادات القياس والموافقة على ملفات الارتباط.',
                'en' => 'Clicks do not necessarily equal website sessions, and data may differ due to page speed, measurement setup, and cookie consent.',
            ],
            'app_installs' => [
                'ar' => 'قد تختلف عمليات التثبيت والأحداث داخل التطبيق بحسب مزود القياس ونموذج الإسناد وسياسات الخصوصية الخاصة بأنظمة التشغيل.',
                'en' => 'Installs and in-app events may differ depending on the measurement provider, attribution model, and operating-system privacy policies.',
            ],
        ],
    ],
];
