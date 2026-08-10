<?php

declare(strict_types=1);

/*
 * Arabic authentication messages (I18N-001).
 *
 * `failed` deliberately does not distinguish "no such account" from "wrong password" — that is an
 * account-enumeration answer, not a helpful one, and the Arabic text must not become friendlier than
 * the English by giving it away.
 */

return [
    'failed' => 'بيانات الدخول غير صحيحة.',
    'password' => 'كلمة المرور غير صحيحة.',
    'throttle' => 'محاولات تسجيل دخول كثيرة. الرجاء المحاولة بعد :seconds ثانية.',

    // Not part of Laravel's file — CampaignsHub's own auth answers.
    'signed_in' => 'تم تسجيل الدخول بنجاح.',
    'code_sent' => 'تم إرسال رمز التحقق.',
    // LOGIN-OTP-001 — نافذة إعادة الإرسال على الخادم، بنفس الصياغة التي يستخدمها العدّاد في الواجهة.
    'resend_cooldown' => 'الرجاء الانتظار :seconds ثانية قبل طلب رمز جديد.',
    // الطرق الثلاث التي يُرفض بها الرمز، بلغة القارئ نفسها (LOGIN-E2E-001).
    'code_expired' => 'انتهت صلاحية رمز التحقق. اطلب رمزًا جديدًا.',
    'code_attempts' => 'محاولات كثيرة. اطلب رمزًا جديدًا.',
    'code_incorrect' => 'الرمز غير صحيح.',
    'signed_out' => 'تم تسجيل الخروج بنجاح.',
    'current_user' => 'المستخدم الحالي.',
    'token_issued' => 'تم إصدار رمز الوصول بنجاح.',

    /*
     * The suspended-account answer, kept deliberately vague.
     *
     * Whether an account was disabled by its owner, by the platform, or because every workspace it
     * belongs to is suspended is not something to tell whoever is holding the password.
     */
    'unavailable' => 'هذا الحساب غير متاح حاليًا. الرجاء التواصل مع الدعم.',

    /*
     * The wrong-portal refusal (LOGIN-003).
     *
     * The credentials were correct and only the portal was wrong, so this must not read like a
     * failed password — it says what happened and the response carries where the account belongs.
     */
    'portal_mismatch' => 'هذا الحساب غير مخوّل للدخول إلى هذه البوابة.',
];
