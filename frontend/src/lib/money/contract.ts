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

/**
 * What a money-bearing payload declares.
 *
 * Written as optional named fields rather than `Record<string, unknown>` so a typed row — a platform
 * or campaign breakdown — can be passed without a cast at every call site. Casting at the boundary is
 * how a field silently stops arriving and nobody notices: the compiler has to be able to see it.
 *
 * The readers below index dynamically (`${key}_withheld_rows`), which a fixed shape cannot express,
 * so the narrowing happens ONCE inside this module instead of at each caller.
 */
export type MoneyFields = {
  spend?: number | null
  revenue?: number | null
  roas?: number | null
  spend_withheld_rows?: number | null
  spend_original?: number | null
  revenue_withheld_rows?: number | null
  revenue_original?: number | null
  money_original_currency?: string | null
  money_original_currencies?: number | null
}

export type MoneyTotals = (MoneyFields & Record<string, unknown>) | MoneyFields | undefined

/** The one place the dynamic-key narrowing happens. */
const bag = (t: MoneyTotals): Record<string, unknown> | undefined => t as Record<string, unknown> | undefined

const NOTES = {
  unconvertible: {
    ar: 'التحويل إلى عملة المشروع غير متاح حاليًا',
    en: 'Conversion to the project currency is unavailable',
  },
  mixed: {
    ar: 'مبالغ بعملات متعددة لا يمكن جمعها أو تحويلها',
    en: 'Amounts in several currencies that cannot be summed or converted',
  },
  partial: {
    ar: 'جزء من المبلغ محوَّل وجزء بانتظار سعر صرف — لا يوجد إجمالي واحد',
    en: 'Part of the amount is converted and part awaits an FX rate — there is no single total',
  },
  derivedFromOriginal: {
    ar: 'محسوب من المبلغ الأصلي — التحويل غير متاح',
    en: 'Derived from the original amount — conversion unavailable',
  },
}

const note = (key: keyof typeof NOTES, ar: boolean): string => (ar ? NOTES[key].ar : NOTES[key].en)

const num = (v: unknown): number => Number(v ?? 0)

/**
 * PARTIAL-WITHHELD-001 — the six things a scope's money can be, one axis above `MetricAvailability`.
 *
 * `MetricAvailability` describes ONE figure's provenance. This describes how a SUM composed: a scope
 * can hold rows that converted AND rows that did not, and the sum of the two is not a figure. The old
 * `withholding()` collapsed this to a boolean and answered «withheld» the moment any row was withheld,
 * which let `readMoney` return only the withheld original — and, mirrored on the client, let a caller
 * return only the CONVERTED subset the moment any row converted. Both drop half the scope's money and
 * present the rest as the whole. Six states, and only three of them are a single number.
 */
export type MoneyState =
  | 'complete_converted'         // every row converted to the reporting currency
  | 'complete_withheld'          // every withheld row shares one currency, none converted
  | 'partial'                    // some converted + some withheld — NO single total
  | 'mixed_currency'             // withheld rows span >1 currency — NO single total
  | 'absent'                     // never reported
  | 'zero'                       // measured as nothing

/** The resolved money composition of `key` over a scope. `amount`/`currency` are set only when a single figure exists. */
export type MoneyScope = {
  state: MoneyState
  /** The converted sum in the reporting currency, when any converted money exists (else null). */
  converted: number | null
  /** The summed original of the withheld rows (0 when none). */
  original: number
  /** The single currency the withheld rows agree on, when they do (else null). */
  originalCurrency: string | null
}

/**
 * Resolve how the money behind `key` composed across the scope — the ONE place the rule lives.
 *
 * `money_original_currencies !== 1` among withheld rows is `mixed_currency`: a sum across currencies is
 * not a quantity. A converted amount coexisting with withheld rows is `partial`: there is real money in
 * two units and no rate to join them, so there is no honest single total — the surfaces fail closed.
 */
