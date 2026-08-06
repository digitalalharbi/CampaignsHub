import { getData, postData } from '@/lib/api/client'

/**
 * §15 — the creative library, over one pipeline.
 *
 * These types mirror `CreativeAnalysisController`, which is the SAME controller the project-scoped
 * analysis and the report creative sections read. There used to be a second endpoint behind this
 * page with its own SQL; it coalesced missing metrics to `0`, so this page and Creative Analysis
 * could disagree about one creative and both look right. §15.17 treats that as an architectural
 * defect rather than a discrepancy, which is why there is exactly one shape here.
 *
 * ## `null` is not `0`
 *
 * Every metric is `number | null`, deliberately. A platform that does not report video completions
 * sends nothing, and a video nobody finished reports zero — the two must not render the same. Read
 * these with `reported` (below) or with an explicit `=== null`, never with `|| 0`, which collapses
 * exactly the distinction the backend went to the trouble of preserving.
 */

/** What the browser may load for a creative, and why when it may not. */
export interface CreativePreview {
  /** `available` renders; the other three explain themselves and never fabricate an image. */
  state: 'available' | 'withheld' | 'expired' | 'unavailable'
  kind: 'image' | 'video' | 'carousel' | 'other'
  image_url: string | null
  video_url: string | null
  thumbnail_url: string | null
  expires_at: string | null
  note_ar: string | null
  note_en: string | null
}

export interface CreativeMetrics {
  spend: number | null
  impressions: number | null
  clicks: number | null
  conversions: number | null
  revenue: number | null
  video_views: number | null
  video_p25: number | null
  video_p50: number | null
  video_p75: number | null
  video_p100: number | null
  frequency: number | null
  ctr: number | null
  cpc: number | null
  cpm: number | null
  cpa: number | null
  roas: number | null
  conversion_rate: number | null
  view_rate: number | null
  completion_rate: number | null
  active_days: number | null
  /** Which keys the provider actually sent. A key that is `false` here is «Not Provided», not zero. */
  reported: Record<string, boolean>
  [key: string]: number | null | boolean | Record<string, boolean> | undefined
}

export type FatigueStatus = 'improving' | 'stable' | 'watch' | 'fatigued' | 'insufficient_data'

export interface CreativeFatigue {
  status: FatigueStatus
  /** The signals that produced the verdict — never a single fixed rule, and never empty. */
  signals: Array<{ metric: string; direction: string; change: number | null }>
  reason_ar: string
  reason_en: string
}

export interface CreativeCard {
  id: string
  name: string
  format: string
  provider: string
  status: string
  campaign_id: string | null
  campaign_name: string | null
  preview: CreativePreview
  aspect_ratio: string | null
  duration_seconds: number | null
  width: number | null
  height: number | null
  file_size: number | null
  grouped: boolean
  is_demo: boolean
  freshness: {
    last_synced_at: string | null
    source_updated_at: string | null
    first_seen_at: string | null
    last_active_at: string | null
  }
  objective: string | null
  path: string
  /** The metrics this creative is judged on, chosen by its objective — never a fixed KPI set. */
  headline_metrics: string[]
  metrics: CreativeMetrics | null
  fatigue: CreativeFatigue
}

export interface LibraryFilterOptions {
  providers: string[]
  formats: string[]
  statuses: string[]
  kinds: string[]
  campaigns: Array<{ id: string; name: string; objective: string | null }>
  ad_sets: string[]
  ads: string[]
  objectives: string[]
  paths: string[]
  projects: Array<{ id: string; name: string; client_id: string | null }>
  clients: Array<{ id: string; name: string }>
  health: FatigueStatus[]
}

export interface LibraryPage {
  creatives: CreativeCard[]
  page: number
  per_page: number
  total: number
  period: { from: string; to: string }
  filters: LibraryFilterOptions
}

/** Everything the library can be narrowed by (§15.2). Empty axes are omitted, never sent as `[]`. */
export interface LibraryQuery {
  from?: string
  to?: string
  page?: number
  per_page?: number
  sort?: string
  search?: string
  client_ids?: string[]
  project_ids?: string[]
  providers?: string[]
  campaign_ids?: string[]
  ad_set_ids?: string[]
  ad_ids?: string[]
  objectives?: string[]
  paths?: string[]
  kinds?: string[]
  statuses?: string[]
  health?: string
}

/**
 * An axis nobody touched is LEFT OUT rather than sent empty.
 *
 * `?providers[]=` and no `providers` at all mean different things to a fail-closed backend — absent
 * is «no bound», and an empty list is reserved for «this narrowed to nothing». Sending `[]` for an
 * untouched filter is how a bound quietly becomes «everything».
 */
export function libraryQueryString(query: LibraryQuery): string {
  const params = new URLSearchParams()

  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') continue

    if (Array.isArray(value)) {
      if (value.length === 0) continue
      for (const item of value) params.append(`${key}[]`, String(item))
    } else {
      params.append(key, String(value))
    }
  }

  const qs = params.toString()
  return qs === '' ? '' : `?${qs}`
}

/**
 * The library across everything the caller may reach, or pinned to one project.
 *
 * The ceiling is the membership's either way — the project id narrows, it never widens, and a
 * project outside the caller's clients is refused rather than answered emptily.
 */
export const listCreatives = (query: LibraryQuery, projectId?: string | null) =>
  getData<LibraryPage>(
    `${projectId ? `/projects/${projectId}/creatives` : '/creatives'}${libraryQueryString(query)}`,
  )

export interface CreativeDetail {
  creative: CreativeCard & {
    copy: { body: string | null; headline: string | null; description: string | null; cta: string | null }
    dimensions: { width: number | null; height: number | null; aspect_ratio: string | null; file_size: number | null }
    destination_url: string | null
    external_ids: { creative: string; ad: string | null; ad_set: string | null; campaign: string | null }
  }
  period: { from: string; to: string; days: number }
  metrics: CreativeMetrics | null
  previous: CreativeMetrics | null
  headline_metrics: string[]
  path: string
  fatigue: CreativeFatigue
  trend: Array<Record<string, number | string | null>>
  by_platform: Array<{ creative_id: string; provider: string; metrics: CreativeMetrics | null; source: string }>
  by_campaign: Array<Record<string, unknown>>
  peers: Record<string, number | null> | null
  group: Record<string, unknown> | null
}

export const getCreative = (projectId: string, creativeId: string, window: { from?: string; to?: string }) =>
  getData<CreativeDetail>(
    `/projects/${projectId}/creatives/${creativeId}${libraryQueryString(window)}`,
  )

export interface CreativeComparison {
  creatives: CreativeCard[]
  /** Per-metric winners only. There is deliberately no overall winner in this shape. */
  winners: Record<string, string | null>
  comparable: boolean
  reason_ar: string | null
  reason_en: string | null
}

/**
 * Compared across the caller's reach, not within one project.
 *
 * The library spans projects, so a selection legitimately can too. The ids are still filtered by the
 * membership ceiling on the way in — a comparison is the one call where a list of ids arrives
 * straight from a browser, and an id outside the caller's clients is dropped rather than trusted
 * because it was asked for.
 */
export const compareCreatives = (
  creativeIds: string[],
  window: { from?: string; to?: string },
  projectId?: string | null,
) =>
  postData<CreativeComparison>(
    `${projectId ? `/projects/${projectId}/creatives` : '/creatives'}/compare${libraryQueryString(window)}`,
    { creative_ids: creativeIds },
  )
