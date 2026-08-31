import type { AdsReading, ReportAd } from './ReportAdsSection'
import type { ObjectivePerformance } from './InteractiveReport'

import { getData, postData, putData } from '@/lib/api/client'
import type { SharedBranding } from './sharedBranding'
import { api } from '@/lib/api/client'

export type ReportStatus = 'draft' | 'processing' | 'completed' | 'failed'
export type ReportFormat = 'pdf' | 'xlsx' | 'csv'

export interface ReportExportRow {
  id: string
  format: ReportFormat
  status: string
  size: number | null
  token: string | null
}
export interface ReportRow {
  id: string
  name: string
  type: string
  status: ReportStatus
  period: { from: string | null; to: string | null }
  currency: string
  is_demo: boolean
  generated_at: string | null
  last_sent_at: string | null
  created_at: string | null
  error: string | null
  exports: ReportExportRow[]
}
export interface ReportsIndex {
  reports: ReportRow[]
  summary: { total: number; completed: number; processing: number; failed: number }
}
export interface ReportDetail extends ReportRow {
  data: {
    period: { from: string; to: string }
    currency: string
    kpis: Record<string, number | null>
    delta: Record<string, number | null>
    platforms: Array<Record<string, unknown>>
    campaigns: Array<Record<string, unknown>>
    funnel: Array<Record<string, unknown>>
    timeseries: Array<Record<string, unknown>>
    summary: string[]
  } | null
}

/**
 * Report type labels are no longer hardcoded here — they are served by the central taxonomy engine
 * (definition `report.type`, is_system) and consumed via `useTaxonomyOptions('report.type')`.
 */

const base = (p: string) => `/projects/${p}/reports`

export const listReports = (p: string, params = '') => getData<ReportsIndex>(`${base(p)}${params ? `?${params}` : ''}`)
export const getReport = (p: string, id: string) => getData<ReportDetail>(`${base(p)}/${id}`)
export const createReport = (p: string, body: Record<string, unknown>) => postData<ReportRow>(base(p), body)
export const regenerateReport = (p: string, id: string) => postData<ReportRow>(`${base(p)}/${id}/regenerate`)
export const exportReport = (p: string, id: string, format: ReportFormat) =>
  postData<{ id: string; format: string; status: string }>(`${base(p)}/${id}/export`, { format })
export const sendReport = (p: string, id: string, recipients: string[]) =>
  postData<{ sent_to: number }>(`${base(p)}/${id}/send`, { recipients })
export const deleteReport = (p: string, id: string) => api.delete(`${base(p)}/${id}`)

/** Public, signed, expiring download link for a finished export. */
export const downloadUrl = (token: string) => `/api/v1/reports/download/${token}`

// ---- Secure client share links -----------------------------------------------------------------

export interface ShareRow {
  id: string
  active: boolean
  allow_download: boolean
  hide_spend: boolean
  hide_revenue: boolean
  hide_campaign_names: boolean
  watermark: boolean
  password_protected: boolean
  view_count: number
  last_viewed_at: string | null
  expires_at: string | null
  revoked_at: string | null
  logs_count: number | null
  created_at: string | null
  /** LIVEREP-001 — `live` recomputes inside `scope`; `snapshot` serves the generated document. */
  mode: 'live' | 'snapshot'
  scope: {
    project_id?: string
    campaign_ids?: string[]
    providers?: string[]
    earliest?: string
    latest?: string
  } | null
}
export interface CreatedShare extends ShareRow {
  url: string
  token: string
}

export const listShares = (p: string, reportId: string) => getData<ShareRow[]>(`${base(p)}/${reportId}/shares`)
export const createShare = (p: string, reportId: string, opts: Record<string, unknown>) =>
  postData<CreatedShare>(`${base(p)}/${reportId}/shares`, opts)
export const revokeShare = (p: string, reportId: string, shareId: string) =>
  postData<ShareRow>(`${base(p)}/${reportId}/shares/${shareId}/revoke`)

// ---- LIVEREP-002: build a live link from a choice, not from a document ---------------------------

export interface LiveBuilderOptions {
  /**
   * REPORT-SCOPE-SELECTION-001 — `last_active_on` is answered for the REPORT'S WINDOW.
   *
   * The last day inside the requested period on which the campaign reported a positive figure, or
   * null when it reported none. Null also means «no period was asked about», and the builder makes
   * no claim in that case rather than sorting campaigns against a window nobody named.
   */
  campaigns: Array<{ id: string; name: string; status: string | null; last_active_on: string | null }>
  providers: string[]
  metrics: Array<{ key: string; ar: string; en: string }>
}

