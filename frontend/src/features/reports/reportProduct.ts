import type { Locale } from '@/stores/ui'

/**
 * REPORT-PRODUCT-MODEL-001 — four products, named, and a label that cannot promise a fifth.
 *
 * ## The defect this exists to end
 *
 * A report is two independent facts: its MODE (live, recomputed when opened — or a snapshot, the
 * document that was signed off) and its FORM (a summary, or the full detail). Four combinations, all
 * of which the product can produce. The label only ever read the FORM, so the live link printed
 * «تقرير تفصيلي — كل المنصات والحملات والإعلانات» over the live dashboard, which renders none of
 * that: a promise of every platform, campaign and creative, above a page with a spend chart and a
 * top-eight bar. Production served exactly that to a client.
 *
 * Naming the four is the fix, because a label derived from one axis will always eventually describe
 * a composition chosen by the other. What each one says is what its page actually renders — the
 * sentence is the contract, and `liveReportForm.test.tsx` holds the page to it.
 */
export type ReportMode = 'live' | 'snapshot'
export type ReportForm = 'executive_summary' | 'detailed'

const PRODUCTS: Record<`${ReportMode}:${ReportForm}`, { ar: string; en: string }> = {
  /*
   * A live link is a product with modes now (`live/modes.ts`), so its sentence names what the reader
   * can do rather than listing contents — and it stays one line: the client came for figures.
   */
  /* CLIENT-LIVE-LINK-SAFETY-001 — the product's name is «تقرير رابط», on both forms. */
  'live:executive_summary': {
    ar: 'تقرير رابط — ملخص يُحدَّث عند كل فتح.',
    en: 'Link Report — a summary recomputed every time it is opened.',
  },
  'live:detailed': {
    ar: 'تقرير رابط — ملخص، أداء، منصات، ومحتوى.',
    en: 'Link Report — summary, performance, platforms and content.',
  },
  'snapshot:executive_summary': {
    ar: 'تقرير محفوظ — أبرز النتائج والقرارات في وثيقة ثابتة.',
    en: 'Saved Report — the headline results and decisions, fixed as a document.',
  },
  'snapshot:detailed': {
    ar: 'تقرير محفوظ — كل المنصات والأهداف والمحتوى الأعلى أداءً.',
    en: 'Saved Report — every platform and objective, and the best-performing content.',
  },
}

export function productLabel(mode: string, form: string, locale: Locale): string {
  const key = `${mode === 'live' ? 'live' : 'snapshot'}:${form === 'executive_summary' ? 'executive_summary' : 'detailed'}` as const
  const product = PRODUCTS[key]

  return locale === 'ar' ? product.ar : product.en
}

/**
 * The short name of the product, for a place with no room for the sentence.
 *
 * REPORT-PRODUCTS-001 — exactly two names, whatever the detail level: «تقرير رابط» / Link Report and
 * «تقرير محفوظ» / Saved Report. «ملخص تنفيذي», «تقرير تفصيلي» and «رابط لحظي للعميل» are not products
 * any more; how much a report shows is its DETAIL LEVEL, a setting, not a name.
 */
export function productName(mode: string, _form: string, locale: Locale): string {
  const ar = locale === 'ar'

  return mode === 'live' ? (ar ? 'تقرير رابط' : 'Link Report') : (ar ? 'تقرير محفوظ' : 'Saved Report')
}

/**
 * REPORT-CLIENT-WORDING-001 — the sentence a CLIENT reads under the report's title.
 *
 * «Link Report» and «Saved Report» are the agency's own product names: how the agency builds the thing, not
 * what the client is looking at. A client who is sent a report should read what it contains and how fresh it
 * is, never the vocabulary of the tool that made it. The Owner-side names above are unchanged.
 */
export function clientBlurb(mode: string, locale: Locale): string {
  const ar = locale === 'ar'

  return mode === 'live'
    ? (ar ? 'ملخص الأداء والمنصات والمحتوى — الأرقام محدَّثة عند كل فتح.' : 'Performance summary, platforms and content — figures are refreshed every time you open it.')
    : (ar ? 'ملخص الأداء والمنصات والمحتوى كما أُعدّ للفترة المحددة.' : 'Performance summary, platforms and content as prepared for the period.')
}
