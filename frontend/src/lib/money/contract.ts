/**
 * MONEY-TRUTH-001 — the ONE place the withheld-money rules live.
 *
 * Every money surface delegates here. There is deliberately no second copy: `readMetric()` and
 * `moneyFromTotals()` were two implementations that a test merely proved *currently* agree, which is
 * not the same as one contract. Two copies drift, and the drift is invisible until an owner sees
 * spend on one screen and «0» on another for the same account and window — which is exactly how this
 * defect was found.
 *
 * ## What the rules protect
 *
 * `MetricsAggregator` coalesces a withheld `SUM(value)` to 0 so arithmetic works. That is correct for
 * summing and fatal for display: by the time a caller holds a number, «this account spent nothing»
 * and «we cannot convert what it spent» are the same value. So every function here takes the TOTALS,
 * never a bare number.
 */

/** Everything a surface needs to render money honestly. */
export type MoneyReading = {
  /** `converted` — a real figure in the reporting currency. `withheld` — real, in its own currency.
   *  `zero` — measured as nothing. `absent` — never reported. `unavailable` — cannot be stated. */
  kind: 'converted' | 'withheld' | 'zero' | 'absent' | 'unavailable'
  /** The amount, or null when there is none to state. */
  amount: number | null
  /** The currency `amount` is expressed in. Never assumed. */
  currency: string | null
  /** Why a figure is not in the reporting currency, for the reader. */
  note: string | null
}

export type MoneyTotals = Record<string, unknown> | undefined

const NOTES = {
  unconvertible: {
    ar: 'التحويل إلى عملة المشروع غير متاح حاليًا',
    en: 'Conversion to the project currency is unavailable',
  },
  mixed: {
    ar: 'مبالغ بعملات متعددة لا يمكن جمعها أو تحويلها',
    en: 'Amounts in several currencies that cannot be summed or converted',
  },
  derivedFromOriginal: {
    ar: 'محسوب من المبلغ الأصلي — التحويل غير متاح',
    en: 'Derived from the original amount — conversion unavailable',
  },
}

const note = (key: keyof typeof NOTES, ar: boolean): string => (ar ? NOTES[key].ar : NOTES[key].en)

const num = (v: unknown): number => Number(v ?? 0)

/**
 * Whether the money behind `key` was withheld, and in what.
 *
 * `money_original_currencies !== 1` is refused rather than labelled: a sum across currencies is not
 * a quantity of anything, and printing it beside one currency's name states a figure nobody measured.
 */
function withholding(totals: MoneyTotals, key: string): { withheld: boolean; original: number; currency: string | null; mixed: boolean } {
  const rows = num(totals?.[`${key}_withheld_rows`])
  const original = num(totals?.[`${key}_original`])
  const currency = totals?.money_original_currency
  const count = num(totals?.money_original_currencies)

  if (rows <= 0 || original <= 0) return { withheld: false, original: 0, currency: null, mixed: false }
  if (count !== 1 || typeof currency !== 'string' || currency === '') {
    return { withheld: true, original, currency: null, mixed: true }
  }

  return { withheld: true, original, currency, mixed: false }
}

/**
 * A raw money total — spend, revenue.
 *
 * `reportingCurrency` is passed in, never defaulted to a market's currency: a generic helper that
 * assumes SAR states the wrong unit the first time somebody reports in anything else, and does it
 * silently.
 */
export function readMoney(
  totals: MoneyTotals,
  key: 'spend' | 'revenue',
  reportingCurrency: string | null,
  ar: boolean,
): MoneyReading {
  const w = withholding(totals, key)

  if (w.mixed) return { kind: 'unavailable', amount: null, currency: null, note: note('mixed', ar) }
  if (w.withheld) return { kind: 'withheld', amount: w.original, currency: w.currency, note: note('unconvertible', ar) }

  const value = totals?.[key]
  if (value === null || value === undefined) return { kind: 'absent', amount: null, currency: null, note: null }

  const n = Number(value)
  return { kind: n === 0 ? 'zero' : 'converted', amount: n, currency: reportingCurrency, note: null }
}

