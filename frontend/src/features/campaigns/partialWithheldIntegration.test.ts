import { describe, expect, it } from 'vitest'

import { moneyFromTotals } from '@/features/analytics/format'
import { readCostPer, readRoas } from '@/lib/money/contract'

/**
 * PARTIAL-WITHHELD-001 — integration gate through a surface #106 added/changed.
 *
 * #106's CampaignCommandCenter renders its spend KPI through `moneyFromTotals(...)` and its
 * cost-per / ROAS through the same contract (`readCostPer` / `readRoas`). This proves the new
 * consumer cannot turn a PARTIAL scope — some spend converted, some withheld and awaiting a rate —
 * back into a plausible single number: the contract returns «—» / unavailable, never the converted
 * subset (1,000) nor a coalesced 0.
 *
 * The fixture is deliberately mixed: 1,000 converted beside 500 USD withheld in one currency.
 */
const PARTIAL_SUMMARY = {
  spend: 1000, // the coalesced converted subset — the number the bug used to show as the whole
  spend_original: 500,
  spend_withheld_rows: 4,
  revenue: 3000,
  revenue_original: 1500,
  revenue_withheld_rows: 4,
  conversions: 40,
  clicks: 200,
  impressions: 50_000,
  roas: 3,
  cpa: 25,
  money_original_currency: 'USD',
  money_original_currencies: 1,
}

describe('CampaignCommandCenter money path — a partial scope stays unavailable (#106 integration)', () => {
  it('the spend KPI is «—», never the converted 1,000', () => {
    const reading = moneyFromTotals(PARTIAL_SUMMARY, 'spend', true, 'SAR')

    expect(reading.text).toBe('—')
    expect(reading.text).not.toContain('1,000')
    expect(reading.text).not.toContain('1000')
    // `withheld: true` drives the card to suppress its delta/spark rather than draw a trend on a
    // number that does not exist.
    expect(reading.withheld).toBe(true)
  })

  it('the cost-per KPI (CPA) is unavailable on a partial numerator', () => {
    expect(readCostPer(PARTIAL_SUMMARY, 'cpa', 'conversions', 'SAR', true).kind).toBe('unavailable')
  })

  it('ROAS is unavailable — no ratio from a partial numerator or denominator', () => {
    expect(readRoas(PARTIAL_SUMMARY, true).kind).toBe('unavailable')
  })
})
