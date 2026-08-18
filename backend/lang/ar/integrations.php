<?php

declare(strict_types=1);

/** The integrations surface's own words. */
return [
    // COMMAND-CENTER §12 — the provider gave no name, and the blank is theirs, not ours.
    'account_unnamed' => 'حساب :provider بدون اسم من المنصة',

    'account_type' => [
        'ad_account' => 'إعلاني',
        'store' => 'متجر',
        'organization' => 'مؤسسة',
        'business' => 'حساب أعمال',
        'page' => 'صفحة',
    ],

    // The refusals, said as the reason rather than as a code.
    'backfill_unassigned' => 'لا يمكن سحب بيانات سابقة لحساب غير مرتبط بمشروع. اربطه بمشروع أولًا.',
    'backfill_window_invalid' => 'المدة المطلوبة غير صالحة: تاريخ البداية يجب أن يسبق تاريخ النهاية.',
    'backfill_window_too_long' => 'أقصى مدة لسحب البيانات السابقة :days يومًا في الطلب الواحد.',
    'connection_not_authorized' => 'هذا الربط لم يعد مفوَّضًا. أعد ربطه ثم حاول مرة أخرى.',
];
