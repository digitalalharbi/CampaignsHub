import { deleteData, getData, getEnvelope, patchData, postData } from '@/lib/api/client'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { Answers, ConversionDelivery, ConversionDestination, ConversionKind, FormDefinition, LeadFormDetail, LeadFormRow } from './types'

/** FORMS-HUB-001 — the team's side, project-scoped. */
const base = (projectId: string) => `/projects/${projectId}/forms`

export const formsKey = (projectId: string | null) => ['lead-forms', projectId] as const
export const formKey = (projectId: string | null, id: string | undefined) => ['lead-form', projectId, id] as const

export function useForms(projectId: string | null) {
  return useQuery({ queryKey: formsKey(projectId), queryFn: () => getData<LeadFormRow[]>(base(projectId as string)), enabled: Boolean(projectId) })
}

export function useForm(projectId: string | null, id: string | undefined) {
  return useQuery({ queryKey: formKey(projectId, id), queryFn: () => getData<LeadFormDetail>(`${base(projectId as string)}/${id}`), enabled: Boolean(projectId && id) })
}

export interface SaveFormPayload {
  name: string
  definition: FormDefinition
  default_owner_id?: number | null
  retention_days?: number | null
}

export function useCreateForm(projectId: string | null) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (p: SaveFormPayload) => postData<LeadFormRow>(base(projectId as string), p),
    onSuccess: () => qc.invalidateQueries({ queryKey: formsKey(projectId) }),
  })
}

export function useUpdateForm(projectId: string | null, id: string | undefined) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (p: Partial<SaveFormPayload>) => patchData<LeadFormRow>(`${base(projectId as string)}/${id}`, p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: formsKey(projectId) }); qc.invalidateQueries({ queryKey: formKey(projectId, id) }) },
  })
}

export function useFormAction(projectId: string | null, id: string | undefined, action: 'publish' | 'archive') {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => postData<LeadFormRow>(`${base(projectId as string)}/${id}/${action}`, {}),
    onSuccess: () => { qc.invalidateQueries({ queryKey: formsKey(projectId) }); qc.invalidateQueries({ queryKey: formKey(projectId, id) }) },
  })
}

/** FORMS-HUB-001 — the public side: the published definition, and one submission. No sign-in. */
export interface PublicForm { name: string; version: number; definition: FormDefinition; tracking?: { gtm_container_id: string | null } }

export function fetchPublicForm(slug: string): Promise<PublicForm> {
  return getData<PublicForm>(`/public/forms/${slug}`)
}

export interface SubmitPayload {
  submission_id: string
  answers: Answers
  consent: { accepted: boolean; measurement?: boolean }
  attribution: Record<string, string>
  session_id: string
  /** The honeypot: a field no person sees. Always empty from this page. */
  website: string
}

export function submitPublicForm(slug: string, payload: SubmitPayload): Promise<{ accepted: boolean; duplicate: boolean; reference?: string; event_id?: string }> {
  return postData(`/public/forms/${slug}/submissions`, payload)
}

/** FORMS-FUNNEL-EVENTS-001 — the visit as the browser saw it. Fire and forget: a lost beacon must never break the form. */
export interface FunnelBeacon {
  event: 'page_view' | 'form_view' | 'form_start' | 'step_completed' | 'submit_attempt'
  event_id: string
  session_id: string
  step?: number
  consent: 'granted' | 'unknown'
  attribution: Record<string, string>
}

export function sendFunnelBeacon(slug: string, beacon: FunnelBeacon): void {
  postData(`/public/forms/${slug}/events`, beacon).catch(() => undefined)
}

/** FORMS-DISPATCH-001 — the team's side: destinations and the ledger. */
const dest = (projectId: string) => `/projects/${projectId}/conversion-destinations`
export const destinationsKey = (projectId: string | null) => ['conversion-destinations', projectId] as const
export const deliveriesKey = (projectId: string | null) => ['conversion-deliveries', projectId] as const

export function useDestinations(projectId: string | null) {
  return useQuery({ queryKey: destinationsKey(projectId), queryFn: () => getEnvelope<ConversionDestination[]>(dest(projectId as string)), enabled: Boolean(projectId) })
}

export function useDeliveries(projectId: string | null, destinationId?: string) {
  return useQuery({
    queryKey: [...deliveriesKey(projectId), destinationId ?? 'all'],
    queryFn: () => getData<ConversionDelivery[]>(`/projects/${projectId}/conversion-deliveries${destinationId ? `?destination=${destinationId}` : ''}`),
    enabled: Boolean(projectId),
  })
}

export interface CreateDestination { kind: ConversionKind; name: string; lead_form_id?: string | null; config: Record<string, string>; secrets?: Record<string, string> }

export function useCreateDestination(projectId: string | null) {
  const qc = useQueryClient()
  return useMutation({ mutationFn: (p: CreateDestination) => postData<ConversionDestination>(dest(projectId as string), p), onSuccess: () => qc.invalidateQueries({ queryKey: destinationsKey(projectId) }) })
}

export function useUpdateDestination(projectId: string | null) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...p }: { id: string; status?: 'test' | 'live' | 'off'; confirm_live?: boolean; secrets?: Record<string, string>; config?: Record<string, string>; name?: string }) => patchData<ConversionDestination>(`${dest(projectId as string)}/${id}`, p),
    onSuccess: () => qc.invalidateQueries({ queryKey: destinationsKey(projectId) }),
  })
}

export function useDeleteDestination(projectId: string | null) {
  const qc = useQueryClient()
  return useMutation({ mutationFn: (id: string) => deleteData(`${dest(projectId as string)}/${id}`), onSuccess: () => qc.invalidateQueries({ queryKey: destinationsKey(projectId) }) })
}

export interface Diagnosis { payload: { url: string; body: Record<string, unknown> }; synthetic: boolean; missing_credentials: string[]; validation: { ok: boolean; code: number | null; error: string | null } | null; note: string }

export function useDiagnose(projectId: string | null) {
  return useMutation({ mutationFn: (id: string) => postData<Diagnosis>(`${dest(projectId as string)}/${id}/diagnose`, {}) })
}

export function useRetryDelivery(projectId: string | null) {
  const qc = useQueryClient()
  return useMutation({ mutationFn: (id: string) => postData(`/projects/${projectId}/conversion-deliveries/${id}/retry`, {}), onSuccess: () => qc.invalidateQueries({ queryKey: deliveriesKey(projectId) }) })
}
