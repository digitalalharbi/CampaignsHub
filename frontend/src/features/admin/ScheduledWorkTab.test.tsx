import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'

import { ScheduledWorkTab } from './PlatformOpsTabs'
import type { ScheduledWork, ScheduledWorkRow } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, fetchScheduledWork: vi.fn() }
})

import { fetchScheduledWork } from './api'

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — the observability half.
 *
 * The run ledger has recorded what the schedulers did since it shipped, and no screen read it. So
 * the automation was observable in the sense that the data existed and unobservable in the sense
 * that nobody could look, which is the half of this requirement that was outstanding.
 *
 * The states are three, not two, and the distinction is the whole point:
 *
 *   - **failed** — it ran and broke. Read the failure.
 *   - **failing repeatedly** — broken every run since the last success. Nobody has looked.
 *   - **never observed** — no run has ever been recorded. Not «fine» and not «broken»: «we cannot
 *     see». A scheduler that never fired reads as green under any surface that collapses this.
 */
const row = (over: Partial<ScheduledWorkRow> = {}): ScheduledWorkRow => ({
  command: 'integrations:sync',
  expression: '*/30 * * * *',
  state: 'observed',
  last_outcome: 'completed',
  last_started_at: new Date(Date.now() - 5 * 60_000).toISOString(),
  last_duration_ms: 4200,
  failure_class: null,
  failure_message: null,
  overdue: false,
  consecutive_failures: 0,
  ...over,
})

const payload = (rows: ScheduledWorkRow[]): ScheduledWork => ({
  scheduled: rows,
  summary: {
    total: rows.length,
    failing: rows.filter((r) => r.last_outcome === 'failed').length,
    overdue: rows.filter((r) => r.overdue === true).length,
    never_observed: rows.filter((r) => r.state === 'never_observed').length,
    failing_repeatedly: rows.filter((r) => r.consecutive_failures > 1).length,
  },
})

describe('the scheduled work surface', () => {
  beforeEach(() => vi.clearAllMocks())

  it('names every scheduled command with its expression and last run', async () => {
    vi.mocked(fetchScheduledWork).mockResolvedValue(payload([row()]))
    renderWithProviders(<ScheduledWorkTab />, { locale: 'en' })

    const item = await screen.findByTestId('scheduled-integrations:sync')
    expect(item).toHaveTextContent('integrations:sync')
    expect(item).toHaveTextContent('*/30 * * * *')
    expect(within(item).getByTestId('scheduled-state-integrations:sync')).toHaveTextContent('ok')
  })

  it('separates never-observed from ok, because it is not the same answer', async () => {
    vi.mocked(fetchScheduledWork).mockResolvedValue(
      payload([row({ command: 'fx:rates', state: 'never_observed', last_outcome: null, last_started_at: null, last_duration_ms: null, overdue: null })]),
    )
    renderWithProviders(<ScheduledWorkTab />, { locale: 'en' })

    expect(await screen.findByTestId('scheduled-state-fx:rates')).toHaveTextContent('never observed')
    expect(screen.getByTestId('scheduled-count-never_observed')).toHaveTextContent('1')
  })

  /* One failure is a transient the next run may clear; four in a row is broken and unattended. */
  it('separates failing once from failing every run', async () => {
    vi.mocked(fetchScheduledWork).mockResolvedValue(
      payload([
        row({ command: 'alerts:evaluate', last_outcome: 'failed', consecutive_failures: 1, failure_message: 'timeout' }),
        row({ command: 'commerce:sync', last_outcome: 'failed', consecutive_failures: 4, failure_message: 'refused' }),
      ]),
    )
    renderWithProviders(<ScheduledWorkTab />, { locale: 'en' })

    expect(await screen.findByTestId('scheduled-state-alerts:evaluate')).toHaveTextContent('failed')
    expect(screen.getByTestId('scheduled-state-commerce:sync')).toHaveTextContent('failing 4 runs')
    expect(screen.getByTestId('scheduled-count-failing_repeatedly')).toHaveTextContent('1')
  })

  /**
   * The one failing every night is read first.
   *
   * A list in schedule order buries the broken command among fifteen healthy ones, and the operator
   * who most needs to see it is the one least likely to scroll.
   */
  it('puts what needs attention above what is fine', async () => {
    vi.mocked(fetchScheduledWork).mockResolvedValue(
      payload([
        row({ command: 'a:healthy' }),
        row({ command: 'z:broken', last_outcome: 'failed', consecutive_failures: 3, failure_message: 'refused' }),
      ]),
    )
    renderWithProviders(<ScheduledWorkTab />, { locale: 'en' })

    await screen.findByTestId('scheduled-z:broken')
    const list = screen.getByTestId('scheduled-z:broken').parentElement
    const text = list?.textContent ?? ''

    expect(text.indexOf('z:broken')).toBeLessThan(text.indexOf('a:healthy'))
  })

  /*
   * The failure MESSAGE, not the class alone. «sync_failed» says which bucket it is in and nothing
   * about what to do; the message is the part that ends the guessing.
   */
  it('shows what actually went wrong', async () => {
    vi.mocked(fetchScheduledWork).mockResolvedValue(
      payload([row({ command: 'commerce:sync', last_outcome: 'failed', failure_class: 'provider_refused', failure_message: 'Salla returned 401 for store 42' })]),
    )
    renderWithProviders(<ScheduledWorkTab />, { locale: 'en' })

    const note = await screen.findByTestId('scheduled-failure-commerce:sync')
    expect(note).toHaveTextContent('provider_refused')
    expect(note).toHaveTextContent('Salla returned 401 for store 42')
  })

  /** No «run now». The requirement is about work that runs on schedulers, not on buttons. */
  it('offers no way to trigger a run by hand', async () => {
    vi.mocked(fetchScheduledWork).mockResolvedValue(payload([row()]))
    renderWithProviders(<ScheduledWorkTab />, { locale: 'en' })

    await screen.findByTestId('scheduled-integrations:sync')
    expect(screen.queryByRole('button', { name: /run/i })).not.toBeInTheDocument()
  })

  it('speaks Arabic', async () => {
    vi.mocked(fetchScheduledWork).mockResolvedValue(
      payload([row({ command: 'fx:rates', state: 'never_observed', last_outcome: null, last_started_at: null, last_duration_ms: null, overdue: null })]),
    )
    renderWithProviders(<ScheduledWorkTab />, { locale: 'ar' })

    expect(await screen.findByTestId('scheduled-state-fx:rates')).toHaveTextContent('لم تُرصد قط')
  })
})
