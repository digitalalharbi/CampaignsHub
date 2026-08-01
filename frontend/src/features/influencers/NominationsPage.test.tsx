import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { NominationsPage } from './NominationsPage'
import type { Nomination } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    fetchNominations: vi.fn(), fetchRoster: vi.fn(),
    decideNomination: vi.fn(), proposeNomination: vi.fn(),
    withdrawNomination: vi.fn(), convertNomination: vi.fn(),
  }
})

import { decideNomination, fetchNominations, fetchRoster } from './api'

const proposed: Nomination = {
  id: 'n1', status: 'proposed', campaign_id: null, client_workspace_id: null,
  proposed_fee: '5000.00', currency: 'SAR', rationale: 'Her audience is the one this campaign is for.',
  proposed_at: '2026-08-01T00:00:00Z', decided_at: null, decision_note: null,
  is_convertible: false, collaboration_id: null,
  influencer: { id: 'i1', name: 'Layla', handle: '@layla', primary_platform: 'instagram', followers: 120000, tier: 'mid' },
}

const rejected: Nomination = {
  ...proposed, id: 'n2', status: 'rejected',
  decided_at: '2026-08-02T00:00:00Z',
  decision_note: 'Her audience skews outside the target market.',
}

/**
 * The shortlist and its answers (INFL-003).
 *
 * What these hold in place is the point of the page rather than its layout: a rejection is VISIBLE
 * and carries its reason, deciding is offered only to somebody allowed to decide, and «create the
 * collaboration» appears only where there is really something to create.
 */
describe('NominationsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchNominations).mockResolvedValue([proposed])
    vi.mocked(fetchRoster).mockResolvedValue({ influencers: [], total: 0 } as never)
  })
  afterEach(() => signOut())

  it('refuses the page outright without influencers.view', () => {
    signInWith([])
    renderWithProviders(<NominationsPage />, { locale: 'en' })
    expect(screen.getByText(/do not have permission/i)).toBeInTheDocument()
  })

  /**
   * Proposing does not carry the right to decide.
   *
   * The server enforces it; the interface must not offer it either, or the refusal arrives as an
   * error on a button that looked available.
   */
  it('offers no decision to somebody who may only propose', async () => {
    signInWith(['influencers.view', 'influencers.manage'])
    renderWithProviders(<NominationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('nomination-card')).toBeInTheDocument()
    expect(screen.queryByTestId('approve-nomination')).not.toBeInTheDocument()
    expect(screen.queryByTestId('reject-nomination')).not.toBeInTheDocument()
    // …but withdrawing their own proposal is still theirs to do.
    expect(screen.getByRole('button', { name: /Withdraw/i })).toBeInTheDocument()
  })

  it('offers the decision to somebody who holds influencers.approve', async () => {
    signInWith(['influencers.view', 'influencers.manage', 'influencers.approve'])
    renderWithProviders(<NominationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('approve-nomination')).toBeInTheDocument()
    expect(screen.getByTestId('reject-nomination')).toBeInTheDocument()
  })

  /** A rejection cannot be sent empty — the reason is the whole value of recording it. */
  it('will not send a rejection until a reason is written', async () => {
    signInWith(['influencers.view', 'influencers.manage', 'influencers.approve'])
    renderWithProviders(<NominationsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('reject-nomination'))
    expect(screen.getByTestId('confirm-reject')).toBeDisabled()

    fireEvent.change(screen.getByTestId('reject-note'), { target: { value: 'Audience is off-market.' } })
    expect(screen.getByTestId('confirm-reject')).not.toBeDisabled()

    fireEvent.click(screen.getByTestId('confirm-reject'))
    // `mutate` schedules the call rather than making it inline.
    await waitFor(() => expect(decideNomination).toHaveBeenCalledWith('n1', 'rejected', 'Audience is off-market.'))
  })

  /**
   * A «no» stays on the page WITH its reason.
   *
   * Filtering rejections away is exactly how the same creator gets proposed again next quarter by
   * somebody who was not in the conversation.
   */
  it('shows a rejected nomination and the reason it was rejected', async () => {
    vi.mocked(fetchNominations).mockResolvedValue([rejected])
    signInWith(['influencers.view', 'influencers.manage', 'influencers.approve'])
    renderWithProviders(<NominationsPage />, { locale: 'en' })

    const card = await screen.findByTestId('nomination-card')
    expect(card).toHaveAttribute('data-status', 'rejected')
    expect(screen.getByTestId('decision-note')).toHaveTextContent('Her audience skews outside the target market.')
  })

  /** «Create the collaboration» appears only where the server would actually allow it. */
  it('offers to create the work only for an approved nomination that is not already work', async () => {
    vi.mocked(fetchNominations).mockResolvedValue([{ ...proposed, status: 'approved', is_convertible: false, collaboration_id: 'c1' }])
    signInWith(['influencers.view', 'influencers.manage', 'influencers.approve'])
    const { unmount } = renderWithProviders(<NominationsPage />, { locale: 'en' })

    expect(await screen.findByText(/Became a collaboration/i)).toBeInTheDocument()
    expect(screen.queryByTestId('convert-nomination')).not.toBeInTheDocument()
    unmount()

    vi.mocked(fetchNominations).mockResolvedValue([{ ...proposed, status: 'approved', is_convertible: true }])
    renderWithProviders(<NominationsPage />, { locale: 'en' })

    expect(await screen.findByTestId('convert-nomination')).toBeInTheDocument()
    // …and not until it has a title, because the server requires one.
    expect(screen.getByTestId('convert-nomination')).toBeDisabled()
  })
})
