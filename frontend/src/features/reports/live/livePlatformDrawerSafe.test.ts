import { describe, expect, it } from 'vitest'
import { completePlatform } from './LivePlatformDrawer'

/** REPORT-DRAWER-SAFE-001 — a drawer answer missing the blocks a link cuts still opens: an absent block reads as empty. */
describe('a platform drawer answer with blocks cut away', () => {
  it('reads every absent block as an empty one', () => {
    const data = completePlatform({ provider: 'snapchat', totals: { clicks: 900 } })

    expect(data.ads).toEqual([])
    expect(data.ads_weakest).toEqual([])
    expect(data.objectives).toEqual([])
    expect(data.timeseries).toEqual([])
    expect(data.shares.spend).toBeNull()
    expect(data.shares.outcome.share).toBeNull()
    expect(data.breakdowns.content_drilldown).toBe(false)
    expect(data.totals.clicks).toBe(900)
  })

  it('keeps what arrived', () => {
    const data = completePlatform({ provider: 'meta', ads: [{ name: 'a', provider: 'meta' }], breakdowns: { platform_drilldown: true, content_drilldown: true } } as never)

    expect(data.ads).toHaveLength(1)
    expect(data.breakdowns.content_drilldown).toBe(true)
  })
})
