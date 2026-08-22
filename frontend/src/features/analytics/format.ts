import { formatMoneyReading, readMoney } from '@/lib/money/contract'
/** Latin-digit formatters (project rule: numbers/dates/ids stay Latin even in Arabic UI). */

export function compact(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  const abs = Math.abs(n)
  if (abs >= 1_000_000) return (n / 1_000_000).toFixed(abs >= 10_000_000 ? 0 : 1) + 'M'
  if (abs >= 1_000) return (n / 1_000).toFixed(abs >= 10_000 ? 0 : 1) + 'K'
  /*
   * COMPACT-ZERO-001 — a real figure below one must not be rounded away to «0».
   *
   * `Math.round` turned a cost of 0.028 per impression into «cost 0 SAR» on the funnel — «this step
   * is free», printed beside a bar that cost thirty-six thousand riyals. It is the same rounding
   * `CreativeDetailPage` already worked around by refusing to show money on its bars at all.
   *
   * A genuine zero still prints «0»: only a value the reader would otherwise be told is nothing
   * gains digits, and it gains only as many as it needs to stop being nothing.
   */
  if (abs > 0 && abs < 1) return n.toFixed(abs < 0.01 ? 4 : 2)
  return String(Math.round(n))
}

export function money(n: number | null | undefined, currency = 'SAR'): string {
  if (n === null || n === undefined) return '—'
  return `${compact(n)} ${currency}`
}

/**
 * Exact money with thousands separators (e.g. "96,122 SAR") — used so the precise figure is always
 * present (and PDF-extractable) alongside the compact display value.
 *
 * ## Why «exact» has to keep the decimals on a small figure
 *
 * It rounded everything through `num()`, so a CPM of 29.71 printed «30 SAR» and a cost per order of
 * 73.72 printed «74 SAR» — in the one strip on the page whose stated job is to carry the precise
 * value into the PDF. On a total that runs to five figures the fraction is noise; on a cost-per it
 * IS the figure, and a 1% misstatement of the number a report is judged on is not a rounding, it is
 * a different answer.
 *
 * Same family as COMPACT-ZERO-001, which taught `compact()` not to print a non-zero cost as «0»;
 * this is the other half of that lesson, one order of magnitude up.
 *
 * The threshold keeps every large total reading exactly as it did — spend, revenue and the rest are
 * unchanged — and gives decimals only where they carry meaning.
 */
export function moneyExact(n: number | null | undefined, currency = 'SAR'): string {
  if (n === null || n === undefined) return '—'
  if (Math.abs(n) < 1000 && !Number.isInteger(n)) {
    return `${new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)} ${currency}`
  }

  return `${num(n)} ${currency}`
}

export function num(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return new Intl.NumberFormat('en-US').format(Math.round(n))
}

export function ratio(n: number | null | undefined, suffix = 'x'): string {
  if (n === null || n === undefined) return '—'
  return `${n.toFixed(2)}${suffix}`
}

export function percent(n: number | null | undefined, digits = 1): string {
  if (n === null || n === undefined) return '—'
  return `${(n * 100).toFixed(digits)}%`
}

export type Trend = 'up' | 'down' | 'flat'

/** For a delta ratio, whether it's up/down and whether that is good (some metrics invert). */
export function trend(delta: number | null | undefined): Trend {
  if (delta === null || delta === undefined || Math.abs(delta) < 0.0005) return 'flat'
  return delta > 0 ? 'up' : 'down'
}


/**
 * MONEY-TRUTH-001 — rendering only. The RULES live in `@/lib/money/contract`.
 *
 * This existed as a second implementation of the withheld-money rules alongside `readMetric()`, with
 * a test proving the two currently agreed. That is not one contract: two copies drift, and the drift
 * stays invisible until an owner sees spend on one screen and «0» on another. Both now delegate.
 */
export type MoneyReading = { text: string; withheld: boolean; note: string | null }

export function moneyFromTotals(
  totals: Record<string, unknown> | undefined,
  key: 'spend' | 'revenue',
  ar: boolean,
  reportingCurrency: string | null = null,
): MoneyReading {
  const r = readMoney(totals, key, reportingCurrency, ar)

  if (r.kind === 'unavailable' || r.kind === 'absent') {
    return { text: '\u2014', withheld: r.kind === 'unavailable', note: r.note }
  }

  return {
    text: formatMoneyReading(r, money),
    withheld: r.kind === 'withheld',
    note: r.note,
  }
}
