import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, postData, api, ensureCsrfCookie } from '@/lib/api/client'
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

export interface CampaignAlert {
  id: string
  type: string
  severity: string
  title: string
  message: string | null
  source: string | null
  status: string
  action_url: string | null
  created_at: string | null
}

/** Campaign alerts (from the shared notification store, filtered to this campaign). */
export function useCampaignAlerts(projectId: string | null, campaignId: string | null, status = '') {
  return useQuery({
    queryKey: ['projects', projectId, 'campaigns', campaignId, 'alerts', status],
    queryFn: () => getData<CampaignAlert[]>(`${base(projectId!, campaignId!)}/alerts${status ? `?status=${status}` : ''}`),
    enabled: Boolean(projectId && campaignId),
  })
}

export interface CampaignReport {
  id: string
  name: string
  type: string
  audience: string | null
  status: string
  mode: string | null
  is_demo: boolean
  generated_at: string | null
  last_sent_at: string | null
  exports: Array<{ format: string; status: string; token: string | null }>
}

/** Reports linked to this campaign (same report pipeline, filtered). */
export function useCampaignReports(projectId: string | null, campaignId: string | null) {
  return useQuery({
    queryKey: ['projects', projectId, 'campaigns', campaignId, 'reports'],
    queryFn: () => getData<CampaignReport[]>(`${base(projectId!, campaignId!)}/reports`),
    enabled: Boolean(projectId && campaignId),
  })
}

export interface CampaignAnnotation {
  id: string
  kind: 'note' | 'recommendation'
  status: 'draft' | 'reviewed' | 'approved' | 'hidden' | 'rejected'
  title: string
  body: string | null
  platform: string | null
  kpi: string | null
  evidence: string | null
  priority: string
  proposed_action: string | null
  assignee_id: number | null
  due_date: string | null
  is_demo: boolean
  approved_at: string | null
  created_at: string | null
}

export function useCampaignAnnotations(projectId: string | null, campaignId: string | null) {
  return useQuery({
    queryKey: ['projects', projectId, 'campaigns', campaignId, 'annotations'],
    queryFn: () => getData<CampaignAnnotation[]>(`${base(projectId!, campaignId!)}/annotations`),
    enabled: Boolean(projectId && campaignId),
  })
}

export function useCreateAnnotation(projectId: string, campaignId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (input: Partial<CampaignAnnotation>) => {
      await ensureCsrfCookie()
      return postData<CampaignAnnotation>(`${base(projectId, campaignId)}/annotations`, input as Record<string, unknown>)
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['projects', projectId, 'campaigns', campaignId, 'annotations'] }),
  })
}

export function useUpdateAnnotation(projectId: string, campaignId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, ...patch }: { id: string } & Record<string, unknown>) => {
      await ensureCsrfCookie()
      const res = await api.patch(`${base(projectId, campaignId)}/annotations/${id}`, patch)
      return res.data.data as CampaignAnnotation
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['projects', projectId, 'campaigns', campaignId, 'annotations'] }),
  })
}
