<?php

declare(strict_types=1);

/**
 * The Connection Center's own words.
 *
 * The lifecycle labels are the part worth reading twice. «مكتشَف» and «مُفعّل» and «مرتبط بمشروع» are
 * three different sentences on purpose — the product spent a long time saying «متصل» for all three,
 * and that one word is what made 309 discovered Snapchat accounts unreadable and let an account
 * nobody chose be counted as though somebody had.
 */
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

    'lifecycle' => [
        'discovered' => 'مكتشَف',
        'enabled' => 'مُفعّل',
        'excluded' => 'مستبعَد',
        'assigned' => 'مرتبط بمشروع',
    ],

    'lifecycle_hint' => [
        'discovered' => 'أعادته المنصة ضمن التفويض. لم يُختَر بعد، ولا تُسحب منه أي بيانات.',
        'enabled' => 'اخترته ضمن حساباتك. لا تُسحب منه بيانات حتى يُربَط بمشروع.',
        'excluded' => 'استبعدته من القائمة. يبقى محفوظًا حتى لا يعود مع كل تحديث للاكتشاف.',
        'assigned' => 'مشروع يملكه، وبياناته تظهر في تقارير ذلك المشروع.',
    ],

    // The refusals, said as the reason rather than as a code.
    'exclude_assigned' => 'لا يمكن استبعاد حساب مرتبط بمشروع. افصله عن المشروع أولًا.',
    'backfill_unassigned' => 'لا يمكن سحب بيانات سابقة لحساب غير مرتبط بمشروع. اربطه بمشروع أولًا.',
    'backfill_window_invalid' => 'المدة المطلوبة غير صالحة: تاريخ البداية يجب أن يسبق تاريخ النهاية.',
    'backfill_window_too_long' => 'أقصى مدة لسحب البيانات السابقة :days يومًا في الطلب الواحد.',
    'connection_not_authorized' => 'هذا الربط لم يعد مفوَّضًا. أعد ربطه ثم حاول مرة أخرى.',
];