export const liveBuilderOptions = (p: string, period?: { from: string; to: string }) =>
  getData<LiveBuilderOptions>(
    `${base(p)}/live/options${period ? `?from=${encodeURIComponent(period.from)}&to=${encodeURIComponent(period.to)}` : ''}`,
  )

export const createLiveLink = (p: string, body: Record<string, unknown>) =>
  postData<{ report_id: string; share_id: string; url: string; token: string }>(`${base(p)}/live`, body)

/** Public (unauthenticated) shared report fetch. Sends the optional password via header. */
export async function fetchSharedReport(token: string, password?: string) {
  const res = await fetch(`/api/v1/reports/shared/${token}`, {
    headers: { Accept: 'application/json', ...(password ? { 'X-Report-Password': password } : {}) },
  })
  const body = await res.json()
  return { status: res.status, envelope: body }
}
/**
 * BRANDING-HIERARCHY-001 — the identity this link carries, addressed by the TOKEN alone.
 *
 * No asset id, tenant id or scope is sent, because none is accepted: an endpoint that takes one is
 * an endpoint somebody will enumerate, and a shared report link is exactly where a stranger has a
 * URL and time.
 */
export const sharedBranding = (token: string) =>
  getData<SharedBranding>(`/reports/shared/${encodeURIComponent(token)}/branding`)

export const sharedDownloadUrl = (token: string, format: ReportFormat) =>
  `/api/v1/reports/shared/${token}/download/${format}`

export const renewShare = (p: string, reportId: string, shareId: string, expiresAt: string) =>
  postData<ShareRow>(`${base(p)}/${reportId}/shares/${shareId}/renew`, { expires_at: expiresAt })

export const shareLogs = (p: string, reportId: string, shareId: string) =>
  getData<ShareAccessLog[]>(`${base(p)}/${reportId}/shares/${shareId}/logs`)

export interface ShareAccessLog {
  action: string
  ip: string | null
  user_agent: string | null
  detail: string | null
  created_at: string
}

/** The live payload — every figure recomputed inside the link's ceiling (LIVEREP-001). */
export interface StoreFunnelPayload {
  stages: Array<{
    key: string
    label_ar: string
    label_en: string
    value: number | null
    state: 'measured' | 'partial' | 'unavailable'
    source: { kind: 'stores' | 'ad_platforms' | 'none'; ar: string; en: string }
    note_ar?: string | null
    note_en?: string | null
  }>
  totals: { orders: number; revenue: number | null; attributed_orders: number }
  derived: { roas: number | null; aov: number | null; cpa: number | null }
  coverage: {
    stores: number
    orders_in_window: number
    store_last_synced_at: string | null
    /**
     * COMMERCE-FX-001 — orders whose money could not be converted into the reporting currency, and
     * are therefore absent from the revenue on this link.
     *
     * It matters more here than on the operator's own page: a client has no other view of their
     * account, so a total that is quietly short is one they cannot possibly check.
     */
    reporting_currency: string
    reporting_timezone?: string
    orders_with_assumed_timezone?: number
    orders_with_money_withheld: number
    money_withheld_currencies: string[]
  }
}

