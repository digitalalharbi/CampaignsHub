import { api, getData, patchData, postData } from '@/lib/api/client'

export interface RequestRow {
  id: string
  reference: string
  service: string
  service_ar: string
  module: string
  status: string
  /** REQ-LABELS-001 — Arabic and English both arrive; the reader's locale picks. */
  status_label: string
  status_label_en: string
  priority: string
  priority_label: string
  priority_label_en: string
  contact: string
  assignee: string | null
  assigned_to: number | null
  source: string
  sla_due_at: string | null
  sla_breached: boolean
  submitted_at: string | null
  last_activity_at: string | null
}

export interface RequestListResult {
  data: RequestRow[]
  meta: { total: number; per_page: number; current_page: number; last_page: number }
}

export interface RequestComment { id: number; visibility: 'internal' | 'client'; author: string; body: string; at: string | null }
export interface RequestEvent { type: string; from: string | null; to: string | null; message: string | null; client_visible: boolean; at: string | null }

export interface ConversionResult { status: string; client_id: string | null; project_id: string | null; campaign_id: string | null }

export interface RequestDetail extends RequestRow {
  objective: string | null
  contact_email: string
  contact_phone: string | null
  company_name: string | null
  budget: string | null
  currency: string
  metadata: Record<string, unknown> | null
  sla: { due_at: string | null; started_at: string | null; paused_at: string | null; breached_at: string | null; remaining_seconds: number | null }
  comments: RequestComment[]
  events: RequestEvent[]
  files: { id: number; name: string; size: number; client_visible: boolean }[]
  archived_at: string | null
  conversion: { client_id: string; project_id: string; campaign_id: string; completed_at: string | null } | null
  /** Canonical selected services resolved to display labels (taxonomy engine). */
  services_resolved?: { key: string; label_en?: string | null; label_ar?: string | null }[]
  /** Quotes raised from this request, each with its issued invoice (if any). */
  billing?: {
    quote_id: string
    number: string
    status: string
    total: string
    currency: string
    tax_treatment: string | null
    invoice: { invoice_id: string; number: string; status: string; total: string; amount_paid: string } | null
  }[]
}

export interface RequestFilters {
  status?: string
  priority?: string
  q?: string
  page?: number
  per_page?: number
}

/** Allowed forward transitions (mirrors the backend RequestStatusMachine) — for Kanban drop validation. */
/**
 * A MIRROR of `RequestStatusMachine::FORWARD`, kept only so the board can grey out a lane you cannot
 * drop into. The backend is the authority and rejects anything this map gets wrong — this exists to
 * avoid offering a move that is about to fail, never to decide whether it may happen.
 */
export const ALLOWED_TRANSITIONS: Record<string, string[]> = {
  new: ['under_review', 'qualified', 'rejected', 'cancelled'],
  under_review: ['waiting_client', 'qualified', 'on_hold', 'rejected', 'cancelled'],
  waiting_client: ['under_review', 'qualified', 'cancelled'],
  qualified: ['quoted', 'approved', 'waiting_client', 'on_hold', 'rejected', 'cancelled'],
  quoted: ['approved', 'waiting_client', 'on_hold', 'rejected', 'cancelled'],
  approved: ['in_progress', 'on_hold', 'cancelled'],
  in_progress: ['delivered', 'waiting_client', 'completed', 'on_hold', 'cancelled'],
  delivered: ['completed', 'in_progress', 'waiting_client', 'cancelled'],
  on_hold: ['under_review', 'qualified', 'quoted', 'approved', 'in_progress', 'cancelled'],
  completed: ['archived'],
  rejected: ['archived'],
  cancelled: ['archived'],
  archived: [],
}

/** The list endpoint returns { data, meta }; fetch the full envelope (getData would strip meta). */
export async function listRequests(filters: RequestFilters): Promise<RequestListResult> {
  const params = new URLSearchParams()
  if (filters.status) params.set('status', filters.status)
  if (filters.priority) params.set('priority', filters.priority)
  if (filters.q) params.set('q', filters.q)
  if (filters.page) params.set('page', String(filters.page))
  if (filters.per_page) params.set('per_page', String(filters.per_page))
  const qs = params.toString()
  const res = await api.get<RequestListResult>(`/app/requests${qs ? `?${qs}` : ''}`)
  return res.data
}

export const getRequest = (id: string) => getData<RequestDetail>(`/app/requests/${id}`)

export const assignRequest = (id: string, assignee_id: number | null) => patchData<{ status: string }>(`/app/requests/${id}/assign`, { assignee_id })
export const changeRequestStatus = (id: string, status: string, reason?: string) => patchData<{ status: string }>(`/app/requests/${id}/status`, { status, reason })
export const changeRequestPriority = (id: string, priority: string) => patchData<{ status: string }>(`/app/requests/${id}/priority`, { priority })
export const requestInformation = (id: string, message: string) => postData<{ status: string }>(`/app/requests/${id}/request-information`, { message })
export const addInternalNote = (id: string, body: string) => postData<{ status: string }>(`/app/requests/${id}/internal-note`, { body })
export const replyToClientInternal = (id: string, body: string) => postData<{ status: string }>(`/app/requests/${id}/reply`, { body })
export const archiveRequest = (id: string) => patchData<{ status: string }>(`/app/requests/${id}/archive`, {})
export const convertRequest = (id: string, client_id?: string) => postData<ConversionResult>(`/app/requests/${id}/convert`, client_id ? { client_id } : {})

/** Raise a draft quote FROM this request (services become line items; backend derives tax from treatment). */
export const raiseQuoteFromRequest = (id: string, body?: { subtotal?: number; tax_treatment?: string }) =>
  postData<{ quote_id: string; number: string; status: string; total: string; currency: string }>(`/app/requests/${id}/quote`, body ?? {})
