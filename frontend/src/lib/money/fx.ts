import { withMark } from '@/lib/money/mark'
import { numberFormatter } from '@/lib/numerals'

/**
 * FX-ENGINE-001 — the frontend half of the ONE conversion contract (backend: `App\Domains\Metrics\Fx\FxEngine::convert`).
 *
 * GOLDEN RULE: money is ONE object {amount, currency, date}. The browser never converts — it has no rates — it renders the
 * `FxConversion` the backend serialized, through `formatConversion()`. A withheld figure (no valid rate for its date) has
 * `converted_amount: null` and is rendered as «بانتظار سعر صرف (EUR)» with a link to the rates page: never zero, never the
 * original number wearing the target currency's mark (the «253 $» defect).
 *
 * `moneyMarksGuard.test.ts` fails the build when a screen glues a currency mark onto a number without going through
 * `withMark` / `formatConversion`, or formats an amount with a guessed currency.
 */

/** A monetary value: an amount is never money without its currency and the date that fixes its rate. */
export type MoneyValue = { amount: number; currency: string; date: string }

/** How a rate came to be: an operator (attributable), a legal peg (every date), or a free daily publication. */
export type RateKind = 'manual' | 'pegged' | 'automatic' | 'cross' | 'identity'

/** The serialized result of `FxEngine::convert()` — `FxConversion::toArray()` on the backend. */
export type FxConversion = {
  original_amount: number
  original_currency: string
  date: string
  /** the target currency */
  currency: string
  /** null exactly when withheld */
  converted_amount: number | null
  rate: number | null
  rate_date: string | null
  source: string | null
  kind: RateKind | null
  /** the person who recorded a manual rate */
  recorded_by: string | null
  /** the rate used is older than 7 days */
  stale: boolean
  /** no valid rate for the amount's date */
  withheld: boolean
  pending_label: string | null
  rates_page: string | null
  legs: Array<Record<string, unknown>>
  /** the serializer's stamp — a payload without it did not pass through convert() */
  via: 'fx.convert' | 'fx.guard'
}

export const RATES_PAGE = '/admin/settings/currency-rates'

/** «بانتظار سعر صرف (EUR)» — the one wording for a withheld figure. */
export function pendingRateLabel(currency: string | null | undefined, ar: boolean): string {
  const code = (currency ?? '').trim().toUpperCase()
  if (ar) return code === '' ? 'بانتظار سعر صرف' : `بانتظار سعر صرف (${code})`
  return code === '' ? 'Awaiting exchange rate' : `Awaiting exchange rate (${code})`
}

export type ConversionText = {
  /** what the reader sees */
  text: string
  /** true when no converted figure exists */
  withheld: boolean
  /** ⚠ the rate used is older than 7 days */
  stale: boolean
  /** where to fix a missing rate (withheld only) */
  link: string | null
  /** «1 EUR = 1.12 USD · 2026-11-09 · يدوي» — the audit line, for a tooltip */
  basis: string | null
}

const KIND_LABEL: Record<RateKind, { ar: string; en: string }> = {
  manual: { ar: 'يدوي', en: 'manual' },
  pegged: { ar: 'مثبّت', en: 'pegged' },
  automatic: { ar: 'تلقائي', en: 'automatic' },
  cross: { ar: 'عبر الدولار', en: 'via USD' },
  identity: { ar: 'نفس العملة', en: 'same currency' },
}

export const rateKindLabel = (kind: RateKind | null | undefined, ar: boolean): string => (kind ? (ar ? KIND_LABEL[kind].ar : KIND_LABEL[kind].en) : '')

const fixed = (n: number, digits: number) => numberFormatter('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(n)

/** The ONE renderer of a converted amount. It never prints the original amount with the target's mark. */
export function formatConversion(c: FxConversion, ar: boolean, digits = 2): ConversionText {
  if (c.withheld || c.converted_amount === null) {
    return { text: c.pending_label ?? pendingRateLabel(c.original_currency, ar), withheld: true, stale: false, link: c.rates_page ?? RATES_PAGE, basis: null }
  }
  // A RATE, not a money figure: «1 EUR = 1.12 USD», then its date and kind — the audit line for a tooltip.
  const rateText = c.rate === null ? null : c.rate.toPrecision(6).replace(/\.?0+$/, '')
  const basis = rateText === null
    ? null
    : [['1', c.original_currency, '=', rateText, c.currency].join(' '), c.rate_date, c.kind ? rateKindLabel(c.kind, ar) : null].filter(Boolean).join(' · ')

  return { text: withMark(fixed(c.converted_amount, digits), c.currency), withheld: false, stale: c.stale, link: null, basis }
}
