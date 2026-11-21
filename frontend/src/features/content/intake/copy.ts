import type { CheckStatus, IntakeAction, IntakeStatus } from './api'

/*
 * CONTENT-INTAKE-001 — every word of the quality gate, in one place, both languages. Statuses, actions, check names and the
 * AI state are keys here; the screens never spell one inline. Short labels, plain professional Saudi Arabic.
 */
type Pair = { ar: string; en: string }

export const STATUS: Record<IntakeStatus, Pair> = {
  draft: { ar: 'مسودة', en: 'Draft' },
  in_review: { ar: 'قيد المراجعة', en: 'In review' },
  needs_changes: { ar: 'يحتاج تعديل', en: 'Needs changes' },
  approved: { ar: 'معتمد', en: 'Approved' },
  rejected: { ar: 'مرفوض', en: 'Rejected' },
  live: { ar: 'منشور', en: 'Live' },
}

/** Tone classes per status — the same tokens the rest of the product uses. */
export const STATUS_TONE: Record<IntakeStatus, string> = {
  draft: 'bg-surface-secondary text-text-secondary',
  in_review: 'bg-info/15 text-info',
  needs_changes: 'bg-warning/15 text-warning',
  approved: 'bg-success/15 text-success',
  rejected: 'bg-danger/15 text-danger',
  live: 'bg-brand-600/15 text-brand-600',
}

export const ACTION: Record<IntakeAction, Pair> = {
  submit: { ar: 'إرسال للمراجعة', en: 'Send for review' },
  approve: { ar: 'اعتماد', en: 'Approve' },
  reject: { ar: 'رفض', en: 'Reject' },
  request_changes: { ar: 'طلب تعديل', en: 'Request changes' },
  reopen: { ar: 'إعادة فتح', en: 'Reopen' },
  mark_live: { ar: 'تأكيد النشر', en: 'Mark live' },
}

export const CHECK_NAME: Record<string, Pair> = {
  target: { ar: 'المنصة والهدف', en: 'Platform and objective' },
  assets: { ar: 'الملف', en: 'Asset' },
  spec: { ar: 'مواصفات المنصة', en: 'Platform spec' },
  format: { ar: 'نوع الملف', en: 'File type' },
  file_size: { ar: 'حجم الملف', en: 'File size' },
  aspect_ratio: { ar: 'النسبة', en: 'Aspect ratio' },
  resolution: { ar: 'الدقة', en: 'Resolution' },
  duration: { ar: 'المدة', en: 'Duration' },
  safe_zone: { ar: 'المنطقة الآمنة', en: 'Safe zone' },
  copy_length: { ar: 'طول النص', en: 'Text length' },
  utm: { ar: 'رابط التتبع', en: 'Tracking link' },
  naming: { ar: 'التسمية', en: 'Naming' },
  duplicate: { ar: 'التكرار', en: 'Duplicate' },
}

export const CHECK_STATUS: Record<CheckStatus, Pair> = {
  pass: { ar: 'سليم', en: 'Pass' },
  warn: { ar: 'تنبيه', en: 'Warning' },
  fail: { ar: 'فشل', en: 'Fail' },
  not_measured: { ar: 'لم يُقس', en: 'Not measured' },
}

export const CHECK_TONE: Record<CheckStatus, string> = {
  pass: 'bg-success/15 text-success',
  warn: 'bg-warning/15 text-warning',
  fail: 'bg-danger/15 text-danger',
  not_measured: 'bg-surface-secondary text-text-muted',
}

export const AI_FIELD: Record<string, Pair> = {
  description: { ar: 'وصف المحتوى', en: 'Description' },
  hook: { ar: 'الخطاف', en: 'Hook' },
  cta: { ar: 'دعوة الإجراء', en: 'Call to action' },
  on_image_text: { ar: 'النص داخل الصورة', en: 'On-image text' },
  tone: { ar: 'النبرة', en: 'Tone' },
  brand_fit: { ar: 'ملاءمة الهوية', en: 'Brand fit' },
}

