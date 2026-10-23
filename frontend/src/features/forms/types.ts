/**
 * FORMS-HUB-001 — the shape of a form definition as the server stores and serves it.
 *
 * Mirrors `FormDefinition::normalise` on the backend: the browser renders and pre-checks what it is
 * given, and the server judges again. Nothing here is trusted by the server.
 */
export type FieldType = 'text' | 'textarea' | 'email' | 'phone' | 'number' | 'select' | 'radio' | 'checkbox' | 'date'
export type LocText = string | { ar?: string; en?: string }
export type MapsTo = 'name' | 'email' | 'phone'
export type ConditionOp = 'equals' | 'not_equals' | 'in' | 'filled'

export interface FormOption { value: string; label: LocText }
export interface FormCondition { field: string; op: ConditionOp; value: string | string[] | null }
export interface FormField {
  id: string
  type: FieldType
  label: LocText
  required?: boolean
  options?: FormOption[]
  validation?: { min?: number; max?: number; min_length?: number; max_length?: number }
  maps_to?: MapsTo
  show_if?: FormCondition
}
export interface FormStep { id: string; title: LocText; fields: FormField[] }
export interface FormDefinition {
  steps: FormStep[]
  consent: { required: boolean; text: LocText; version?: string; measurement?: { enabled: boolean; text: LocText } }
  thank_you: { message: LocText; redirect_url: string | null }
  style: { accent: string | null; theme: 'light' | 'dark' | 'auto' }
}

export type AnswerValue = string | number | string[]
export type Answers = Record<string, AnswerValue>

export interface LeadFormRow {
  id: string
  name: string
  status: 'draft' | 'published' | 'archived'
  slug: string
  public_url: string
  default_owner_id: number | null
  assignment_mode?: 'fixed' | 'round_robin'
  assignment_owner_ids?: number[]
  follow_up_minutes?: number | null
  retention_days: number | null
  published_version_id: string | null
  created_at: string | null
  submissions?: number
}
export interface LeadFormDetail extends LeadFormRow {
  definition: FormDefinition | null
  definition_version: number | null
  definition_published: boolean
  versions: Array<{ version: number; published_at: string | null; checksum: string }>
}

/** FORMS-DISPATCH-001 — a place a lead's conversion is reported. Secrets are never in this shape: only which exist. */
export type ConversionKind = 'ga4' | 'meta_capi' | 'snapchat_capi' | 'gtm_server' | 'gtm_web'
export interface ConversionDestination {
  id: string
  kind: ConversionKind
  name: string
  status: 'test' | 'live' | 'off'
  lead_form_id: string | null
  config: Record<string, string>
  secrets_set: Record<string, boolean>
  missing_for_live: string[]
  went_live_at: string | null
  last_7_days: Record<string, number>
  last_error: { error: string | null; at: string | null } | null
}
export interface ConversionDelivery {
  id: string
  destination_id: string
  event_name: string
  event_id: string
  status: 'queued' | 'sent' | 'failed' | 'skipped_consent' | 'test'
  attempts: number
  response_code: number | null
  error: string | null
  created_at: string | null
}

/** FORMS-DASHBOARDS-001 — a form's or a link's funnel. A `null` rate means its input was absent: never read it as zero. */
export interface FunnelLeads { submissions: number | null; received: number; people: number; contacted: number; qualified: number; appointment: number; won: number; referred?: number; referred_won?: number }
export interface FunnelRates { lead_to_contacted: number | null; lead_to_qualified: number | null; lead_to_appointment: number | null; lead_to_won: number | null }
export interface FunnelVisit {
  state: 'measured' | 'tracking_off' | 'no_events_yet'
  since: string | null
  unit?: 'session'
  views?: number
  starts?: number
  attempts?: number
  steps?: Array<{ step: number; reached: number; completed: number; left: number }>
  rates?: { view_to_start: number | null; start_to_attempt: number | null }
}
export interface Funnel {
  scope: { type: 'form' | 'link'; id: string; name: string }
  period: { from: string; to: string; timezone: string }
  visit?: FunnelVisit
  tracking?: { since: string | null }
  clicks?: { clicks: number; machine: number; device_days: number } | null
  attribution_rule?: Bi
  credited?: boolean
  click_to_lead_rate?: number | null
  leads: FunnelLeads
  rates: FunnelRates
  won_estimated_value: Record<string, number>
  cost: { cost_per_lead: number | null; reason: Bi }
  stage_note: Bi
}

/** TRACKING-HEALTH-001 */
export type HealthSeverity = 'critical' | 'warning' | 'info' | 'unavailable' | 'ok'
/** A sentence the server wrote in both languages. */
export interface Bi { en: string; ar: string }
export interface HealthCheck { key: string; severity: HealthSeverity; title: Bi; detail: Bi | null; numbers: Record<string, number> | null; action: Bi | null }
export interface TrackingHealth {
  period: { from: string; to: string; timezone: string }
  tracking_enabled: boolean
  summary: { critical: number; warning: number }
  checks: HealthCheck[]
  coverage: { form_leads: number; with_campaign_tag: number | null; with_click_id: number | null; measurement_consent_granted: number | null; minimum_for_a_rate: number }
  deliveries: Array<{ destination_id: string; name: string; kind: string; status: string; sent: number; failed: number; queued: number; queued_stuck: number; skipped_consent: number; test: number; eligible_leads: number; attempted: number; gap: number }>
  reconciliation: Array<{ provider: string; platform_reported: number; stored: number; difference: number; stored_to_reported: number | null }>
  confidence_note: Bi
}
