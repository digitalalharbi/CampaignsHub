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
