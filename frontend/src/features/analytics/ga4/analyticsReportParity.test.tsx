import { describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { getData } from '@/lib/api/client'
import { basisFor, Ga4Workbench } from './Ga4Workbench'
import type { MatchPayload } from './matchTypes'
import type { StorePayload } from './storeTypes'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn() }))

/**
 * ANALYTICS-REPORT-PARITY-001 (Owner 2026-11-25) — «أجزاء تعدّلت في التقارير لكن لم تتعدل في التحليلات». The Analytics website tab
 * draws the report's «المنصة مقابل الموقع» with the same basis the report uses: the website's orders and sales, and the spend the
 * platforms reported (Karam Alnakhil 10–16 Nov: 3,426.95 USD over 1,663 orders = 2.06 USD per order, as on the shared report).
 */
const match = {
  state: 'ready', basis: 'ga4', order_level: false,
  platforms: [
    { platform: 'google', claimed: { conversions: 194, revenue_usd: null, spend_usd: 402.59 }, ga4: { sessions: 4839, purchases: 95, revenue: null, revenue_usd: null, aov_usd: null, conversion_rate: null }, matched_share: null },
    { platform: 'snapchat', claimed: { conversions: 391, revenue_usd: null, spend_usd: 1160 }, ga4: { sessions: 4314, purchases: 14, revenue: null, revenue_usd: null, aov_usd: null, conversion_rate: null }, matched_share: null },
  ],
} as unknown as MatchPayload
const store = {
  state: 'ready', period: { from: '2026-11-10', to: '2026-11-16' },
  kpis: [
    { key: 'transactions', kind: 'count', current: 1663, previous: 1840, change_pct: -0.1, currency: null },
    { key: 'revenue', kind: 'money', current: 385000, previous: 400000, change_pct: -0.04, currency: 'SAR', current_usd: 102600, previous_usd: 106000 },
  ],
} as unknown as StorePayload

describe('analytics reads the website like the report', () => {
  it('judges against the website orders, its sales in dollars, and the platforms’ reported spend', () => {
    expect(basisFor(match, store)).toEqual({ orders: 1663, ordersSource: 'site', spendUsd: 1562.59, revenueUsd: 102600 })
  })

  it('never guesses spend or orders it was not sent', () => {
    const noSpend = { ...match, platforms: match.platforms!.map((p) => ({ ...p, claimed: { conversions: p.claimed?.conversions ?? null, revenue_usd: null } })) } as MatchPayload
    expect(basisFor(noSpend, undefined)).toEqual({ orders: null, ordersSource: 'site', spendUsd: null, revenueUsd: null })
  })

  it('draws the report’s platform-vs-website reading on the tab, with the analyst tables folded under the details', async () => {
    vi.mocked(getData).mockImplementation(async (url: string) => {
      if (url.includes('/measurement/attribution')) return match
      if (url.includes('/measurement/store')) return store
      if (url.includes('/measurement/gap')) return { state: 'empty' }
      return { state: 'empty', groups: {}, rows: [] }
    })
    renderWithProviders(<Ga4Workbench projectId="p1" range={{ from: '2026-11-10', to: '2026-11-16' }} ar={false} />, { route: '/agency/analytics', locale: 'en' })

    expect(await screen.findByTestId('report-ga4-attribution-block')).toBeInTheDocument()
    expect(screen.getByTestId('analytics-technical-details')).toContainElement(screen.getByTestId('ga4-match'))
  })
})
