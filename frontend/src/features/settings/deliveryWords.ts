/**
 * The words for a message's stage — EMAIL-DELIVERY-LEDGER-001 (§17.4.4, §17.4.6).
 *
 * One vocabulary for the operator's console, the workspace's log and the settings screen. The stage is
 * decided on the server (`MailDeliveryState`); this only names it. «Accepted» is said as what it is,
 * the receiving server took the message, because «sent» or «delivered» here is a claim nobody can back.
 */
export interface StageWords { ar: string; en: string; tone: string }

export const STAGE_WORDS: Record<string, StageWords> = {
  queued: { ar: 'في الانتظار', en: 'Queued', tone: 'text-text-muted' },
  attempted: { ar: 'قيد المحاولة', en: 'Being attempted', tone: 'text-text-muted' },
  accepted: { ar: 'قبلها خادم البريد', en: 'Accepted by the mail server', tone: 'text-success' },
  delivered: { ar: 'تأكّد استلامها', en: 'Receipt confirmed', tone: 'text-success' },
  failed: { ar: 'فشلت', en: 'Failed', tone: 'text-danger' },
  suppressed: { ar: 'لم تُرسل عمدًا', en: 'Deliberately not sent', tone: 'text-warning' },
}

export const stageWords = (state: string): StageWords =>
  STAGE_WORDS[state] ?? { ar: state, en: state, tone: 'text-text-muted' }

/** Why it was not sent. Each one is a different thing to do, so each is said. */
export const SUPPRESSION_WORDS: Record<string, { ar: string; en: string }> = {
  no_provider: { ar: 'لا يوجد مزوّد بريد مربوط، فلم يغادر شيء.', en: 'No mail provider is wired, so nothing left.' },
  sandbox_transport: { ar: 'وضع الاختبار: كُتبت في السجل ولا تصل إلى أحد.', en: 'Sandbox: written to the log, and it reaches nobody.' },
  nothing_to_report: { ar: 'لا يوجد ما يُرسل في هذه الفترة.', en: 'There was nothing to send for that period.' },
  preference: { ar: 'أوقف المستلم هذا النوع من إعداداته.', en: 'The recipient switched this type off.' },
  unsubscribed: { ar: 'ألغى المستلم اشتراكه في هذا النوع من رابط الرسالة.', en: 'The recipient unsubscribed from this type through the link in a message.' },
  daily_limit: { ar: 'بلغت مساحة العمل حدّها اليومي للرسائل، فلم تُرسل هذه الرسالة.', en: 'The workspace reached its daily message limit, so this one was not sent.' },
  repeat_limit: { ar: 'وصل هذا التنبيه إلى المستلم بالعدد الأقصى خلال 24 ساعة، فلم يُرسل مرة أخرى. ما زال ظاهرًا في المنصة.', en: 'This alert already reached the recipient the most times allowed in 24 hours, so it was not sent again. It is still shown in the product.' },
  outside_scope: { ar: 'المستلم خارج نطاق هذه الرسالة في مساحة العمل أو المشروع، فلم تُرسل.', en: 'The recipient is outside this message\'s workspace or project, so it was not sent.' },
}

/** The true operational reason and the step that fixes it. No transport text, so nothing secret can be in it. */
export const FAILURE_WORDS: Record<string, { ar: string; en: string }> = {
  auth_failed: { ar: 'رفض خادم البريد تسجيل الدخول. راجع اسم المستخدم وكلمة مرور SMTP في إعدادات الخادم.', en: 'The mail server refused the sign-in. Check the SMTP username and password in the server settings.' },
  connection_failed: { ar: 'تعذّر الوصول إلى خادم البريد. راجع العنوان والمنفذ وحالة الخادم.', en: 'The mail server could not be reached. Check the host, the port and the server status.' },
  tls_failed: { ar: 'فشل الاتصال الآمن بخادم البريد. راجع إعداد التشفير والشهادة.', en: 'The secure connection to the mail server failed. Check the encryption setting and the certificate.' },
  throttled: { ar: 'حدّ خادم البريد من الإرسال مؤقتًا. ستُعاد المحاولة تلقائيًا.', en: 'The mail server is limiting sends for now. It will be tried again automatically.' },
  recipient_rejected: { ar: 'رفض الخادم عنوان المستلم. تأكد من صحة البريد الإلكتروني.', en: 'The server refused the recipient address. Check the email address is right.' },
  sender_rejected: { ar: 'رفض الخادم عنوان المُرسِل. راجع إعداد عنوان الإرسال والنطاق.', en: 'The server refused the sender address. Check the from address and its domain.' },
  template_error: { ar: 'تعذّر تجهيز الرسالة نفسها. هذا عطل في المنصة ولا تفيد فيه إعادة المحاولة.', en: 'The message itself could not be built. This is a platform fault and retrying will not help.' },
  unknown: { ar: 'فشل غير مصنّف. راجع التفاصيل في السجل.', en: 'An unclassified failure. See the detail in the log.' },
}
