import type { ClientBudgetRow } from '@/features/agency/api'

/**
 * NAV-HOME-PLANS-001 — the arithmetic behind «الرئيسية › كل العملاء».
 *
 * Pure, so the three rules the Owner stated are testable rather than asserted:
 *   - the totals are summed from the SAME rows the cards draw, so the cards add up to the figure above them;
 *   - nothing is added across currencies — a mixed set prints no total rather than a wrong one;
 *   - «تكفي N يوم» exists only where it can be measured: the balance is known AND the last seven days moved money.
 */

/** «قرّبت تخلص» — the Owner's 90% alert threshold, or a balance that lasts a week or less. */
export const NEAR_END_RATIO = 0.9
export const NEAR_END_DAYS = 7

export function weekSpend(row: ClientBudgetRow): number | null {
  const line = row.daily_spend_7d

  return line === undefined || line === null ? null : line.reduce((sum, v) => sum + v, 0)
}

/** Whole days the remaining budget lasts at the last seven days' average. Null = cannot be measured; 0 = already out. */
export function coverDays(row: ClientBudgetRow): number | null {
  if (row.remaining === null || row.remaining === undefined) return null
  if (row.remaining <= 0) return 0

  const week = weekSpend(row)
  if (week === null || week <= 0) return null

  return Math.floor(row.remaining / (week / 7))
}

export function budgetRatio(row: ClientBudgetRow): number | null {
  if (row.budget === null || row.budget <= 0 || row.spent === null) return null

  return row.spent / row.budget
}

export function isNearEnd(row: ClientBudgetRow): boolean {
  const days = coverDays(row)
  const ratio = budgetRatio(row)

  return (days !== null && days <= NEAR_END_DAYS) || (ratio !== null && ratio >= NEAR_END_RATIO)
}

export function isRunning(row: ClientBudgetRow): boolean {
  return (weekSpend(row) ?? 0) > 0
}

export interface OverviewTotals {
  /** Null where the rows disagree on currency, or none has a figure — never a sum of two units. */
  currency: string | null
  mixedCurrency: boolean
  spent: number | null
  remaining: number | null
  budget: number | null
  running: number
  nearEnd: number
}

export function totalsOf(rows: readonly ClientBudgetRow[]): OverviewTotals {
  const currencies = new Set(rows.filter((r) => r.budget !== null && r.currency).map((r) => r.currency as string))
  const mixedCurrency = currencies.size > 1 || rows.some((r) => r.currencies > 1)
  const sum = (pick: (r: ClientBudgetRow) => number | null | undefined): number | null => {
    if (mixedCurrency) return null
    const parts = rows.map(pick).filter((v): v is number => typeof v === 'number')

    return parts.length === 0 ? null : parts.reduce((a, b) => a + b, 0)
  }

  return {
    currency: mixedCurrency ? null : [...currencies][0] ?? null,
    mixedCurrency,
    spent: sum((r) => r.spent),
    remaining: sum((r) => r.remaining),
    budget: sum((r) => r.budget),
    running: rows.filter(isRunning).length,
    nearEnd: rows.filter(isNearEnd).length,
  }
}

/**
 * The Owner's order: the closest to running out first, then whoever has alerts, then the biggest spender.
 *
 * «Closest to running out» is fewest cover days; a client whose cover cannot be measured sorts after every measured
 * one, because an unknown is not a reassurance but is not a countdown either. The sort is stable and total, so two
 * reloads of the same data draw the same page.
 */
export function orderClients(rows: readonly ClientBudgetRow[], alertCount: (clientId: string) => number): ClientBudgetRow[] {
  return [...rows].sort((a, b) => {
    const da = coverDays(a)
    const db = coverDays(b)
    if (da !== db) {
      if (da === null) return 1
      if (db === null) return -1

      return da - db
    }

    const aa = alertCount(a.client_id)
    const ab = alertCount(b.client_id)
    if (aa !== ab) return ab - aa

    return (b.spent ?? -1) - (a.spent ?? -1) || a.client_name.localeCompare(b.client_name)
  })
}
