import { getData, postData } from '@/lib/api/client'

export interface RequestType {
  key: string
  module: string
  name_ar: string
  name_en: string
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
  website?: string // honeypot — always empty
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
  submitted_at: string | null
  updated_at: string | null
  timeline: { type: string; status: string | null; message: string | null; at: string | null }[]
  comments: { author: string; body: string; at: string | null }[]
}

export const getRequestMeta = () => getData<{ types: RequestType[] }>('/requests/meta')

export const submitRequest = (payload: RequestSubmitPayload) => postData<RequestSubmitResult>('/requests', payload)

export const trackRequest = (token: string) => getData<RequestTrackResult>(`/requests/track/${encodeURIComponent(token)}`)