export const AI_STATE: Record<string, Pair> = {
  awaiting_credentials: { ar: 'غير مفعّل: بانتظار مفتاح الذكاء الاصطناعي', en: 'Not enabled: awaiting an AI key' },
  not_wired: { ar: 'غير مفعّل: المزوّد غير موصول بعد', en: 'Not enabled: provider not connected yet' },
  disabled: { ar: 'غير مفعّل', en: 'Not enabled' },
  active: { ar: 'مفعّل', en: 'Enabled' },
}

export const EVENT: Record<string, Pair> = {
  created: { ar: 'أُنشئ', en: 'Created' },
  edited: { ar: 'عُدّل', en: 'Edited' },
  rechecked: { ar: 'أُعيد الفحص', en: 'Checks re-run' },
  drive_imported: { ar: 'استُورد من Drive', en: 'Imported from Drive' },
  source_changed: { ar: 'تغيّر الملف في Drive', en: 'Drive file changed' },
  submit: { ar: 'أُرسل للمراجعة', en: 'Sent for review' },
  approve: { ar: 'اعتُمد', en: 'Approved' },
  reject: { ar: 'رُفض', en: 'Rejected' },
  request_changes: { ar: 'طُلب تعديل', en: 'Changes requested' },
  reopen: { ar: 'أُعيد فتحه', en: 'Reopened' },
  mark_live: { ar: 'أُكّد نشره', en: 'Marked live' },
}

export const PLACEMENT: Record<string, Pair> = {
  feed: { ar: 'الخلاصة', en: 'Feed' },
  story: { ar: 'ستوري', en: 'Story' },
  reel: { ar: 'ريلز', en: 'Reels' },
}

export const FAMILY: Record<string, Pair> = {
  awareness: { ar: 'الوعي', en: 'Awareness' },
  traffic: { ar: 'الزيارات', en: 'Traffic' },
  engagement: { ar: 'التفاعل', en: 'Engagement' },
  video: { ar: 'مشاهدات الفيديو', en: 'Video views' },
  leads: { ar: 'العملاء المحتملون', en: 'Leads' },
  sales: { ar: 'المبيعات', en: 'Sales' },
  app: { ar: 'التطبيقات', en: 'App' },
  unknown: { ar: 'غير محدد', en: 'Not set' },
}

export const DRIVE_STATE: Record<string, Pair> = {
  awaiting_credentials: { ar: 'بانتظار ربط Google Drive', en: 'Awaiting Google Drive connection' },
  sandbox_verified: { ar: 'تجريبي', en: 'Sandbox' },
  synced: { ar: 'مُزامَن', en: 'Synced' },
  error: { ar: 'تعذّرت المزامنة', en: 'Sync failed' },
}

export const tr = (pair: Pair | undefined, ar: boolean, fallback = ''): string => (pair ? (ar ? pair.ar : pair.en) : fallback)

