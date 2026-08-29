import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'

vi.mock('@/lib/api/client', () => ({ getData: vi.fn() }))
import { getData } from '@/lib/api/client'

import { useCampaignOptionSource } from './useCampaignOptionSource'

/**
 * UX-MULTISELECT-SCALE-002 — the campaign options come from the server, and the term costs a request.
 *
 * The properties that matter are the ones a reader pays for:
 *
 *   1. A burst of keystrokes is ONE request, not one per character. Typing «ramadan» against an
 *      un-debounced source is seven round trips over the whole estate.
 *   2. The term reaches the SERVER as `q`. If it did not, this would be the frontend-only filtering
 *      ANALYTICS-FILTER-TRUTH forbids, dressed up as a server call.
 *   3. A campaign's NAME survives leaving the option list, because the reader's own selection
 *      routinely does.
 */
const wrapper = () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

const page = (options: Array<{ id: string; name: string }>, has_more = false) => ({
  options,
  has_more,
  limit: 120,
})

const urls = () => vi.mocked(getData).mock.calls.map((c) => String(c[0]))

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
  vi.mocked(getData).mockReset()
  vi.mocked(getData).mockResolvedValue(page([{ id: 'a', name: 'Ramadan Retargeting' }]))
})

afterEach(() => {
  vi.useRealTimers()
})

