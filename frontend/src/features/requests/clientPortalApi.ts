import { api, getData, postData } from '@/lib/api/client'

/**
 * Client Portal API. Auth is the httpOnly `client_portal` cookie set on login — it flows automatically with
 * `withCredentials` and is never read/stored in JS (no localStorage token). Every payload is client-safe.
 */

export interface PortalLoginStart {
  verification_id: string
  delivery_status: string
  dev_code: string | null
}

export const portalLoginStart = (channel: 'sms' | 'whatsapp' | 'email', destination: string) =>
  postData<PortalLoginStart>('/client/login/start', { channel, destination })

export const portalLoginVerify = (verification_id: string, code: string) =>
  postData<{ authenticated: boolean; contact: string; dev_token: string | null }>('/client/login/verify', { verification_id, code })

export const portalSession = () => getData<{ contact_email: string | null; contact_phone: string | null }>('/client/session')
export const portalLogout = () => postData<{ ok: boolean }>('/client/logout')

export interface PortalRequestCard {
  reference: string
  type: string
  type_ar: string
  status: string
  status_label: string
  /** REQ-LABELS-001 — both languages travel together so a toggle needs no refetch. */
  status_label_en: string
  progress: number
  submitted_at: string | null
  updated_at: string | null
}

export const listPortalRequests = () => getData<{ requests: PortalRequestCard[] }>('/client/requests')

export interface PortalRequestDetail extends PortalRequestCard {
  timeline: { type: string; status: string | null; message: string | null; at: string | null }[]
  comments: { author: string; body: string; at: string | null }[]
  files: { id: number; name: string; size: number }[]
  result: { converted: boolean; project_name: string | null; campaign_name: string | null; campaign_status: string | null } | null
  notifications: { event: string; channel: string; status: string; at: string | null }[]
}

export const getPortalRequest = (reference: string) =>
  getData<PortalRequestDetail>(`/client/requests/${encodeURIComponent(reference)}`)

export const portalReply = (reference: string, message: string, upload_token?: string) =>
  postData<{ status: string }>(`/client/requests/${encodeURIComponent(reference)}/reply`, { message, upload_token })

export const portalFileUrl = (reference: string, fileId: number) =>
  `/api/v1/client/requests/${encodeURIComponent(reference)}/files/${fileId}`

// Portal uploads reuse the public upload-session endpoints (private disk, token in memory only).
export const portalStartUpload = () => postData<{ upload_token: string; expires_at: string }>('/requests/uploads/start')
export async function portalUploadFile(uploadToken: string, file: File): Promise<{ id: number; original_name: string }> {
  const body = new FormData()
  body.append('upload_token', uploadToken)
  body.append('file', file)
  const res = await api.post<{ data: { id: number; original_name: string } }>('/requests/uploads', body)
  return res.data.data
}
