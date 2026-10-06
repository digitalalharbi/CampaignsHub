import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { CreativesPage } from './CreativesPage'
import type { LibraryPage } from './api'
import { renderWithProviders } from '@/test/utils'
import { useAuth } from '@/stores/auth'
import type { AuthUser } from '@/lib/api/types'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listCreatives: vi.fn(), compareCreatives: vi.fn(), groupCreatives: vi.fn() }
})

import { listCreatives } from './api'

/**
 * CONTENT-OBJECTIVE-SORT-001 — the automatic order accounts for itself.
 *
 * The owner's rule is «most orders if the objective is sales, most clicks if it is engagement, most
 * impressions if it is awareness, and so on». The metric is resolved on the SERVER, from
 * `ObjectiveFamily::headlineMetrics()`, so nothing here maps objectives to metrics — this page's job
 * is to say which one was used.
 *
 * That sentence is the point of the unit as much as the order is. The owner's report was that content
 * with no spend and no figures appearing high «makes you doubt the accuracy of the data in the
 * system»; an order a reader cannot account for has exactly that effect even when it is right.
 */
const page = (sort: LibraryPage['sort']): LibraryPage => ({
  creatives: [],
  page: 1,
  per_page: 24,
  total: 0,
  totals: null,
  period: { from: '2026-09-07', to: '2026-10-06' },
  currency: 'SAR',
  metrics_availability: {},
  sort,
  filters: { providers: [], objectives: [], kinds: [], campaigns: [], projects: [], clients: [], health: [] },
} as unknown as LibraryPage)

describe('the automatic order says what it ordered by', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuth.setState({
      user: { id: '1', name: 'Op', permissions: ['campaigns.view'], is_platform_admin: false } as unknown as AuthUser,
      status: 'authenticated',
    })
  })

  it('names the metric and the objective it came from', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page({ applied: 'auto', metric: 'conversions', objective: 'sales' }))
    renderWithProviders(<CreativesPage />, { locale: 'en' })

    const note = await screen.findByTestId('content-sort-note')
    expect(note).toHaveTextContent(/orders/i)
    expect(note).toHaveTextContent(/Sales/i)
  })

  it('says it fell back to spend when no single objective was named', async () => {
    // Not «ordered by sales»: the library spans objectives, and naming one would be a claim about
    // the whole of it made from a part.
    vi.mocked(listCreatives).mockResolvedValue(page({ applied: 'auto', metric: 'spend', objective: null }))
    renderWithProviders(<CreativesPage />, { locale: 'en' })

    expect(await screen.findByTestId('content-sort-note')).toHaveTextContent(/no single objective/i)
  })

  it('names impressions for awareness, which is the owner’s rule for that objective', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page({ applied: 'auto', metric: 'impressions', objective: 'awareness' }))
    renderWithProviders(<CreativesPage />, { locale: 'en' })

    expect(await screen.findByTestId('content-sort-note')).toHaveTextContent(/impressions/i)
  })

  it('says it in Arabic for an Arabic reader', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page({ applied: 'auto', metric: 'conversions', objective: 'sales' }))
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    expect(await screen.findByTestId('content-sort-note')).toHaveTextContent('الطلبات')
  })

  /*
    Both absence cases wait for a DATA-dependent element first.
    *
    * `content-search` renders while the query is still pending, so asserting absence straight after
    * it passed whether or not the rule held — the note had simply not had its data yet. Caught by
    * probing what actually rendered rather than by trusting the green.
  */
  it('stays silent for a sort the reader chose themselves', async () => {
    // Every other option names its own metric in the control they just used; repeating it is noise.
    vi.mocked(listCreatives).mockResolvedValue(page({ applied: 'spend', metric: 'spend', objective: null }))
    renderWithProviders(<CreativesPage />, { locale: 'en' })

    // The empty-state card only renders once the query has resolved, which is the wait this needs.
    await screen.findByTestId('content-no-results')
    expect(screen.queryByTestId('content-sort-note')).toBeNull()
  })

  it('draws no note at all against a server that does not report its sort', async () => {
    vi.mocked(listCreatives).mockResolvedValue(page(undefined))
    renderWithProviders(<CreativesPage />, { locale: 'en' })

    // The empty-state card only renders once the query has resolved, which is the wait this needs.
    await screen.findByTestId('content-no-results')
    expect(screen.queryByTestId('content-sort-note')).toBeNull()
  })
})
