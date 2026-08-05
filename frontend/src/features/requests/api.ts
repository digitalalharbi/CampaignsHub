import { api, deleteData, getData, postData } from '@/lib/api/client'

export interface RequestType {
  key: string
  module: string
  name_ar: string
  name_en: string
}

/**
 * A service the form NAMES but cannot open a request against (INFL-SOON-001).
 *
 * Deliberately its own type, fed by its own key on the response, rather than a `disabled` flag on
 * `RequestType`. The list the form submits against then contains only submittable things, so no
 * amount of forgetting to check a boolean can turn an announcement into an order. The reason travels
 * with the row so the message the user sees is the server's sentence, not one this page invented.
 */
export interface ComingSoonType extends RequestType {
  note_ar: string
  note_en: string
}

export interface RequestSubmitPayload {
  type: string
  contact_name: string
  contact_email: string
  contact_phone?: string
  company_name?: string
  objective?: string
  budget?: number | null
  currency?: string
  priority?: 'critical' | 'high' | 'medium' | 'low'
  start_date?: string | null
  due_date?: string | null
  metadata?: Record<string, unknown>
  /** Selected paid-media service keys (engine taxonomy keys) — dynamic intake only. */
  services?: string[]
  /** Dynamic paid-media answers keyed by field token (e.g. budget, site_url, events). */
  service_details?: Record<string, unknown>
  upload_token?: string
  website?: string // honeypot — always empty
  phone_verification_id?: string
  email_verification_id?: string
}

export interface VerificationStart {
  verification_id: string
  channel: string
  destination: string
  delivery_status: string
  dev_code: string | null // non-production only
}

export interface RequestSubmitResult {
  reference: string
  type: string
  status: string
  submitted_at: string | null
  tracking_token: string
  tracking_url: string
  email_delivery: string
  next_step: string
}

export interface RequestTrackResult {
  reference: string
  type: string
  type_ar: string
  status: string
  status_label: string
  /** REQ-LABELS-001 — both languages travel together so a toggle needs no refetch. */
  status_label_en: string
  submitted_at: string | null
  updated_at: string | null
  timeline: { type: string; status: string | null; message: string | null; at: string | null }[]
  comments: { author: string; body: string; at: string | null }[]
  files: { id: number; name: string; size: number }[]
}

export interface UploadedFileMeta {
  id: number
  original_name: string
  size: number
  mime: string | null
}

export const getRequestMeta = () =>
  getData<{ types: RequestType[]; coming_soon?: ComingSoonType[] }>('/requests/meta')

/** Start an expiring upload session; the token is held in memory only (never localStorage). */
export const startUploadSession = () => postData<{ upload_token: string; expires_at: string }>('/requests/uploads/start')

export async function uploadRequestFile(uploadToken: string, file: File, onProgress?: (pct: number) => void): Promise<UploadedFileMeta> {
  const body = new FormData()
  body.append('upload_token', uploadToken)
  body.append('file', file)
  const res = await api.post<{ data: UploadedFileMeta }>('/requests/uploads', body, {
    onUploadProgress: (e) => { if (onProgress && e.total) onProgress(Math.round((e.loaded / e.total) * 100)) },
  })
  return res.data.data
}

export const deleteUploadFile = (uploadToken: string, fileId: number) =>
  deleteData<{ status: string }>(`/requests/uploads/${fileId}`, { upload_token: uploadToken })

export const submitRequest = (payload: RequestSubmitPayload) => postData<RequestSubmitResult>('/requests', payload)

/** Start an OTP challenge for a phone (sms/whatsapp) or email destination. */
export const startVerification = (channel: 'sms' | 'whatsapp' | 'email', destination: string, purpose = 'contact_verify') =>
  postData<VerificationStart>('/requests/verify/start', { channel, destination, purpose })

/** Verify an OTP code; returns the verified verification id. */
export const checkVerification = (verification_id: string, code: string) =>
  postData<{ verification_id: string; verified: boolean }>('/requests/verify/check', { verification_id, code })

export const trackRequest = (token: string) => getData<RequestTrackResult>(`/requests/track/${encodeURIComponent(token)}`)

export const replyToRequest = (token: string, message: string, upload_token?: string) =>
  postData<{ status: string }>(`/requests/track/${encodeURIComponent(token)}/reply`, { message, upload_token })

/** Secure download URL for a client-visible file (goes through the token route, not storage). */
export const trackFileUrl = (token: string, fileId: number) =>
  `/api/v1/requests/track/${encodeURIComponent(token)}/files/${fileId}`
