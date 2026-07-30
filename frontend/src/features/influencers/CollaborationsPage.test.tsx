import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { CollaborationsPage } from './CollaborationsPage'
import type { Collaboration, CollaborationsResult } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchCollaborations: vi.fn() }
})

import { fetchCollaborations } from './api'

function collaboration(over: Partial<Collaboration> = {}): Collaboration {
  return {
    id: 'c1',
    title: 'Ramadan launch',
    status: 'active',
    currency: 'SAR',
    agreed_fee: '25000.00',
    starts_on: null,
    ends_on: null,
    brief: null,
    influencer: { id: 'i1', name: 'Layla', handle: 'layla', primary_platform: 'instagram' },
    client: { id: 'cl1', name: 'Acme' },
    deliverables: [],
    progress: { total: 0, published: 0, overdue: 0 },
    ...over,
  }
}

function result(over: Partial<CollaborationsResult> = {}): CollaborationsResult {
  return {
    collaborations: [collaboration()],
    meta: { total: 1, page: 1, per_page: 25 },
    can_manage: true,
    can_see_costs: false,
    ...over,
  }
}

describe('CollaborationsPage', () => {
  beforeEach(() => vi.clearAllMocks())

  /**
   * The rule this page exists to keep: when the server withholds cost, it withholds it — the page
   * must not put a zero or a dash where the creator's fee would be, because either can be read as
   * the real figure, and one of them can be worked backwards into a margin.
   */
  it('shows nothing at all in place of a withheld creator fee', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(result({ can_see_costs: false }))
    renderWithProviders(<CollaborationsPage />, { route: '/influencers', locale: 'en' })

    await waitFor(() => expect(screen.getByText('Ramadan launch')).toBeInTheDocument())
    expect(screen.getByText('Billed to the client')).toBeInTheDocument()
    expect(screen.queryByText('Paid to the creator')).not.toBeInTheDocument()
    expect(screen.queryByText('Agency margin')).not.toBeInTheDocument()
  })

  it('shows both figures and the margin to someone allowed to see costs', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(
      result({
        can_see_costs: true,
        collaborations: [collaboration({ influencer_fee: '18000.00', margin: '7000.00' })],
      }),
    )
    renderWithProviders(<CollaborationsPage />, { route: '/influencers', locale: 'en' })

    await waitFor(() => expect(screen.getByText('Agency margin')).toBeInTheDocument())
    expect(screen.getByText('18,000 SAR')).toBeInTheDocument()
    expect(screen.getByText('7,000 SAR')).toBeInTheDocument()
  })

  /** A fee that was never agreed is "not set" — distinct from one being withheld. */
  it('distinguishes an unset fee from a withheld one', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(
      result({ can_see_costs: true, collaborations: [collaboration({ agreed_fee: null, influencer_fee: null, margin: null })] }),
    )
    renderWithProviders(<CollaborationsPage />, { route: '/influencers', locale: 'en' })

    await waitFor(() => expect(screen.getByText('Agency margin')).toBeInTheDocument())
    expect(screen.getAllByText('Not set').length).toBe(3)
  })

  /** Progress is per deliverable — a single agreement status cannot say "two of three are live". */
  it('reports progress and overdue work per deliverable', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(
      result({
        collaborations: [collaboration({
          progress: { total: 3, published: 2, overdue: 1 },
          deliverables: [
            { id: 'd1', type: 'reel', platform: 'instagram', status: 'published', due_on: '2026-07-01', submitted_url: 'https://e.test/1', published_at: '2026-07-01T00:00:00Z', is_overdue: false, feedback: null },
            { id: 'd2', type: 'story', platform: 'instagram', status: 'pending', due_on: '2026-07-02', submitted_url: null, published_at: null, is_overdue: true, feedback: null },
          ],
        })],
      }),
    )
    renderWithProviders(<CollaborationsPage />, { route: '/influencers', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('progress-published')).toBeInTheDocument())
    expect(screen.getByTestId('progress-published')).toHaveTextContent('2/3')
    expect(screen.getByTestId('progress-overdue')).toHaveTextContent('1')
  })

  /** No overdue chip when nothing is late — a zero badge reads as a problem that is not there. */
  it('shows no overdue chip when nothing is late', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(
      result({ collaborations: [collaboration({ progress: { total: 2, published: 2, overdue: 0 } })] }),
    )
    renderWithProviders(<CollaborationsPage />, { route: '/influencers', locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('progress-published')).toBeInTheDocument())
    expect(screen.queryByTestId('progress-overdue')).not.toBeInTheDocument()
  })

  it('says the scope is empty rather than showing sample agreements', async () => {
    vi.mocked(fetchCollaborations).mockResolvedValue(result({ collaborations: [], meta: { total: 0, page: 1, per_page: 25 } }))
    renderWithProviders(<CollaborationsPage />, { route: '/influencers', locale: 'en' })

    await waitFor(() =>
      expect(screen.getByText('No collaborations within your scope yet.')).toBeInTheDocument(),
    )
  })
})
