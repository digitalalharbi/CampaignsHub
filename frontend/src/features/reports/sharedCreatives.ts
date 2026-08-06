import type { CreativeCard, CreativeFunnelShape, LibraryFilterOptions } from '@/features/content/api'
import type { CreativeMove, CreativePulse, KindWinner, ObjectiveWinner, PulseList } from '@/features/content/pulse'

/**
 * §15.12 — the client report's creative endpoints, called with a token instead of a session.
 *
 * `getData` is not used here on purpose. It carries the app's cookie session and its error handling
 * assumes a signed-in user; this reader has neither, and the password travels as a header rather than
 * as a query parameter so it does not end up in a proxy log or a referrer.
 */

export interface CreativePermissions {
  creatives: boolean
  video: boolean
  image_zoom: boolean
  download: boolean
  ad_copy: boolean
  headline: boolean
  cta: boolean
  destination_url: boolean
  comparison: boolean
  spend: boolean
  revenue: boolean
  cpa: boolean
  roas: boolean
  insights: boolean
  recommendations: boolean
}

/** What the reader narrowed to, AFTER the ceiling had its say. Rendered, never assumed. */
export interface AppliedCreativeFilters {
  from: string
  to: string
  providers: string[]
  campaign_ids: string[]
  objectives: string[]
  paths: string[]
  kinds: string[]
  search: string
  sort: string
}

export interface SharedCreativeLibraryPage {
  creatives: CreativeCard[]
  page: number
  per_page: number
  total: number
  period: { from: string; to: string }
  applied: AppliedCreativeFilters
  available: Pick<LibraryFilterOptions, 'providers' | 'campaigns' | 'objectives' | 'paths' | 'kinds'> & {
    earliest: string
    latest: string
  }
  permissions: CreativePermissions
}

export interface CreativeInsightItem {
  key: string
  severity: 'warning' | 'opportunity' | 'positive'
  comparison: 'previous_period' | 'peers'
  title_ar: string
  title_en: string
  detail_ar: string
  detail_en: string
  /** Absent when the link withholds recommendations — the finding stays, the action goes. */
  action_ar?: string
  action_en?: string
  supporting_metrics: Record<string, number>
  previous_metrics: Record<string, number> | null
  movement: { metric: string; current: number | null; previous: number | null; change: number | null } | null
  confidence: 'high' | 'medium' | 'insufficient_data'
  spend: number | null
  creative_id: string | null
  creative_name: string | null
  objective: string | null
  path: string | null
  provider: string | null
  campaign_name: string | null
  kind: string | null
  period: { from: string; to: string; days: number }
  previous_period: { from: string; to: string }
  /** `rules` today. A model-written insight cannot arrive undeclared — see CreativeInsights. */
  generated_by: string
  needs_human_review: boolean
  fatigue_signals?: string[]
}

/*
 * `filters` is replaced by `available`, which is why this omits it rather than extending straight.
 *
 * The operator's section offers every axis the library has. A client's offers only what its ceiling
 * can honour — and a control that cannot change the answer is worse than an absent one, because the
 * reader concludes the data is empty rather than that the filter was never theirs to set.
 */
/**
 * A ranking whose NUMBER the link withholds while the ranking itself stands.
 *
 * «Your best awareness creative, judged on CPM» is a useful sentence without the CPM, so the winner
 * is still named. A null value alone would be indistinguishable from a figure that failed to load,
 * which is why the flag exists rather than the absence of a number carrying the meaning.
 */
type Withheld = { value_hidden?: boolean }

export type SharedObjectiveWinner = Omit<ObjectiveWinner, 'value'> & Withheld & { value: number | null }
export type SharedKindWinner = Omit<KindWinner, 'value'> & Withheld & { value: number | null }
export type SharedCreativeMove = Omit<CreativeMove, 'current' | 'previous'> &
  Withheld & { current: number | null; previous: number | null }