export function moneyState(totals: MoneyTotals, key: 'spend' | 'revenue'): MoneyScope {
  const t = bag(totals)
  const rows = num(t?.[`${key}_withheld_rows`])
  const original = num(t?.[`${key}_original`])
  const currencyRaw = t?.money_original_currency
  const currencies = num(t?.money_original_currencies)
  const currency = typeof currencyRaw === 'string' && currencyRaw !== '' ? currencyRaw : null

  const raw = t?.[key]
  const convertedAmount = raw === null || raw === undefined ? null : Number(raw)
  const hasConverted = convertedAmount !== null && convertedAmount > 0
  const hasWithheld = rows > 0 && original > 0

  if (hasWithheld && currencies !== 1) return { state: 'mixed_currency', converted: convertedAmount, original, originalCurrency: null }
  if (hasWithheld && hasConverted) return { state: 'partial', converted: convertedAmount, original, originalCurrency: currency }
  if (hasWithheld) return { state: 'complete_withheld', converted: convertedAmount, original, originalCurrency: currency }
  if (convertedAmount === null) return { state: 'absent', converted: null, original: 0, originalCurrency: null }
  if (convertedAmount === 0) return { state: 'zero', converted: 0, original: 0, originalCurrency: null }
  return { state: 'complete_converted', converted: convertedAmount, original: 0, originalCurrency: null }
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
  const s = moneyState(totals, key)

  switch (s.state) {
    case 'mixed_currency':
      return { kind: 'unavailable', amount: null, currency: null, note: note('mixed', ar) }
    case 'partial':
      // Real money in two units with no rate to join them — not a single figure. Fail closed.
      return { kind: 'unavailable', amount: null, currency: null, note: note('partial', ar) }
    case 'complete_withheld':
      return { kind: 'withheld', amount: s.original, currency: s.originalCurrency, note: note('unconvertible', ar) }
    case 'absent':
      return { kind: 'absent', amount: null, currency: null, note: null }
    case 'zero':
      return { kind: 'zero', amount: 0, currency: reportingCurrency, note: null }
    case 'complete_converted':
      return { kind: 'converted', amount: s.converted as number, currency: reportingCurrency, note: null }
  }
}

/**
 * Sum a money key across many rows to ONE figure — but only when every row is a single figure in one
 * shared currency (PARTIAL-WITHHELD-001). Returns null the moment any row is `partial`,
 * `mixed_currency` or `absent`, or the rows do not agree on a currency — a converted (reporting)
 * total beside a withheld (platform) one is riyals-plus-dollars, not a sum.
 *
 * This is the ONE place a caller that needs a scope total (a KPI card, a budget consumed figure, a
 * chart's denominator) asks «is there a single number here?». Returning the converted subset, or
 * `Number(x ?? 0)`, is exactly the defect this contract exists to stop — so callers must treat null
 * as «unavailable», never coalesce it to 0.
 */
export function aggregateMoney(
  rows: MoneyTotals[],
  key: 'spend' | 'revenue',
  reportingCurrency: string | null,
): { amount: number; currency: string | null } | null {
  let sum = 0
  let sawReporting = false
  const withheldCurrencies = new Set<string>()

  for (const row of rows) {
    const s = moneyState(row, key)
    switch (s.state) {
      case 'complete_converted':
        sum += s.converted as number
        sawReporting = true
        break
      case 'zero':
        break // a measured zero adds nothing and is comparable with any currency
      case 'complete_withheld':
        sum += s.original
        if (s.originalCurrency !== null) withheldCurrencies.add(s.originalCurrency)
        break
      default:
        return null // partial / mixed_currency / absent ⇒ no single total for the scope
    }
  }

  // More than one withheld currency, or a converted (reporting) figure mixed with a withheld
  // (platform) one, cannot be added — the sum would be across unlike units.
  if (withheldCurrencies.size > 1) return null
  if (withheldCurrencies.size === 1 && sawReporting) return null

  const currency = withheldCurrencies.size === 1 ? [...withheldCurrencies][0] : reportingCurrency
  return { amount: sum, currency }
}

/**
 * The spend as ONE figure in `targetCurrency`, or null — the single comparability rule.
 *
 * A budget comparison is subtraction, so both sides must be the same unit. Two independent fields
 * decide that: the scope's money state, and which currency its figure is actually denominated in.
 * A converted total is in the project's REPORTING currency, which is not required to equal the
 * campaign's `budget_currency` — a project reporting in SAR may hold a campaign budgeted in USD.
 * Reading the converted number as if it were the budget's unit computes «budget − spend» across two
 * currencies and prints the result as money.
 *
 * So: a converted total counts only when the reporting currency IS the target; a withheld total only
 * when its own original currency is; partial and mixed have no single figure at all. Anything else
 * is null, and every caller renders «unavailable» rather than pacing against a number nobody can
 * compare. `rankableMoney` answers the neighbouring question for charts — which rows share an axis —
 * and deliberately may drop rows; this one never drops anything, because a total that omits part of
 * its scope is not that scope's total.
 */
