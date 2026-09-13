/**
 * REPORT-EXPORT-FUNCTIONAL-001 — what an operator is told when an export fails.
 *
 * The backend classifies the renderer's own message into a code (`ExportFailureReason`) and never
 * publishes the message itself: it carries stderr, binary names and absolute server paths. So the
 * sentence lives here, in both languages, and every code the backend can send has one.
 *
 * Each line is written to be ACTED on. «Export failed» tells an operator to click again, which is
 * exactly wrong when the renderer is switched off on the server — that needs somebody with access
 * to the deployment, and saying so is the difference between a report that gets sent today and a
 * client who is told the tool is broken.
 */
export const EXPORT_FAILURE_COPY: Record<string, { ar: string; en: string }> = {
  renderer_disabled: {
    ar: 'مُصدِّر PDF غير مُفعَّل على هذا الخادم — يحتاج تفعيلًا من إدارة النظام، وإعادة المحاولة وحدها لن تنجح.',
    en: 'The PDF renderer is not enabled on this server. It needs enabling in the deployment — retrying alone will not help.',
  },
  renderer_unavailable: {
    ar: 'تعذّر تشغيل مُحرّك الطباعة على الخادم. أبلغ إدارة النظام؛ الملف لم يُنشأ.',
    en: 'The print engine could not start on the server. Tell your administrator — no file was produced.',
  },
  text_layer_failed: {
    ar: 'تعذّر التحقق من طبقة النص العربية، ولم يُسلَّم ملف حتى لا يصل العميل ملفًا لا يمكن نسخ نصه أو البحث فيه.',
    en: 'The Arabic text layer could not be validated, and no file was shipped rather than send a client a PDF whose text cannot be copied or searched.',
  },
  data_not_ready: {
    ar: 'بيانات التقرير لم تعد متسقة مع نصه. أعد توليد التقرير ثم صدّره.',
    en: 'The report’s data no longer agrees with its narrative. Regenerate the report, then export it.',
  },
  timed_out: {
    ar: 'استغرق التوليد وقتًا أطول من المسموح. أعد المحاولة؛ فإن تكرّر فالتقرير أكبر من أن يُرسم بهذا الحجم.',
    en: 'Generating the file took longer than allowed. Try again — if it repeats, the report is too large to render at this size.',
  },
  export_failed: {
    ar: 'فشل التصدير، ولم يُسجَّل سبب يمكن عرضه. أعد المحاولة، وإن تكرّر فأبلغ إدارة النظام.',
    en: 'The export failed and recorded no reason that can be shown. Try again, and report it if it repeats.',
  },
}

/**
 * A code with no sentence must still say something true, so an unknown code reads as the generic
 * failure rather than as an empty tooltip — the state this whole unit exists to remove.
 */
export function exportFailureCopy(reason: string | null | undefined, ar: boolean): string {
  const entry = (reason && EXPORT_FAILURE_COPY[reason]) || EXPORT_FAILURE_COPY.export_failed
  return ar ? entry.ar : entry.en
}
