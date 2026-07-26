import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
import type { BudgetRow, FunnelStage, PlatformRow, Range, Summary, TimePoint } from '@/features/analytics/api'

/**
 * Per-campaign metrics for the Command Center. Every key follows the mandated isolation convention
 * ['projects', projectId, 'campaigns', campaignId, section, filters] and is disabled until both ids
 * are present, so switching project/campaign cannot show another context's cached numbers.
 */
const q = (r: Range) => `from=${r.from}&to=${r.to}`
const base = (projectId: string, campaignId: string) => `/projects/${projectId}/campaigns/${campaignId}`

function useCampaignMetric<T>(section: string, projectId: string | null, campaignId: string | null, range: Range) {
  return useQuery({
    queryKey: ['projects', projectId, 'campaigns', campaignId, section, range.from, range.to],
    queryFn: () => getData<T>(`${base(projectId!, campaignId!)}/${section}?${q(range)}`),
    enabled: Boolean(projectId && campaignId),
  })
}

export const useCampaignSummary = (p: string | null, c: string | null, r: Range) =>
  useCampaignMetric<Summary>('summary', p, c, r)
export const useCampaignPerformance = (p: string | null, c: string | null, r: Range) =>
  useCampaignMetric<TimePoint[]>('performance', p, c, r)
export const useCampaignPlatforms = (p: string | null, c: string | null, r: Range) =>
  useCampaignMetric<PlatformRow[]>('platforms', p, c, r)
export const useCampaignBudget = (p: string | null, c: string | null, r: Range) =>
  useCampaignMetric<BudgetRow[]>('budget', p, c, r)
export const useCampaignFunnel = (p: string | null, c: string | null, r: Range) =>
  useCampaignMetric<FunnelStage[]>('funnel', p, c, r)

export interface CampaignActivityEvent {
  id: string
  action: string
  label: string
  actor: string
  at: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  source: string
}

/** Activity timeline (audit log) — not range-scoped; keyed per campaign for isolation. */
export function useCampaignActivity(projectId: string | null, campaignId: string | null) {
  return useQuery({
    queryKey: ['projects', projectId, 'campaigns', campaignId, 'activity'],
    queryFn: () => getData<CampaignActivityEvent[]>(`${base(projectId!, campaignId!)}/activity`),
    enabled: Boolean(projectId && campaignId),
  })
}