export function spendComparableAmount(
  totals: MoneyTotals | undefined,
  key: 'spend' | 'revenue',
  reportingCurrency: string | null,
  targetCurrency: string | null,
): number | null {
  if (targetCurrency === null || targetCurrency === '') return null
  const target = targetCurrency.toUpperCase()
  const s = moneyState(totals as MoneyTotals, key)
  if (s.state === 'zero') return 0
  if (s.state === 'complete_converted') {
    return reportingCurrency !== null && reportingCurrency !== '' && reportingCurrency.toUpperCase() === target
      ? s.converted ?? 0
      : null
  }
  if (s.state === 'complete_withheld') {
    return s.originalCurrency !== null && s.originalCurrency.toUpperCase() === target ? s.original : null
  }
  return null // partial, mixed_currency, absent
}

/**
 * Per-row money values for a ranking or share chart — DROP AND DISCLOSE, not all-or-nothing.
 *
 * A chart is not a total, and the two do not fail the same way. `aggregateMoney()` refuses the whole
 * figure the moment one row is unreadable, because a sum of half a scope printed as the scope is the
 * defect this contract exists to stop. A donut of six platforms is different: refusing all six
 * because one is withheld hides five that are perfectly known, and «unavailable» is then a worse
 * answer than the truth.
 *
 * So a row with no comparable magnitude is LEFT OFF and COUNTED, and every caller must render
 * `dropped` — a chart that silently shows fewer rows than the account has is the same lie in another
 * shape. What remains must still be honest on its own, which is the whole point of the currency rule
 * below: the kept rows are always one single currency, never riyals drawn beside dollars.
 *
 *   - `complete_converted` / `zero` — kept, in the reporting currency.
 *   - `complete_withheld`          — a platform figure, NOT comparable with a converted one. Kept
 *                                    only when no converted row exists and every withheld row agrees
 *                                    on one currency, which is the case where the whole chart can
 *                                    honestly be drawn in the platform's own unit.
 *   - `partial` / `mixed_currency` / `absent` — no single magnitude at all. Always dropped.
 *
 * A measured zero joins either group: it adds nothing and contradicts no currency.
 *
 * Returns null when NOTHING is comparable — then there is no chart to draw and the caller says so.
 * `values` is index-aligned with `rows`; a dropped row is null.
 */
export function rankableMoney(
  rows: MoneyTotals[],
  key: 'spend' | 'revenue',
  reportingCurrency: string | null,
): { values: Array<number | null>; currency: string | null; dropped: number } | null {
  const states = rows.map((row) => moneyState(row, key))

  const hasConverted = states.some((s) => s.state === 'complete_converted')
  const withheldCurrencies = new Set(
    states.flatMap((s) => (s.state === 'complete_withheld' && s.originalCurrency !== null ? [s.originalCurrency] : [])),
  )
  /*
   * Withheld rows are drawable only when they are the ONLY money on the axis and they agree on a
   * unit. Beside a converted row they would be a second currency in the same donut; a withheld row
   * with no currency name cannot be labelled at all.
   */
  const keepWithheld = ! hasConverted
    && withheldCurrencies.size === 1
    && states.every((s) => s.state !== 'complete_withheld' || s.originalCurrency !== null)

  const values = states.map((s) => {
    switch (s.state) {
      case 'complete_converted':
        return s.converted as number
      case 'zero':
        return 0
      case 'complete_withheld':
        return keepWithheld ? s.original : null
      default:
        return null
    }
  })

  const dropped = values.filter((v) => v === null).length
  // Nothing comparable to draw ⇒ no chart. An EMPTY set is not that case: it is «no rows yet», which
  // the caller renders as its own empty state, not as «this money cannot be shown».
  if (values.length > 0 && dropped === values.length) return null

  return { values, currency: keepWithheld ? [...withheldCurrencies][0]! : reportingCurrency, dropped }
}

