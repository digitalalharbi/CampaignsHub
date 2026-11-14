import { describe, expect, it } from 'vitest'
import type { ClientBudgetRow } from '@/features/agency/api'
import { budgetRatio, coverDays, isNearEnd, orderClients, totalsOf } from './overviewMath'

const row = (over: Partial<ClientBudgetRow>): ClientBudgetRow => ({
  client_id: 'c', client_name: 'C', projects: 1, campaigns: 1, budget: 1000, spent: 400, remaining: 600, projected: null, pace: null,
  currency: 'SAR', currencies: 1, excluded: 0, projects_breakdown: [], daily_spend_7d: [100, 100, 100, 100, 100, 100, 100], ...over,
})

describe('cover days', () => {
  it('is the remaining budget over the last seven days’ average, in whole days', () => {
    expect(coverDays(row({}))).toBe(6)
    expect(coverDays(row({ remaining: 650 }))).toBe(6)
  })

  it('is 0 when the budget is gone, and unknown when nothing moved or the balance is unknown', () => {
    expect(coverDays(row({ remaining: 0 }))).toBe(0)
    expect(coverDays(row({ remaining: -20 }))).toBe(0)
    expect(coverDays(row({ daily_spend_7d: [0, 0, 0, 0, 0, 0, 0] }))).toBeNull()
    expect(coverDays(row({ daily_spend_7d: null }))).toBeNull()
    expect(coverDays(row({ remaining: null }))).toBeNull()
  })
})

describe('near the end', () => {
  it('counts a week or less of cover, or 90% spent', () => {
    expect(isNearEnd(row({}))).toBe(true) // 6 days
    expect(isNearEnd(row({ remaining: 2000, budget: 3000, spent: 1000 }))).toBe(false)
    expect(isNearEnd(row({ budget: 1000, spent: 900, remaining: 100, daily_spend_7d: [1, 1, 1, 1, 1, 1, 1] }))).toBe(true)
    expect(budgetRatio(row({ budget: 0 }))).toBeNull()
  })
})

describe('totals', () => {
  it('equals the sum of the cards', () => {
    const rows = [row({ client_id: 'a', spent: 400, remaining: 600 }), row({ client_id: 'b', spent: 100, remaining: 900 })]
    const t = totalsOf(rows)
    expect(t.spent).toBe(500)
    expect(t.remaining).toBe(1500)
    expect(t.running).toBe(2)
    expect(t.currency).toBe('SAR')
  })

  it('prints no total across two currencies', () => {
    const t = totalsOf([row({ currency: 'SAR' }), row({ currency: 'USD' })])
    expect(t.mixedCurrency).toBe(true)
    expect(t.spent).toBeNull()
    expect(t.remaining).toBeNull()
  })
})

describe('order', () => {
  it('puts the nearest to running out first, then alerts, then the biggest spender', () => {
    const rows = [
      row({ client_id: 'big', client_name: 'Big', remaining: 5000, spent: 9000 }),
      row({ client_id: 'soon', client_name: 'Soon', remaining: 100 }),
      row({ client_id: 'unknown', client_name: 'Unknown', daily_spend_7d: null }),
      row({ client_id: 'alerted', client_name: 'Alerted', remaining: 5000, spent: 10 }),
    ]
    const order = orderClients(rows, (id) => (id === 'alerted' ? 3 : 0)).map((r) => r.client_id)
    expect(order[0]).toBe('soon')
    expect(order.at(-1)).toBe('unknown')
    expect(order.indexOf('alerted')).toBeLessThan(order.indexOf('big'))
  })
})
