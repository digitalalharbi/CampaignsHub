import { api, ensureCsrfCookie, getData, postData } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'
import type { Lead, Opportunity, Pagination } from './types'

export interface LeadListParams {
  status?: string
  source?: string
  search?: string
  per_page?: number
}

export interface LeadListResult {
  leads: Lead[]
  pagination: Pagination
}

export async function listLeads(params: LeadListParams): Promise<LeadListResult> {
  const response = await api.get<ApiEnvelope<Lead[]>>('/leads', { params })
  return {
    leads: response.data.data,
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
