import { deleteData, getData, postData } from '@/lib/api/client'

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

/**
 * One card of a carousel — its own picture, its own copy, its own destination.
 *
 * The optional fields are optional because a client link REMOVES them rather than blanking them: an
 * empty `headline` would tell the reader a headline exists and is being kept from them, which is a
 * different disclosure. Read them with `in` or `?.`, never with `?? ''`.
 */
export interface CreativeCardSlide {
  index: number
  kind: 'image' | 'video'
  image_url: string | null
  video_url: string | null
  thumbnail_url: string | null
  headline?: string | null
  body?: string | null
  cta?: string | null
  destination_url?: string | null
}

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
  /**
   * A carousel's cards. `null` means the provider sent no breakdown — NOT that there are none.
   *
   * The distinction is the whole point: a five-card carousel synced into the singular columns keeps
   * its first card, and a UI that could not tell the two apart would render a fifth of what ran with
   * nothing on screen saying so.
   */
  cards?: CreativeCardSlide[] | null
  cards_reported?: boolean
  /** Cards whose link was refused for carrying a credential — counted, so «3 of 5» is sayable. */
  cards_withheld?: number
  /** Set only on a client link: whether the reader may zoom, and whether the asset may be downloaded. */
  can_zoom?: boolean
  can_download?: boolean
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
  /** The two rungs between the campaign and the creative — the dashboard's drill-down needs both. */
  ad_set_id: string | null
  ad_id: string | null
  /**
   * CREATIVE-FRONTEND-ADS-001 — every ad running this creative, not just the first one found.
   *
   * `ad_id` is one ad chosen from many by row order; the canonical relation is `external_ads
   * .creative_id`, and one asset is routinely placed by several ads across squads. The backend has
   * sent this array since the presenter was fixed; nothing consumed it, so the library kept
   * implying each creative belonged to exactly one ad.
   */
  ads: CreativeAd[]
  preview: CreativePreview
  aspect_ratio: string | null
  duration_seconds: number | null
  width: number | null
  height: number | null
  file_size: number | null
  grouped: boolean
  /** The group itself, not only that there is one — «best platform» is a per-group question. */
  group_id: string | null
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
  /** Labelled, because a select of provider ids is not a control somebody can use. */
  ads: Array<{ value: string; label: string }>
  objectives: string[]
  paths: string[]
  projects: Array<{ id: string; name: string; client_id: string | null }>
  clients: Array<{ id: string; name: string }>
  health: FatigueStatus[]
}

