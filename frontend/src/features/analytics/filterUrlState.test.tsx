import { describe, expect, it } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router-dom'
import type { ReactNode } from 'react'

import { useUrlState, useUrlList, useUrlNumber } from './filterUrlState'

/**
 * ANALYTICS-FILTER-TRUTH-001 — «deep-link / refresh / Back preserve state».
 *
 * Every filter on the dashboard and on Analytics lived in `useState` and nowhere else. A reader who
 * narrowed to one platform and one objective, then refreshed — or pressed Back, or sent the link to
 * a colleague — got the unfiltered page, with no sign that anything had been dropped. The link they
 * shared showed their colleague a different answer to the question they were discussing.
 *
 * The URL is the only place this state can live and survive all three.
 */
const wrapper = (initial: string) =>
  function Wrapper({ children }: { children: ReactNode }) {
    return <MemoryRouter initialEntries={[initial]}>{children}</MemoryRouter>
  }

describe('a filter that lives in the URL', () => {
  it('starts from the URL rather than from its default', () => {
    const { result } = renderHook(() => useUrlState('objective', 'all'), { wrapper: wrapper('/app?objective=sales') })

    expect(result.current[0]).toBe('sales')
  })

  it('falls back to the default when the URL says nothing', () => {
    const { result } = renderHook(() => useUrlState('objective', 'all'), { wrapper: wrapper('/app') })

    expect(result.current[0]).toBe('all')
  })

  it('writes the choice into the URL so a refresh and a shared link both keep it', () => {
    const { result } = renderHook(
      () => ({ state: useUrlState('objective', 'all'), location: useLocation() }),
      { wrapper: wrapper('/app') },
    )

    act(() => result.current.state[1]('leads'))

    expect(result.current.location.search).toContain('objective=leads')
    expect(result.current.state[0]).toBe('leads')
  })

  /*
   * A filter at its default is ABSENT from the URL, not written as `objective=all`.
   *
   * A query string that spells out every default is unreadable and unshareable, and it makes two
   * links to the same view look different. Clearing a filter has to remove the parameter, or the URL
   * keeps a filter the page no longer applies.
   */
  it('removes the parameter when the filter goes back to its default', () => {
    const { result } = renderHook(
      () => ({ state: useUrlState('objective', 'all'), location: useLocation() }),
      { wrapper: wrapper('/app?objective=leads') },
    )

    act(() => result.current.state[1]('all'))

    expect(result.current.location.search).not.toContain('objective')
  })

  /** One filter changing must not wipe another — they share one query string. */
  it('leaves the other filters in the URL alone', () => {
    const { result } = renderHook(
      () => ({ objective: useUrlState('objective', 'all'), location: useLocation() }),
      { wrapper: wrapper('/app?provider=meta&objective=sales') },
    )

    act(() => result.current.objective[1]('leads'))

    expect(result.current.location.search).toContain('provider=meta')
    expect(result.current.location.search).toContain('objective=leads')
  })
})

describe('a multi-select filter in the URL', () => {
  it('reads a comma-separated list', () => {
    const { result } = renderHook(() => useUrlList('provider'), { wrapper: wrapper('/app?provider=meta,snapchat') })

    expect(result.current[0]).toEqual(['meta', 'snapchat'])
  })

  it('is an empty list — «every platform» — when the URL says nothing', () => {
    const { result } = renderHook(() => useUrlList('provider'), { wrapper: wrapper('/app') })

    expect(result.current[0]).toEqual([])
  })

  /*
   * An EMPTY selection is not a scope. `provider=` would ask the server for no platforms at all on
   * some readings and every platform on others; removing the parameter says «every» unambiguously,
   * which is what an empty multi-select means everywhere else in this product.
   */
  it('drops the parameter entirely when the selection is emptied', () => {
    const { result } = renderHook(
      () => ({ list: useUrlList('provider'), location: useLocation() }),
      { wrapper: wrapper('/app?provider=meta') },
    )

    act(() => result.current.list[1]([]))

    expect(result.current.location.search).not.toContain('provider')
  })

  it('accepts a functional update, the way a chip removing itself sends one', () => {
    const { result } = renderHook(() => useUrlList('provider'), { wrapper: wrapper('/app?provider=meta,tiktok') })

    act(() => result.current[1]((prev) => prev.filter((p) => p !== 'meta')))

    expect(result.current[0]).toEqual(['tiktok'])
  })
})

describe('the period in the URL', () => {
  it('reads a number and keeps the default when the URL is nonsense', () => {
    expect(renderHook(() => useUrlNumber('days', 30), { wrapper: wrapper('/app?days=7') }).result.current[0]).toBe(7)
    expect(renderHook(() => useUrlNumber('days', 30), { wrapper: wrapper('/app?days=abc') }).result.current[0]).toBe(30)
    // A negative or zero period is not a period. Reading it would ask the backend for a window that
    // ends before it starts.
    expect(renderHook(() => useUrlNumber('days', 30), { wrapper: wrapper('/app?days=-5') }).result.current[0]).toBe(30)
  })
})
