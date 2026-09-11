import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  getEnvelope: vi.fn(),
}))

import { getData, getEnvelope } from '@/lib/api/client'

/**
 * KPI-SELECTION-001 — four cards, each one's metric chosen by the reader.
 *
 * «More metrics / مؤشرات إضافية» expanded a second row of cards nobody asked for and hid the ones
 * somebody did. The Owner replaced it with the shape an ads manager uses: four cards, and the metric
 * NAME on each is a dropdown with a search.
 *
 * The cards are built by the catalogue's own `buildItems`, so a selected metric carries the same
 * reading, comparison, sparkline, definition and currency as the same metric chosen by the objective
 * layout — a selector with its own arithmetic is how this page would come to disagree with Analytics
 * about a figure they both read from one payload.
 */
const SUMMARY = {
  current: { spend: 13_100, impressions: 7_440_000, clicks: 52_800, ctr: 0.0071, reach: 900_000 },
  previous: {},
  delta: { spend: 8.51, impressions: 5.04, clicks: 8.69, ctr: 0.61, reach: 0.2 },
  currency: 'USD',
  reported: { spend: true, impressions: true, clicks: true, ctr: true, reach: true },
  rows_in_scope: 12,
}

function route() {
  const body = (url: string) => {
    if (url.includes('/summary')) return SUMMARY
    if (url.includes('budget-explanation') || url.includes('disclaimer')) return null

    return []
  }
  vi.mocked(getData).mockImplementation((url: string) => body(url) as never)
  vi.mocked(getEnvelope).mockImplementation(
    (url: string) => ({ data: body(url), meta: null, message: null, success: true }) as never,
  )
}

describe('the dashboard KPI cards', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    route()
    window.localStorage.clear()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view', 'analytics.view'])
  })
  afterEach(() => {
    signOut()
    /* The chosen KPIs outlive the component by design — clear them so the choice does not leak into the next test. */
    window.localStorage.clear()
  })

  it('shows exactly four cards, and no «more metrics» control', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })

    /*
      Awaited on the pickers: the strip renders skeletons while the summary is in flight, so counting
      immediately sees zero cards and would fail against a working page.
    */
    await screen.findByTestId('kpi-picker-0')

    expect(screen.getAllByTestId(/^kpi-picker-\d$/)).toHaveLength(4)
    expect(screen.queryByRole('button', { name: /More metrics|مؤشرات إضافية/i })).toBeNull()
  })

  it('defaults to spend, impressions, clicks and CTR', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })

    await screen.findByTestId('kpi-picker-0')
    const strip = screen.getByTestId('dashboard-kpis')

    /* «Click-through rate» is the catalogue's own name for `ctr` — the product's word, not mine. */
    for (const label of ['Spend', 'Impressions', 'Clicks', 'Click-through rate']) {
      expect(strip.textContent).toContain(label)
    }
  })

  /**
   * The values are the summary's, formatted by the catalogue.
   *
   * Awaited rather than read once: the container renders before the query resolves, so an immediate
   * read sees «No data» on every card and would pass a test that asserted only labels.
   */
  it('reads its figures from the summary, in its currency', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })

    await screen.findByTestId('kpi-picker-0')
    const strip = screen.getByTestId('dashboard-kpis')

    await waitFor(() => expect(strip.textContent).toMatch(/13\.1K|13,100/))
    expect(strip.textContent).toContain('USD')
    expect(strip.textContent).not.toMatch(/undefined|NaN/)
  })

  /* The metric name is the control — clicking it opens a searchable list. */
  it('replaces one card’s metric through a searchable selector', async () => {
    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('kpi-picker-0'))

    fireEvent.change(await screen.findByTestId('kpi-search'), { target: { value: 'Reach' } })
    fireEvent.click(await screen.findByTestId('kpi-option-reach'))

    await waitFor(() => {
      expect(screen.getByTestId('dashboard-kpis').textContent).toContain('Reach')
    })
  })

  /* A choice that does not survive a reload is a choice the reader makes again every visit. */
  it('remembers the chosen metrics', async () => {
    const { unmount } = renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('kpi-picker-0'))
    fireEvent.click(await screen.findByTestId('kpi-option-reach'))

    await waitFor(() => expect(screen.getByTestId('dashboard-kpis').textContent).toContain('Reach'))
    unmount()

    renderWithProviders(<AnalyticsPage surface="dashboard" />, { locale: 'en' })

    await screen.findByTestId('kpi-picker-0')
    expect(screen.getByTestId('dashboard-kpis').textContent).toContain('Reach')
  })
})