export interface LivePayload {
  period: { from: string; to: string; days: number }
  currency: string
  totals: Record<string, number | null>
  deltas: Record<string, number | null>
  timeseries: Array<Record<string, unknown>>
  platforms: Array<Record<string, unknown> & { provider: string; spend: number | null }>
  campaigns: Array<Record<string, unknown> & { campaign_name: string | null; provider: string | null; spend: number | null }>
  /**
   * FUNNEL-NULL-001 — `count` is null for a stage no platform reported, and `reported` says so
   * outright. On a client link this matters more than anywhere else: the reader has no other view of
   * their account, so «0 add to cart» beside 176 purchases is a conclusion they cannot check.
   */
  funnel: Array<{ stage: string; label: string; reported: boolean; count: number | null; from_stage: string | null; step_rate: number | null; cost_per: number | null }>
  /**
   * FUNNEL-001 — «الفانل والمتجر» for this link's project, or null when it has no store.
   *
   * Null rather than a funnel of nulls: a section of empty rows reads as one that failed to load, and
   * a client would ask about a store they never had. Every figure here obeys the share's own
   * hide-revenue and hide-spend flags, because a funnel is a second place a hidden number could reach
   * a reader.
   */
  /**
   * REPORT-AD-PREVIEW-001 — the ads, built by the same service the generated deck calls.
   *
   * `ads_absent_reason` travels WITH the empty list: «no ad-level rows in this window» and «no
   * metric this objective can be ranked on» are different facts, and a heading over an empty grid is
   * a claim about the client's advertising made by a gap in ours.
   */
  ads?: ReportAd[]
  ads_level?: string | null
  ads_absent_reason?: string | null
  ads_reading?: AdsReading
  /**
   * REPORT-OBJECTIVE-003/004 — Direct against Blended, on the surface where it matters most.
   *
   * `totals` above rolls the whole scope together, so its cost per order divides EVERY campaign's
   * spend by the orders the sales campaigns produced. The link is where the person paying asks what
   * an order costs, and that is not the same question.
   */
  objective_performance?: ObjectivePerformance
  store_funnel: StoreFunnelPayload | null
  freshness: Array<{
    provider: string
    data_as_of: string | null
    last_checked_at: string | null
    /** `awaiting_credentials` is stated, never rendered as a zero — see LiveSharedReport. */
    state: 'synced' | 'awaiting_credentials'
  }>
  available: {
    providers: string[]
    campaigns: Array<{ id: string; name: string }>
    earliest: string
    latest: string
  }
  /** LIVEREP-002 — the KPIs the operator chose; empty means all of them. */
  metrics: string[]
  applied: { from: string; to: string; providers: string[]; campaigns: string[] }
  is_demo: boolean
}

/**
 * Fetch live figures for a shared link.
 *
 * Arrays go over as repeated `providers[]=` params because that is what Laravel's validator reads back
 * as an array; a comma-joined string would arrive as one nonsense provider name and be intersected
 * away to nothing, which fails silently as "no filter" rather than loudly as an error.
 */
export async function fetchLiveShared(
  token: string,
  opts: { from: string; to: string; providers: string[]; campaigns: string[]; password?: string },
) {
  const qs = new URLSearchParams({ from: opts.from, to: opts.to })
  opts.providers.forEach((p) => qs.append('providers[]', p))
  opts.campaigns.forEach((c) => qs.append('campaigns[]', c))

  const res = await fetch(`/api/v1/reports/shared/${token}/live?${qs.toString()}`, {
    headers: {
      Accept: 'application/json',
      ...(opts.password ? { 'X-Report-Password': opts.password } : {}),
    },
  })
  const body = await res.json()
  return { status: res.status, envelope: body }
}

// ---- Recommendation approval (report annotations) ----------------------------------------------
export interface ReportAnnotation {
  id: string
  annotation_id: string
  type: 'finding' | 'recommendation'
  text_ar: string | null
  platform: string | null
  kpi: string | null
  priority: string
  status: 'draft' | 'reviewed' | 'approved' | 'hidden' | 'rejected'
  is_ai_generated: boolean
  approved_by: number | null
}
export const listAnnotations = (p: string, id: string) =>
  getData<{ annotations: ReportAnnotation[] }>(`${base(p)}/${id}/annotations`)
export const setAnnotationStatus = (p: string, id: string, annId: string, status: string) =>
  postData(`${base(p)}/${id}/annotations/${annId}/status`, { status })

/** REPORT-SCHEDULING: a saved schedule and its honest delivery ledger counts. */
export interface ReportScheduleRow {
  id: string
  name: string
  type: string
  frequency: 'daily' | 'weekly' | 'monthly' | 'custom'
  day: string | null
  time: string | null
  timezone: string | null
  audience: string | null
  language: string | null
  formats: string[]
  recipients: Array<{ email: string; name?: string }>
  cron: string | null
  report_id: string | null
  active: boolean
  is_demo: boolean
  last_run_at: string | null
  next_run_at: string | null
  /** status → count, exactly as recorded. `sent` only ever appears after a provider acknowledgement. */
  deliveries: Record<string, number>
}

export type ReportScheduleInput = Partial<Omit<ReportScheduleRow, 'id' | 'deliveries' | 'is_demo' | 'last_run_at' | 'next_run_at'>>

const schedulesBase = (projectId: string) => `/projects/${projectId}/reports/schedules`

