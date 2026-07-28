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
