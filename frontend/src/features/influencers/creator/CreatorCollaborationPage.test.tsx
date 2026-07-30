import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CreatorCollaborationPage } from './CreatorCollaborationPage'
import type { CreatorCollaboration, CreatorDeliverable } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('react-router-dom', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, useParams: () => ({ collaborationId: 'c1' }) }
})

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchMyCollaboration: vi.fn(), respondToTerms: vi.fn(), submitDeliverable: vi.fn() }
})

import { fetchMyCollaboration, respondToTerms, submitDeliverable } from './api'

function deliverable(over: Partial<CreatorDeliverable> = {}): CreatorDeliverable {
  return {
    id: 'd1', type: 'reel', platform: 'instagram', status: 'pending',
    due_on: '2026-08-10', submitted_url: null, submitted_at: null, published_at: null,
    is_overdue: false, feedback: null, can_submit: true,
    ...over,
  }
}

function collaboration(over: Partial<CreatorCollaboration> = {}): CreatorCollaboration {
  return {
    id: 'c1',
    title: 'Ramadan launch',
    status: 'active',
    currency: 'SAR',
    fee: '18000.00',
    starts_on: '2026-08-01',
    ends_on: '2026-08-30',
    brief: 'Three reels, product in frame within the first two seconds.',
    client_name: 'Acme',
    offered_at: '2026-07-01T09:00:00+03:00',
    decision: 'accepted',
    responded_at: '2026-07-02T09:00:00+03:00',
    can_respond: false,
    can_submit: true,
    deliverables: [deliverable()],
    progress: { total: 1, awaiting_me: 1, with_agency: 0, done: 0 },
    ...over,
  }
}

