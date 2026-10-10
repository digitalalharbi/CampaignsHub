import { describe, expect, it } from 'vitest'
import { accountContribution } from './accountContribution'
import type { AccountRow } from './api'

const clean = { spend_original: 0, spend_withheld_rows: 0, revenue_original: 0, revenue_withheld_rows: 0, money_original_currency: null, money_original_currencies: 0 }
const row = (over: Partial<AccountRow>): AccountRow => ({ account_id: 'a', provider: 'meta', account_name: 'A', spend: 0, revenue: 0, conversions: 0, impressions: 0, clicks: 0, ...clean, ...over } as AccountRow)

describe('account contribution per platform', () => {
  it('shares a platform\'s spend between its accounts, largest first', () => {
    const out = accountContribution([
      row({ account_id: 'r', account_name: 'Retail', spend: 300 }),
      row({ account_id: 'b', account_name: 'Brand', spend: 700 }),
      row({ account_id: 's', provider: 'snapchat', account_name: 'Snap', spend: 500 }),
    ], 'SAR')
    expect(out.map((p) => p.provider)).toEqual(['meta', 'snapchat'])
    expect(out[0].accounts.map((a) => [a.account_name, a.share])).toEqual([['Brand', 0.7], ['Retail', 0.3]])
    expect(out[1].accounts[0].share).toBe(1)
  })

  it('gives a withheld account no share and counts it, rather than a zero', () => {
    const out = accountContribution([
      row({ account_id: 'b', account_name: 'Brand', spend: 700 }),
      row({ account_id: 'w', account_name: 'Withheld', spend: 0, spend_original: 400, spend_withheld_rows: 2, money_original_currency: 'USD', money_original_currencies: 1 }),
    ], 'SAR')
    expect(out[0].withheld).toBe(1)
    const withheld = out[0].accounts.find((a) => a.account_name === 'Withheld')!
    expect(withheld.share).toBeNull()
    expect(withheld.spend).toBeNull()
    // The stated account's share is of the STATED spend, and says so by being 1, not 0.64.
    expect(out[0].accounts.find((a) => a.account_name === 'Brand')!.share).toBe(1)
  })

  it('keeps a removed account, unnamed, with its real spend', () => {
    const out = accountContribution([row({ account_id: null, account_name: null, spend: 50 })], 'SAR')
    expect(out[0].accounts[0]).toMatchObject({ account_name: null, share: 1, spend: 50 })
  })
})
