/**
 * MONEY-MARKS-001 — the ONE place a currency becomes its SHORT mark (the backend twin is `FundingMoney::mark`):
 * ر.س for SAR, $ for USD, € for EUR, the ISO code for any other — the same in Arabic and English.
 *
 * Policy (Owner 2026-11-14): every money figure shows its mark beside the number; amounts per platform are normalised to USD,
 * summaries, totals and budgets are in SAR; a figure with no FX rate keeps its OWN currency's mark beside «بدون سعر صرف» and is
 * never converted by guess. ROAS, percentages and counts are not money and never go through here.
 * `moneyMarksGuard.test.ts` fails when a screen glues a currency code to a number on its own.
 */
const MARKS: Record<string, string> = { SAR: 'ر.س', USD: '$', EUR: '€' }

export const currencyMark = (currency: string): string => MARKS[currency.trim().toUpperCase()] ?? currency.trim().toUpperCase()

/** The plain chip words for a figure that has no exchange rate. */
export const NO_RATE_LABEL = { ar: 'بدون سعر صرف', en: 'No FX rate' } as const

/** An already-formatted number with its mark after it; no currency (unknown) prints the number bare, never a guessed one. */
export function withMark(figure: string, currency: string | null | undefined): string {
  return currency ? `${figure} ${currencyMark(currency)}` : figure
}