describe('the campaign option source', () => {
  it('asks the option endpoint, not the breakdown', async () => {
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })

    await waitFor(() => expect(result.current.options).toHaveLength(1))
    expect(urls()[0]).toBe('/projects/p1/metrics/campaign-options')
    /* Not the metrics breakdown, and not windowed by a period it must not depend on. */
    expect(urls()[0]).not.toContain('from=')
  })

  it('collapses a burst of keystrokes into a single request', async () => {
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })
    await waitFor(() => expect(urls()).toHaveLength(1))

    for (const term of ['r', 'ra', 'ram', 'rama', 'ramad', 'ramada', 'ramadan']) {
      act(() => result.current.search.onTerm(term))
      act(() => void vi.advanceTimersByTime(40))
    }
    act(() => void vi.advanceTimersByTime(400))

    await waitFor(() => expect(urls()).toHaveLength(2))
    expect(urls()[1]).toBe('/projects/p1/metrics/campaign-options?q=ramadan')
  })

  it('sends the term to the server rather than filtering what it already has', async () => {
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })
    await waitFor(() => expect(urls()).toHaveLength(1))

    act(() => result.current.search.onTerm('  riyadh  '))
    act(() => void vi.advanceTimersByTime(400))

    await waitFor(() => expect(urls()).toHaveLength(2))
    /* Trimmed: a trailing space is not a different search, and would be a different cache key. */
    expect(urls()[1]).toBe('/projects/p1/metrics/campaign-options?q=riyadh')
  })

  it('asks nothing at all without a project', () => {
    renderHook(() => useCampaignOptionSource(null), { wrapper: wrapper() })

    expect(urls()).toHaveLength(0)
  })

  it('reports the server has_more as given, never inferred from a full page', async () => {
    vi.mocked(getData).mockResolvedValue(
      page(Array.from({ length: 120 }, (_, i) => ({ id: `c-${i}`, name: `Campaign ${i}` })), false),
    )
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })

    await waitFor(() => expect(result.current.options).toHaveLength(120))
    /* A full page with has_more false is NOT more. Inferring it from the length would say otherwise. */
    expect(result.current.search.hasMore).toBe(false)
  })

  /**
   * The deep link — the case that made the id resolution necessary.
   *
   * The selection lives in the URL, so a shared link opens with ids and no names, and the option
   * page is the first 120 campaigns by name. A chosen campaign is very often not in it, and without
   * a resolution both the control and the applied-filter chips render the reader's own choice as a
   * bare uuid on exactly the link somebody sent a colleague.
   */
  it('resolves the name of a campaign chosen before the page ever loaded', async () => {
    const chosen = '9f2c1d84-6b3e-4a17-9c55-0f7e2ab41d63'

    vi.mocked(getData).mockImplementation(async (url: string) =>
      url.includes('ids=')
        ? page([{ id: chosen, name: 'Ramadan Retargeting' }])
        : page([{ id: 'other', name: 'Riyadh Season' }]),
    )

    const { result } = renderHook(() => useCampaignOptionSource('p1', [chosen]), { wrapper: wrapper() })

    await waitFor(() => expect(result.current.labelOf(chosen)).toBe('Ramadan Retargeting'))
    /* And it asked by ID, not by guessing from the page it happened to get. */
    expect(urls().some((u) => u.includes(`ids=${chosen}`))).toBe(true)
  })

  /**
   * The resolution costs ONE request per selection, not one per keystroke.
   *
   * It runs beside the search rather than after it, because the name is what the reader is looking
   * at and making them wait a second round trip to stop seeing a uuid would be fixing the defect
   * slowly. What it must not do is re-ask: it is keyed by the ids, so typing — which changes only the
   * search — leaves it alone.
   */
  it('asks for a selection once, and not again on every keystroke', async () => {
    const chosen = '9f2c1d84-6b3e-4a17-9c55-0f7e2ab41d63'
    vi.mocked(getData).mockImplementation(async (url: string) =>
      url.includes('ids=') ? page([{ id: chosen, name: 'Ramadan Retargeting' }]) : page([{ id: 'o', name: 'Other' }]),
    )

    const { result } = renderHook(() => useCampaignOptionSource('p1', [chosen]), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.labelOf(chosen)).toBe('Ramadan Retargeting'))

    const before = urls().filter((u) => u.includes('ids=')).length
    expect(before).toBe(1)

    for (const term of ['r', 'ri', 'riy', 'riya']) {
      act(() => result.current.search.onTerm(term))
      act(() => void vi.advanceTimersByTime(400))
    }
    await waitFor(() => expect(urls().some((u) => u.includes('q=riya'))).toBe(true))

    expect(urls().filter((u) => u.includes('ids=')), 'the resolution was re-asked').toHaveLength(before)
  })

  /**
   * Adding a second campaign asks for the SECOND one, not for both again.
   *
   * The names already known are filtered out before the request is built. Without that the key grows
   * to «a,b» and the round trip re-fetches a name the client is already displaying — small on two
   * campaigns and not small on a report scope with forty.
   */
  it('asks only for the campaigns it does not already have a name for', async () => {
    const a = 'aaaaaaaa-1111-2222-3333-444444444444'
    const b = 'bbbbbbbb-1111-2222-3333-444444444444'

    vi.mocked(getData).mockImplementation(async (url: string) =>
      url.includes('ids=')
        ? page(
            (url.includes(a) ? [{ id: a, name: 'Alpha' }] : []).concat(
              url.includes(b) ? [{ id: b, name: 'Beta' }] : [],
            ),
          )
        : page([{ id: 'o', name: 'Other' }]),
    )

    const { result, rerender } = renderHook(
      ({ sel }: { sel: string[] }) => useCampaignOptionSource('p1', sel),
      { wrapper: wrapper(), initialProps: { sel: [a] } },
    )
    await waitFor(() => expect(result.current.labelOf(a)).toBe('Alpha'))

    rerender({ sel: [a, b] })
    await waitFor(() => expect(result.current.labelOf(b)).toBe('Beta'))

    const asked = urls().filter((u) => u.includes('ids='))
    expect(asked.some((u) => u.includes(b) && !u.includes(a)), `asked: ${asked.join(' | ')}`).toBe(true)
  })

  /**
   * Two hundred campaigns, and the reader has chosen one that is not on the page.
   *
   * This is the cardinality the requirement is about: the client must not download the estate, the
   * page stays at the server's cap, and the selection is still named.
   */
  it('names a selection at two hundred campaigns without downloading them', async () => {
    const chosen = '11111111-2222-3333-4444-555555555555'
    const first120 = Array.from({ length: 120 }, (_, i) => ({ id: `c-${i}`, name: `Campaign ${i}` }))

    vi.mocked(getData).mockImplementation(async (url: string) =>
      url.includes('ids=')
        ? page([{ id: chosen, name: 'Campaign 199' }])
        : page(first120, true),
    )

    const { result } = renderHook(() => useCampaignOptionSource('p1', [chosen]), { wrapper: wrapper() })

    await waitFor(() => expect(result.current.options).toHaveLength(120))
    expect(result.current.search.hasMore).toBe(true)
    await waitFor(() => expect(result.current.labelOf(chosen)).toBe('Campaign 199'))
  })

  it('remembers a campaign name after that campaign leaves the option list', async () => {
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.options).toHaveLength(1))
    expect(result.current.labelOf('a')).toBe('Ramadan Retargeting')

    vi.mocked(getData).mockResolvedValue(page([{ id: 'z', name: 'Riyadh Season' }]))
    act(() => result.current.search.onTerm('riyadh'))
    act(() => void vi.advanceTimersByTime(400))

    await waitFor(() => expect(result.current.options[0]?.value).toBe('z'))
    expect(result.current.labelOf('a')).toBe('Ramadan Retargeting')
  })

  it('falls back to the id for a campaign it has never seen named', async () => {
    const { result } = renderHook(() => useCampaignOptionSource('p1'), { wrapper: wrapper() })
    await waitFor(() => expect(result.current.options).toHaveLength(1))

    expect(result.current.labelOf('never-seen')).toBe('never-seen')
  })
})
