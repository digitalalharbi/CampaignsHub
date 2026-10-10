import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { SiteAnalyticsTab } from './SiteAnalyticsTab'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn() }))
import { getData } from '@/lib/api/client'

/** GA4-ANALYTICS-PRODUCT-001 — the site tab states what GA4 measured, and says plainly when it cannot. */
const range = { from: '2026-10-01', to: '2026-10-10' }

describe('the site analytics tab', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['campaigns.view']) })
  afterEach(() => signOut())

  it('says GA4 is not connected rather than drawing an empty dashboard', async () => {
    vi.mocked(getData).mockResolvedValue({ state: 'not_connected', period: range })
    renderWithProviders(<SiteAnalyticsTab projectId="p1" range={range} />, { route: '/agency/analytics', locale: 'en' })
    expect(await screen.findByTestId('site-state-not_connected')).toHaveTextContent('Google Analytics 4 is not connected')
  })

  it('states sessions, engagement, purchases and revenue, the paid share per platform, and absent breakdowns as absent', async () => {
    vi.mocked(getData).mockResolvedValue({
      state: 'ready', period: range, property: { id: '111', name: 'Store', timezone: 'Asia/Riyadh' }, last_synced_at: null, currency: 'SAR',
      totals: { sessions: 1000, engaged_sessions: 560, engagement_rate: 0.56, purchases: 30, key_events: 30, revenue: 5500, users_daily_sum: 1400 },
      trend: [{ date: '2026-10-05', sessions: 1000, engaged_sessions: 560, purchases: 30, revenue: 5500 }],
      funnel: [{ stage: 'sessions', count: 1000, step_rate: null, exceeds_previous: false }, { stage: 'purchases', count: 30, step_rate: 0.03, exceeds_previous: false }],
      paid: { available: true, sessions: 500, purchases: 17, revenue: 3400, session_share: 0.5, purchase_share: 0.567, revenue_share: 0.618, platforms: [{ platform: 'meta', sessions: 300, purchases: 9, revenue: 1800 }, { platform: 'google', sessions: 200, purchases: 8, revenue: 1600 }] },
      breakdowns: { source_medium: { rows: [{ dimension_1: 'facebook', dimension_2: 'paid_social', sessions: 300, purchases: 9, revenue: 1800, engagement_rate: 0.5, conversion_rate: 0.03 }], more: 0 }, campaign: null, landing_page: null, device: null, country: null, user_type: null },
      events: { rows: [{ event: 'purchase', event_count: 30, key_events: 30, is_key_event: true }], more: 0 },
    })
    renderWithProviders(<SiteAnalyticsTab projectId="p1" range={range} />, { route: '/agency/analytics', locale: 'en' })

    expect(await screen.findByTestId('site-kpi-sessions')).toHaveTextContent('1,000')
    expect(screen.getByTestId('site-kpi-sessions')).toHaveTextContent('1,400 users (sum of daily)')
    expect(screen.getByTestId('site-kpi-engagement')).toHaveTextContent('56.0%')
    expect(screen.getByTestId('site-kpi-revenue')).toHaveTextContent('5.5K SAR')
    expect(screen.getByTestId('site-kpi-revenue')).toHaveTextContent('62% from paid visits')
    const paid = screen.getByTestId('site-paid')
    expect(within(paid).getAllByText('Meta').length).toBeGreaterThan(0)
    expect(screen.getByTestId('site-source-medium')).toHaveTextContent('facebook / paid_social')
    expect(screen.getByTestId('site-landing-page-absent')).toBeInTheDocument()
    expect(screen.getByTestId('site-events')).toHaveTextContent('purchase')
  })
})
