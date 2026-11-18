import type { ClientBudgetRow } from '@/features/agency/api'

/**
 * NAV-HOME-PLANS-001 — the arithmetic behind «الرئيسية › كل العملاء».
 *
 * Pure, so the three rules the Owner stated are testable rather than asserted:
 *   - the totals are summed from the SAME rows the cards draw, so the cards add up to the figure above them;
 *   - the budget is the APPROVED project budget (SAR), so the totals always add — never a platform campaign budget;
 *   - «تكفي N يوم» exists only where it can be measured: the server states it from the budget's last three complete days.
 */

/** «قرّبت تخلص» — the Owner's 90% alert threshold, or a balance that lasts a week or less. */
export const NEAR_END_RATIO = 0.9
export const NEAR_END_DAYS = 7

/*
 * ONE-BUDGET-001 — every budget figure on the home page is the client's APPROVED project budget (`project_budget`, from
 * /agency/budgets, over each budget's own period). The campaign budgets set inside the platforms are not read here.
 */

export function weekSpend(row: ClientBudgetRow): number | null {
  const line = row.daily_spend_7d

  return line === undefined || line === null ? null : line.reduce((sum, v) => sum + v, 0)
}

/** Whole days the remaining approved budget lasts, as the server states it (last three complete days). Null = cannot be measured. */
export function coverDays(row: ClientBudgetRow): number | null {
  return row.project_budget?.cover_days ?? null
}

export function budgetRatio(row: ClientBudgetRow): number | null {
  const b = row.project_budget
  if (!b || b.approved === null || b.approved <= 0 || b.spent === null) return null

  return b.spent / b.approved
}

export function hasBudget(row: ClientBudgetRow): boolean {
  return (row.project_budget?.budgeted ?? 0) > 0
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
  /** Always SAR: approved budgets are kept in riyals, so they always add. Null when no client has a budget. */
  currency: string | null
  mixedCurrency: boolean
  spent: number | null
  remaining: number | null
  budget: number | null
  running: number
  nearEnd: number
}

export function totalsOf(rows: readonly ClientBudgetRow[]): OverviewTotals {
  const sum = (pick: (r: ClientBudgetRow) => number | null | undefined): number | null => {
    const parts = rows.map(pick).filter((v): v is number => typeof v === 'number')

    return parts.length === 0 ? null : parts.reduce((a, b) => a + b, 0)
  }
  const budget = sum((r) => r.project_budget?.approved)

  return {
    currency: budget === null ? null : 'SAR',
    mixedCurrency: false,
    spent: sum((r) => r.project_budget?.spent),
    remaining: sum((r) => r.project_budget?.remaining),
    budget,
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

    const sa = a.project_budget?.spent ?? -1
    const sb = b.project_budget?.spent ?? -1

    return sb - sa || a.client_name.localeCompare(b.client_name)
  })
}
