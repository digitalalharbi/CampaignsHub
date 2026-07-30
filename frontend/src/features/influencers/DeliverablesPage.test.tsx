import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { DeliverablesPage } from './DeliverablesPage'
import type { Collaboration, CollaborationsResult, Deliverable } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchCollaborations: vi.fn(), updateDeliverable: vi.fn() }
})

import { fetchCollaborations, updateDeliverable } from './api'

function deliverable(over: Partial<Deliverable> = {}): Deliverable {
  return {
    id: 'd1', type: 'reel', platform: 'instagram', status: 'pending',
    due_on: '2026-07-10', submitted_url: null, published_at: null, is_overdue: false, feedback: null,
    ...over,
  }
}

function collaboration(id: string, deliverables: Deliverable[]): Collaboration {
  return {
    id, title: `Agreement ${id}`, status: 'active', currency: 'SAR', agreed_fee: '1000.00',
    starts_on: null, ends_on: null, brief: null,
    influencer: { id: 'i1', name: 'Layla', handle: 'layla', primary_platform: 'instagram' },
    client: null, deliverables, progress: { total: deliverables.length, published: 0, overdue: 0 },
  }
}

function result(collaborations: Collaboration[], canManage = true): CollaborationsResult {
  return {
    collaborations,
    meta: { total: collaborations.length, page: 1, per_page: 25 },
    can_manage: canManage,
    can_see_costs: false,
  }
}

describe('DeliverablesPage', () => {
  beforeEach(() => vi.clearAllMocks())

  /**
   * The question this page exists for — "what is late anywhere?" — is invisible when work is only
   * ever grouped under its own agreement. Late work must come first regardless of which one it is on.
   */
  it('puts late work first, across agreements', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(result([
      collaboration('c1', [deliverable({ id: 'early', due_on: '2026-07-01', is_overdue: false })]),
      collaboration('c2', [deliverable({ id: 'late', due_on: '2026-07-20', is_overdue: true })]),
    ]))
    renderWithProviders(<DeliverablesPage />, { route: '/influencers/deliverables', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('deliverables')).toBeInTheDocument())
    const ids = within(screen.getByTestId('deliverables')).getAllByRole('listitem')
      .map((li) => li.getAttribute('data-testid'))

    expect(ids).toEqual(['deliverable-late', 'deliverable-early'])
  })

  /** Undated work cannot be late, and must not crowd the top of a list sorted by urgency. */
  it('sorts undated work last rather than treating it as urgent', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(result([
      collaboration('c1', [
        deliverable({ id: 'undated', due_on: null }),
        deliverable({ id: 'dated', due_on: '2026-07-05' }),
      ]),
    ]))
    renderWithProviders(<DeliverablesPage />, { route: '/influencers/deliverables', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('deliverables')).toBeInTheDocument())
    const ids = within(screen.getByTestId('deliverables')).getAllByRole('listitem')
      .map((li) => li.getAttribute('data-testid'))

    expect(ids).toEqual(['deliverable-dated', 'deliverable-undated'])
  })

  it('filters to just the late work', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(result([
      collaboration('c1', [
        deliverable({ id: 'ontime', is_overdue: false }),
        deliverable({ id: 'late', is_overdue: true }),
      ]),
    ]))
    renderWithProviders(<DeliverablesPage />, { route: '/influencers/deliverables', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('filter-overdue')).toBeInTheDocument())
    fireEvent.click(screen.getByTestId('filter-overdue'))

    expect(screen.getByTestId('deliverable-late')).toBeInTheDocument()
    expect(screen.queryByTestId('deliverable-ontime')).not.toBeInTheDocument()
  })

  /** The next step is the next HONEST one; a published post has nowhere further to go. */
  it('offers the next step and stops at published', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(result([
      collaboration('c1', [
        deliverable({ id: 'pending', status: 'pending' }),
        deliverable({ id: 'done', status: 'published', due_on: '2026-07-11' }),
      ]),
    ]))
    vi.mocked(updateDeliverable).mockResolvedValue({ collaboration: collaboration('c1', []) })
    renderWithProviders(<DeliverablesPage />, { route: '/influencers/deliverables', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('deliverable-pending')).toBeInTheDocument())

    expect(within(screen.getByTestId('deliverable-done')).queryByRole('button')).not.toBeInTheDocument()

    fireEvent.click(within(screen.getByTestId('deliverable-pending')).getByRole('button'))
    await waitFor(() => expect(updateDeliverable).toHaveBeenCalledWith('c1', 'pending', { status: 'submitted' }))
  })

  /** A read-only operator gets no controls the server would refuse. */
  it('offers no step controls without the manage permission', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(
      result([collaboration('c1', [deliverable({ id: 'pending', status: 'pending' })])], false),
    )
    renderWithProviders(<DeliverablesPage />, { route: '/influencers/deliverables', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('deliverable-pending')).toBeInTheDocument())
    expect(within(screen.getByTestId('deliverable-pending')).queryByRole('button')).not.toBeInTheDocument()
  })

  it('says nothing is late rather than showing an empty list with no explanation', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(
      result([collaboration('c1', [deliverable({ id: 'ontime', is_overdue: false })])]),
    )
    renderWithProviders(<DeliverablesPage />, { route: '/influencers/deliverables', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('filter-overdue')).toBeInTheDocument())
    fireEvent.click(screen.getByTestId('filter-overdue'))

    expect(screen.getByText(/Nothing is late/)).toBeInTheDocument()
  })
})
