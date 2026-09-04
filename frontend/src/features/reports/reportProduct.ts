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
  'live:executive_summary': {
    ar: 'لوحة مباشرة — الأرقام تُحسب عند فتح الصفحة، ويمكنك تغيير الفترة والمنصات.',
    en: 'Live dashboard — the figures are computed when you open the page, and you can change the period and platforms.',
  },
  'live:detailed': {
    ar: 'تقرير مباشر تفصيلي — اللوحة نفسها، ومعها كل منصة وهدف في الفترة المختارة.',
    en: 'Live detailed report — the dashboard, and with it every platform and objective in the chosen period.',
  },
  'snapshot:executive_summary': {
    ar: 'ملخص تنفيذي — أبرز النتائج والقرارات. التفاصيل الكاملة في التقرير التفصيلي.',
    en: 'Executive summary — the headline results and decisions. Full detail lives in the detailed report.',
  },
  'snapshot:detailed': {
    ar: 'تقرير تفصيلي — كل المنصات والأهداف والمحتوى الأعلى أداءً.',
    en: 'Detailed report — every platform and objective, and the best-performing content.',
  },
}

export function productLabel(mode: string, form: string, locale: Locale): string {
  const key = `${mode === 'live' ? 'live' : 'snapshot'}:${form === 'executive_summary' ? 'executive_summary' : 'detailed'}` as const
  const product = PRODUCTS[key]

  return locale === 'ar' ? product.ar : product.en
}

/** The short name of the product, for a place with no room for the sentence. */
export function productName(mode: string, form: string, locale: Locale): string {
  const ar = locale === 'ar'

  if (mode === 'live') {
    return form === 'executive_summary'
      ? (ar ? 'لوحة مباشرة' : 'Live dashboard')
      : (ar ? 'تقرير مباشر تفصيلي' : 'Live detailed report')
  }

  return form === 'executive_summary'
    ? (ar ? 'ملخص تنفيذي' : 'Executive summary')
    : (ar ? 'تقرير تفصيلي' : 'Detailed report')
}
