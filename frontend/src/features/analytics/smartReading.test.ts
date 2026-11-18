import { describe, expect, it } from 'vitest'
import { buildFindings } from './SmartReading'
import type { CampaignRow } from './api'

const row = (over: Partial<CampaignRow> & Record<string, unknown>) => ({
  campaign_id: 'c', campaign_name: 'C', provider: 'meta', objective: 'sales', objective_family: 'sales', objective_source: 'platform',
  status: 'active', last_active_on: null, previous_spend: null, spend_change: null, ...over,
} as unknown as CampaignRow)

/** ANALYTICS-SMART-READING-001 — findings are measured facts over the page's own rows. */
describe('the smart reading', () => {
  it('states concentration and the cost spread inside one objective', () => {
    const f = buildFindings([
      row({ campaign_id: 'a', campaign_name: 'A', spend: 300, purchases: 3, conversions: 3 }),
      row({ campaign_id: 'b', campaign_name: 'B', spend: 100, purchases: 4, conversions: 4 }),
    ], [], 'SAR', false)
    expect(f.find((x) => x.key === 'concentration')?.figure).toBe('75%')
    expect(f.find((x) => x.key === 'cost-spread')?.figure).toBe('4.0×')
  })

  it('withholds every money finding when one campaign cannot be stated in the report currency', () => {
    const f = buildFindings([
      row({ campaign_id: 'a', spend: 300, purchases: 3 }),
      row({ campaign_id: 'b', spend: undefined, spend_withheld_rows: 2, spend_original: 50, money_original_currency: 'EGP', money_original_currencies: 1 }),
    ], [], 'SAR', false)
    expect(f.filter((x) => ['concentration', 'cost-spread', 'no-result', 'mover'].includes(x.key))).toEqual([])
  })

  it('reads a click-through leader only above 1,000 impressions', () => {
    const f = buildFindings([], [
      { provider: 'meta', impressions: 10000, clicks: 300 },
      { provider: 'tiktok', impressions: 20000, clicks: 200 },
      { provider: 'x', impressions: 50, clicks: 40 },
    ], 'SAR', false)
    expect(f.find((x) => x.key === 'ctr-leader')?.figure).toBe('3.00%')
  })
})
