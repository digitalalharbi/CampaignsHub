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
  consent: { required: boolean; text: LocText; version?: string }
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
