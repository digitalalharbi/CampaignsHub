import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { CampaignStructureTab } from './CampaignStructureTab'
import type { UnifiedCampaign } from './types'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  postData: vi.fn(),
}))

import { getData, postData } from '@/lib/api/client'

const campaign = { id: 'camp-1', name: 'Ramadan' } as UnifiedCampaign

function payload(over: Record<string, unknown> = {}) {
  return {
    linked_platform_campaigns: [{ id: 'e1', provider: 'meta', external_id: '120', name: 'Ramadan' }],
    ad_sets: [],
    ads_without_ad_set: [],
    awaiting_credentials: [],
    state: 'ready',
    ...over,
  }
}

function render() {
  return renderWithProviders(<CampaignStructureTab campaign={campaign} projectId="p1" />, { locale: 'ar' })
}

describe('CampaignStructureTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['campaigns.view', 'integrations.view'])
  })
  afterEach(() => signOut())

  /**
   * The four empty states are four different instructions, and the one that matters most is the
   * credentials one — it used to look identical to «never synced» and sent the reader to press a
   * discovery button that could not possibly have worked.
   */
  it('offers no discovery button when the platform holds no credentials, and says where the setup lives', async () => {
    vi.mocked(getData).mockResolvedValue(payload({ state: 'awaiting_credentials', awaiting_credentials: ['meta'] }))

    render()

    expect(await screen.findByText(/غير مهيّأة على هذا النظام/)).toBeInTheDocument()
    expect(screen.getByText(/ميتا/)).toBeInTheDocument()
    expect(screen.queryByTestId('discover-structure')).not.toBeInTheDocument()
  })

  it('offers the discovery button when the structure has simply never been pulled', async () => {
    vi.mocked(getData).mockResolvedValue(payload({ state: 'not_synced' }))
    vi.mocked(postData).mockResolvedValue({ queued: 1 })

    render()

    fireEvent.click(await screen.findByTestId('discover-structure'))

    await waitFor(() => expect(postData).toHaveBeenCalledWith('/projects/p1/campaigns/camp-1/structure/sync'))

    /*
     * The confirmation claims a QUEUED request, never a completed sync.
     *
     * The endpoint answers 202 and a worker does the platform call afterwards, so «تمت المزامنة»
     * here would assert a round trip that has not happened and may yet fail.
     */
    const queued = await screen.findByTestId('structure-queued')
    expect(queued.textContent).toMatch(/أُرسل طلب الجلب/)
    expect(queued.textContent).not.toMatch(/تمت المزامنة/)
  })

  it('surfaces a failed request instead of leaving the button looking successful', async () => {
    vi.mocked(getData).mockResolvedValue(payload({ state: 'not_synced' }))
    vi.mocked(postData).mockRejectedValue(new Error('nope'))

    render()
    fireEvent.click(await screen.findByTestId('discover-structure'))

    expect(await screen.findByTestId('structure-queue-failed')).toBeInTheDocument()
  })

  it('renders the ad-set hierarchy with each ad’s creative format', async () => {
    vi.mocked(getData).mockResolvedValue(payload({
      ad_sets: [{
        id: 's1', provider: 'meta', external_id: '220', name: 'Riyadh 25-45', status: 'active',
        optimization_goal: 'conversions', bid_strategy: 'lowest_cost', daily_budget: 50,
        lifetime_budget: null, currency: 'SAR', targeting: { countries: ['SA'] },
        is_demo: false, source_type: 'api', last_synced_at: null,
        ads: [{
          id: 'a1', external_id: '320', name: 'Video A', status: 'active', review_status: 'pending',
          destination_url: null, is_demo: false,
          creative: { id: 'c1', name: 'Hero', format: 'video', thumbnail_url: null, preview_url: null },
        }],
      }],
    }))

    render()

    expect(await screen.findByText('Riyadh 25-45')).toBeInTheDocument()
    expect(screen.getByText('Video A')).toBeInTheDocument()
    expect(screen.getByText('فيديو')).toBeInTheDocument()
    expect(screen.getByText('قيد المراجعة')).toBeInTheDocument()
    // The platform sent no thumbnail, so a placeholder marker is shown — never a stand-in image that
    // a client could mistake for the creative.
    expect(screen.getByTestId('no-preview')).toBeInTheDocument()
    expect(document.querySelector('img')).toBeNull()
  })

  /**
   * LinkedIn has no ad-set level. Its ads must appear, and the panel must say why they are not
   * inside one — an empty ad-set list beside a list of ads otherwise reads as a bug.
   */
  it('shows ads that belong to no ad set, and explains that the platform has no such level', async () => {
    vi.mocked(getData).mockResolvedValue(payload({
      linked_platform_campaigns: [{ id: 'e1', provider: 'linkedin', external_id: '771', name: 'Leads' }],
      ads_without_ad_set: [{
        id: 'a9', external_id: '991', name: 'Creative 991', status: 'active', review_status: 'approved',
        destination_url: null, is_demo: false,
        creative: { id: 'c9', name: 'Creative 991', format: 'image', thumbnail_url: null, preview_url: null },
      }],
    }))

    render()

    const section = await screen.findByTestId('ads-without-ad-set')
    expect(section.textContent).toMatch(/لينكدإن/)
    expect(screen.getByText('Creative 991')).toBeInTheDocument()
    // Counted in the total, rather than silently missing from it.
    expect(screen.getByText('1', { selector: '.tnum' })).toBeInTheDocument()
  })
})
