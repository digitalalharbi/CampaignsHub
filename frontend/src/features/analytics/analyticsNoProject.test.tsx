import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * Analytics says WHY it is empty, and says the true reason.
 *
 * Every metric query on this page is `enabled: Boolean(projectId)`, so with no project selected
 * none of them ever fires. The panels then each drew their own empty state — «لا توجد بيانات لهذه
 * الفترة» — across a dozen blank cards, which blames the PERIOD for an absence the period had
 * nothing to do with. A reader's next move is to widen the range; widening it changes nothing,
 * because the request was never made.
 *
 * `CampaignsPage` and `RecommendationsPage` already answer this the honest way.
 */
describe('analytics with no project chosen', () => {
  beforeEach(() => {
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId(null)
    vi.mocked(getData).mockResolvedValue({} as never)
  })

  afterEach(() => {
    signOut()
    vi.clearAllMocks()
  })

  it('asks for a project instead of blaming the period', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })

    expect(await screen.findByText('Select a project')).toBeInTheDocument()
    expect(screen.queryByText(/No data for this period/i)).not.toBeInTheDocument()
  })

  /**
   * The filter bar stays. This page carries its own project selector, so the thing the sentence
   * asks for has to be on screen beside it — replacing the whole page, as `CampaignsPage` does,
   * would hide the control that resolves the state.
   */
  it('keeps the project selector on screen beside the sentence', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en', route: '/app/analytics' })

    await screen.findByText('Select a project')

    expect(screen.getByText('No project')).toBeInTheDocument()
  })
})
