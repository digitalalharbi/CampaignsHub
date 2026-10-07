import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { TasksPage } from './TasksPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listTasks: vi.fn(), createTask: vi.fn(), updateTask: vi.fn() }))

import { listTasks } from './api'

/**
 * VIZ-OPS-001 — the task ledger's shape, and the one band it must NOT draw.
 *
 * Four cards answer «how many» four times. «How much of the ledger is still open» is a fifth question
 * none of them answers and the one somebody opens this page to ask.
 *
 * ## `overdue` is not a band, and drawing it as one would be wrong arithmetic
 *
 * The server counts `open` over the open statuses, `done` over `completed`, and `overdue` as «a due
 * date in the past that nobody has finished» — which is a SUBSET of open, not a sibling of it. Putting
 * the three in one bar would count every overdue task twice, push the bands past the total, and —
 * because `StatusMixBar` refuses a composition whose parts exceed their whole — show nothing at all.
 *
 * So the bar divides open · done · everything else, and overdue is drawn as an emphasis INSIDE open,
 * where it actually lives.
 *
 * ## And «everything else» is a real band
 *
 * `cancelled` is neither open nor done, so open + done is less than the ledger. Summing the two and
 * calling it the total would make both shares too large; the remainder is named instead.
 */
const page = (counts: { open: number; done: number; overdue: number }, total: number) => ({
  tasks: [], total, page: 1, lastPage: 1, counts,
})

describe('the task ledger, drawn', () => {
  beforeEach(() => vi.clearAllMocks())

  it('divides the ledger into open, done and whatever is neither', async () => {
    vi.mocked(listTasks).mockResolvedValue(page({ open: 6, done: 3, overdue: 2 }, 10) as never)
    renderWithProviders(<TasksPage />, { locale: 'en' })

    expect(await screen.findByTestId('task-mix-segment-open')).toHaveStyle({ width: '60%' })
    expect(screen.getByTestId('task-mix-segment-done')).toHaveStyle({ width: '30%' })
    // One task is cancelled: neither open nor done, and not silently absorbed into either.
    expect(screen.getByTestId('task-mix-segment-residual')).toHaveStyle({ width: '10%' })
  })

  it('never draws overdue as a band beside open, because it is inside it', async () => {
    vi.mocked(listTasks).mockResolvedValue(page({ open: 6, done: 3, overdue: 2 }, 10) as never)
    renderWithProviders(<TasksPage />, { locale: 'en' })

    await screen.findByTestId('task-mix-bar')
    expect(screen.queryByTestId('task-mix-segment-overdue')).toBeNull()
  })

  it('says how many of the open ones are late, where a reader is looking at open', async () => {
    vi.mocked(listTasks).mockResolvedValue(page({ open: 6, done: 3, overdue: 2 }, 10) as never)
    renderWithProviders(<TasksPage />, { locale: 'en' })

    expect(await screen.findByTestId('task-overdue-note')).toHaveTextContent('2')
  })

  it('says nothing about lateness when nothing is late', async () => {
    vi.mocked(listTasks).mockResolvedValue(page({ open: 6, done: 4, overdue: 0 }, 10) as never)
    renderWithProviders(<TasksPage />, { locale: 'en' })

    await screen.findByTestId('task-mix-bar')
    expect(screen.queryByTestId('task-overdue-note')).toBeNull()
  })

  it('draws nothing at all for an empty ledger rather than an empty bar', async () => {
    vi.mocked(listTasks).mockResolvedValue(page({ open: 0, done: 0, overdue: 0 }, 0) as never)
    renderWithProviders(<TasksPage />, { locale: 'en' })

    expect(screen.queryByTestId('task-mix-bar')).toBeNull()
  })
})
