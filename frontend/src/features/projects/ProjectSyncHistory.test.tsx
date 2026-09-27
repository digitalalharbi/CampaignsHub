import { beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { ProjectSyncHistory } from './ProjectSyncHistory'
import type { SyncRun, SyncRunLog } from './api'
import { useUi } from '@/stores/ui'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listSyncRuns: vi.fn() }
})

import { listSyncRuns } from './api'

function run(over: Partial<SyncRun> = {}): SyncRun {
  return {
    id: 'run-1',
    provider: 'meta',
    status: 'success',
    trigger: 'manual',
    account: 'Sandbox Ad Account',
    account_external_id: 'act_1234567890',
    window_start: '2026-09-01',
    window_end: '2026-09-27',
    provider_rows: 480,
    parsed_rows: 480,
    mapped_rows: 12,
    metrics_imported: 336,
    duration_seconds: 4,
    attempts: 1,
    started_at: '2026-09-27T21:00:00+03:00',
    finished_at: '2026-09-27T21:00:04+03:00',
    error: null,
    is_demo: false,
    repeats: 1,
    repeats_since: '2026-09-27T21:00:00+03:00',
    ...over,
  }
}

function log(over: Partial<SyncRunLog> = {}): SyncRunLog {
  return { runs: [run()], summary: { success: 1 }, runs_total: 1, runs_withheld: 0, ...over }
}

function mount(locale: 'ar' | 'en' = 'ar'): QueryClient {
  useUi.setState({ locale })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
  render(<ProjectSyncHistory projectId="p1" />, { wrapper: Wrapper })

  return client
}

/**
 * SYNC-001 — the run log the pipeline keeps, on the page that asks about it.
 *
 * The endpoint has served this since the pipeline was built and nothing read it. These cover the
 * three things that make a log worth having rather than decorative: it refreshes when a sync is
 * queued, it never converts «not measured» into a number, and it says what it is not showing.
 */
describe('ProjectSyncHistory', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listSyncRuns).mockResolvedValue(log())
  })

  /**
   * The query is registered under the key `PlatformIntegrationsPanel` already invalidates.
   *
   * That invalidation existed on its own — a call naming a query no component had registered — so
   * «مزامنة الآن» refreshed nothing. If this key ever drifts, the button goes back to being silent
   * while every other test here still passes, which is exactly why the key itself is asserted.
   */
  it('refetches when a queued sync invalidates its key', async () => {
    const client = mount()
    await screen.findByText('Sandbox Ad Account')
    expect(listSyncRuns).toHaveBeenCalledTimes(1)

    await client.invalidateQueries({ queryKey: ['project', 'p1', 'sync-runs'] })

    await waitFor(() => expect(listSyncRuns).toHaveBeenCalledTimes(2))
  })

  /**
   * A stage that never reported says so, instead of showing a zero.
   *
   * The three stage counts are nullable in the schema for this reason — the migration that added
   * them says a 0 would be a claim. «480 rows arrived and 0 matched» and «we never got as far as
   * matching» send an operator to two different places.
   */
  it('does not turn an unmeasured stage into a zero', async () => {
    vi.mocked(listSyncRuns).mockResolvedValue(log({
      runs: [run({ status: 'failed', provider_rows: null, parsed_rows: null, mapped_rows: null, metrics_imported: 0, error: 'The provider rejected the token.' })],
      summary: { failed: 1 },
    }))
    mount()

    await screen.findByText('فشلت')
    expect(screen.getAllByText('لم يُقس')).toHaveLength(3)
    // The provider's own sentence, not a tidied-up replacement for it.
    expect(screen.getByTestId('sync-run-error-run-1')).toHaveTextContent('The provider rejected the token.')
  })

  /**
   * A capped log that does not say it is capped invites its oldest row to be read as the first run.
   *
   * The number counts ATTEMPTS READ, not rows drawn: identical consecutive runs collapse into one
   * row, so a response holding a hundred attempts can render as four. Measured on a real project —
   * 130 attempts, 4 rows — which is why this fixture sends one run for a hundred read attempts.
   */
  it('states how many attempts it is not showing, counting attempts and not rows', async () => {
    vi.mocked(listSyncRuns).mockResolvedValue(log({ runs: [run({ repeats: 100 })], runs_total: 412, runs_withheld: 312 }))
    mount('en')

    expect(await screen.findByTestId('sync-runs-withheld')).toHaveTextContent('Showing the most recent 100 of 412 attempts.')
  })

  /** §8 — a repeated answer is one row plus the count, and the count must be on the row. */
  it('says how many times a repeated run said the same thing', async () => {
    vi.mocked(listSyncRuns).mockResolvedValue(log({ runs: [run({ status: 'no_data', repeats: 17 })], summary: { no_data: 17 } }))
    mount()

    expect(await screen.findByTestId('sync-run-repeats-run-1')).toHaveTextContent('تكرّر 17 مرة')
    expect(screen.getByText('لا بيانات للفترة')).toBeInTheDocument()
  })

  /** Never-synced is a state the pipeline reports, not an empty panel to be puzzled over. */
  it('says plainly that nothing has run yet', async () => {
    vi.mocked(listSyncRuns).mockResolvedValue(log({ runs: [], summary: {}, runs_total: 0 }))
    mount()

    expect(await screen.findByText('لم تُنفَّذ أي مزامنة بعد')).toBeInTheDocument()
  })

  /** An unknown status shows as itself: a state the product does not know is worth seeing. */
  it('shows a status it does not know rather than guessing at one', async () => {
    vi.mocked(listSyncRuns).mockResolvedValue(log({ runs: [run({ status: 'throttled_by_provider' })], summary: { throttled_by_provider: 1 } }))
    mount()

    expect(await screen.findAllByText('throttled_by_provider')).not.toHaveLength(0)
  })
})
