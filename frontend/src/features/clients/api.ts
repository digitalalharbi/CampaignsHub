import { api, getData } from '@/lib/api/client'

export interface ClientCard {
  id: string
  name: string
  client_status: string | null
  service_level: string | null
  industry: string | null
  priority: string | null
  owner_id: number | null
  is_archived: boolean
  projects: number
  active_campaigns: number
  open_requests: number
  alerts: number
  spend: number | null
  spend_currency_mode: 'single' | 'mixed' | 'none'
  currency: string | null
  data_sources: string[]
  last_report_at: string | null
  last_sync_at: string | null
}

export interface ClientListResult {
  data: ClientCard[]
  meta: { total: number; per_page: number; current_page: number; last_page: number }
}

export interface ClientClassification {
  client_status: string | null
  service_level: string | null
  industry: string | null
  owner_id: number | null
  owner_name: string | null
  priority: string | null
  default_currency: string | null
  timezone: string | null
  language: string | null
  week_start: string | null
}

export interface ClientDetail {
  id: string
  name: string
  client_status: string | null
  service_level: string | null
  industry: string | null
  source: string | null
  classification: ClientClassification
  is_archived: boolean
  archived_at: string | null
  can: {
    update: boolean
    manage_settings: boolean
    manage_team: boolean
    archive: boolean
    view_analytics: boolean
    view_reports: boolean
    manage_files: boolean
  }
  overview: { projects: number; active_campaigns: number; draft_campaigns: number; open_requests: number }
  projects: { id: string; name: string; status: string; created_at: string | null }[]
  campaigns: { id: string; project_id: string; name: string; objective: string; status: string; budget: string | null; currency: string }[]
  requests: { id: string; reference: string; service: string; status: string; submitted_at: string | null }[]
}

export interface ClientTaxonomy {
  client_statuses: string[]
  service_levels: string[]
  industries: string[]
  access_roles: string[]
  priorities: string[]
  assignable_users: { id: number; name: string; email: string }[]
}

export interface ClientFilters {
  status?: string
  service_level?: string
  industry?: string
  owner_id?: number
  q?: string
  needs_attention?: boolean
  has_open_requests?: boolean
  has_active_campaigns?: boolean
  include_archived?: boolean
  sort?: string
  page?: number
}

export async function listClients(filters: ClientFilters): Promise<ClientListResult> {
  const params = new URLSearchParams()
  const set = (k: string, v: unknown) => { if (v !== undefined && v !== '' && v !== false) params.set(k, String(v)) }
  set('status', filters.status)
  set('service_level', filters.service_level)
  set('industry', filters.industry)
  set('owner_id', filters.owner_id)
  set('q', filters.q)
  set('needs_attention', filters.needs_attention)
  set('has_open_requests', filters.has_open_requests)
  set('has_active_campaigns', filters.has_active_campaigns)
  set('include_archived', filters.include_archived)
  set('sort', filters.sort)
  set('page', filters.page)
  const qs = params.toString()
  const res = await api.get<ClientListResult>(`/app/clients${qs ? `?${qs}` : ''}`)
  return res.data
}

export const getClient = (id: string) => getData<ClientDetail>(`/app/clients/${id}`)
export const getTaxonomy = () => getData<ClientTaxonomy>(`/app/clients/meta/taxonomy`)

export async function updateClassification(id: string, patch: Partial<ClientClassification>): Promise<ClientClassification> {
  const res = await api.patch<{ data: { classification: ClientClassification } }>(`/app/clients/${id}/classification`, patch)
  return res.data.data.classification
}

export interface ClientSettingsPatch {
  name?: string
  branding?: { logo_url?: string | null }
  settings?: Record<string, unknown>
}

export async function updateSettings(id: string, patch: ClientSettingsPatch) {
  const res = await api.patch(`/app/clients/${id}/settings`, patch)
  return res.data
}

export const archiveClient = (id: string, reason?: string) => api.post(`/app/clients/${id}/archive`, { reason })
export const restoreClient = (id: string) => api.post(`/app/clients/${id}/restore`, {})

