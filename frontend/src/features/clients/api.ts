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