export const listSchedules = (projectId: string) =>
  getData<ReportScheduleRow[]>(schedulesBase(projectId))

export const createSchedule = (projectId: string, input: ReportScheduleInput) =>
  postData<ReportScheduleRow>(schedulesBase(projectId), input as Record<string, unknown>)

export const toggleSchedule = (projectId: string, id: string) =>
  postData<ReportScheduleRow>(`${schedulesBase(projectId)}/${id}/toggle`)

export const runScheduleNow = (projectId: string, id: string) =>
  postData<{ schedule: ReportScheduleRow; report_id: string }>(`${schedulesBase(projectId)}/${id}/run`)

export async function deleteSchedule(projectId: string, id: string): Promise<void> {
  await api.delete(`${schedulesBase(projectId)}/${id}`)
}

// ---- §14.5: what a report covers, and a scope worth using again ---------------------------------

/**
 * The twelve axes, as the API spells them. Empty (or absent) means «no bound on this axis» — which is
 * why the picker sends omitted keys rather than empty arrays for anything it did not narrow.
 */
export interface ReportScopeShape {
  client_ids?: string[]
  project_ids?: string[]
  providers?: string[]
  account_ids?: string[]
  campaign_ids?: string[]
  ad_set_ids?: string[]
  ad_ids?: string[]
  creative_ids?: string[]
  objectives?: string[]
  paths?: string[]
  metrics?: string[]
  from?: string
  to?: string
}

/** What a bound axis actually reaches — `figures` narrows every number, `campaign` resolves upward. */
export interface ScopeExplain {
  axis: string
  count: number
  grain: 'figures' | 'campaign' | 'creatives' | 'projects'
  note_ar: string
  note_en: string
}

export interface ScopeOptions {
  campaigns: Array<{ id: string; name: string; status: string | null; objective: string | null }>
  providers: string[]
  accounts: Array<{ id: string; name: string; provider: string }>
  ad_sets: Array<{ id: string; name: string; provider: string; campaign_id: string }>
  ads: Array<{ id: string; name: string; provider: string; campaign_id: string }>
  creatives: Array<{ id: string; name: string; provider: string; format: string | null; campaign_id: string }>
  objectives: Array<{ key: string; labels: { ar: string; en: string }; path: string }>
  paths: Array<{ key: string; labels: { ar: string; en: string }; headline_metrics: string[] }>
  metrics: Array<{ key: string; ar: string; en: string }>
  grain: { figures: string[]; resolved_to_campaign: string[]; creatives_only: string[] }
  /*
   * REPORT-SCOPE-SELECTION-001 — which axes did not fit.
   *
   * An operator who cannot find their ad set has two possible explanations — it was never synced, or
   * the list stopped — and they lead to opposite actions. Optional because a server that has not
   * shipped this yet reports nothing, and a client that assumed `false` would be stating something
   * it was never told.
   */
  truncated?: { campaigns: boolean; ad_sets: boolean; ads: boolean; creatives: boolean }
  limit?: number
}

export interface ScopeTemplate {
  id: string
  name: string
  description: string | null
  shared: boolean
  scope: ReportScopeShape
  bound_axes: string[]
  explain: ScopeExplain[]
  created_at: string | null
}

export const scopeOptions = (p: string) => getData<ScopeOptions>(`${base(p)}/scope/options`)

export const getReportScope = (p: string, id: string) =>
  getData<{ scope: ReportScopeShape; explain: ScopeExplain[]; bound_axes: string[] }>(`${base(p)}/${id}/scope`)

/** Edits the scope ON the report and regenerates it — the same id, so a link already sent keeps working. */
export const updateReportScope = (p: string, id: string, scope: ReportScopeShape) =>
  putData<{ id: string; scope: ReportScopeShape | null; explain: ScopeExplain[]; status: string }>(
    `${base(p)}/${id}/scope`,
    { scope },
  )

export const listScopeTemplates = (p: string) =>
  getData<{ templates: ScopeTemplate[] }>(`${base(p)}/scope-templates`)

export const createScopeTemplate = (p: string, body: { name: string; description?: string; shared?: boolean; scope: ReportScopeShape }) =>
  postData<ScopeTemplate>(`${base(p)}/scope-templates`, body)

export const deleteScopeTemplate = (p: string, id: string) => api.delete(`${base(p)}/scope-templates/${id}`)