/**
 * A cost-per metric — CPA, CPC, CPM, CPL, CPE, CPI, CPV, cost per landing-page view.
 *
 * The numerator is money, so the provenance is the SPEND's. When spend is withheld the aggregator's
 * derived figure was computed from a coalesced 0 and is therefore 0 — «CPA 0 SAR» over real spend,
 * the same lie one level down. Derived from the original when that is mathematically valid, and
 * refused when it is not.
 */
export function readCostPer(
  totals: MoneyTotals,
  key: string,
  denominatorKey: string,
  reportingCurrency: string | null,
  ar: boolean,
): MoneyReading {
  const w = withholding(totals, 'spend')

  if (w.mixed) return { kind: 'unavailable', amount: null, currency: null, note: note('mixed', ar) }

  if (w.withheld) {
    const denominator = num(totals?.[denominatorKey])

    // No denominator means no rate to state — «unavailable», not zero and not infinity.
    if (denominator <= 0) return { kind: 'unavailable', amount: null, currency: null, note: note('unconvertible', ar) }

    return {
      kind: 'withheld',
      amount: w.original / denominator,
      currency: w.currency,
      note: note('derivedFromOriginal', ar),
    }
  }

  const value = totals?.[key]
  if (value === null || value === undefined) return { kind: 'absent', amount: null, currency: null, note: null }

  const n = Number(value)
  return { kind: n === 0 ? 'zero' : 'converted', amount: n, currency: reportingCurrency, note: null }
}

/**
 * ROAS — a ratio, so it survives a missing rate when both sides share one original currency.
 *
 * Revenue and spend in the same currency divide to the same number they would after conversion, so
 * the figure is true. Two different currencies do not, and the answer there is «unavailable» rather
 * than a number that looks like a verdict.
 */
export function readRoas(totals: MoneyTotals, ar: boolean): { kind: MoneyReading['kind']; value: number | null; note: string | null } {
  const spend = withholding(totals, 'spend')
  const revenue = withholding(totals, 'revenue')

  if (spend.mixed || revenue.mixed) return { kind: 'unavailable', value: null, note: note('mixed', ar) }

  if (spend.withheld || revenue.withheld) {
    // Both sides must be present, and in the SAME currency, or the ratio is between unlike units.
    if (!spend.withheld || !revenue.withheld || spend.currency === null || spend.currency !== revenue.currency) {
      return { kind: 'unavailable', value: null, note: note('unconvertible', ar) }
    }
    if (spend.original <= 0) return { kind: 'unavailable', value: null, note: note('unconvertible', ar) }

    return { kind: 'withheld', value: revenue.original / spend.original, note: note('derivedFromOriginal', ar) }
  }

  const value = totals?.roas
  if (value === null || value === undefined) return { kind: 'absent', value: null, note: null }

  return { kind: Number(value) === 0 ? 'zero' : 'converted', value: Number(value), note: null }
}

/**
 * The TEXT of a reading — so two surfaces cannot print the same figure differently.
 *
 * Delegating the rules but not the rendering still left `4.1K USD` on one screen and
 * `4,128.93 USD` on another. A withheld figure is deliberately exact: it is an unconverted number
 * the reader is being asked to take on trust, and compacting it to «4.1K» removes the precision that
 * makes it checkable against the platform's own reporting. A converted figure keeps the compact
 * house style, because it can be verified in the product's own currency.
 */
export function formatMoneyReading(
  reading: MoneyReading,
  compactFormat: (n: number | null | undefined, currency?: string) => string,
): string {
  if (reading.amount === null) return '—'

  if (reading.kind === 'withheld') {
    const amount = reading.amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    return `${amount} ${reading.currency ?? ''}`.trim()
  }

  return compactFormat(reading.amount, reading.currency ?? undefined)
}
