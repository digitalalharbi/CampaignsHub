import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'

import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

import { CampaignsPage } from './CampaignsPage'
import type { UnifiedCampaign } from './types'

/**
 * ATTENTION-REQUEST-STATE-001 — a request that has not answered is not a finding about the account.
 *
 * `attentionFlags()` raises `no_metrics` — «لا توجد بيانات أداء لهذه الحملة في الفترة المحددة» — from
 * `m === undefined`, and `m` is a lookup into the per-campaign metrics response. That response is
 * undefined for a request still in flight and for one that FAILED, exactly as it is for a campaign the
 * platform genuinely reported nothing for. Three different facts, one rendering.
 *
 * What a reader sees: opening the workspace flashes «تحتاج تدخلًا N» in warning tone, with the banner
 * offering to open the reasons, and then it disappears. On a 403 or a dead metrics endpoint it does not
 * disappear — the page states that every campaign has no performance data, which is a claim about the
 * ACCOUNT assembled out of a request that never answered.
 *
 * The page already owns the honest half and uses it one memo away: `metricsKnown` gates the lifecycle
 * view, with a comment saying a short list «is read as a fact about the account rather than as a request
 * still in flight». The attention engine is the same question and was not asked it.
 *
 * Found by the gate, not by review: `campaigns-overview-first.spec.ts:96` lost webkit four times in two
 * days on this transient — the slowest engine is the one that looks while the count is still wrong.
 */
vi.mock('./api', () => ({ listCampaigns: vi.fn(), createCampaign: vi.fn(), updateCampaign: vi.fn() }))
vi.mock('@/features/projects/api', () => ({ listProjects: vi.fn(), listUsers: vi.fn() }))

const metrics = vi.hoisted(() => ({ value: undefined as unknown }))

vi.mock('@/features/analytics/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/analytics/api')>()
  const empty = { data: undefined, isPending: false, isLoading: false, isError: false }
  return {
    ...actual,
    useSummary: () => empty,
    useTimeseries: () => empty,
    usePlatforms: () => empty,
    useBudget: () => empty,
    useCampaigns: () => metrics.value,
  }
})

import { listCampaigns } from './api'
import { listProjects, listUsers } from '@/features/projects/api'
import { campaignPage } from '@/test/campaignPage'

/** Linked and ordinary — nothing about these campaigns is a finding on its own. */
const campaign = (id: string, name: string): UnifiedCampaign => ({
  id, project_id: 'p1', name, objective: 'sales', status: 'active', total_budget: 1000, budget_currency: 'SAR',
  starts_on: null, ends_on: null, primary_conversion_purpose: null, attribution_model: null,
  attribution_window: null, owner_id: null, target_kpi: null, audience: null, regions: null,
  external_campaigns_count: 2, created_at: null,
})

const attentionCount = () => screen.getByTestId('campaigns-attention').textContent?.match(/\d+/)?.[0]

/*
 * The campaign list, not merely the card.
 *
 * The card paints before either query answers, so asserting on it straight away measures an empty
 * page and passes for the wrong reason — which is what the first draft of this file did. «2 in total»
 * comes from the campaigns response, so it is the anchor that says the rows are in and the only thing
 * still outstanding is the figures.
 */
const campaignsHaveLanded = () => screen.findByText(/2 in total/)

describe('what the workspace says while its metrics request has not answered', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listProjects).mockResolvedValue([])
    vi.mocked(listUsers).mockResolvedValue([])
    vi.mocked(listCampaigns).mockResolvedValue(campaignPage([
      campaign('a', 'Growth — Acquisition'),
      campaign('b', 'Growth — Retention'),
    ]))
    signInWith(['campaigns.view'])
    useProject.getState().setCurrentProjectId('p1')
  })
  afterEach(() => signOut())

  /**
   * In flight. The campaigns have arrived and their figures have not — the window the gate kept
   * photographing, and the one a reader meets on every single page load.
   */
  it('raises no attention verdict while the per-campaign metrics are still in flight', async () => {
    metrics.value = { data: undefined, isPending: true, isLoading: true, isError: false }

    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    await campaignsHaveLanded()

    /* Not «0» either: a zero under «Needs attention» reads as «nothing is wrong», which is the
       other false claim available here. The card says which silence it is looking at instead. */
    expect(screen.getByTestId('campaigns-attention')).toHaveTextContent('—')
    expect(attentionCount()).toBeUndefined()
    expect(screen.getByTestId('campaigns-attention')).toHaveTextContent(/Waiting for the campaign figures/i)
    expect(screen.queryByTestId('campaigns-band-attention')).toHaveAttribute('data-count', '0')
    /* The banner is the loudest form of the claim, so it is asserted by its absence. */
    expect(screen.queryByText(/need attention — open them/i)).toBeNull()
  })

  /**
   * Failed. The same absence, and this one never resolves — so the false verdict is not a flash, it is
   * the page's final answer until somebody reloads.
   */
  it('raises no attention verdict when the per-campaign metrics request failed', async () => {
    metrics.value = { data: undefined, isPending: false, isLoading: false, isError: true }

    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    await campaignsHaveLanded()

    expect(attentionCount()).toBeUndefined()
    /* A refusal is not an empty account, and it is not the same news as «not yet» — so it says so. */
    expect(screen.getByTestId('campaigns-attention')).toHaveTextContent(/could not be read/i)
    expect(screen.queryByText(/No performance data for this campaign/i)).toBeNull()
  })

  /**
   * And the guard is not a silencer: once the figures are in, a campaign the platform reported nothing
   * for is still raised. Without this the fix would read as «attention was switched off».
   */
  it('still raises the verdict once the figures are in', async () => {
    metrics.value = {
      data: [{ campaign_id: 'a', last_active_on: null, reported: {}, spend: 0, impressions: 0, clicks: 0 }],
      isPending: false, isLoading: false, isError: false,
    }

    renderWithProviders(<CampaignsPage />, { locale: 'en' })
    await campaignsHaveLanded()

    /* Both: `b` has no row at all, `a` has a row reporting nothing. Two campaigns, two findings. */
    expect(attentionCount()).toBe('2')
  })
})
