import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { FollowUpWorkspacePage } from './FollowUpWorkspacePage'
import type { FollowUpSummary } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchFollowUpWorkspace: vi.fn(),
}))

import { fetchFollowUpWorkspace } from './api'

/**
 * LEAD-OPERATIONS-001 — the screen the follow-up figures were computed for.
 *
 * `FollowUpWorkspace` shipped, was tested, and was called by nobody: every figure it produced
 * reached only the daily digest, so a manager who wanted the same answer inside the product had to
 * open an email. These tests hold the three things that make the screen worth having rather than
 * being a grid of numbers.
 *
 *   1. What needs a person is separated from what describes the period.
 *   2. A rate with no denominator is ABSENT, never «0%» — a verdict on nothing is still read as a
 *      verdict.
 *   3. «Overdue» is scoped to the whole open pipeline while everything beside it is scoped to the
 *      window, and the screen SAYS so rather than letting a reader assume they agree.
 */
const summary = (over: Partial<FollowUpSummary> = {}): FollowUpSummary => ({
  window: { from: '2026-08-01', to: '2026-08-30' },
  received: 40,
  unassigned: 0,
  contacted: 28,
  not_contacted: 12,
  qualified: 9,
  appointments: 4,
  won: 2,
  lost: 3,
  invalid: 1,
  overdue: 0,
  overdue_scope: 'all_open',
  contact_rate: 0.72,
  qualification_rate: 0.32,
  appointment_rate: 0.44,
  win_rate: 0.22,
  first_response: { median_minutes: 41, measured: 28, of: 40 },
  ...over,
})

describe('the follow-up workspace', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['leads.view'])
  })
  afterEach(() => signOut())

  it('puts what needs a person above the figures that describe the period', async () => {
    vi.mocked(fetchFollowUpWorkspace).mockResolvedValue({
      summary: summary({ unassigned: 5, overdue: 3, not_contacted: 12 }),
      by_owner: null,
    })

    renderWithProviders(<FollowUpWorkspacePage />, { locale: 'en' })

    const attention = await screen.findByTestId('followup-attention')

    expect(attention).toHaveTextContent('5')
    expect(attention).toHaveTextContent('No owner')
    expect(attention).toHaveTextContent('Overdue follow-up')
    expect(attention).toHaveTextContent('Never contacted')
  })

  /** «Nothing needs you» is a result, and a screen that cannot say it reads as broken. */
  it('says so when nothing is overdue and nothing is unowned', async () => {
    vi.mocked(fetchFollowUpWorkspace).mockResolvedValue({
      summary: summary({ unassigned: 0, overdue: 0, not_contacted: 0 }),
      by_owner: null,
    })

    renderWithProviders(<FollowUpWorkspacePage />, { locale: 'en' })

    expect(await screen.findByTestId('followup-attention-clear')).toBeInTheDocument()
    expect(screen.queryByTestId('followup-attention')).not.toBeInTheDocument()
  })

  /**
   * A rate whose denominator was zero is absent, never «0%».
   *
   * Nobody was contacted, so there is no qualification rate to state — and a manager who reads «0%»
   * as a verdict on the team acts on a figure that measures nothing.
   */
  it('leaves a rate nobody could measure blank rather than printing zero', async () => {
    vi.mocked(fetchFollowUpWorkspace).mockResolvedValue({
      summary: summary({ contacted: 0, qualified: 0, qualification_rate: null, win_rate: null }),
      by_owner: null,
    })

    renderWithProviders(<FollowUpWorkspacePage />, { locale: 'en' })

    const rate = await screen.findByTestId('followup-figure-qualification_rate')

    expect(rate).toHaveTextContent('—')
    expect(rate).not.toHaveTextContent('0%')
  })

  /** The scope statement is printed, because the two scopes genuinely differ. */
  it('states that overdue is not scoped to the chosen period', async () => {
    vi.mocked(fetchFollowUpWorkspace).mockResolvedValue({ summary: summary(), by_owner: null })

    renderWithProviders(<FollowUpWorkspacePage />, { locale: 'en' })

    expect(await screen.findByTestId('followup-scope')).toHaveTextContent(
      'Overdue counts the whole open pipeline',
    )
  })

  /**
   * The per-owner table names colleagues, and the unassigned bucket is a row rather than a person.
   *
   * A workspace that lists user ids is one nobody can act on — the same defect the ad-set table had.
   */
  it('names each owner, and calls the unowned bucket what it is', async () => {
    vi.mocked(fetchFollowUpWorkspace).mockResolvedValue({
      summary: summary(),
      by_owner: [
        { ...summary({ received: 20, contacted: 15, overdue: 2 }), owner_id: 7, owner_name: 'نورة' },
        { ...summary({ received: 6, contacted: 0, overdue: 0, contact_rate: null }), owner_id: null, owner_name: null },
      ],
    })

    renderWithProviders(<FollowUpWorkspacePage />, { locale: 'en' })

    const owners = await screen.findByTestId('followup-owners')

    expect(owners).toHaveTextContent('نورة')
    expect(owners).toHaveTextContent('Unassigned')
  })

  /** A reader who does not run the pipeline gets no colleague league table. */
  it('shows no per-owner table when the server withheld one', async () => {
    vi.mocked(fetchFollowUpWorkspace).mockResolvedValue({ summary: summary(), by_owner: null })

    renderWithProviders(<FollowUpWorkspacePage />, { locale: 'en' })

    await screen.findByTestId('followup-figures')
    expect(screen.queryByTestId('followup-owners')).not.toBeInTheDocument()
  })
})
