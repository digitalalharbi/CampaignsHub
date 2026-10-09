import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { CampaignStructureTab } from './CampaignStructureTab'
import type { UnifiedCampaign } from './types'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { snapchatPlacementLabel, targetingChip } from '@/lib/snapchatVocabulary'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  postData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

const campaign = { id: 'camp-1', name: 'Ramadan' } as UnifiedCampaign

/** SNAP-OCT26-CHAT-FEED — a placement reads in the reader's language; one the product has not named stays itself. */
describe('ad-set placements', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['campaigns.view', 'integrations.view']) })
  afterEach(() => signOut())

  it('names Chat Feed and keeps an unknown token verbatim', () => {
    expect(snapchatPlacementLabel('CHAT_FEED', true)).toBe('خلاصة الدردشة')
    expect(snapchatPlacementLabel('chat_feed', false)).toBe('Chat Feed')
    expect(snapchatPlacementLabel('INSTREAM_SPOTLIGHT', true)).toBe('INSTREAM_SPOTLIGHT')
    expect(targetingChip('placements', ['FEED', 'CHAT_FEED'], true)).toEqual({ label: 'المواضع', value: 'الخلاصة · خلاصة الدردشة' })
    expect(targetingChip('placement_config', 'custom', false)).toEqual({ label: 'Placement mode', value: 'Custom placements' })
    expect(targetingChip('countries', ['SA'], true)).toEqual({ label: 'countries', value: 'SA' })
    expect(targetingChip('placements', [], true)).toBeNull()
  })

  it('shows the placements on the ad set as chips', async () => {
    vi.mocked(getData).mockResolvedValue({
      linked_platform_campaigns: [{ id: 'e1', provider: 'snapchat', external_id: '120', name: 'Ramadan' }],
      ad_sets: [{
        id: 's1', external_id: 'as1', name: 'الجمهور الأساسي', status: 'active', optimization_goal: 'swipes', bid_strategy: 'auto_bid',
        daily_budget: 100, lifetime_budget: null, currency: 'SAR',
        targeting: { countries: ['SA'], placement_config: 'custom', placements: ['FEED', 'CHAT_FEED', 'INTERSTITIAL_USER'] },
        ads: [],
      }],
      ads_without_ad_set: [], awaiting_credentials: [], state: 'ready',
    })
    renderWithProviders(<CampaignStructureTab campaign={campaign} projectId="p1" />, { locale: 'ar' })

    await screen.findByText('الجمهور الأساسي') // ad sets open by default

    expect((await screen.findByTestId('adset-targeting-placements')).textContent).toBe('المواضع: الخلاصة · خلاصة الدردشة · بين قصص المستخدمين')
    expect(screen.getByTestId('adset-targeting-placement_config').textContent).toBe('نمط المواضع: مواضع مخصّصة')
  })
})
