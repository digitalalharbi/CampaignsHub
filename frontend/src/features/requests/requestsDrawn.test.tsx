import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { RequestsDashboardPage } from './RequestsDashboardPage'
import { renderWithProviders } from '@/test/utils'
import type { RequestBreakdown } from './internalApi'

vi.mock('./internalApi', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  listRequests: vi.fn(),
  changeRequestStatus: vi.fn(),
}))
vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  // The hook returns a query result PLUS `options`; the select reads the latter.
  useTaxonomyOptions: () => ({ data: [], options: [], isLoading: false, isError: false }),
}))

import { listRequests } from './internalApi'

/**
 * VIZ-REQUESTS-001 — three cards that drew their own bars, and an SLA figure that was flattering itself.
 *
 * ## The SLA card was the wrong shape for what it holds
 *
 * Breached, due soon and on track are mutually exclusive states of the SAME requests, so they are a
 * COMPOSITION — one bar divided three ways. They were drawn as three separate bars, each scaled to
 * the same total, which asks the reader to add three lengths back together to see the one thing the
 * card exists to say: how much of the queue is in trouble.
 *
 * ## And «on track» was counting requests nobody promised anything about
 *
 * The server's bucket read `sla_due_at IS NULL OR sla_due_at > NOW() + 24h`. A request with no
 * deadline at all landed in «on track» beside requests comfortably inside one — a promise reported as
 * kept where no promise exists, inflating the figure in the direction of comfort. `no_sla` is its own
 * band now, and the four partition the queue, which is what lets a composition be drawn over them.
 */
const breakdown = (over: Partial<RequestBreakdown> = {}): RequestBreakdown => ({
  by_status: [
    { key: 'new', label: 'جديد', label_en: 'New', total: 5 },
    { key: 'review', label: 'قيد المراجعة', label_en: 'Under review', total: 3 },
  ],
  by_type: [{ key: 'launch', label: 'إطلاق', label_en: 'Campaign launch', total: 8 }],
  sla: { breached: 2, due_soon: 1, on_track: 4, no_sla: 3 },
  ...over,
} as RequestBreakdown)

const result = (over: Record<string, unknown> = {}) => ({
  rows: [],
  meta: {
    pagination: { total: 10, per_page: 25, current_page: 1, last_page: 1 },
    summary: { total: 10, new: 5, review: 3, paused: 0, needs_attention: 2 },
    breakdown: breakdown(),
  },
  ...over,
})

describe('the requests dashboard draws through the product’s own chart layer', () => {
  beforeEach(() => vi.clearAllMocks())

  it('divides the queue by how its SLA stands, as one bar', async () => {
    vi.mocked(listRequests).mockResolvedValue(result() as never)
    renderWithProviders(<RequestsDashboardPage />, { locale: 'en' })

    // 2 breached of 10 requests.
    expect(await screen.findByTestId('sla-mix-segment-breached')).toHaveStyle({ width: '20%' })
    expect(screen.getByTestId('sla-mix-segment-on_track')).toHaveStyle({ width: '40%' })
  })

  it('names the requests nobody promised anything about, instead of calling them on track', async () => {
    vi.mocked(listRequests).mockResolvedValue(result() as never)
    renderWithProviders(<RequestsDashboardPage />, { locale: 'en' })

    const legend = await screen.findByTestId('sla-mix-legend')
    expect(legend).toHaveTextContent(/No SLA/i)
    expect(screen.getByTestId('sla-mix-segment-no_sla')).toHaveStyle({ width: '30%' })
  })

  it('draws the status split through the shared ranked bar', async () => {
    vi.mocked(listRequests).mockResolvedValue(result() as never)
    renderWithProviders(<RequestsDashboardPage />, { locale: 'en' })

    expect(await screen.findByTestId('requests-by-status')).toBeInTheDocument()
    expect(screen.getByTestId('requests-by-status-legend')).toHaveTextContent('Under review')
  })

  it('draws the service types the same way, and says so when there are none', async () => {
    vi.mocked(listRequests).mockResolvedValue(result({
      meta: { ...result().meta, breakdown: breakdown({ by_type: [] }) },
    }) as never)
    renderWithProviders(<RequestsDashboardPage />, { locale: 'en' })

    await screen.findByTestId('requests-by-status')
    expect(screen.queryByTestId('requests-by-type')).toBeNull()
    expect(screen.getByTestId('requests-by-type-empty')).toBeInTheDocument()
  })

  it('counts the SLA bands against the whole queue, so an unlabelled request cannot vanish', async () => {
    // The four bands sum to 10 here. A queue whose bands sum to less keeps the remainder visible.
    vi.mocked(listRequests).mockResolvedValue(result({
      meta: { ...result().meta, breakdown: breakdown({ sla: { breached: 1, due_soon: 1, on_track: 1, no_sla: 1 } as never }) },
    }) as never)
    renderWithProviders(<RequestsDashboardPage />, { locale: 'en' })

    expect(await screen.findByTestId('sla-mix-segment-residual')).toBeInTheDocument()
  })

  it('renders the status labels in the reader’s language, never the stored key', async () => {
    vi.mocked(listRequests).mockResolvedValue(result() as never)
    renderWithProviders(<RequestsDashboardPage />, { locale: 'ar' })

    const legend = await screen.findByTestId('requests-by-status-legend')
    expect(legend).toHaveTextContent('قيد المراجعة')
    expect(legend).not.toHaveTextContent('review')
  })
})
