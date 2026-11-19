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
  /** PROJECT-LIFECYCLE-001 §7 — the rate used is past its validity (still used, flagged) */
  expired?: boolean
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

/**
 * FX-APPLY-001 (builder G) — the audit line the Owner specified for a converted figure's tooltip:
 * «253.00 EUR × 1.12 (2026-11-09، يدوي: a@x) = 283.36 USD». ISO codes on purpose: it states the arithmetic, not a price.
 * Null for a withheld figure (there is no arithmetic to state) and for a same-currency one (nothing was converted).
 */
export function conversionTooltip(c: FxConversion, ar: boolean, digits = 2): string | null {
  if (c.withheld || c.converted_amount === null || c.rate === null) return null
  if (c.original_currency === c.currency) return null
  const rate = c.rate.toPrecision(6).replace(/\.?0+$/, '')
  const who = c.kind === 'manual' && c.recorded_by ? `: ${c.recorded_by}` : ''
  const how = [c.rate_date, c.kind ? `${rateKindLabel(c.kind, ar)}${who}` : null].filter(Boolean).join(ar ? '، ' : ', ')
  const from = [fixed(c.original_amount, digits), c.original_currency].join(' ')
  const to = [fixed(c.converted_amount, digits), c.currency].join(' ')
  return `${from} × ${rate}${how ? ` (${how})` : ''} = ${to}`
}

/** True when this figure was converted from another currency (the «محوّل» icon). */
export const isConverted = (c: FxConversion | null | undefined): boolean =>
  !!c && !c.withheld && c.converted_amount !== null && c.original_currency !== c.currency

/** The original figure in its own currency, through the one mark module («253.00 €»). */
export function formatOriginal(c: FxConversion, digits = 2): string {
  return withMark(fixed(c.original_amount, digits), c.original_currency)
}

/**
 * FX-APPLY-001 — a TOTAL of several amounts, each converted by the backend (`FxDisplay::total`). Withheld when any part had no
 * rate (`converted_amount: null`): never the smaller sum of what did convert, never zero.
 */
export type FxTotal = {
  currency: string
  converted_amount: number | null
  withheld: boolean
  withheld_parts?: number
  withheld_currencies?: string[]
  /** a part was in another currency than `currency` — «يشمل عملات محوّلة» */
  includes_converted: boolean
  currencies: string[]
  stale: boolean
  pending_label: string | null
  rates_page: string | null
  via: 'fx.convert' | 'fx.guard'
  /** PROJECT-LIFECYCLE-001 §7 — a total shown WITHOUT its excluded parts (named in `excluded` / `statement`), never withheld whole. */
  partial?: boolean
  excluded?: FxExcludedPart[]
  statement?: string | null
  statement_en?: string | null
  stale_parts?: FxStalePart[]
}

/**
 * PROJECT-LIFECYCLE-001 §7 — a part of a spend total that had NO exchange rate: excluded from the total and named (currency,
 * account, original amount, days). Every page reads it from the one backend aggregation (`SpendLedger`).
 */
export type FxExcludedPart = {
  project_id: string
  account_id: string
  account_name: string
  provider: string
  currency: string | null
  original_amount: number
  days: number
  first_day: string
  last_day: string
}

/** A part converted at an old (or expired) rate: used, labelled «سعر قديم — بتاريخ …». */
/** An old rate that was USED: internal data only (no wording is produced for a client; the alert lives in /admin). */
export type FxStalePart = { project_id?: string; currency: string; rate_date: string | null; expired: boolean; days: number }

/** What a spend total excluded and which old rates it used — `SpendLedger` readings and the budgets' `fx` note share it. */
export type FxSpendNote = {
  complete: boolean
  excluded: FxExcludedPart[]
  excluded_label: string | null
  statement: string | null
  statement_en?: string | null
  stale: FxStalePart[]
  rates_page: string | null
}

/** One spend total from the shared aggregation (`SpendLedger::read`). */
export type SpendReading = FxSpendNote & {
  currency: string
  from: string | null
  to: string | null
  /** null only when NOTHING could be converted */
  amount: number | null
  partial: boolean
  currencies: string[]
  includes_converted: boolean
  by_project: Record<string, number>
}

/** The words for a total that adds converted currencies. */
export const INCLUDES_CONVERTED = { ar: 'يشمل عملات محوّلة', en: 'Includes converted currencies' } as const

/** The ONE renderer of a converted total. */
export function formatFxTotal(t: FxTotal, ar: boolean, digits = 2): ConversionText & { includesConverted: boolean } {
  // PROJECT-LIFECYCLE-001 §7 — a partial total (some parts had no rate) is SHOWN; its exclusions are stated beside it.
  if (t.converted_amount === null || (t.withheld && !t.partial)) {
    return { text: t.pending_label ?? pendingRateLabel(t.withheld_currencies?.[0] ?? null, ar), withheld: true, stale: false, link: t.rates_page ?? RATES_PAGE, basis: null, includesConverted: t.includes_converted }
  }
  return {
    text: withMark(fixed(t.converted_amount, digits), t.currency),
    withheld: false,
    stale: t.stale,
    link: null,
    basis: t.includes_converted ? [ar ? INCLUDES_CONVERTED.ar : INCLUDES_CONVERTED.en, t.currencies.join(' · ')].join(': ') : null,
    includesConverted: t.includes_converted,
  }
}

/**
 * FX-APPLY-001 — what the backend says the money in a metrics scope was converted FROM (`MetricsAggregator::fxCoverage`).
 */
export type FxCoverage = {
  reporting_currency: string
  includes_converted: boolean
  currencies: string[]
  stale: boolean
  withheld_rows: number
  withheld_currencies: string[]
  rates_page: string
}
