import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { RecommendationsPage } from './RecommendationsPage'
import type { Recommendation } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  listRecommendations: vi.fn(),
  setRecommendationStatus: vi.fn(),
}))

import { listRecommendations, setRecommendationStatus } from './api'

/**
 * RECOMMENDATIONS-ACTION-CENTER-001 — a recommendation you cannot act on is a list, not a centre.
 *
 * The page rendered every recommendation with a status badge and a link to its campaign, and offered
 * no way to approve, reject or hide one. The server has supported all four transitions the whole
 * time — `CampaignAnnotationController::update()` validates the status and gates it on
 * `reports.approve` — and nothing ever called it, so the operator's only route to a decision was to
 * leave the product.
 *
 * The permission is the SERVER's; the buttons follow it rather than deciding it. Hiding a control is
 * not a boundary, and showing one that will be refused is worse than showing none.
 */
const rec = (over: Partial<Recommendation> = {}): Recommendation => ({
  id: 'r1', kind: 'recommendation', status: 'draft', title: 'Raise the Riyadh budget',
  body: null, platform: 'meta', kpi: 'roas', evidence: 'ROAS 3.4 over 14 days',
  priority: 'high', proposed_action: null, assignee_id: null, due_date: null,
  is_demo: false, approved_at: null, created_at: null,
  campaign_id: 'c1', campaign_name: 'Riyadh launch',
  ...over,
})

describe('the recommendations page is an action centre', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    vi.mocked(listRecommendations).mockResolvedValue([rec()])
    vi.mocked(setRecommendationStatus).mockResolvedValue(undefined as never)
  })
  afterEach(() => signOut())

  it('approves a recommendation where the operator holds the permission', async () => {
    signInWith(['campaigns.view', 'reports.approve'])
    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByRole('button', { name: /Approve/i }))

    await waitFor(() => {
      expect(vi.mocked(setRecommendationStatus)).toHaveBeenCalledWith('p1', 'c1', 'r1', 'approved')
    })
  })

  it('offers reject and hide beside it', async () => {
    signInWith(['campaigns.view', 'reports.approve'])
    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    expect(await screen.findByRole('button', { name: /Reject/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Hide/i })).toBeInTheDocument()
  })

  /**
   * Without the permission the server refuses, so the page must not offer the decision.
   *
   * Showing a control that will be refused is worse than showing none: it teaches the reader that
   * the product is broken rather than that the decision is not theirs.
   */
  it('offers no decision to an operator the server would refuse', async () => {
    signInWith(['campaigns.view'])
    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    expect(await screen.findByText('Raise the Riyadh budget')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Approve/i })).toBeNull()
  })

  /* An already-approved recommendation is not offered approval again. */
  it('does not offer a decision that has already been taken', async () => {
    vi.mocked(listRecommendations).mockResolvedValue([rec({ status: 'approved' })])
    signInWith(['campaigns.view', 'reports.approve'])
    renderWithProviders(<RecommendationsPage />, { locale: 'en' })

    expect(await screen.findByText('Raise the Riyadh budget')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Approve/i })).toBeNull()
  })
})
