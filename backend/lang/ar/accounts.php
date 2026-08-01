<?php

declare(strict_types=1);

/* Applying for an account, and the sign-in methods around it, in Arabic (I18N-001). */

return [
    'no_mobile_on_file' => 'لا يوجد رقم جوال مسجّل على هذا الطلب.',

    // 503 rather than 400: the request was fine, the capability is not switched on. Said as a state
    // of the platform, not as a mistake the caller made.
    'oauth_awaiting_credentials' => 'طريقة الدخول هذه بانتظار بيانات اعتماد المزوّد.',
    'oauth_providers' => 'طرق تسجيل الدخول.',

    'portal_capability_unavailable' => 'هذه الخاصية غير متاحة في هذه البوابة.',
];