export interface ClientAnalytics {
  range: { from: string; to: string }
  source_of_truth: string
  currency_mode: 'single' | 'mixed' | 'none'
  currency: string | null
  money_blended: boolean
  objective_mix: { objective: string; count: number }[]
  roas_is_primary: boolean
  totals: Record<string, number | null> | null
  previous: Record<string, number | null> | null
  delta: Record<string, number | null> | null
  counts?: { impressions: number; clicks: number; conversions: number }
  platforms: { provider: string; spend: number; spend_share: number; impressions: number; clicks: number; conversions: number; revenue: number; roas: number | null; ctr: number | null; cpc: number | null; cpm: number | null; cpa: number | null }[]
  projects: { project_id: string; name: string; spend: number; currency: string | null }[]
  timeseries: { date: string; spend: number; clicks: number; impressions: number; conversions: number; revenue: number }[]
  best_campaign: Record<string, unknown> | null
  worst_campaign: Record<string, unknown> | null
  freshness: { state: 'fresh' | 'partial' | 'stale' | 'sync_failed' | 'no_data'; last_sync_at: string | null; missing_days: number; sync_failed: boolean }
  attribution: { windows: string[] }
}

export const getClientAnalytics = (id: string, from?: string, to?: string) => {
  const p = new URLSearchParams()
  if (from) p.set('from', from)
  if (to) p.set('to', to)
  const qs = p.toString()
  return getData<ClientAnalytics>(`/app/clients/${id}/analytics${qs ? `?${qs}` : ''}`)
}

export interface ClientReport {
  id: string
  project_id: string
  name: string
  type: string
  audience: 'client' | 'internal' | 'executive'
  status: 'draft' | 'processing' | 'completed' | 'failed'
  shareable: boolean
  formats: string[]
  generated_at: string | null
  created_at: string | null
}

export const listClientReports = (id: string) =>
  getData<{ reports: ClientReport[] }>(`/app/clients/${id}/reports`)

export interface CreateReportInput {
  project_id: string
  name: string
  type: string
  audience?: 'client' | 'internal' | 'executive'
  campaign_objective?: string
}

export async function createClientReport(id: string, input: CreateReportInput): Promise<ClientReport> {
  const res = await api.post<{ data: ClientReport }>(`/app/clients/${id}/reports`, input)
  return res.data.data
}

export async function shareClientReport(id: string, reportId: string, opts: { allow_download?: boolean } = {}) {
  const res = await api.post<{ data: { id: string; url: string; token: string } }>(`/app/clients/${id}/reports/${reportId}/share`, opts)
  return res.data.data
}

// ---- Team access ----
export interface ClientMember {
  user_id: number
  name: string
  email: string
  access_role: string
  project_ids: string[] | null
  granted_at: string | null
}
export const listClientTeam = (id: string) => getData<{ members: ClientMember[] }>(`/app/clients/${id}/team`)
export const listAssignableUsers = (id: string) => getData<{ assignable: { id: number; name: string; email: string }[] }>(`/app/clients/${id}/team/assignable`)
export const grantClientAccess = (id: string, body: { user_id: number; access_role: string; project_ids?: string[] }) => api.post(`/app/clients/${id}/team`, body)
export const updateClientAccess = (id: string, userId: number, body: { access_role?: string; project_ids?: string[] | null }) => api.patch(`/app/clients/${id}/team/${userId}`, body)
export const removeClientAccess = (id: string, userId: number) => api.delete(`/app/clients/${id}/team/${userId}`)

// ---- Files ----
export interface ClientFile {
  source: 'request' | 'report'
  id: string
  name: string
  type: string | null
  size: number | null
  visibility: 'client_visible' | 'internal'
  checksum: string | null
  uploaded_at: string | null
  uploader: string | null
  related_entity: { type: string; label: string | null }
  download_url: string
}
export const listClientFiles = (id: string) => getData<{ files: ClientFile[] }>(`/app/clients/${id}/files`)

// ---- Activity ----
export interface ActivityItem {
  id: string
  action: string
  actor: string | null
  source: string
  time: string | null
  old: Record<string, unknown> | null
  new: Record<string, unknown> | null
  related_entity: { type: string | null; id: string | null }
}
export const listClientActivity = (id: string) => getData<{ timeline: ActivityItem[] }>(`/app/clients/${id}/activity`)
