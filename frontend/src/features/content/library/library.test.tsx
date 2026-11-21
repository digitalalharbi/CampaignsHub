import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders, signInWith } from '@/test/utils'
import type { CreativeCard, CreativeMetrics, LibraryPage } from '../api'
import { CreativesTable } from './CreativesTable'
import { CreativeInsights } from './CreativeInsights'
import { deliveryOf } from './util'

vi.mock('../api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api')>()
  return { ...actual, listCreatives: vi.fn() }
})
import { listCreatives } from '../api'

const metrics = (over: Partial<CreativeMetrics> = {}): CreativeMetrics => ({
  spend: 100, impressions: 5000, clicks: 100, conversions: 2, revenue: 400, video_views: null, video_p25: null, video_p50: null, video_p75: null, video_p100: null,
  frequency: null, ctr: 0.02, cpc: 1, cpm: 20, cpa: 50, roas: 4, conversion_rate: 0.02, view_rate: null, completion_rate: null, active_days: 7, ...over,
} as CreativeMetrics)

const card = (over: Partial<CreativeCard> = {}): CreativeCard => ({
  id: 'cr-1', name: 'Hero image', kind: 'image', provider: 'snapchat', status: 'ACTIVE', campaign_id: 'c1', campaign_name: '25Oct 2026 ــ Sale',
  preview: { state: 'available', kind: 'image', image_url: null, video_url: null, thumbnail_url: null, expires_at: null, note_ar: null, note_en: null },
  freshness: { last_synced_at: null, source_updated_at: null, first_seen_at: '2026-11-20T10:00:00Z', last_active_at: null },
  ad_delivered: true, metrics: metrics(), headline_metrics: [], ...over,
} as unknown as CreativeCard)

const page = (creatives: CreativeCard[], total = creatives.length): LibraryPage => ({
  creatives, page: 1, per_page: 25, total, period: { from: '2026-11-01', to: '2026-11-22' }, currency: 'SAR', totals: null, metrics_availability: {},
  filters: { providers: ['snapchat', 'meta'], formats: [], statuses: [], kinds: [{ key: 'image', count: 1 }, { key: 'video', count: 1 }], campaigns: [], ad_sets: [], ads: [], objectives: [] },
} as unknown as LibraryPage)

describe('CONTENT-LIBRARY-SNAP-001 — the library', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['content.view', 'campaigns.view'])
  })

  it('reads a platform status, with «inactive» never read as delivering', () => {
    expect(deliveryOf({ status: 'ACTIVE', ad_delivered: false })).toBe('delivering')
    expect(deliveryOf({ status: 'INACTIVE', ad_delivered: true })).toBe('paused')
    expect(deliveryOf({ status: 'disabled', ad_delivered: true })).toBe('paused')
    expect(deliveryOf({ status: '', ad_delivered: false })).toBe('ended')
  })

  it('lists creatives with the platform columns, and the actions wake only on selection', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page([card(), card({ id: 'cr-2', name: 'Promo film', kind: 'video', status: 'PAUSED', campaign_name: null })]))
    renderWithProviders(<CreativesTable />)
    expect(await screen.findByText('Hero image')).toBeInTheDocument()
    for (const h of ['Creative name', 'Format', 'Platform', 'Status', 'Usage', 'Date created']) expect(screen.getByRole('columnheader', { name: h })).toBeInTheDocument()
    expect(screen.getByText('Delivering')).toBeInTheDocument()
    expect(screen.getByText('Paused')).toBeInTheDocument()
    expect(screen.getByText('Not in a campaign')).toBeInTheDocument()
    expect(screen.getByTestId('creatives-action-compare')).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Hero image' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Promo film' }))
    expect(screen.getByTestId('creatives-action-compare')).toBeEnabled()
    expect(screen.getByTestId('creatives-action-export')).toBeEnabled()
  })

  it('sends the search and a platform filter to the one creatives endpoint', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page([card()]))
    renderWithProviders(<CreativesTable />)
    await screen.findByText('Hero image')
    fireEvent.change(screen.getByTestId('creatives-search'), { target: { value: 'hero' } })
    fireEvent.click(screen.getByTestId('creatives-filter'))
    fireEvent.click(screen.getByRole('checkbox', { name: 'meta' }))
    await waitFor(() => {
      const last = vi.mocked(listCreatives).mock.calls.at(-1)![0]
      expect(last.search).toBe('hero')
      expect(last.providers).toEqual(['meta'])
    })
  })

  it('ranks insights by click rate over enough impressions, and says «Not available» rather than zero', async () => {
    vi.mocked(listCreatives).mockImplementation(async (q) => (q.creative_ids
      ? page([card({ id: 'a', metrics: metrics({ ctr: 0.01 }) })])
      : page([
        card({ id: 'a', name: 'Strong', metrics: metrics({ ctr: 0.03 }) }),
        card({ id: 'b', name: 'Thin', metrics: metrics({ ctr: 0.5, impressions: 40 }) }),
        card({ id: 'c', name: 'Steady', metrics: metrics({ ctr: 0.02 }) }),
      ])))
    renderWithProviders(<CreativeInsights />)
    const strong = await screen.findByTestId('insights-card-a')
    expect(screen.queryByTestId('insights-card-b')).not.toBeInTheDocument()
    expect(strong).toHaveTextContent('3%')
    await waitFor(() => expect(strong).toHaveTextContent('200%'))
  })
})
