import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

/** One creative row inside a comparison column. `thumbnail_url` is null when the platform gave none. */
export interface CompareCreative {
  creative_id: string
  name: string
  format: string
  provider: string
  thumbnail_url: string | null
  spend: number
  impressions: number
  clicks: number
  conversions: number
  cpa: number | null
  cpm: number | null
}

export interface CompareCampaign {
  campaign_id: string
  name: string
  objective: string | null
  status: string | null
  client_display_name: string | null
  total_budget: number | null
  budget_currency: string | null
  totals: Record<string, number | null>
  series: Array<Record<string, number | null | string>>
  platforms: Array<{ provider: string; spend: number; conversions: number }>
  creatives: CompareCreative[]
}

export interface ComparePayload {
  campaigns: CompareCampaign[]
  /** Server-side verdict: the picked campaigns do not share one objective. */
  mixed_objectives: boolean
  objectives: string[]
}

/**
 * CAMPAIGN-020. Disabled until at least two campaigns are picked — the endpoint rejects fewer, and
 * asking for a one-sided "comparison" would be meaningless anyway.
 */
export function useCampaignComparison(
  projectId: string | null,
  campaignIds: string[],
  range: { from: string; to: string },
) {
  const ids = [...campaignIds].sort()
  return useQuery({
    queryKey: ['project', projectId, 'metrics', 'compare', ids, range],
    queryFn: () => {
      const q = new URLSearchParams({ from: range.from, to: range.to })
      for (const id of campaignIds) q.append('campaign_ids[]', id)
      return getData<ComparePayload>(`/projects/${projectId}/metrics/compare?${q.toString()}`)
    },
    enabled: Boolean(projectId) && campaignIds.length >= 2,
  })
}
