import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CampaignsPage } from './CampaignsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import { listCampaigns } from './api'

/**
 * ANALYTICS-OBJECTIVE-SYSTEM-001 reaching the campaigns list, and scoping the REQUEST.
 *
 * The dashboard and Analytics offer the five canonical objectives; this page offered raw ones, so
 * the same product asked its reader a different question on the screen where they manage the
 * campaigns. «الوعي والتفاعل» could not be asked for here at all — it covers four raw objectives and
 * the endpoint took one.
 *
 * The assertion is on what leaves the browser. A canonical key sent as itself would match no
 * campaign's `objective` column and empty the page; a canonical key that narrowed only the visible
 * rows would be the frontend-only filtering the requirement forbids.
 */
vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  listCampaigns: vi.fn(() => Promise.resolve([])),
}))

vi.mock('@/features/analytics/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/analytics/api')>()
  const empty = { data: undefined, isPending: false, isLoading: false, isError: false }
  return {
    ...actual,
    useSummary: () => empty,
    useTimeseries: () => empty,
    usePlatforms: () => empty,
    useBudget: () => empty,
    useCampaigns: () => empty,
  }
})

describe('the campaigns list narrowed to a canonical objective', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
  })
  afterEach(() => signOut())

  const paramsFor = async (route: string) => {
    renderWithProviders(<CampaignsPage />, { locale: 'en', route })
    await waitFor(() => expect(vi.mocked(listCampaigns)).toHaveBeenCalled())

    return vi.mocked(listCampaigns).mock.calls.at(-1)?.[1]
  }

  it('sends the RAW objectives the bucket covers, never the canonical key', async () => {
    const params = await paramsFor('/campaigns?objective=awareness_engagement')

    expect(params?.objective).toBe('awareness,reach,video_views,engagement')
  })

  it('sends nothing at all when no objective is chosen — absent means every', async () => {
    const params = await paramsFor('/campaigns')

    expect(params?.objective).toBeUndefined()
  })

  it('arrives already narrowed when the link carries the objective', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en', route: '/campaigns?objective=sales' })

    /*
      The REQUEST first — CAMPAIGNS-OVERVIEW-FIRST-001 moved the landing view to the overview, where
      the filter row is not drawn: «filters must support analysis, not dominate the first screen».
      Asserting the control alone would have said nothing about whether the deep link reached the
      server, which is the half that matters — and it is the stronger claim, because a control
      showing «Sales» over an unnarrowed query is exactly the frontend-only filtering this product
      forbids.
    */
    await waitFor(() => {
      const sent = vi.mocked(listCampaigns).mock.calls.at(-1)?.[1] as { objective?: string } | undefined

      /* Comma-joined RAW objectives — the canonical key expanded, never the key itself. */
      expect(sent?.objective).toBe('sales,conversions,add_to_cart,purchases')
    })

    /* And the control carries it, where the control lives. */
    fireEvent.click(screen.getByTestId('view-table'))
    await waitFor(() => expect(screen.getByDisplayValue('Sales')).toBeInTheDocument())
  })
})