/** One ad placing a creative. The id is the address; the name is what a person recognises. */
export interface CreativeAd {
  id: string
  external_id: string
  name: string | null
  status: string | null
  external_ad_set_id: string | null
  external_campaign_id: string | null
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

/**
 * §15.6 — one stage of a creative's funnel, as the platform reported it.
 *
 * A stage the provider never sent is NOT in `stages`; it is named in `missing` instead. So this list
 * is never padded with zeroes, and a four-step funnel means four steps were reported rather than
 * three of them having failed.
 */
export interface FunnelStage {
  key: string
  label_ar: string
  label_en: string
  count: number | null
  /** The step above this one IN THIS FUNNEL — not the step above it in theory. */
  from_stage: string | null
  rate_from_previous: number | null
  cost_per: number | null
  /** True on a client link that withholds spend: the cost is absent, not zero. */
  cost_hidden?: boolean
  source: string
}

export interface CreativeFunnelShape {
  stages: FunnelStage[]
  missing: Array<{ key: string; label_ar: string; label_en: string }>
  source: string
}

/** §15.10's findings, for one creative. Every field is evidence for the sentence it carries. */
export interface CreativeInsight {
  /**
   * The identity of this FINDING — the rule plus the creative it is about.
   *
   * `key` alone is the RULE, and one rule fires once per creative, so a list spanning an account
   * holds many items with the same `key`. Keying a list on it drops findings silently while the
   * total beside them stays honest.
   */
  id: string
  key: string
  severity: 'warning' | 'opportunity' | 'positive'
  comparison: 'previous_period' | 'peers'
  title_ar: string
  title_en: string
  detail_ar: string
  detail_en: string
  action_ar?: string
  action_en?: string
  supporting_metrics: Record<string, number>
  previous_metrics: Record<string, number> | null
  movement: { metric: string; current: number | null; previous: number | null; change: number | null } | null
  confidence: 'high' | 'medium' | 'insufficient_data'
  creative_id: string | null
  creative_name: string | null
  objective: string | null
  path: string | null
  provider: string | null
  campaign_name: string | null
  period: { from: string; to: string; days: number }
  previous_period: { from: string; to: string }
  /** `rules` today. A model-written insight cannot arrive undeclared. */
  generated_by: string
  needs_human_review: boolean
  fatigue_signals?: string[]
}

export interface CreativeDetail {
  creative: CreativeCard & {
    copy: { body: string | null; headline: string | null; description: string | null; cta: string | null }
    dimensions: { width: number | null; height: number | null; aspect_ratio: string | null; file_size: number | null }
    destination_url: string | null
    external_ids: { creative: string; ad: string | null; ad_set: string | null; campaign: string | null }
  }
  period: { from: string; to: string; days: number }
  previous_period: { from: string; to: string }
  metrics: CreativeMetrics | null
  previous: CreativeMetrics | null
  headline_metrics: string[]
  path: string
  fatigue: CreativeFatigue
  funnel: CreativeFunnelShape
  trend: Array<Record<string, number | string | null>>
  weekly: Array<Record<string, number | string | null>>
  by_platform: Array<{ creative_id: string; provider: string; metrics: CreativeMetrics | null; source: string }>
  by_campaign: Array<Record<string, unknown>>
  peers: Record<string, number | null> | null
  group: Record<string, unknown> | null
  insights: {
    items: CreativeInsight[]
    total: number
    /** What the peer half of the analysis was actually taken against — stated, never assumed. */
    compared_against: { path: string; creatives: number; capped: boolean; cap: number }
  }
  attribution: { source: string; note_ar: string; note_en: string }
  /** What the pipeline normalised INTO. Null when the project has no daily rows yet. */
  currency: string | null
  timezone: string | null
  project_id: string
}

export const getCreative = (projectId: string, creativeId: string, window: { from?: string; to?: string }) =>
  getData<CreativeDetail>(
    `/projects/${projectId}/creatives/${creativeId}${libraryQueryString(window)}`,
  )

/**
 * The detail page's own address — no project id (§15.6).
 *
 * A library card does not carry a project id, so a page that needed one could not be linked to from
 * the page that lists them. The ceiling is the caller's membership either way, and a creative
 * outside it answers 404 rather than 403 — a 403 would confirm the id exists and is someone else's.
 */
export const getCreativeInReach = (creativeId: string, window: { from?: string; to?: string }) =>
  getData<CreativeDetail>(`/creatives/${creativeId}${libraryQueryString(window)}`)

/**
 * §15.8, §15.13 — the same asset on more than one platform.
 *
 * `mixed_objectives` is the field that keeps this honest. Spend and impressions add across
 * platforms; CPA and ROAS do not add across OBJECTIVES, so a group holding an awareness cut and a
 * sales cut of one film carries an empty `headline_metrics` and a stated reason instead of a blended
 * figure that answers neither question.
 */
export interface CreativeGroupSummary {
  id: string
  name: string
  /** Ordered by how much it proves: a hash is evidence, a person's judgement is a decision. */
  method: string
  confirmed: boolean
  confirmed_at: string | null
  project_id: string
  creative_count: number
  providers: string[]
  objectives: string[]
  paths: string[]
  objective: string | null
  mixed_objectives: boolean
  /** Empty exactly when the members disagree about the objective — never a fallback KPI set. */
  headline_metrics: string[]
  metrics: CreativeMetrics | null
  mixed_reason_ar: string | null
  mixed_reason_en: string | null
}

export interface CreativeGroupAuditEntry {
  id: string
  action: string
  at: string | null
  actor: string | null
  creative_ids: string[]
  group_dissolved: boolean
}

export interface CreativeGroupDetail extends CreativeGroupSummary {
  members: CreativeCard[]
  by_platform: Array<{
    provider: string
    creative_count: number
    creative_ids: string[]
    metrics: CreativeMetrics | null
  }>
  period: { from: string; to: string }
  audit: CreativeGroupAuditEntry[]
}

export interface CreativeGroupsPage {
  groups: CreativeGroupSummary[]
  page: number
  per_page: number
  total: number
  period: { from: string; to: string }
}

export const listCreativeGroups = (query: LibraryQuery) =>
  getData<CreativeGroupsPage>(`/creatives/groups${libraryQueryString(query)}`)

export const getCreativeGroup = (groupId: string, window: { from?: string; to?: string }) =>
  getData<CreativeGroupDetail>(`/creatives/groups/${groupId}${libraryQueryString(window)}`)

/**
 * Merge a selection into one asset.
 *
 * No project id: the library spans projects and a card does not carry one, so the backend derives
 * the project from the selection — and refuses a selection that spans two, because a group is one
 * asset and one asset cannot belong to two clients' books.
 */
export const groupCreatives = (creativeIds: string[], name?: string) =>
  postData<{ id: string; name: string; method: string; creative_ids: string[] }>('/creatives/group', {
    creative_ids: creativeIds,
    ...(name ? { name } : {}),
  })

export const ungroupCreative = (creativeId: string) =>
  deleteData<{ creative_id: string; group_dissolved: boolean }>(`/creatives/${creativeId}/group`)

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
