/**
 * BUDGET-CURRENCY-001 — the ONE place a money figure of the budgets surfaces becomes text or a node (the backend twin is
 * `FundingMoney`): the number, Latin digits, then a SHORT currency mark beside it — ر.س for SAR, $ for USD, € for EUR, the ISO
 * code for any other — the same in Arabic and English.
 *
 * Policy (Owner 2026-11-14): the budget is SAR and totals, remaining and balances are shown in SAR; a platform's own spend is
 * shown in USD (one unit for platforms). A figure with no rate keeps its own currency's mark and says «بدون سعر صرف».
 * `fundingMoney.test.ts` fails when another funding file formats a figure on its own.
 */
const MARKS: Record<string, string> = { SAR: 'ر.س', USD: '$', EUR: '€' }

export const currencyMark = (currency: string): string => MARKS[currency.trim().toUpperCase()] ?? currency.trim().toUpperCase()

/** The number alone: Latin digits, thousands separators, whole units unless `decimals` says otherwise. */
export function moneyNumber(n: number, decimals = 0): string {
  return Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
}

/** `1,234 ر.س` as text (a minus stays in front of the number). */
export function formatMoney(n: number | null | undefined, currency = 'SAR', decimals = 0): string {
  if (n === null || n === undefined) return '—'

  return `${n < 0 ? '−' : ''}${moneyNumber(n, decimals)} ${currencyMark(currency)}`
}

/** A figure with its mark beside it. The number is its own left-to-right island, so a minus stays in front of it in Arabic. */
export function Money({ n, currency = 'SAR', className = '', decimals = 0 }: { n: number | null | undefined; currency?: string; className?: string; decimals?: number }) {
  if (n === null || n === undefined) return <span className={className}>—</span>

  return (
    <span className={className} data-currency={currency.toUpperCase()}>
      <bdi dir="ltr" className="tnum">{`${n < 0 ? '−' : ''}${moneyNumber(n, decimals)}`}</bdi> <span className="text-text-muted">{currencyMark(currency)}</span>
    </span>
  )
}
