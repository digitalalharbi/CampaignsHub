import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { ImpactPage } from './ImpactPage'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn() }))
import { getData } from '@/lib/api/client'

/** INFLUENCER-IMPACT-001 — figures with their evidence; only ever mounted while the service is offered. */
const row = (over = {}) => ({
  influencer_id: 'i1', name: 'Layla', handle: '@layla', collaborations: 1, links: 1, codes: 1, clicks: 40, visits: null, add_to_cart: null, orders: 2, confirmed: 1, overlap_orders: 1,
  net_revenue: 500, currency: 'SAR', revenue_by_currency: { SAR: 500 }, cost: 250, cost_currency: 'SAR', cpa: 125, roas: 2, roi: 1, is_ugc: false, ...over,
})
const payload = (over = {}) => ({
  state: 'ready', can_see_costs: true, rows: [row()], total: 1,
  summary: { influencers: 1, clicks: 40, orders: 2, overlap_orders: 1, has_ledger: true, comparison: [{ group: 'influencers', label_ar: 'مؤثرون', label_en: 'Creators', orders: 2 }] },
  notes: { clicks: { ar: 'نقرة', en: 'A click is not a purchase' }, orders: { ar: 'طلب', en: 'Coupon only' }, visits: { ar: 'زيارة', en: 'No source' }, overlap: { ar: 'تداخل', en: 'Not credited' } },
  ...over,
})

describe('the creator impact page', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['influencers.view']) })
  afterEach(() => signOut())

  it('states what a click is, reads a page from the server and opens a creator’s path', async () => {
    vi.mocked(getData).mockResolvedValue(payload())
    renderWithProviders(<ImpactPage />, { route: '/influencers/impact', locale: 'en' })
    expect(await screen.findByTestId('impact-notes')).toHaveTextContent('A click is not a purchase')
    expect(vi.mocked(getData).mock.calls[0][0]).toContain('per_page=10')
    expect(vi.mocked(getData).mock.calls[0][0]).toContain('sort=orders')
    expect(screen.getByRole('columnheader', { name: 'ROAS' })).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('table-expand'))
    expect(await screen.findByTestId('impact-funnel')).toHaveTextContent('visits —')
  })

  it('shows no cost column to a reader who may not see costs', async () => {
    vi.mocked(getData).mockResolvedValue(payload({ can_see_costs: false, rows: [row({ cost: null, cpa: null, roas: null, roi: null })] }))
    renderWithProviders(<ImpactPage />, { route: '/influencers/impact', locale: 'en' })
    await waitFor(() => expect(screen.getByText('Layla')).toBeInTheDocument())
    expect(screen.queryByRole('columnheader', { name: 'ROAS' })).toBeNull()
    expect(screen.queryByRole('columnheader', { name: 'Cost' })).toBeNull()
  })

  it('says there is no ledger rather than printing zero orders', async () => {
    vi.mocked(getData).mockResolvedValue(payload({ rows: [row({ orders: null, confirmed: null, overlap_orders: null, net_revenue: null })], summary: { influencers: 1, clicks: 40, orders: 0, overlap_orders: 0, has_ledger: false, comparison: [] } }))
    renderWithProviders(<ImpactPage />, { route: '/influencers/impact', locale: 'en' })
    await screen.findByText('No store ledger is tied to these collaborations')
    expect(screen.getByTestId('impact-orders')).toHaveTextContent('—')
    expect(screen.queryByTestId('impact-comparison')).toBeNull()
  })
})