export interface SharedCreativeSummaryPayload
  extends Omit<CreativePulse, 'filters' | 'best_by_objective' | 'best_image' | 'best_video' | 'fastest_growing' | 'declining'> {
  best_by_objective: SharedObjectiveWinner[]
  best_image: SharedKindWinner[]
  best_video: SharedKindWinner[]
  fastest_growing: PulseList<SharedCreativeMove>
  declining: PulseList<SharedCreativeMove>
  insights?: { items: CreativeInsightItem[]; total: number; shown: number }
  applied: AppliedCreativeFilters
  available: SharedCreativeLibraryPage['available']
  permissions: CreativePermissions
}

export interface SharedCreativeDetail {
  creative: CreativeCard & {
    copy?: { body?: string | null; headline?: string | null; description?: string | null; cta?: string | null }
    destination_url?: string | null
    metrics: CreativeCard['metrics']
    previous: CreativeCard['metrics']
    headline_metrics: string[]
    fatigue: NonNullable<CreativeCard['fatigue']>
  }
  period: { from: string; to: string; days: number }
  previous_period: { from: string; to: string }
  /**
   * §15.6 — the same reshaping of the same figures the operator's page shows.
   *
   * `cost_hidden` is set when the link withholds spend: a cost per stage IS the spend divided by a
   * count printed beside it, so it goes while the stages and their conversion rates stay.
   */
  funnel: CreativeFunnelShape
  trend: Array<Record<string, number | string | null>>
  by_platform: Array<{ creative_id: string; provider: string; metrics: CreativeCard['metrics']; source: string }>
  by_campaign: Array<Record<string, number | string | null>>
  attribution: { source: string; note_ar: string; note_en: string }
  permissions: CreativePermissions
}

export interface SharedCreativeComparison {
  creatives: CreativeCard[]
  period: { from: string; to: string }
  comparable: boolean
  reason: string | null
  reason_ar: string | null
  permissions: CreativePermissions
}

async function ask<T>(token: string, path: string, query: string, password?: string): Promise<T> {
  const res = await fetch(`/api/v1/reports/shared/${token}${path}${query}`, {
    headers: { Accept: 'application/json', ...(password ? { 'X-Report-Password': password } : {}) },
  })
  const body = await res.json().catch(() => ({}))

  if (!res.ok) {
    // The message the SERVER gave, so «this content is not available on this link» reaches the reader
    // as itself rather than as a generic failure they will read as the page being broken.
    throw new Error(body?.message ?? 'unavailable')
  }

  return body.data as T
}

/** Only what the ceiling can honour. Anything else in here would be a control that changes nothing. */
export interface SharedCreativeQuery {
  from?: string
  to?: string
  providers?: string[]
  campaign_ids?: string[]
  objectives?: string[]
  paths?: string[]
  kinds?: string[]
  page?: number
  per_page?: number
  sort?: string
  creative_ids?: string[]
}

export function sharedCreativeQuery(query: SharedCreativeQuery): string {
  const params = new URLSearchParams()

  for (const [key, value] of Object.entries(query)) {
    if (Array.isArray(value)) {
      value.filter(Boolean).forEach((v) => params.append(`${key}[]`, String(v)))
    } else if (value !== undefined && value !== null && value !== '') {
      params.set(key, String(value))
    }
  }

  const qs = params.toString()
  return qs ? `?${qs}` : ''
}

export const getSharedCreativeSummary = (token: string, query: SharedCreativeQuery, password?: string) =>
  ask<SharedCreativeSummaryPayload>(token, '/creatives/summary', sharedCreativeQuery(query), password)

export const getSharedCreatives = (token: string, query: SharedCreativeQuery, password?: string) =>
  ask<SharedCreativeLibraryPage>(token, '/creatives', sharedCreativeQuery(query), password)

export const getSharedCreative = (token: string, id: string, query: SharedCreativeQuery, password?: string) =>
  ask<SharedCreativeDetail>(token, `/creatives/${id}`, sharedCreativeQuery(query), password)

export const compareSharedCreatives = (token: string, ids: string[], query: SharedCreativeQuery, password?: string) =>
  ask<SharedCreativeComparison>(
    token,
    '/creatives/comparison',
    sharedCreativeQuery({ ...query, creative_ids: ids }),
    password,
  )
