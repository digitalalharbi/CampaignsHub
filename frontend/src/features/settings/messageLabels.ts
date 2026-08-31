/**
 * What each message is called, in the reader's own words — MAIL-011.
 *
 * The server sends keys (`budget_pace`, `frequency_saturation`); this is the only place they become
 * something a person can decide about. Two rules held throughout:
 *
 * - **The label says what arrives, not what the code detects.** «تنبيه استهلاك الميزانية» rather than
 *   «budget pace anomaly». Somebody deciding whether to be interrupted needs to know what the
 *   interruption will say.
 * - **The note answers «when would I get this?»** A switch with no answer to that gets left alone in
 *   whatever state it shipped in, which is the same as not offering it.
 *
 * A key with no entry falls back to itself, so a new message type appears on the screen — unlabelled
 * but present — rather than vanishing from a list that claims to be complete.
 */

export interface Words { ar: string; en: string }

export const CATEGORY_LABELS: Record<string, Words> = {
  performance: { ar: 'الأداء', en: 'Performance' },
  budget: { ar: 'الميزانية', en: 'Budget' },
  // The KEY stays `content` — it is stored on rows and read by the backend. Only the words change:
  // ADS-TERMINOLOGY-001 gives the advertising entity ONE user-facing name.
  content: { ar: 'الإعلانات', en: 'Ads' },
  integrations: { ar: 'التكاملات', en: 'Integrations' },
  reports: { ar: 'التقارير', en: 'Reports' },
  operations: { ar: 'التشغيل', en: 'Operations' },
  billing: { ar: 'المالية', en: 'Billing' },
  account: { ar: 'الحساب والأمان', en: 'Account & security' },
  // Older arrangement rows still carry these two; MAIL-011 folded both into `integrations`.
  sync: { ar: 'المزامنة', en: 'Syncing' },
  token: { ar: 'انتهاء الصلاحيات', en: 'Token expiry' },
  security: { ar: 'الأمان', en: 'Security' },
}

export const CATEGORY_NOTES: Record<string, Words> = {
  performance: {
    ar: 'ملاحظات عن حركة المؤشرات: معدل التحويل، والمقارنة بالفترة السابقة.',
    en: 'Notes about how the figures moved: conversion rate, and the comparison with the previous period.',
  },
  budget: {
    ar: 'ما يكلّفك مالًا وأنت غير منتبه: سرعة الاستهلاك، وارتفاع التكلفة، وتوزيع الإنفاق.',
    en: 'What costs money while nobody is looking: pacing, rising cost, and how spend is distributed.',
  },
  content: {
    ar: 'ما يخصّ الإعلان نفسه، مثل تكرار ظهوره على الجمهور نفسه.',
    en: 'What concerns the creative itself — such as the same audience seeing it too often.',
  },
  integrations: {
    ar: 'حالة المصادر: توقّف المزامنة، وانقطاع البيانات، وقرب انتهاء صلاحية الربط.',
    en: 'The state of your sources: syncing that stopped, gaps in the data, and connections about to expire.',
  },
  reports: { ar: 'الملخصات الدورية والتقارير الجاهزة.', en: 'The periodic summaries and finished reports.' },
  operations: {
    ar: 'ما يحتاج ردًا منك: الطلبات، والموافقات، والرسائل.',
    en: 'What needs an answer from you: requests, approvals and messages.',
  },
  billing: { ar: 'الفواتير والاشتراك.', en: 'Invoices and your subscription.' },
  account: {
    ar: 'رسائل تُرسل عند الحاجة إليها ولا يمكن إيقافها — لأنها إما ردّ على شيء فعلته للتو، أو التحذير الوحيد الذي ستصله إن دخل أحد إلى حسابك.',
    en: 'Sent whenever they apply, and cannot be switched off — each is either the answer to something you just did, or the only warning you would get that somebody else is in your account.',
  },
}

export const TYPE_LABELS: Record<string, Words> = {
  // الأداء
  falling_rate: { ar: 'انخفاض معدل التحويل', en: 'Conversion rate falling' },
  period_comparison: { ar: 'مقارنة بالفترة السابقة', en: 'Compared with the previous period' },
  // الميزانية
  budget_pace: { ar: 'سرعة استهلاك الميزانية', en: 'Budget pacing' },
  rising_cost: { ar: 'ارتفاع تكلفة النتيجة', en: 'Cost per result rising' },
  reallocation: { ar: 'إعادة توزيع الإنفاق', en: 'Spend worth reallocating' },
  budget_risk: { ar: 'تجاوز حدود الميزانية', en: 'Budget threshold crossed' },
  // المحتوى
  frequency_saturation: { ar: 'تشبّع تكرار الإعلان', en: 'Creative frequency saturated' },
  // التكاملات
  stale_data: { ar: 'بيانات لم تُحدَّث', en: 'Data has stopped updating' },
  data_gap: { ar: 'انقطاع في البيانات', en: 'A gap in the data' },
  sync_failed: { ar: 'فشل المزامنة', en: 'Syncing failed' },
  token_expiring: { ar: 'قرب انتهاء صلاحية الربط', en: 'A connection is about to expire' },
  // التقارير
  daily_digest: { ar: 'الملخص اليومي', en: 'Daily summary' },
  weekly_digest: { ar: 'الملخص الأسبوعي', en: 'Weekly summary' },
  report_ready: { ar: 'تقرير جاهز', en: 'A report is ready' },
  report_failed: { ar: 'تعذّر إنشاء تقرير', en: 'A report could not be built' },
  // التشغيل
  message: { ar: 'رسالة جديدة', en: 'A new message' },
  request: { ar: 'طلب جديد أو تحديث عليه', en: 'A request, new or updated' },
  journey_transition: { ar: 'انتقال طلب إلى مرحلة جديدة', en: 'A request moved to a new stage' },
  client_needs_attention: { ar: 'عميل يحتاج انتباهًا', en: 'A client needs attention' },
  // المالية
  subscription: { ar: 'الاشتراك والفواتير', en: 'Subscription and invoices' },
  // الحساب
  password_reset: { ar: 'إعادة تعيين كلمة المرور', en: 'Password reset' },
  email_verification: { ar: 'تأكيد البريد الإلكتروني', en: 'Email verification' },
  sign_in_code: { ar: 'رمز تسجيل الدخول', en: 'Sign-in code' },
  member_setup: { ar: 'تهيئة حساب عضو جديد', en: 'New member account setup' },
  invitation: { ar: 'دعوة للانضمام', en: 'Invitation to join' },
  security_alert: { ar: 'تنبيه أمني', en: 'Security alert' },
}

export const TYPE_NOTES: Record<string, Words> = {
  daily_digest: {
    ar: 'ملخص أمس لكل مشروع تصل إليه، في الساعة التي تختارها.',
    en: 'Yesterday, for every project you can see, at the hour you choose.',
  },
  weekly_digest: { ar: 'ملخص الأسبوع المنتهي، يصل صباح الاثنين.', en: 'The finished week, on Monday morning.' },
  message: {
    ar: 'يصلك داخل النظام دائمًا. البريد مغلق افتراضيًا لأن رسائل المحادثات كثيرة.',
    en: 'Always reaches the bell. Email is off by default — conversations are frequent.',
  },
  security_alert: {
    ar: 'عند تسجيل دخول جديد أو تغيير كلمة المرور.',
    en: 'On a new sign-in, or when your password changes.',
  },
}

export function words(map: Record<string, Words>, key: string, ar: boolean): string {
  const w = map[key]
  return w ? (ar ? w.ar : w.en) : key
}
