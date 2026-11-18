<?php

declare(strict_types=1);

/*
 * NOTIF-V2-SETTINGS-UI-001 — defaults of the platform text layer (Owner spec §7, §12, §13). The platform admin overrides
 * each in `notification_platform_texts`; a workspace overrides it again in its own settings. Placeholders are written
 * `{workspace}`. No emoji (guarded by NoEmojiInNotificationsTest).
 */
return [
    'phrasing' => [
        'preferred' => ['على المسار', 'فرصة تحسين', 'يحتاج متابعة'],
        'forbidden' => ['فشل', 'خسارة', 'قصور', 'تجاوز', 'إنذار', 'عاجل', 'خطأ'],
    ],
    'disclaimer' => [
        'ar' => 'تقرير آلي من {workspace} لأغراض الاطلاع والمتابعة فقط، ولا يتطلب أي إجراء منك. الأرقام من المنصات الإعلانية وقت الإنشاء وقد تُحدَّث لاحقًا، والمرجع المالي النهائي هو الفواتير الرسمية. الاقتراحات استرشادية.',
        'en' => 'An automated report from {workspace} for information and follow-up only; it requires no action from you. Figures come from the advertising platforms at creation time and may be updated later; the final financial reference is the official invoices. Suggestions are advisory.',
    ],
    'footprint' => [
        'invitation' => [
            'ar' => 'تقارير تُنشأ تلقائيًا. تعرّف على CampaignsHub.',
            'en' => 'Reports created automatically. Meet CampaignsHub.',
        ],
    ],
];
