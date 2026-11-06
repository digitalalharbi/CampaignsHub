/** Delivery states as the ledger records them — never invented, never upgraded to "تم الإرسال". */
export const DELIVERY_LABEL: Record<string, { ar: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }> = {
  // The mail server took it. Nobody has confirmed receipt, so this never says «delivered».
  sent: { ar: 'قبلها خادم البريد', tone: 'success' },
  queued: { ar: 'في الانتظار', tone: 'neutral' },
  suppressed: { ar: 'لم تُرسل عمدًا', tone: 'neutral' },
  unsubscribed: { ar: 'ألغى المستلم اشتراكه', tone: 'neutral' },
  limit_reached: { ar: 'بلغت الحدّ اليومي', tone: 'warning' },
  // The mail ledger writes these back. A test driver reaches nobody, and that is not an acceptance.
  refused_scope: { ar: 'خارج نطاق المشروع', tone: 'warning' },
  sandbox: { ar: 'وضع الاختبار: لم تصل إلى أحد', tone: 'neutral' },
  awaiting_provider_credentials: { ar: 'بانتظار مزود بريد', tone: 'warning' },
  suppressed_internal: { ar: 'محجوبة (تقرير داخلي)', tone: 'neutral' },
  failed: { ar: 'فاشلة', tone: 'danger' },
}

/** Why a delivery was held back. Each one is a different thing to do, so each is said. */
export const HOLD_REASON: Record<string, string> = {
  schedule_mail_off: 'بريد التقارير المجدولة غير مفعّل بعد',
  outside_recipient_off: 'الإرسال إلى مستلمين خارج مساحة العمل غير مفعّل',
  outside_project_access: 'المستلم لا يصل إلى هذا المشروع',
  internal_audience: 'تقرير داخلي لا يُرسل خارج مساحة العمل',
  report_failed: 'تعذّر إنشاء التقرير',
  could_not_compose: 'تعذّر تجهيز الرسالة',
}
