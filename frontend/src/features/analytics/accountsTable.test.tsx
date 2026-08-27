import { describe, expect, it } from 'vitest'
import { orderRows } from './tableSort'
import type { SortValues } from './AnalyticsPage'

/**
 * ANALYTICS-TABLES-001 — the Accounts tab uses the canonical table.
 *
 * It rendered its own `<table>`: numeric columns `text-start`, so under RTL the figures sat against
 * one edge and their headings against the other; no sorting at all; and a caption saying «ordered by
 * spend» over a list ordered by impressions.
 *
 * Sorting is exercised through `orderRows`, the comparator `MetricTable` uses, because that is where
 * the behaviour worth protecting lives — an absent figure must not sort as a zero.
 */
const ACCOUNTS: SortValues[] = [
  // name, platform, spend, impressions, clicks, ctr, cpm
  ['Riyadh', 'Snapchat', 1200, 90_000, 800, 0.0089, 13.3],
  ['Jeddah', 'Meta', 4800, 40_000, 300, 0.0075, 120.0],
  ['Dammam', 'TikTok', null, 10_000, 90, 0.009, null],
]

describe('the accounts table', () => {
  it('leads with the highest spender, which is what its caption promises', () => {
    // The old table said «ordered by spend» and sorted by impressions — Riyadh would have led on
    // 90,000 impressions while Jeddah spent four times as much.
    const order = orderRows(ACCOUNTS, 2, 'desc')
    expect(order.map((i) => ACCOUNTS[i]![0])).toEqual(['Jeddah', 'Riyadh', 'Dammam'])
  })

  it('keeps an account with no spend last in both directions', () => {
    // Dammam's spend is withheld, not zero. It must not win an ascending sort by being «cheapest».
    expect(orderRows(ACCOUNTS, 2, 'asc').map((i) => ACCOUNTS[i]![0])).toEqual(['Riyadh', 'Jeddah', 'Dammam'])
    expect(orderRows(ACCOUNTS, 2, 'desc').map((i) => ACCOUNTS[i]![0])).toEqual(['Jeddah', 'Riyadh', 'Dammam'])
  })

  it('sorts a derived cost only where both of its parts are real', () => {
    // CPM is null for Dammam because its spend is withheld; a derived figure built on a coalesced
    // zero would have ranked it as the cheapest inventory in the account.
    expect(orderRows(ACCOUNTS, 6, 'asc').map((i) => ACCOUNTS[i]![0])).toEqual(['Riyadh', 'Jeddah', 'Dammam'])
  })

  it('sorts a text column without disturbing the rows', () => {
    const order = orderRows(ACCOUNTS, 0, 'asc')
    expect(order).toHaveLength(ACCOUNTS.length)
    expect(new Set(order).size).toBe(ACCOUNTS.length)
  })
})
