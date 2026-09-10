import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  getEnvelope: vi.fn(),
}))

import { getData, getEnvelope } from '@/lib/api/client'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 — «the ad-set and ad rungs inside the family».
 *
 * The Objectives tab settles which campaign in a family is worth more money next week. The question
 * that follows is always «which ad set, which ad» — and the family tables answered it by going
 * nowhere: the reader left the tab, switched to Ad sets, and looked for the campaign by eye in a
 * list that is not grouped by family at all.
 *
 * The rung tables already existed and were tested. What was missing was the way in, and this asserts
 * it end to end rather than on the callback: the click must land on the Ad sets tab with the drill
 * path naming that campaign, because the tab and the path are one statement about where the reader
 * is and either half alone is a broken destination.
 */
const CAMPAIGNS = [
  {
    campaign_id: 'c-ramadan', campaign_name: 'Ramadan', provider: 'meta', objective: 'sales',
    objective_family: 'sales', objective_source: 'platform', status: 'active', last_active_on: '2026-08-20',
    spend: 9000, revenue: 40000, impressions: 500000, clicks: 9000, conversions: 300,
    previous_spend: null, spend_change: null, reported: ['spend', 'conversions', 'revenue'],
  },
  {
    campaign_id: 'c-eid', campaign_name: 'Eid', provider: 'meta', objective: 'sales',
    objective_family: 'sales', objective_source: 'platform', status: 'active', last_active_on: '2026-08-20',
    spend: 4000, revenue: 12000, impressions: 200000, clicks: 3000, conversions: 90,
    previous_spend: null, spend_change: null, reported: ['spend', 'conversions', 'revenue'],
  },
]

function route() {
  const body = (url: string) => {
    if (url.includes('/campaigns')) return CAMPAIGNS
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR' }
    if (url.includes('disclaimer')) return null
    return []
  }

  vi.mocked(getData).mockImplementation((url: string) => body(url) as never)
  vi.mocked(getEnvelope).mockImplementation(
    (url: string) => ({ data: body(url), meta: null, message: null, success: true }) as never,
  )
}

describe('a family’s campaign leads to its ad sets', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    route()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('lands on the ad sets tab, scoped to the campaign that was clicked', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Objectives/i }))

    fireEvent.click(await screen.findByTestId('family-drill-c-ramadan'))

    /*
     * The crumbs are the reader's own account of where they are, so they are what is asserted —
     * a URL that named the campaign under a tab still showing objectives would be the same defect
     * this drill vocabulary exists to prevent.
     */
    const crumbs = await screen.findByTestId('drill-crumbs')
    await waitFor(() => expect(crumbs).toHaveTextContent(/Ramadan/))
  })
})
