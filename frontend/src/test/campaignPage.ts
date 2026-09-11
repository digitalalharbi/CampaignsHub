import type { CampaignPage } from '@/features/campaigns/api'
import type { UnifiedCampaign } from '@/features/campaigns/types'

/**
 * CAMPAIGNS-LEDGER-001 — a fixture for the page shape `listCampaigns` now returns.
 *
 * The endpoint used to hand over every campaign in the project, so a test could mock a bare array.
 * It returns a page with the project's own counts beside it, and these fixtures describe a single
 * page holding everything — which is what every one of them meant when it passed an array.
 */
export function campaignPage(campaigns: UnifiedCampaign[], over: Partial<CampaignPage> = {}): CampaignPage {
  return {
    campaigns,
    total: campaigns.length,
    page: 1,
    lastPage: 1,
    counts: campaigns.reduce<Record<string, number>>((acc, c) => {
      acc[c.status] = (acc[c.status] ?? 0) + 1

      return acc
    }, {}),
    ...over,
  }
}
