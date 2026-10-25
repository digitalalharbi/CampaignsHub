import { api, getData, patchData, postData, putData } from '@/lib/api/client'

/** WD-PACK-001 — the weekly delivery, as the agency API answers it. */
export interface PackReadiness {
  report: string
  undecided_recommendations: number
  approved_recommendations: number
  evidence: { included: boolean; count: number }
  invoice: { included: boolean; status: string }
  approved: boolean
  ready_to_approve: boolean
}

export interface DeliveryPack {
  id: string
  client_workspace_id: string
  project_id: string
  report_id: string | null
  invoice_id: number | null
  period: { from: string; to: string }
  status: 'draft' | 'approved' | 'sent'
  components: Record<string, boolean>
  approved_at: string | null
  sent_at: string | null
  readiness: PackReadiness
  client?: string | null
}

export interface PackRecommendation {
  id: string
  platform: string | null
  text_ar: string | null
  text_en: string | null
  proposed_action: string | null
  priority: string | null
  status: 'draft' | 'reviewed' | 'approved' | 'hidden' | 'rejected'
  source: string | null
}

export interface PackEvidence {
  id: string
  provider: string
  period_start: string
  period_end: string
  original_name: string
  mime: string
  size: number
  note: string | null
  include_in_report: boolean
}

export const NOTE_KINDS = ['observation', 'challenge', 'opportunity', 'recommendation'] as const
export type NoteKind = (typeof NOTE_KINDS)[number]
/** DELIVERY-NOTES-001 — a note the Owner wrote. `audience: internal` never reaches a client surface. */
export interface PackNote {
  id: string
  kind: NoteKind
  text_ar: string | null
  text_en: string | null
  audience: 'client' | 'internal'
  position: number | null
  status: string
  version: number
}

export interface PackDetail extends DeliveryPack {
  recommendations: PackRecommendation[]
  notes: PackNote[]
  evidence: PackEvidence[]
}

export const COMPONENT_KEYS = ['whatsapp', 'link', 'report_pdf', 'evidence', 'invoice_pdf', 'amount_due', 'spend'] as const

export const listPacks = (clientId: string) => getData<DeliveryPack[]>(`/delivery/packs?client=${encodeURIComponent(clientId)}`)
export const getPack = (id: string) => getData<PackDetail>(`/delivery/packs/${id}`)
export const preparePack = (projectId: string) => postData<DeliveryPack>('/delivery/packs', { project_id: projectId })
export const setComponents = (id: string, components: Record<string, boolean>) => putData<DeliveryPack>(`/delivery/packs/${id}/components`, { components })
export const attachInvoice = (id: string, invoiceId: number) => postData<DeliveryPack>(`/delivery/packs/${id}/invoice`, { invoice_id: invoiceId })
export const approvePack = (id: string) => postData<DeliveryPack>(`/delivery/packs/${id}/approve`)
export const markPackSent = (id: string) => postData<DeliveryPack>(`/delivery/packs/${id}/sent`)
export const whatsappText = (id: string, lang: 'ar' | 'en') => getData<{ text: string }>(`/delivery/packs/${id}/whatsapp?lang=${lang}`).then((d) => d.text)
export const editRecommendation = (packId: string, annotationId: string, body: { text_ar?: string; text_en?: string }) =>
  patchData<PackRecommendation>(`/delivery/packs/${packId}/recommendations/${annotationId}`, body)
export const setRecommendationStatus = (projectId: string, reportId: string, annotationId: string, status: PackRecommendation['status']) =>
  postData<PackRecommendation>(`/projects/${projectId}/reports/${reportId}/annotations/${annotationId}/status`, { status })

export function uploadEvidence(input: {
  clientId: string
  projectId: string
  provider: string
  from: string
  to: string
  file: File
  note?: string
}) {
  const form = new FormData()
  form.set('client_workspace_id', input.clientId)
  form.set('project_id', input.projectId)
  form.set('provider', input.provider)
  form.set('period_start', input.from)
  form.set('period_end', input.to)
  form.set('file', input.file)
  if (input.note) form.set('note', input.note)

  return api.post('/delivery/evidence', form).then((r) => r.data.data as PackEvidence)
}

export const deleteEvidence = (id: string) => api.delete(`/delivery/evidence/${id}`)
export const evidenceFileUrl = (id: string) => `/api/v1/delivery/evidence/${id}/file`

/** DELIV-DASH-001 — one row per client: where the weekly delivery and the money stand, and the one next step. */
export type NextAction = 'prepare_pack' | 'review_pack' | 'approve_pack' | 'send_pack' | 'follow_up_payment' | 'none'

export interface DeliveryOverviewRow {
  client_id: string
  client: string
  pack: { id: string; period: { from: string; to: string }; status: 'draft' | 'approved' | 'sent'; sent_at: string | null } | null
  report: { status: string; undecided_recommendations: number } | null
  evidence: number | null
  last_viewed_at: string | null
  /** Null when the reader may not see this client's billing — never a zero. */
  invoice: { id: number; number: string; status: string; currency: string; total: number; amount_due: number | null; due_date: string | null } | null
  follow_up_required: boolean
  next_action: NextAction
  visual: 'ok' | 'attention' | 'urgent'
}

export const getDeliveryOverview = () => getData<DeliveryOverviewRow[]>('/delivery/overview')


/** DELIVERY-SETTINGS-001 — how a client's periodic delivery is prepared. */
export interface DeliverySettingsData {
  enabled: boolean
  cadence: 'weekly' | 'monthly'
  week_start: 'monday' | 'sunday'
  mode: 'snapshot' | 'live'
  components: Record<string, boolean>
  reviewer_id: number | null
  reminder_hours: number
  reviewers: { id: number; name: string }[]
  next_period: { from: string; to: string }
}
export const getDeliverySettings = (clientId: string) => getData<DeliverySettingsData>(`/delivery/settings?client=${encodeURIComponent(clientId)}`)
export const saveDeliverySettings = (clientId: string, body: Partial<Omit<DeliverySettingsData, 'next_period' | 'reviewers'>>) =>
  putData<DeliverySettingsData>('/delivery/settings', { client_workspace_id: clientId, ...body })

export const addNote = (packId: string, body: { kind: NoteKind; text_ar?: string; text_en?: string; audience: PackNote['audience'] }) => postData<PackNote>(`/delivery/packs/${packId}/notes`, body)
export const updateNote = (packId: string, id: string, body: Partial<Pick<PackNote, 'kind' | 'text_ar' | 'text_en' | 'audience'>>) => patchData<PackNote>(`/delivery/packs/${packId}/notes/${id}`, body)
export const deleteNote = (packId: string, id: string) => api.delete(`/delivery/packs/${packId}/notes/${id}`)
export const orderNotes = (packId: string, ids: string[]) => putData<PackNote[]>(`/delivery/packs/${packId}/notes/order`, { ids })
