/**
 * RECOMMENDATIONS-001 — the project's recommendations, read where they were written.
 *
 * These are stored `campaign_annotations` of kind `recommendation`. Nothing here derives advice from
 * figures: a recommendation was written by a person, carries their evidence, and is shown with it.
 */
import { getData } from '@/lib/api/client'

export type RecommendationStatus = 'draft' | 'reviewed' | 'approved' | 'hidden' | 'rejected'
export type RecommendationPriority = 'critical' | 'high' | 'medium' | 'low'

export interface Recommendation {
  id: string
  kind: 'note' | 'recommendation'
  status: RecommendationStatus
  title: string
  body: string | null
  platform: string | null
  kpi: string | null
  /** What the writer based this on. A recommendation without it is an opinion, and reads as one. */
  evidence: string | null
  priority: RecommendationPriority | null
  proposed_action: string | null
  assignee_id: number | null
  due_date: string | null
  is_demo: boolean
  approved_at: string | null
  created_at: string | null
  campaign_id: string | null
  campaign_name: string | null
}

export function listRecommendations(
  projectId: string,
  filters: { status?: string; priority?: string } = {},
): Promise<Recommendation[]> {
  const q = new URLSearchParams()
  if (filters.status && filters.status !== 'all') q.set('status', filters.status)
  if (filters.priority && filters.priority !== 'all') q.set('priority', filters.priority)

  const suffix = q.toString() ? `?${q}` : ''
  return getData<Recommendation[]>(`/v1/projects/${projectId}/recommendations${suffix}`)
}
