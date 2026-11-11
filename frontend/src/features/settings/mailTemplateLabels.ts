import type { Words } from './messageLabels'

/**
 * What each gallery template is called, for a person reviewing them — EMAIL-TEST-SEND-001 (§17.4.5).
 *
 * The server sends keys (`account-sign-in-code`). A key with no entry falls back to itself, so a new
 * template still appears in the list rather than vanishing from a review that claims to be complete.
 */
export const MAIL_TEMPLATE_LABELS: Record<string, Words> = {
  'account-password-reset': { ar: 'استعادة كلمة المرور', en: 'Password reset' },
  'account-email-verification': { ar: 'تأكيد البريد الإلكتروني', en: 'Email verification' },
  'account-sign-in-code': { ar: 'رمز تسجيل الدخول', en: 'Sign-in code' },
  'account-member-setup': { ar: 'تجهيز حساب عضو', en: 'Member account setup' },
  'account-invitation': { ar: 'دعوة عضو', en: 'Member invitation' },
  'account-owner-invitation': { ar: 'دعوة مالك مساحة عمل', en: 'Workspace owner invitation' },
  'account-invitation-unknown-role': { ar: 'دعوة بلا دور محدد', en: 'Invitation without a named role' },
  'account-security-sign-in': { ar: 'تنبيه أمني: تسجيل دخول جديد', en: 'Security: new sign-in' },
  'account-password-changed': { ar: 'تنبيه أمني: تغيّرت كلمة المرور', en: 'Security: password changed' },
  'account-security-sparse': { ar: 'تنبيه أمني ببيانات قليلة', en: 'Security alert with little detail' },
  'digest-daily': { ar: 'الملخص اليومي', en: 'Daily digest' },
  'digest-weekly': { ar: 'الملخص الأسبوعي', en: 'Weekly digest' },
  'digest-monthly': { ar: 'الملخص الشهري', en: 'Monthly digest' },
  'digest-agency-branded': { ar: 'ملخص باسم الوكالة', en: 'Agency-branded digest' },
  'digest-restricted-daily': { ar: 'ملخص يومي بلا مبالغ مالية', en: 'Daily digest without money' },
  'digest-client-weekly': { ar: 'ملخص العميل الأسبوعي', en: 'Client weekly digest' },
  'digest-single-project': { ar: 'ملخص مشروع واحد', en: 'Single-project digest' },
  // DIGESTS-CADENCE-BUDGETS-001 — the approved templates on every rhythm, and one card per alert family.
  'digest-project': { ar: 'تقرير المشروع الأسبوعي', en: 'Weekly project report' },
  'digest-project-leads': { ar: 'تقرير المشروع اليومي', en: 'Daily project report' },
  'digest-project-monthly': { ar: 'تقرير المشروع الشهري', en: 'Monthly project report' },
  'digest-portfolio': { ar: 'ملخص المشاريع (ثلاثة مشاريع)', en: 'Projects summary (three projects)' },
  'digest-projects-daily': { ar: 'ملخص المشاريع اليومي', en: 'Daily projects summary' },
  'digest-projects-weekly': { ar: 'ملخص المشاريع الأسبوعي', en: 'Weekly projects summary' },
  'digest-projects-monthly': { ar: 'ملخص المشاريع الشهري', en: 'Monthly projects summary' },
  'alert-budget': { ar: 'تنبيه: الميزانية', en: 'Alert: budget' },
  'alert-roas': { ar: 'تنبيه: العائد', en: 'Alert: return on spend' },
  'alert-cpl': { ar: 'تنبيه: تكلفة المهتم', en: 'Alert: cost per lead' },
  'alert-sync': { ar: 'تنبيه: توقف المزامنة', en: 'Alert: sync stopped' },
  'alert-tracking': { ar: 'تنبيه: توقف قياس الموقع', en: 'Alert: website measurement stopped' },
  'alert-leads': { ar: 'تنبيه: المهتمين', en: 'Alert: leads' },
  'alert-launch': { ar: 'تنبيه: الحساب جاهز لمرحلة أقوى', en: 'Alert: ready for a stronger stage' },
  'alert-report-failed': { ar: 'تنبيه: التقرير ما انطلع', en: 'Alert: report failed' },
  'alert-security': { ar: 'تنبيه أمني', en: 'Security alert' },
  'alert-sample': { ar: 'تنبيه تجريبي', en: 'Sample alert' },
  'alerts-bundle': { ar: 'مجموعة تنبيهات', en: 'Alert bundle' },
  'alerts-one': { ar: 'تنبيه واحد', en: 'Single alert' },
  'delivery-reminder': { ar: 'تذكير بالتسليم', en: 'Delivery reminder' },
  'report-ready': { ar: 'التقرير جاهز', en: 'Report ready' },
  billing: { ar: 'الفواتير والاشتراك', en: 'Billing and subscription' },
  approval: { ar: 'طلب موافقة', en: 'Approval request' },
  message: { ar: 'رسالة جديدة', en: 'New message' },
  'report-scheduled': { ar: 'التقرير المجدول', en: 'Scheduled report' },
  'report-scheduled-sparse': { ar: 'تقرير مجدول ببيانات قليلة', en: 'Scheduled report with little data' },
  'request-to-team': { ar: 'طلب عميل إلى الفريق', en: 'Client request to the team' },
  'request-to-client': { ar: 'تحديث طلب إلى العميل', en: 'Request update to the client' },
  'member-welcome': { ar: 'ترحيب بعضو جديد', en: 'New member welcome' },
  'role-changed': { ar: 'تغيّر دور عضو', en: 'Member role changed' },
}

export const mailTemplateLabel = (key: string, ar: boolean): string => {
  const words = MAIL_TEMPLATE_LABELS[key]
  return words ? (ar ? words.ar : words.en) : key
}
