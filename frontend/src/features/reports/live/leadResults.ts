import { canonicalPlatform } from '@/lib/platforms'
import type { LivePayload } from '../api'

/**
 * NASEL 2026-10-29 — the ONE reading of verified leads every summary block draws from: `results.leads`, which the server's single resolver
 * (`ResultEvents::leadBreakdown`) computed. The platforms summary shows native lead-form results only; landing-page completions are their own
 * figure and are never folded into a platform card; «total interest» is the two added — exactly the server's `total`.
 */
export type NativeLeads = { total: number; of: (provider: string) => number | null }

export function nativeLeads(payload: LivePayload): NativeLeads | null {
  const leads = payload.results?.leads
  if (!leads?.configured || leads.native === null || leads.native === undefined) return null
  const byPlatform = leads.native_by_platform ?? {}

  return {
    total: Number(leads.native),
    of: (provider) => {
      const value = byPlatform[canonicalPlatform(provider)]
      return value === undefined ? null : Number(value)
    },
  }
}

/** True when every platform's landing-page result is the `purchases` event — then it stands in for a form completion and is named so. */
export function purchaseIsLandingProxy(payload: LivePayload): boolean {
  const events = payload.results?.leads?.events ?? {}
  const landing = Object.values(events).map((paths) => paths.landing_page).filter((m): m is string => typeof m === 'string')

  return landing.length > 0 && landing.every((m) => m === 'purchases')
}

export function landingLabel(payload: LivePayload, ar: boolean): string {
  return purchaseIsLandingProxy(payload) ? (ar ? 'إتمامات نموذج الصفحة' : 'Landing-page form completions') : (ar ? 'عملاء صفحة الهبوط' : 'Landing-page leads')
}

export const NATIVE_LEADS_LABEL = { ar: 'العملاء المحتملون الأصليون', en: 'Native lead-form leads' }

/**
 * NASEL 2026-10-29 — the report's own words for «Results», and whether it has Results at all.
 *
 * `results_label` is the link's name for them («الاهتمام» → «تكلفة الاهتمام»). `shown` is false where the link defines no Results: then the
 * word, its cost and any series or column named after it are absent from every view — the platform's generic `conversions` is never
 * printed under that name. `series` is the one daily line a chart may draw as the result: native lead-form submissions where verified leads
 * are configured (`form_leads` per day), and nothing otherwise, because no other daily figure comes from the resolver.
 */
export function resultWords(payload: LivePayload, ar: boolean): { result: string; cost: string; shown: boolean; off: boolean; legacy: boolean; series: { key: string; name: string } | null } {
  const custom = typeof payload.results_label === 'string' && payload.results_label.trim() !== '' ? payload.results_label.trim() : null
  const off = payload.results_off === true
  // A link that never defined Results keeps the platform's own result as before (`legacy`); only an explicit «none» turns to clicks.
  const legacy = !off && payload.results?.configured !== true
  const shown = !off
  /*
   * Owner 2026-10-29: with every Results path on «none», each place that would say «النتائج» reads CLICKS instead — «النقرات»,
   * «تكلفة النقرة» — from the same platform rows. With Results defined on an event, the resolver's Results stay exactly as they are.
   */
  const result = off ? (ar ? 'النقرات' : 'Clicks') : custom ?? (ar ? 'النتائج' : 'Results')
  const cost = off ? (ar ? 'تكلفة النقرة' : 'Cost per click') : custom ? (ar ? `تكلفة ${custom}` : `Cost per ${custom}`) : (ar ? 'تكلفة النتيجة' : 'Cost per result')
  const native = nativeLeads(payload)

  return {
    result,
    cost,
    shown,
    off,
    legacy,
    series: native ? { key: 'form_leads', name: ar ? NATIVE_LEADS_LABEL.ar : NATIVE_LEADS_LABEL.en } : legacy ? { key: 'conversions', name: result } : null,
  }
}

/** NASEL 2026-10-29 — revenue a client may read: none when every platform reported zero revenue (a lead purchase event carries value 0). */
export function revenueReported(payload: LivePayload): boolean {
  const revenue = payload.totals?.revenue
  return revenue !== null && revenue !== undefined && Number(revenue) > 0
}
