<?php

declare(strict_types=1);

/*
 * The API envelope, in Arabic (I18N-001).
 *
 * Every JSON error in the application passes through the renderer in `bootstrap/app.php`, so these
 * seven strings are what a customer reads whenever anything at all goes wrong — a stale tab, an
 * expired session, a rate limit, a page they cannot reach. They were English in an Arabic product,
 * which is exactly the moment a person is least able to shrug it off.
 */

return [
    'ok' => 'تمت العملية بنجاح.',
    'failed' => 'تعذّر تنفيذ الطلب.',

    'validation' => 'البيانات المُدخلة غير صحيحة.',
    'unauthenticated' => 'الجلسة غير صالحة. الرجاء تسجيل الدخول.',
    'unauthorized' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
    // 419: the CSRF token is stale, which almost always means the tab was left open. Said as the
    // thing the person can act on rather than as the token that expired.
    'csrf' => 'انتهت صلاحية الصفحة. الرجاء تحديثها والمحاولة مرة أخرى.',
    'not_found' => 'العنصر المطلوب غير موجود.',
    // بوابة موجودة لكنها غير معروضة في هذا الإصدار (INFL-OFF-001). تُقال كخدمة غير متاحة حاليًا، لا
    // كنقص صلاحية ولا كصفحة غير موجودة — فالفرق بين الثلاثة هو ما يعرف به العميل ما عليه فعله.
    'portal_unavailable' => 'هذه الخدمة غير متاحة حاليًا. سنعلن عن إتاحتها لاحقًا.',
    'too_many_requests' => 'عدد كبير من الطلبات. الرجاء المحاولة بعد قليل.',
    'server_error' => 'حدث خطأ غير متوقع. الرجاء المحاولة مرة أخرى.',

    // Password reset, answered identically for a known and an unknown address so the endpoint does
    // not become a way to discover who has an account.
    'password_reset_sent' => 'إذا كان هناك حساب مرتبط بهذا البريد، فسيصلك رابط إعادة التعيين.',
];
