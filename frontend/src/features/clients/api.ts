import { api, getData } from '@/lib/api/client'

export interface ClientCard {
  id: string
  name: string
  client_status: string | null
  service_level: string | null
  industry: string | null
  projects: number
  active_campaigns: number
  open_requests: number
}

export interface ClientListResult {
  data: ClientCard[]
  meta: { total: number; per_page: number; current_page: number; last_page: number }
}

export interface ClientDetail {
  id: string
  name: string
  client_status: string | null
  service_level: string | null
  industry: string | null
  source: string | null
  overview: { projects: number; active_campaigns: number; draft_campaigns: number; open_requests: number }
  projects: { id: string; name: string; status: string; created_at: string | null }[]
  campaigns: { id: string; project_id: string; name: string; objective: string; status: string; budget: string | null; currency: string }[]
  requests: { id: string; reference: string; service: string; status: string; submitted_at: string | null }[]
}

export async function listClients(filters: { status?: string; service_level?: string; q?: string; page?: number }): Promise<ClientListResult> {
  const params = new URLSearchParams()
  if (filters.status) params.set('status', filters.status)
  if (filters.service_level) params.set('service_level', filters.service_level)
  if (filters.q) params.set('q', filters.q)
  if (filters.page) params.set('page', String(filters.page))
  const qs = params.toString()
  const res = await api.get<ClientListResult>(`/app/clients${qs ? `?${qs}` : ''}`)
  return res.data
}

export const getClient = (id: string) => getData<ClientDetail>(`/app/clients/${id}`)
