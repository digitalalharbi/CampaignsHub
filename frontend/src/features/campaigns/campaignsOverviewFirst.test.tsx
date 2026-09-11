import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { CampaignsPage } from './CampaignsPage'
import { campaignPage } from '@/test/campaignPage'
import type { UnifiedCampaign } from './types'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('./api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listCampaigns: vi.fn() }))

import { listCampaigns } from './api'

/**
 * CAMPAIGNS-OVERVIEW-FIRST-001 — «the first thing the user sees must be a strong portfolio overview».
 *
 * It was a grid of tiles. The mode list already began with «نظرة عامة» and the page still landed on
 * CARDS, because the default was hard-coded past it — so a reader arriving to answer «what needs me
 * today» had to find the control that would tell them before they could begin.
 *
 * Asserted on the LANDING rather than on the control: a page whose first tab is Overview and whose
 * first screen is a card grid satisfies any test that only reads the tab bar.
 */
const campaign = (id: string, status = 'active'): UnifiedCampaign => ({
  id, project_id: 'p1', name: `Campaign ${id}`, objective: 'sales', status,
  total_budget: 1000, budget_currency: 'SAR', starts_on: null, ends_on: null,
  primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
  owner_id: null, target_kpi: null, audience: null, regions: null, external_campaigns_count: 0,
  created_at: null,
} as UnifiedCampaign)

describe('the campaigns workspace', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view', 'campaigns.manage'])
    vi.mocked(listCampaigns).mockResolvedValue(campaignPage([campaign('a'), campaign('b', 'paused')]))
  })
  afterEach(() => signOut())

  it('opens on the overview, not on the card grid', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    /* The portfolio's own blocks, which only the overview draws. */
    expect(await screen.findByTestId('campaigns-bands')).toBeInTheDocument()

    /* And the mode button for Overview is the one that reads as chosen. */
    expect(screen.getByTestId('view-overview')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('view-cards')).toHaveAttribute('aria-pressed', 'false')
  })

  /**
   * The owner's IA, in order: «Overview first, then Table, then Cards, then Comparison.»
   *
   * Asserted as a SEQUENCE rather than as a set of present buttons — the complaint was that the
   * hierarchy felt random, and a set says nothing about order.
   */
  it('offers the modes in the order the work is done in', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    await screen.findByTestId('campaigns-bands')

    const ids = [...document.querySelectorAll('[data-testid^="view-"]')].map((b) => b.getAttribute('data-testid'))

    expect(ids).toEqual(['view-overview', 'view-table', 'view-cards', 'view-compare', 'view-attention'])
  })

  /**
   * Every band is shown with its count, including the empty ones.
   *
   * «No campaigns need attention» is the answer somebody came for, and a strip that drops its most
   * important word when the news is good cannot be trusted when it is bad.
   */
  it('classifies the portfolio into the five bands, zeroes included', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    const strip = within(await screen.findByTestId('campaigns-bands'))

    for (const band of ['attention', 'spending', 'weak', 'paused', 'ended']) {
      expect(strip.getByTestId(`campaigns-band-${band}`)).toBeInTheDocument()
    }

    /* Nothing is flagged in this fixture, so the attention band says zero rather than disappearing. */
    expect(screen.getByTestId('campaigns-band-attention')).toHaveAttribute('data-count', '0')
  })

  it('says it in Arabic too', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'ar' })

    const strip = within(await screen.findByTestId('campaigns-bands'))

    expect(strip.getByText('تحتاج تدخلًا')).toBeInTheDocument()
    expect(strip.getByText('نشطة وتنفق')).toBeInTheDocument()
  })

  /**
   * And the movement block is on the OVERVIEW — where the reader is.
   *
   * It was written into the list branch, beside the filters, so the page this unit exists to
   * strengthen never drew it. A full green suite said nothing, because no case rendered the overview
   * and looked for it. This is that case, and it reads the source rather than a mocked payload for
   * one reason: whether the block is INSIDE the overview branch is a property of the composition,
   * true in every data state, and a render test would prove it only for the state it mocked.
   */
  it('draws the movement block inside the overview, not beside the filters', async () => {
    const source = (await import('./CampaignsPage.tsx?raw')).default as string

    const overview = source.indexOf("view === 'overview' ? (")
    const listBranch = source.indexOf('Filters — search + taxonomy chips')
    const movers = source.indexOf('data-testid="campaigns-movers"')

    expect(overview).toBeGreaterThan(-1)
    expect(listBranch).toBeGreaterThan(overview)
    expect(movers, 'the movement block is not rendered at all').toBeGreaterThan(-1)
    expect(movers, 'the movement block sits in the list branch, so the overview never draws it').toBeLessThan(listBranch)
  })

  /**
   * CAMPAIGNS-TABLE-COMPARISON-001 — the header sends the column to the SERVER.
   *
   * The list is server-paginated and server-ranked, so a header that sorted the rows the browser
   * holds would answer «the dearest of the most relevant twenty-five» while looking exactly like an
   * answer about the project. Asserted on the REQUEST, because a page-local sort and a real one look
   * identical on screen — which is the whole reason this can go wrong unnoticed.
   */
  it('sends the chosen column to the server rather than reordering the page', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('view-table'))
    fireEvent.click(await screen.findByTestId('campaigns-sort-spend'))

    await waitFor(() => {
      const sent = vi.mocked(listCampaigns).mock.calls.at(-1)?.[1] as { sort?: string; dir?: string } | undefined

      expect(sent?.sort).toBe('spend')
      /* A figure column opens on «largest first», which is what «show me the biggest» means. */
      expect(sent?.dir).toBe('desc')
    })

    /* Clicking the same header turns the direction round rather than re-sorting the same way. */
    fireEvent.click(screen.getByTestId('campaigns-sort-spend'))

    await waitFor(() => {
      const sent = vi.mocked(listCampaigns).mock.calls.at(-1)?.[1] as { dir?: string } | undefined

      expect(sent?.dir).toBe('asc')
    })
  })

  /** With nothing chosen the list keeps the relevance ranking — «no sort» is not a column. */
  it('sends no sort at all until a header is clicked', async () => {
    renderWithProviders(<CampaignsPage />, { locale: 'en' })

    await screen.findByTestId('campaigns-bands')

    await waitFor(() => expect(vi.mocked(listCampaigns)).toHaveBeenCalled())

    const sent = vi.mocked(listCampaigns).mock.calls.at(-1)?.[1] as { sort?: string } | undefined

    expect(sent?.sort).toBeUndefined()
  })
})