/**
 * Resolve a timeseries into EFFECTIVE money a chart may plot, or null when it may not.
 *
 * A money line/area is a claim in ONE currency at EVERY point. Plotting the raw/coalesced `spend`
 * draws a withheld day as 0 (its real value lives in the original-amount provenance) and a partial or
 * mixed day as a fabricated figure — a false trend, which is worse than no trend.
 *
 * Unlike a donut, a trend line cannot DROP a point and stay honest: a gap day reads as a real dip,
 * not as an omission the reader was told about. So where `rankableMoney` keeps the comparable rows
 * and discloses a count, a series fails CLOSED — the moment any point is not a single figure in the
 * one shared currency (`rankableMoney` reports that as a dropped row), the whole series is «—». For
 * each money key every row must be a single figure in one currency, and the keys must agree on that
 * currency (SAR spend beside USD revenue is not one chart). When they do, each key is REPLACED by its
 * effective value (withheld→original, converted→converted, zero→0) and the currency returned.
 */
export function resolveMoneySeries<T extends Record<string, unknown>>(
  rows: T[],
  keys: Array<'spend' | 'revenue'>,
  reportingCurrency: string | null,
): { rows: T[]; currency: string | null } | null {
  const perKey = new Map<string, { values: Array<number | null>; currency: string | null }>()
  for (const key of keys) {
    const r = rankableMoney(rows as MoneyTotals[], key, reportingCurrency)
    // null ⇒ nothing comparable; dropped > 0 ⇒ some point is partial/mixed/cross-currency. A trend
    // tolerates neither, so either one closes the whole series.
    if (r === null || r.dropped > 0) return null
    perKey.set(key, r)
  }

  const currencies = new Set(keys.map((k) => perKey.get(k)!.currency).filter((c): c is string => c !== null))
  if (currencies.size > 1) return null // the money keys are in different currencies
  const currency = currencies.size === 1 ? [...currencies][0] : reportingCurrency

  const out = rows.map((row, i) => {
    const copy: Record<string, unknown> = { ...row }
    // Guarded above: dropped === 0, so every value is a real number, not null.
    for (const key of keys) copy[key] = perKey.get(key)!.values[i]
    return copy as T
  })

  return { rows: out, currency }
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
  /**
   * The denominator: a field NAME, or the number itself.
   *
   * CPM divides by impressions per THOUSAND, which is not a stored field. Accepting a computed
   * number keeps that factor at the call site where it is visible, instead of inventing a field name
   * like `impressions_thousands` that no payload carries — which silently yields «unavailable»
   * because the lookup misses, and looks like a provenance decision rather than a typo.
   */
  denominator: string | number,
  reportingCurrency: string | null,
  ar: boolean,
): MoneyReading {
  const s = moneyState(totals, 'spend')

  // A cost-per whose numerator is only partly convertible cannot be stated from the converted subset.
  if (s.state === 'mixed_currency') return { kind: 'unavailable', amount: null, currency: null, note: note('mixed', ar) }
  if (s.state === 'partial') return { kind: 'unavailable', amount: null, currency: null, note: note('partial', ar) }

  if (s.state === 'complete_withheld') {
    const d = typeof denominator === 'number' ? denominator : num(bag(totals)?.[denominator])

    // No denominator means no rate to state — «unavailable», not zero and not infinity.
    if (d <= 0) return { kind: 'unavailable', amount: null, currency: null, note: note('unconvertible', ar) }

    return {
      kind: 'withheld',
      amount: s.original / d,
      currency: s.originalCurrency,
      note: note('derivedFromOriginal', ar),
    }
  }

  const value = bag(totals)?.[key]
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
  const spend = moneyState(totals, 'spend')
  const revenue = moneyState(totals, 'revenue')

  if (spend.state === 'mixed_currency' || revenue.state === 'mixed_currency') {
    return { kind: 'unavailable', value: null, note: note('mixed', ar) }
  }
  // A partial side has no single figure, so no ratio can be formed for the whole scope.
  if (spend.state === 'partial' || revenue.state === 'partial') {
    return { kind: 'unavailable', value: null, note: note('partial', ar) }
  }

  const spendWithheld = spend.state === 'complete_withheld'
  const revenueWithheld = revenue.state === 'complete_withheld'

  if (spendWithheld || revenueWithheld) {
    // The exception: both COMPLETE and in the SAME currency, or the ratio is between unlike units.
    if (!spendWithheld || !revenueWithheld || spend.originalCurrency === null || spend.originalCurrency !== revenue.originalCurrency) {
      return { kind: 'unavailable', value: null, note: note('unconvertible', ar) }
    }
    if (spend.original <= 0) return { kind: 'unavailable', value: null, note: note('unconvertible', ar) }

    return { kind: 'withheld', value: revenue.original / spend.original, note: note('derivedFromOriginal', ar) }
  }

  const value = bag(totals)?.roas
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
