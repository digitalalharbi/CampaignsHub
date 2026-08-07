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

/** Exact money with thousands separators (e.g. "96,122 SAR") — used so the precise figure is always
 * present (and PDF-extractable) alongside the compact display value. */
export function moneyExact(n: number | null | undefined, currency = 'SAR'): string {
  if (n === null || n === undefined) return '—'
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
