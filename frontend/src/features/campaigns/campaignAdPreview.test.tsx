import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { CampaignCreativesTab } from './CampaignCommandCenter'
import type { UnifiedCampaign } from './types'
import type { Range } from '@/features/analytics/api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * AD-PREVIEW-001 — the ad opens where the reader is looking at it.
 *
 * This tab ranks a campaign's ads and, until now, the name was plain text in the table and
 * unclickable in the grid. «Which ad is this?» — the first question anybody asks of a ranked list of
 * ads — could only be answered by leaving the campaign, finding the library, and filtering back down
 * to it, losing the comparison that prompted the question.
 *
 * The preview itself came from a boolean the endpoint computed for itself: `thumbnail_url !== null
 * || preview_url !== null`. Wrong in both directions — yes for a link the presenter withholds, no
 * for an asset sitting in the row — which is why the payload now carries the canonical block.
 */
const range: Range = { from: '2026-08-01', to: '2026-08-26' }

const campaign: UnifiedCampaign = {
  id: 'c1', project_id: 'p1', name: 'Always-On', objective: 'sales', status: 'active',
  total_budget: 800, budget_currency: 'SAR', starts_on: '2026-08-01', ends_on: '2026-08-31',
  primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
  owner_id: null, target_kpi: null, audience: null, regions: null, external_campaigns_count: 2,
  created_at: null,
}

const ad = (over: Record<string, unknown> = {}) => ({
  id: 'cr-1',
  name: 'Eid film',
  client_display_name: null,
  provider: 'meta',
  format: 'video',
  status: 'active',
  preview: {
    state: 'available', kind: 'video', image_url: null, video_url: null,
    thumbnail_url: 'https://cdn/poster.jpg', expires_at: null, note_ar: null, note_en: null,
  },
  is_demo: false,
  metrics: { spend: 1200, impressions: 90_000, clicks: 700, conversions: 30, revenue: 4000, roas: 3.33, cpa: 40, ctr: 0.008, cpm: 13.3, view_rate: 0.4, completion_rate: 0.2 },
  rank_metric: 'roas',
  rank_value: 3.33,
  classification: 'top',
  ranking_reason: 'Above the campaign average on ROAS.',
  ...over,
})

function route(rows: unknown[]) {
  vi.mocked(getData).mockImplementation(() => Promise.resolve(rows as never))
}

describe('AD-PREVIEW-001 — a campaign’s ads open in place', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('draws the poster the platform sent, and opens the ad without navigating', async () => {
    route([ad()])
    renderWithProviders(<CampaignCreativesTab campaign={campaign} projectId="p1" range={range} locale="en" />, { locale: 'en' })

    const poster = await screen.findByTestId('ad-poster-cr-1')
    expect(poster).toHaveAttribute('src', 'https://cdn/poster.jpg')

    expect(screen.queryByTestId('ad-preview-panel')).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Eid film' }))

    const panel = await screen.findByTestId('ad-preview-panel')
    // The metadata that answers «which ad is this?», beside the still.
    expect(panel).toHaveTextContent('Meta')
    expect(panel).toHaveTextContent('video')
    expect(panel).toHaveTextContent('Above the campaign average')
    expect(screen.getByTestId('ad-preview-panel-poster')).toHaveAttribute('src', 'https://cdn/poster.jpg')
  })

  /**
   * The state that used to be a grey box whatever had happened.
   *
   * A withheld link, an expired one and a platform that exposes nothing are three different facts,
   * and the operator's next action differs for each: nothing, re-sync, nothing.
   */
  it('says why there is no picture instead of showing an empty frame', async () => {
    route([ad({
      preview: {
        state: 'expired', kind: 'image', image_url: null, video_url: null, thumbnail_url: null,
        expires_at: '2026-08-02T00:00:00Z', note_ar: null,
        note_en: 'The platform link has expired — it needs a fresh sync.',
      },
    })])
    renderWithProviders(<CampaignCreativesTab campaign={campaign} projectId="p1" range={range} locale="en" />, { locale: 'en' })

    expect(await screen.findByTestId('ad-poster-cr-1-absent')).toHaveTextContent('has expired')
    expect(screen.queryByTestId('ad-poster-cr-1')).toBeNull()
  })

  /** And the table view opens the same panel — one ad, one place to see it. */
  it('opens the same panel from the table', async () => {
    route([ad()])
    renderWithProviders(<CampaignCreativesTab campaign={campaign} projectId="p1" range={range} locale="en" />, { locale: 'en' })

    fireEvent.click(await screen.findByRole('button', { name: 'table' }))
    fireEvent.click(screen.getByRole('button', { name: 'Eid film' }))

    expect(await screen.findByTestId('ad-preview-panel')).toBeInTheDocument()
  })
})
