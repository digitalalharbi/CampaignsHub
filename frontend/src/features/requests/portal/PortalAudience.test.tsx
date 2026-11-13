import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { PortalAudience } from './PortalAudience'
import { useUi } from '@/stores/ui'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

const reading = {
  period: { from: '2026-11-12', to: '2026-11-12', days: 1 }, providers: ['meta'], cross_platform: { summed: false, reason: 'x' },
  targeting: { state: 'ready', items: [] },
  paid_delivery: { state: 'ready', unavailable: [], groups: [{ kind: 'paid_delivery', provider: 'meta', dimension: 'region', metric: 'impressions', source_field: 'meta:x', denominator: 400, reach_note: null, coverage: { days_with_rows: 1, days_in_period: 1, ad_sets_with_rows: 1, ad_sets_delivering: 1 }, synced_at: null, rows: [{ value: 'Najran Province', impressions: 300, share: { numerator: 300, denominator: 400, ratio: 0.75 }, clicks: 6, reach: null }] }] },
  site_events: { state: 'unavailable', groups: [] }, organic: { state: 'unavailable', reason: 'not_fetched' },
}

/** AUDIENCE-001 — the client's audience under «الأداء»: drawn when the server answers, absent when it refuses. */
describe('PortalAudience', () => {
  beforeEach(() => { vi.clearAllMocks(); useUi.setState({ locale: 'en' }) })
  afterEach(() => vi.clearAllMocks())

  it('draws each project’s cards with no spend line', async () => {
    vi.mocked(getData).mockResolvedValue({ period: reading.period, projects: [{ project: { id: 'p1', name: 'P' }, reading }] })
    renderWithProviders(<PortalAudience />)
    expect(await screen.findByTestId('portal-audience')).toHaveTextContent('75% (300/400)')
    expect(screen.queryByText(/Spend/)).toBeNull()
    expect(vi.mocked(getData).mock.calls[0][0]).toBe('/client/audience')
  })

  it('draws nothing when the agency left it off (403)', async () => {
    vi.mocked(getData).mockRejectedValue(Object.assign(new Error('forbidden'), { status: 403 }))
    const { container } = renderWithProviders(<PortalAudience />)
    await new Promise((r) => setTimeout(r, 20))
    expect(container.querySelector('[data-testid="portal-audience"]')).toBeNull()
  })
})
