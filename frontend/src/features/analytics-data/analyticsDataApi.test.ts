import { describe, expect, it } from 'vitest'
import { analyticsPath, ATTRIBUTION_MODELS, creditTotal, type AttributionPayload } from './analyticsDataApi'

describe('analytics data paths', () => {
  it('writes the project-scoped path with a named period or a custom span, never both', () => {
    expect(analyticsPath('p1', 'site')).toBe('/projects/p1/analytics/site')
    expect(analyticsPath('p1', 'attribution', { period: 'last_30' })).toBe('/projects/p1/analytics/attribution?period=last_30')
    expect(analyticsPath('p1', 'store', { from: '2026-10-01', to: '2026-10-31', period: 'last_7' })).toBe('/projects/p1/analytics/store?from=2026-10-01&to=2026-10-31')
    expect(analyticsPath('p1', 'influencer-links')).toBe('/projects/p1/analytics/influencer-links')
  })

  it('names the four models in the order the switch shows them', () => {
    expect(ATTRIBUTION_MODELS).toEqual(['last_click', 'first_click', 'linear', 'position_based'])
  })

  it('adds a model\'s channel credit up so the board can check it against the total', () => {
    const cell = (orders: number) => ({ orders, revenue: orders * 100 })
    const payload = {
      channels: [
        { channel: 'meta', models: { last_click: cell(2), first_click: cell(1), linear: cell(1.5), position_based: cell(1.5) } },
        { channel: 'influencer', models: { last_click: cell(1), first_click: cell(2), linear: cell(1.5), position_based: cell(1.5) } },
      ],
    } as unknown as AttributionPayload
    for (const model of ATTRIBUTION_MODELS) expect(creditTotal(payload, model)).toBe(3)
  })
})
