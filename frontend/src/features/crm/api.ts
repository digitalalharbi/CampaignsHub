import { api, ensureCsrfCookie, getData, postData } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'
import type { Lead, Opportunity, Pagination } from './types'

export interface LeadListParams {
  status?: string
  source?: string
  search?: string
  per_page?: number
  /** LEAD-DEDUP-001 — narrow the LIST to canonicals. Both counts come back either way. */
  unique?: 1
}

/**
 * «Received» and «unique» are different figures and the server reports both.
 *
 * One `total` would have to be one of them, and whichever it was would be wrong for the other
 * question — under-reporting a campaign's volume or over-reporting its audience, with nothing on
 * screen to say which. Optional because a server that has not shipped this yet returns neither, and
 * a client that invented a zero would be stating a fact it was never told.
 */
export interface LeadCounts {
  received: number
  unique: number
}

export interface LeadListResult {
  leads: Lead[]
  pagination: Pagination
  counts: LeadCounts | null
}

export async function listLeads(params: LeadListParams): Promise<LeadListResult> {
  const response = await api.get<ApiEnvelope<Lead[]>>('/leads', { params })
  const counts = response.data.meta.counts as LeadCounts | undefined

  return {
    leads: response.data.data,
    counts: counts ?? null,
    pagination: (response.data.meta.pagination as Pagination) ?? {
      total: response.data.data.length,
      per_page: 15,
      current_page: 1,
      last_page: 1,
    },
  }
}

export function getLead(id: string): Promise<Lead> {
  return getData<Lead>(`/leads/${id}`)
}

export interface CreateLeadInput {
  name: string
  email?: string
  phone?: string
  source: string
  status?: string
  estimated_value?: number
  currency?: string
  notes?: string
}

export async function createLead(input: CreateLeadInput): Promise<Lead> {
  await ensureCsrfCookie()
  return postData<Lead>('/leads', input)
}

export async function convertLead(id: string): Promise<Opportunity> {
  await ensureCsrfCookie()
  return postData<Opportunity>(`/leads/${id}/convert`)
}

export function listOpportunities(): Promise<Opportunity[]> {
  return getData<Opportunity[]>('/opportunities')
}
