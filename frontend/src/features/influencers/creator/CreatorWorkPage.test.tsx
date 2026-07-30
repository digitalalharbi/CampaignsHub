import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { CreatorWorkPage } from './CreatorWorkPage'
import type { CreatorCollaboration, CreatorProfile } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchCreatorProfile: vi.fn(), fetchMyCollaborations: vi.fn() }
})

import { fetchCreatorProfile, fetchMyCollaborations } from './api'

function collaboration(over: Partial<CreatorCollaboration> = {}): CreatorCollaboration {
  return {
    id: 'c1',
    title: 'Ramadan launch',
    status: 'active',
    currency: 'SAR',
    fee: '18000.00',
    starts_on: null,
    ends_on: null,
    brief: null,
    client_name: 'Acme',
    offered_at: '2026-07-01T09:00:00+03:00',
    decision: 'accepted',
    responded_at: '2026-07-02T09:00:00+03:00',
    can_respond: false,
    can_submit: true,
    deliverables: [],
    progress: { total: 3, awaiting_me: 1, with_agency: 1, done: 1 },
    ...over,
  }
}

const profile: CreatorProfile = {
  creator: {
    id: 'i1', name: 'Layla', handle: 'layla', primary_platform: 'instagram',
    profile_url: null, followers: 120000, engagement_rate: '4.25',
  },
  summary: { offers_awaiting_response: 0, active: 1, deliverables_awaiting_me: 1 },
}

describe('CreatorWorkPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(fetchCreatorProfile).mockResolvedValue(profile)
  })

  /**
   * The creator's page shows what THEY are paid. The client's price is not merely hidden here — it
   * is never sent to this surface, so a number that could reveal the agency's markup has nowhere to
   * come from. This asserts the figure that IS shown is theirs.
   */
  it('shows the creator their own fee', async () => {
    vi.mocked(fetchMyCollaborations).mockResolvedValue({ collaborations: [collaboration()] })

    renderWithProviders(<CreatorWorkPage />)

    await waitFor(() => expect(screen.getByText(/18,000 SAR/)).toBeInTheDocument())
    expect(screen.queryByText(/25,000/)).not.toBeInTheDocument()
  })

  /** An unanswered offer is the one thing only this person can unblock, so it leads the page. */
  it('separates offers awaiting an answer from active work', async () => {
    vi.mocked(fetchMyCollaborations).mockResolvedValue({
      collaborations: [
        collaboration({ id: 'offer', title: 'New offer', decision: null, can_respond: true, can_submit: false }),
        collaboration({ id: 'live', title: 'Running work' }),
      ],
    })

    renderWithProviders(<CreatorWorkPage />)

    await waitFor(() => expect(screen.getByTestId('creator-offer')).toBeInTheDocument())
    expect(screen.getByTestId('creator-offer')).toHaveTextContent('New offer')
    expect(screen.getByTestId('creator-collaboration')).toHaveTextContent('Running work')
  })

  /**
   * A creator with an offer but nothing running is told what to do next, not shown a blank panel
   * that reads as "the agency has forgotten about you".
   */
  it('points an unanswered creator at the offer instead of showing an empty page', async () => {
    vi.mocked(fetchMyCollaborations).mockResolvedValue({
      collaborations: [collaboration({ decision: null, can_respond: true, can_submit: false })],
    })

    renderWithProviders(<CreatorWorkPage />)

    await waitFor(() => expect(screen.getByTestId('creator-no-active-work')).toBeInTheDocument())
    expect(screen.getByTestId('creator-no-active-work')).toHaveTextContent(/Accept an offer/i)
  })

  /** How much is on this creator's plate, stated as a count rather than left to be inferred. */
  it('says how many deliverables are waiting on the creator', async () => {
    vi.mocked(fetchMyCollaborations).mockResolvedValue({
      collaborations: [collaboration({ progress: { total: 3, awaiting_me: 2, with_agency: 0, done: 1 } })],
    })

    renderWithProviders(<CreatorWorkPage />)

    await waitFor(() => expect(screen.getByTestId('creator-collaboration')).toHaveTextContent(/2 awaiting you/i))
  })

  /** …and when nothing is, it says so rather than leaving a bare number to be read as a problem. */
  it('says plainly when nothing is needed from the creator', async () => {
    vi.mocked(fetchMyCollaborations).mockResolvedValue({
      collaborations: [collaboration({ progress: { total: 3, awaiting_me: 0, with_agency: 1, done: 2 } })],
    })

    renderWithProviders(<CreatorWorkPage />)

    await waitFor(() =>
      expect(screen.getByTestId('creator-collaboration')).toHaveTextContent(/Nothing needed from you/i))
  })

  it('offers a retry rather than a blank page when the request fails', async () => {
    vi.mocked(fetchMyCollaborations).mockRejectedValue(Object.assign(new Error('boom'), { status: 500 }))

    renderWithProviders(<CreatorWorkPage />)

    await waitFor(() => expect(screen.getByText(/could not be loaded/i)).toBeInTheDocument(), { timeout: 4000 })
  })
})
