/**
 * VAT treatment — the single source of truth on the frontend (mirrors backend App\Domains\Billing\Support\
 * TaxTreatment). The tax amount is DERIVED from the chosen treatment's rate, never free-typed.
 */
export type TaxTreatmentKey = 'basic_15' | 'zero_rated' | 'exempt' | 'out_of_scope' | 'historical_5'

interface TaxTreatmentMeta { rate: number; ar: string; en: string; historical?: boolean }

export const TAX_TREATMENTS: Record<TaxTreatmentKey, TaxTreatmentMeta> = {
  basic_15: { rate: 0.15, ar: 'ضريبة أساسية 15%', en: 'Standard VAT 15%' },
  zero_rated: { rate: 0, ar: 'خاضع لنسبة صفرية 0%', en: 'Zero-rated 0%' },
  exempt: { rate: 0, ar: 'معفى من الضريبة', en: 'Exempt' },
  out_of_scope: { rate: 0, ar: 'خارج نطاق الضريبة', en: 'Out of scope' },
  historical_5: { rate: 0.05, ar: 'ضريبة تاريخية 5%', en: 'Historical VAT 5%', historical: true },
}

export const DEFAULT_TREATMENT: TaxTreatmentKey = 'basic_15'

/** Treatments offered for NEW documents — the legacy 5% is intentionally excluded. */
export const SELECTABLE_TREATMENTS: TaxTreatmentKey[] = ['basic_15', 'zero_rated', 'exempt', 'out_of_scope']

export const isHistoricalTreatment = (key: string | null | undefined): boolean =>
  !!key && TAX_TREATMENTS[key as TaxTreatmentKey]?.historical === true

/** Human label for a treatment (adds a «تاريخي/historical» tag for the legacy rate). */
export function taxTreatmentLabel(key: string | null | undefined, ar: boolean): string | null {
  if (!key) return null
  const m = TAX_TREATMENTS[key as TaxTreatmentKey]
  if (!m) return key
  const base = ar ? m.ar : m.en
  return m.historical ? `${base} · ${ar ? 'تاريخي' : 'historical'}` : base
}

/** Derived tax amount for a subtotal under a treatment (2 dp). */
export function taxForTreatment(key: string | null | undefined, subtotal: number): number {
  const m = key ? TAX_TREATMENTS[key as TaxTreatmentKey] : undefined
  return Math.round(Math.max(0, subtotal) * (m?.rate ?? 0) * 100) / 100
}