export const UI = {
  ar: {
    tabs: { library: 'المكتبة', review: 'الاستلام والمراجعة', followup: 'المتابعة', drive: 'Google Drive' },
    hubPurpose: { review: 'ارفع المحتوى الجديد ليمر بفحص الجودة قبل الإطلاق، ثم يعتمده أو يرفضه المسؤول.', followup: 'ما ينتظر قرارًا، وما أُعيد للتعديل، وما اعتُمد ولم يُنشر.', drive: 'استيراد ملفات Google Drive إلى الاستلام للقراءة فقط.' },
    upload: 'رفع محتوى', all: 'الكل', empty: 'لا يوجد محتوى بعد. ارفع أول ملف ليمر بفحص الجودة.',
    emptyFiltered: 'لا يوجد محتوى بهذه الحالة.', readiness: 'الجاهزية', noScore: 'غير مقاسة',
    noProject: 'اختر مشروعًا أولًا.', noPermission: 'لا تملك صلاحية عرض المحتوى المرفوع.',
    bounded: (shown: number, total: number) => `يظهر ${shown} من ${total}`,
    detail: { checks: 'فحص الجودة', recs: 'التوصيات', history: 'مقارنة بتاريخ المشروع', ai: 'تحليل الذكاء الاصطناعي', trail: 'سجل القرارات', after: 'بعد النشر', file: 'الملف', copy: 'النص', link: 'رابط الوجهة', decision: 'القرار', reason: 'السبب', notes: 'ملاحظات', by: 'بواسطة', recheck: 'إعادة الفحص', edit: 'تعديل', save: 'حفظ', cancel: 'إلغاء', close: 'إغلاق', reasonRequired: 'اكتب السبب أولًا.', creativeId: 'معرّف المحتوى المنشور (اختياري)', noRecs: 'لا توصيات: لا توجد حقائق مقاسة تستدعيها.', coverage: (m: number, t: number) => `قيس ${m} من ${t} فحصًا`, driveNote: 'ملف من Drive: لم تُقرأ محتوياته، فالأبعاد والمدة لا تُقاس.' },
    history: {
      insufficient: (p: number, n: number) => `يوجد ${p} محتوى مقاس فقط لنفس الهدف والمنصة، ونحتاج ${n} على الأقل.`,
      none: 'حدّد المنصة والهدف لتظهر المقارنة.', notReported: 'المنصة لم ترسل مؤشر هذا الهدف بعد.',
      fit: (pct: number, same: number, top: number, fmt: string) => `${same} من أفضل ${top} محتوى في نفس الهدف والمنصة من نوع ${fmt} (${pct}%).`,
    },
    after: { none: 'لم يُربط هذا المحتوى بمحتوى منشور بعد، فلا مقارنة.', no_delivery: 'لا توجد بيانات عرض للمحتوى المربوط في آخر 30 يومًا.', insufficient: 'لا يكفي عدد المحتوى المقارَن.', vs: (v: string, a: string, d: string) => `${v} مقابل متوسط ${a} لنفس الهدف والمنصة (${d})`, rank: (r: number, n: number) => `الترتيب ${r} من ${n}` },
    form: { title: 'رفع محتوى جديد', project: 'المشروع', file: 'الملف (صورة أو فيديو)', name: 'الاسم', platform: 'المنصة', objective: 'الهدف', placement: 'الموضع', headline: 'العنوان', cta: 'دعوة الإجراء', copy: 'النص', url: 'رابط الوجهة', kind: 'أو بدون ملف', kindCopy: 'نص فقط', kindLink: 'رابط فقط', none: 'غير محدد', submit: 'رفع وفحص', tooBig: (mb: number) => `الملف أكبر من ${mb} ميغابايت. استخدم Drive أو رابطًا.`, limit: (mb: number) => `حتى ${mb} ميغابايت. الأكبر يأتي من Drive أو رابط.` },
    follow: { title: 'المتابعة', review: 'بانتظار المراجعة', changes: 'يحتاج تعديل', rejected: 'مرفوض وأسبابه', approved: 'معتمد ولم يُنشر', fatigue: 'منشور وعليه إرهاق', none: 'لا شيء هنا.', fatigueNote: 'إشارة عرض من تقييم الإرهاق نفسه في المكتبة. للتنبيهات افتح صفحة التنبيهات.', open: 'فتح' },
    drive: { title: 'Google Drive', note: 'قراءة فقط: مجلد لكل مشروع، تُستورد ملفاته إلى الاستلام مع فحصها. لا تُنسخ الملفات ولا يُعدَّل شيء في Drive.', awaiting: 'لا يوجد ربط بـ Google Drive بعد، فلا تُعرض أي ملفات. تظهر الملفات هنا بعد توفير الاعتماد.', none: 'لا توجد مجلدات مربوطة بمشاريع. اربط مجلدًا من صفحة الملفات.', sync: 'مزامنة الآن', syncing: 'جارٍ المزامنة…', last: 'آخر مزامنة', never: 'لم تتم بعد', error: 'الخطأ', manage: 'إدارة الروابط', result: (c: number, u: number, k: number, s: number) => `جديد ${c} · محدّث ${u} · دون تغيير ${k} · متجاوَز ${s}`, truncated: 'تجاوز الحد لكل مزامنة، وسيُكمل التشغيل التالي.' },
  },
  en: {
    tabs: { library: 'Library', review: 'Intake and review', followup: 'Follow-up', drive: 'Google Drive' },
    hubPurpose: { review: 'Upload new content to pass the quality gate before launch; an approver then approves or rejects it.', followup: 'What waits on a decision, what went back for changes, and what was approved but not launched.', drive: 'Import Google Drive files into intake, read-only.' },
    upload: 'Upload content', all: 'All', empty: 'No content yet. Upload a first file and it goes through the quality gate.',
    emptyFiltered: 'No content in this state.', readiness: 'Readiness', noScore: 'Not measured',
    noProject: 'Choose a project first.', noPermission: 'You do not have permission to view uploaded content.',
    bounded: (shown: number, total: number) => `Showing ${shown} of ${total}`,
    detail: { checks: 'Quality checks', recs: 'Recommendations', history: 'Against the project history', ai: 'AI analysis', trail: 'Decision trail', after: 'After launch', file: 'File', copy: 'Text', link: 'Destination link', decision: 'Decision', reason: 'Reason', notes: 'Notes', by: 'by', recheck: 'Re-run checks', edit: 'Edit', save: 'Save', cancel: 'Cancel', close: 'Close', reasonRequired: 'Write the reason first.', creativeId: 'Live content id (optional)', noRecs: 'No recommendations: no measured fact triggers one.', coverage: (m: number, t: number) => `${m} of ${t} checks measured`, driveNote: 'A Drive file: its bytes are not read, so size, ratio and duration are not measured.' },
    history: {
      insufficient: (p: number, n: number) => `Only ${p} measured items share this objective and platform; at least ${n} are needed.`,
      none: 'Set the platform and objective to compare.', notReported: 'The platform has not reported this objective’s metric yet.',
      fit: (pct: number, same: number, top: number, fmt: string) => `${same} of the top ${top} items for this objective and platform are ${fmt} (${pct}%).`,
    },
    after: { none: 'This item is not linked to live content yet, so there is no comparison.', no_delivery: 'The linked content has no delivery data in the last 30 days.', insufficient: 'Not enough comparable content.', vs: (v: string, a: string, d: string) => `${v} against an average of ${a} for the same objective and platform (${d})`, rank: (r: number, n: number) => `Rank ${r} of ${n}` },
    form: { title: 'Upload new content', project: 'Project', file: 'File (image or video)', name: 'Name', platform: 'Platform', objective: 'Objective', placement: 'Placement', headline: 'Headline', cta: 'Call to action', copy: 'Text', url: 'Destination link', kind: 'Or without a file', kindCopy: 'Text only', kindLink: 'Link only', none: 'Not set', submit: 'Upload and check', tooBig: (mb: number) => `The file is over ${mb} MB. Use Drive or a link.`, limit: (mb: number) => `Up to ${mb} MB. Larger files come from Drive or a link.` },
    follow: { title: 'Follow-up', review: 'Awaiting review', changes: 'Needs changes', rejected: 'Rejected, with reasons', approved: 'Approved, not launched', fatigue: 'Live with fatigue', none: 'Nothing here.', fatigueNote: 'A view signal from the same fatigue assessment the library uses. For alerts, open the alerts page.', open: 'Open' },
    drive: { title: 'Google Drive', note: 'Read-only: one folder per project, its files are imported into intake and checked. Files are not copied and nothing in Drive is changed.', awaiting: 'Google Drive is not connected yet, so no files are shown. They appear here once the credential is provided.', none: 'No folders are linked to projects. Link one from the files page.', sync: 'Sync now', syncing: 'Syncing…', last: 'Last sync', never: 'Not yet', error: 'Error', manage: 'Manage links', result: (c: number, u: number, k: number, s: number) => `new ${c} · updated ${u} · unchanged ${k} · skipped ${s}`, truncated: 'Over the per-sync limit; the next run continues.' },
  },
}
