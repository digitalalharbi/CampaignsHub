import { getData, postData } from '@/lib/api/client'
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

/** Public (unauthenticated) shared report fetch. Sends the optional password via header. */
export async function fetchSharedReport(token: string, password?: string) {
  const res = await fetch(`/api/v1/reports/shared/${token}`, {
    headers: { Accept: 'application/json', ...(password ? { 'X-Report-Password': password } : {}) },
  })
  const body = await res.json()
  return { status: res.status, envelope: body }
}
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
export interface LivePayload {
  period: { from: string; to: string; days: number }
  currency: string
  totals: Record<string, number | null>
  deltas: Record<string, number | null>
  timeseries: Array<Record<string, unknown>>
  platforms: Array<Record<string, unknown> & { provider: string; spend: number | null }>
  campaigns: Array<Record<string, unknown> & { campaign_name: string | null; provider: string | null; spend: number | null }>
  funnel: Array<{ stage: string; label: string; count: number; step_rate: number | null; cost_per: number | null }>
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
