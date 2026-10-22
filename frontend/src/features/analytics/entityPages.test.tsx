import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { Route, Routes, useLocation } from 'react-router-dom'
import { AdGroupsPage } from './AdGroupsPage'
import { AdsPage } from './AdsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * ADGROUPS-PAGE-001 / ADS-PAGE-001 — «المجموعات» and «الإعلانات» are pages of their own.
 *
 * The permanent guard (§5.5, §5.6) rules out an Analytics TAB for either. The page is the same entity table the Analytics tab
 * draws — one source for the figures — with the tab fixed by the ROUTE, no eleven-tab bar, the page naming itself, and a
 * drilldown that crosses to the sibling PAGE instead of switching a tab.
 */
const ROW = {
  entity_id: 'e1', external_id: 'sq-1', name: 'Riyadh · 18-34', status: 'active', campaign_id: 'c1', ad_set_id: null,
  active_days: 2, last_active_on: '2026-08-02', impressions: 90000, reach: 45000, frequency: 2, clicks: 300, ctr: 0.00333,
  spend: 412.5, spend_original: 412.5, spend_withheld_rows: 0, money_original_currency: 'SAR', money_original_currencies: 1,
  conversions: 12, cpa: 34.4, cpm: 4.6, cpc: 1.4,
}

function route(entities: unknown[]) {
  vi.mocked(getData).mockImplementation(async (url: string) => {
    if (url.includes('/entities/')) {
      return { entities, entity_type: url.includes('ad_set') ? 'ad_set' : 'ad', period: { from: '2026-07-25', to: '2026-08-10' }, currency: 'SAR', attribution_window: null } as never
    }
    if (url.includes('disclaimer')) return null as never
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR', provenance: { source: 'live', live_rows: 5, demo_rows: 0 } } as never
    return [] as never
  })
}

function Where() {
  const l = useLocation()

  return <div data-testid="where">{l.pathname}{l.search}</div>
}

describe('the ad-groups and ads pages', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it.each([
    ['en', 'Ad groups'],
    ['ar', 'المجموعات'],
  ] as const)('the ad-groups page names itself and shows the ad-set table, with no Analytics tab bar — %s', async (locale, title) => {
    route([ROW])
    renderWithProviders(<AdGroupsPage />, { locale, route: '/app/ad-groups' })

    expect(await screen.findByRole('table')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(title)
    expect(screen.queryByRole('tablist'), 'an independent page does not carry the Analytics tab bar').toBeNull()
    expect(screen.getByText('Riyadh · 18-34')).toBeInTheDocument()
  })

  it.each([
    ['en', 'Ads'],
    ['ar', 'الإعلانات'],
  ] as const)('the ads page names itself and shows the ads table — %s', async (locale, title) => {
    route([{ ...ROW, name: 'Video 9x16', external_id: 'ad-1' }])
    renderWithProviders(<AdsPage />, { locale, route: '/app/ads' })

    expect(await screen.findByRole('table')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(title)
    expect(screen.queryByRole('tablist')).toBeNull()
  })

  it('the route decides the entity, whatever ?tab= a stale link carries', async () => {
    route([ROW])
    renderWithProviders(<AdGroupsPage />, { locale: 'en', route: '/app/ad-groups?tab=ads' })

    await screen.findByRole('table')
    expect(vi.mocked(getData).mock.calls.some(([url]) => String(url).includes('ad_set')), 'the ad-set level was never requested').toBe(true)
    expect(vi.mocked(getData).mock.calls.every(([url]) => !String(url).includes('/entities/ad?') && !String(url).includes('entity_type=ad&')), 'the stale ?tab=ads switched the page to ads').toBe(true)
  })

  it('drilling from an ad group into its ads moves to the ads PAGE and keeps the period', async () => {
    route([ROW])
    renderWithProviders(
      <>
        <Routes>
          <Route path="/app/ad-groups" element={<AdGroupsPage />} />
          <Route path="/app/ads" element={<AdsPage />} />
        </Routes>
        <Where />
      </>,
      { locale: 'en', route: '/app/ad-groups?days=7' },
    )

    const row = await screen.findByText('Riyadh · 18-34')
    fireEvent.click(row)

    await waitFor(() => expect(screen.getByTestId('where').textContent).toMatch(/^\/app\/ads\?/))
    expect(screen.getByTestId('where').textContent).toContain('days=7')
    expect(screen.getByTestId('where').textContent).toContain('drill=')
    expect(screen.getByTestId('where').textContent, 'the route decides the tab; none travels').not.toContain('tab=')
  })
})