describe('CreatorCollaborationPage', () => {
  beforeEach(() => vi.clearAllMocks())

  /** The number they are agreeing to, stated plainly — and the client's price nowhere on the page. */
  it('states the creator fee and never the client price', async () => {
    vi.mocked(fetchMyCollaboration).mockResolvedValue({ collaboration: collaboration() })

    renderWithProviders(<CreatorCollaborationPage />)

    await waitFor(() => expect(screen.getByText('18,000 SAR')).toBeInTheDocument())
    expect(screen.getByText(/Your fee/i)).toBeInTheDocument()
    expect(screen.queryByText(/25,000/)).not.toBeInTheDocument()
    expect(screen.queryByText(/margin/i)).not.toBeInTheDocument()
  })

  it('shows the brief the agency shared', async () => {
    vi.mocked(fetchMyCollaboration).mockResolvedValue({ collaboration: collaboration() })

    renderWithProviders(<CreatorCollaborationPage />)

    await waitFor(() => expect(screen.getByText(/first two seconds/)).toBeInTheDocument())
  })

  /** An unanswered offer gets both answers, and the page says the answer is final. */
  it('offers accept and decline while the terms are unanswered', async () => {
    vi.mocked(fetchMyCollaboration).mockResolvedValue({
      collaboration: collaboration({ decision: null, can_respond: true, can_submit: false }),
    })

    renderWithProviders(<CreatorCollaborationPage />)

    await waitFor(() => expect(screen.getByTestId('creator-terms')).toBeInTheDocument())
    expect(screen.getByRole('button', { name: /Accept terms/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^Decline$/i })).toBeInTheDocument()
    expect(screen.getByTestId('creator-terms')).toHaveTextContent(/answer once/i)
  })

  it('sends the acceptance', async () => {
    vi.mocked(fetchMyCollaboration).mockResolvedValue({
      collaboration: collaboration({ decision: null, can_respond: true, can_submit: false }),
    })
    vi.mocked(respondToTerms).mockResolvedValue({ collaboration: collaboration() })

    renderWithProviders(<CreatorCollaborationPage />)

    await waitFor(() => expect(screen.getByRole('button', { name: /Accept terms/i })).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: /Accept terms/i }))

    await waitFor(() => expect(respondToTerms).toHaveBeenCalledWith('c1', { decision: 'accepted' }))
  })

  /** Declining asks why before it commits — the reason is what makes the answer useful to the agency. */
  it('asks for a reason before committing a decline', async () => {
    vi.mocked(fetchMyCollaboration).mockResolvedValue({
      collaboration: collaboration({ decision: null, can_respond: true, can_submit: false }),
    })
    vi.mocked(respondToTerms).mockResolvedValue({ collaboration: collaboration() })

    renderWithProviders(<CreatorCollaborationPage />)

    await waitFor(() => expect(screen.getByRole('button', { name: /^Decline$/i })).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: /^Decline$/i }))

    expect(respondToTerms).not.toHaveBeenCalled()
    fireEvent.change(screen.getByLabelText(/Reason/i), { target: { value: 'Exclusivity clash' } })
    fireEvent.click(screen.getByRole('button', { name: /Confirm decline/i }))

    await waitFor(() =>
      expect(respondToTerms).toHaveBeenCalledWith('c1', { decision: 'declined', reason: 'Exclusivity clash' }))
  })

  /** Once answered, the answer is stated and neither button is offered again. */
  it('does not offer a second answer once the terms are answered', async () => {
    vi.mocked(fetchMyCollaboration).mockResolvedValue({ collaboration: collaboration() })

    renderWithProviders(<CreatorCollaborationPage />)

    await waitFor(() => expect(screen.getByTestId('creator-terms-answered')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: /Accept terms/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Decline$/i })).not.toBeInTheDocument()
  })

  it('submits a deliverable url', async () => {
    vi.mocked(fetchMyCollaboration).mockResolvedValue({ collaboration: collaboration() })
    vi.mocked(submitDeliverable).mockResolvedValue({ collaboration: collaboration() })

    renderWithProviders(<CreatorCollaborationPage />)

    await waitFor(() => expect(screen.getByRole('button', { name: /Submit content/i })).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: /Submit content/i }))
    fireEvent.change(screen.getByLabelText(/Content link/i), { target: { value: 'https://instagram.com/p/abc' } })
    fireEvent.click(screen.getByRole('button', { name: /Submit for review/i }))

    await waitFor(() =>
      expect(submitDeliverable).toHaveBeenCalledWith('c1', 'd1', { submitted_url: 'https://instagram.com/p/abc' }))
  })

  /**
   * The separation of powers, from the creator's side: nothing on this page approves or publishes.
   * Those are the agency's acts, and a creator who could reach them would sign off their own work.
   */
  it('offers no control that approves or publishes the work', async () => {
    vi.mocked(fetchMyCollaboration).mockResolvedValue({
      collaboration: collaboration({ deliverables: [deliverable({ status: 'submitted', can_submit: false })] }),
    })

    renderWithProviders(<CreatorCollaborationPage />)

    await waitFor(() => expect(screen.getByTestId('creator-deliverable')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: /approve/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /publish/i })).not.toBeInTheDocument()
    // While the agency holds it, the creator has nothing to press at all.
    expect(screen.queryByRole('button', { name: /Submit/i })).not.toBeInTheDocument()
  })

  /** A rejection's feedback is what makes it actionable, so it is shown with the piece. */
  it('shows the feedback on a rejected deliverable', async () => {
    vi.mocked(fetchMyCollaboration).mockResolvedValue({
      collaboration: collaboration({
        deliverables: [deliverable({ status: 'rejected', feedback: 'The logo is cropped.', can_submit: true })],
      }),
    })

    renderWithProviders(<CreatorCollaborationPage />)

    await waitFor(() => expect(screen.getByTestId('creator-feedback')).toHaveTextContent('The logo is cropped.'))
    expect(screen.getByRole('button', { name: /Submit a new version/i })).toBeInTheDocument()
  })

  /**
   * A 404 means "not yours, or never offered to you". It is a settled answer, so the page states it
   * instead of retrying — and it must not leave the creator on a screen that is neither loading nor
   * failed while a retry runs.
   */
  it('states plainly that unreachable work is not available, without retrying', async () => {
    vi.mocked(fetchMyCollaboration).mockRejectedValue(Object.assign(new Error('nope'), { status: 404 }))

    renderWithProviders(<CreatorCollaborationPage />)

    await waitFor(() => expect(screen.getByText(/not available to you/i)).toBeInTheDocument())
    expect(fetchMyCollaboration).toHaveBeenCalledTimes(1)
  })
})
