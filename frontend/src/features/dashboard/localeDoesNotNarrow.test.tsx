import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, screen, waitFor } from '@testing-library/react'
import { DashboardPage } from './DashboardPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * FILTER-LOCALE-EMPTY-STATE-OBS — the language is not an axis of the data.
 *
 * ## The observation this pins
 *
 * On gate run `33103038784` the dashboard was narrowed to Objective = Sales, rendered its ROAS card,
 * and then — moments after `toggleLanguage` — showed «No data matches these filters» with the
 * applied-filter chip still reading Sales. A same-head rerun passed, which proves non-determinism
 * and closes nothing.
 *
 * A single browser run cannot settle a flake either way, so this asserts the INVARIANT the
 * observation suspects instead: changing the language must not change what is asked of the server,
 * and must not turn a populated scope into an empty one. Whatever owns the flake — the query key,
 * the URL state, the rerender — every one of those hypotheses is a way for the locale to reach the
 * data path, and this fails if any of them does.
 *
 * It is deterministic where the gate is not: the request URLs are compared directly, before and
 * after, with the same filter in place.
 */
const SUMMARY = {
  current: { spend: 96_000, revenue: 796_000, conversions: 1_158, roas: 8.28, impressions: 900_000, clicks: 12_000 },
  previous: {},
  delta: {},
  currency: 'SAR',
  rows_in_scope: 42,
  reported: { spend: true, revenue: true, conversions: true, roas: true, impressions: true, clicks: true },
  provenance: { source: 'live', live_rows: 42, demo_rows: 0 },
}

function route(): string[] {
  const urls: string[] = []

  vi.mocked(getData).mockImplementation((url: string) => {
    urls.push(url)
    if (url.includes('/summary')) return SUMMARY as never
    if (url.includes('disclaimer')) return null as never

    return [] as never
  })

  return urls
}

/** Every metrics URL asked for, in order — the request is the thing the locale must not touch. */
const metricUrls = (urls: string[]) => urls.filter((u) => u.includes('/metrics/'))

describe('FILTER-LOCALE-EMPTY-STATE-OBS — switching language narrows nothing', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    useUi.setState({ locale: 'ar' })
    signInWith(['campaigns.view', 'analytics.view'])
  })
  afterEach(() => {
    signOut()
    useUi.setState({ locale: 'ar' })
  })

  it('asks the server for exactly the same scope after the language changes', async () => {
    const urls = route()
    renderWithProviders(<DashboardPage />, { locale: 'ar' })

    await screen.findByTestId('dashboard-objective')
    fireEvent.change(screen.getByTestId('dashboard-objective'), { target: { value: 'sales' } })

    await waitFor(() => expect(metricUrls(urls).some((u) => u.includes('objective'))).toBe(true))

    const before = metricUrls(urls).length

    await act(async () => {
      useUi.getState().toggleLocale()
    })
    await waitFor(() => expect(useUi.getState().locale).toBe('en'))
    // Let anything the toggle set in motion actually run before counting.
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 50)) })

    /*
     * COUNTED, not de-duplicated.
     *
     * A locale in the URL asks for a URL that was never asked for — easy to see. A locale in the
     * QUERY KEY asks for the SAME url again, and a set of distinct URLs cannot tell that apart from
     * nothing happening. It is not nothing: it is a refetch, which is a network round trip and a
     * loading state, and a loading state under a chip that still names the objective is exactly the
     * shape the gate photographed.
     */
    const after = metricUrls(urls).slice(before)

    expect(after, 'the language change asked the server something').toEqual([])

    /*
     * And the language is not IN any request either.
     *
     * A locale in the query KEY refetches, which the count above sees. A locale in the URL does not
     * refetch — the key is unchanged — so it is invisible to the count and arrives on the NEXT fetch
     * instead: the same defect, one interaction later, when nobody is looking at the language any
     * more. Both are «the locale reached the data path»; both are checked.
     */
    const carriesLocale = metricUrls(urls).filter((url) =>
      [...new URLSearchParams(url.split('?')[1] ?? '')].some(
        ([key, value]) => key === 'lang' || key === 'locale' || value === 'ar' || value === 'en',
      ),
    )

    expect(carriesLocale, 'a metrics request carried the reader’s language').toEqual([])
  })

  /**
   * And the strip does not fall into the empty-scope state.
   *
   * That panel is the sentence the gate saw: «No data matches these filters», under a chip still
   * naming the objective that had just rendered a ROAS card. It is correct for a genuinely empty
   * scope and it is a lie about a populated one.
   */
  it('keeps the populated scope populated across the language change', async () => {
    route()
    renderWithProviders(<DashboardPage />, { locale: 'ar' })

    await screen.findByTestId('dashboard-objective')
    fireEvent.change(screen.getByTestId('dashboard-objective'), { target: { value: 'sales' } })

    await waitFor(() => expect(screen.queryByTestId('dashboard-metrics')).not.toBeNull())
    expect(screen.queryByTestId('dashboard-metrics-empty-scope')).toBeNull()

    await act(async () => {
      useUi.getState().toggleLocale()
    })

    await waitFor(() => expect(useUi.getState().locale).toBe('en'))
    expect(
      screen.queryByTestId('dashboard-metrics-empty-scope'),
      'a scope with 42 rows was reported empty because the reader changed language',
    ).toBeNull()
  })

  /** The objective itself survives — the filter is a key, not a label. */
  it('keeps the chosen objective across the language change', async () => {
    route()
    renderWithProviders(<DashboardPage />, { locale: 'ar' })

    const select = await screen.findByTestId('dashboard-objective')
    fireEvent.change(select, { target: { value: 'sales' } })
    expect((select as HTMLSelectElement).value).toBe('sales')

    await act(async () => {
      useUi.getState().toggleLocale()
    })

    await waitFor(() => expect(useUi.getState().locale).toBe('en'))
    expect((screen.getByTestId('dashboard-objective') as HTMLSelectElement).value).toBe('sales')
  })
})
