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

export const REPORT_TYPES = [
  { value: 'executive', label: 'تقرير تنفيذي' },
  { value: 'project', label: 'تقرير مشروع' },
  { value: 'campaign', label: 'تقرير حملة' },
  { value: 'platform', label: 'تقرير منصة' },
  { value: 'platform_comparison', label: 'مقارنة منصات' },
  { value: 'weekly', label: 'تقرير أسبوعي' },
  { value: 'monthly', label: 'تقرير شهري' },
  { value: 'custom', label: 'تقرير مخصص' },
]

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
