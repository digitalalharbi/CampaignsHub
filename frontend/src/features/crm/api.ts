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

/**
 * LEAD-OPERATIONS-001 — the follow-up workspace.
 *
 * `FollowUpWorkspace` has been served at `GET /leads/workspace` since it shipped, tested, and called
 * by nobody: every figure it computes — unassigned, overdue, never contacted, the rates, the median
 * first response — reached only the daily digest. This is the client for it.
 *
 * A rate is `null` where its denominator was zero, and stays null all the way to the screen: «0%
 * contacted» out of no leads is a verdict on nothing.
 */
export interface FollowUpSummary {
  window: { from: string; to: string }
  received: number
  unassigned: number
  contacted: number
  not_contacted: number
  qualified: number
  appointments: number
  won: number
  lost: number
  invalid: number
  overdue: number
  /** «all_open» — overdue is asked of the whole pipeline, not of the window. The payload says so. */
  overdue_scope: string
  contact_rate: number | null
  qualification_rate: number | null
  appointment_rate: number | null
  win_rate: number | null
  first_response: { median_minutes: number | null; measured: number; of: number }
}

export interface FollowUpOwnerRow extends FollowUpSummary {
  owner_id: number | null
  /** Staff, not lead PII. Null for the unassigned bucket, which is a row and not a person. */
  owner_name: string | null
}

export interface FollowUpWorkspaceResult {
  summary: FollowUpSummary
  /** Null for a reader who does not run the pipeline — a colleague league table nobody asked for. */
  by_owner: FollowUpOwnerRow[] | null
}

export async function fetchFollowUpWorkspace(params: {
  from?: string
  to?: string
  project_id?: string
}): Promise<FollowUpWorkspaceResult> {
  const query = new URLSearchParams(
    Object.entries(params).filter((entry): entry is [string, string] => typeof entry[1] === 'string'),
  ).toString()

  return getData<FollowUpWorkspaceResult>(`/leads/workspace${query ? `?${query}` : ''}`)
}
