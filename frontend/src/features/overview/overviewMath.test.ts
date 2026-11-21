import { describe, expect, it } from 'vitest'
import type { ClientBudgetRow } from '@/features/agency/api'
import type { ApprovedBudgetTotal } from '@/features/funding/fundingApi'
import { budgetRatio, coverDays, hasBudget, isNearEnd, orderClients, totalsOf } from './overviewMath'

const total = (over: Partial<ApprovedBudgetTotal> = {}): ApprovedBudgetTotal => ({
  currency: 'SAR', budgeted: 1, unbudgeted: 0, without_balance: 0, approved: 1000, spent: 400, remaining: 600, used_ratio: 0.4,
  pace_per_day: 100, cover_days: 6, ...over,
})

const row = (over: Partial<ClientBudgetRow> = {}, budget: Partial<ApprovedBudgetTotal> = {}): ClientBudgetRow => ({
  client_id: 'c', client_name: 'C', projects: 1, campaigns: 1,
  // The platform campaign roll-up: deliberately different, and never read as the budget.
  budget: 3410, spent: 1280, remaining: 2130, projected: null, pace: null,
  currency: 'USD', currencies: 1, excluded: 0, projects_breakdown: [], daily_spend_7d: [100, 100, 100, 100, 100, 100, 100],
  project_budget: total(budget), ...over,
})

describe('ONE-BUDGET-001 — the home cards read the approved budget', () => {
  it('ratio and cover days come from the approved budget, not the platform campaign budgets', () => {
    const r = row({}, { approved: 5069, spent: 4404, remaining: 665, cover_days: 3 })
    expect(budgetRatio(r)).toBeCloseTo(4404 / 5069)
    expect(coverDays(r)).toBe(3)
  })

  it('a client with no approved budget has no ratio, no cover and is «بدون ميزانية»', () => {
    const r = row({}, { budgeted: 0, unbudgeted: 1, approved: null, spent: null, remaining: null, used_ratio: null, cover_days: null })
    expect(hasBudget(r)).toBe(false)
    expect(budgetRatio(r)).toBeNull()
    expect(coverDays(r)).toBeNull()
  })
})

describe('near the end', () => {
  it('counts a week or less of cover, or 90% spent', () => {
    expect(isNearEnd(row())).toBe(true) // 6 days
    expect(isNearEnd(row({}, { approved: 3000, spent: 1000, remaining: 2000, cover_days: 20 }))).toBe(false)
    expect(isNearEnd(row({}, { approved: 1000, spent: 900, remaining: 100, cover_days: 50 }))).toBe(true)
    expect(budgetRatio(row({}, { approved: 0 }))).toBeNull()
  })
})

describe('totals', () => {
  it('equals the sum of the cards, in SAR', () => {
    const rows = [row({ client_id: 'a' }, { spent: 400, remaining: 600 }), row({ client_id: 'b' }, { spent: 100, remaining: 900 })]
    const t = totalsOf(rows)
    expect(t.spent).toBe(500)
    expect(t.remaining).toBe(1500)
    expect(t.running).toBe(2)
    expect(t.currency).toBe('SAR')
    expect(t.mixedCurrency).toBe(false)
  })

  it('leaves a client without a budget out of the sums rather than counting it as zero', () => {
    const t = totalsOf([row({ client_id: 'a' }, { remaining: 600 }), row({ client_id: 'b' }, { approved: null, spent: null, remaining: null })])
    expect(t.remaining).toBe(600)
  })
})

describe('order', () => {
  it('puts the nearest to running out first, then alerts, then the biggest spender', () => {
    const rows = [
      row({ client_id: 'big', client_name: 'Big' }, { remaining: 5000, spent: 9000, cover_days: 50 }),
      row({ client_id: 'soon', client_name: 'Soon' }, { remaining: 100, cover_days: 1 }),
      row({ client_id: 'unknown', client_name: 'Unknown' }, { cover_days: null }),
      row({ client_id: 'alerted', client_name: 'Alerted' }, { remaining: 5000, spent: 10, cover_days: 50 }),
    ]
    const order = orderClients(rows, (id) => (id === 'alerted' ? 3 : 0)).map((r) => r.client_id)
    expect(order[0]).toBe('soon')
    expect(order.at(-1)).toBe('unknown')
    expect(order.indexOf('alerted')).toBeLessThan(order.indexOf('big'))
  })
})
