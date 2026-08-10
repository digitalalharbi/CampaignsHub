import { postData } from '@/lib/api/client'

/**
 * LEGAL-002 — the three things a visitor can actually send.
 *
 * Each is public and unauthenticated because the people who need them most may not have an account,
 * or may have lost access to one. The shapes differ deliberately: an enquiry gets no reference back
 * because there is no queue to chase, while a ticket and a data request each return one, because
 * both are things the sender is entitled to follow up on.
 */

/**
 * LOGIN-HELP-001 — the five things people actually write in about.
 *
 * A closed list, because the point is to be able to GROUP them: «choosing a plan» and «connecting
 * accounts» go to different people, and a free-text subject cannot be routed. `other` keeps the list
 * from ever forcing a wrong answer.
 */
export type ContactTopic =
  | 'own_campaigns'
  | 'multi_client_campaigns'
  | 'plan_choice'
  | 'connect_accounts'
  | 'other'

/** Where the panel was opened from — a triage hint for the operator, not a claim about the sender. */
export type ContactSource = 'login' | 'contact_page' | 'pricing' | 'other'

export interface ContactPayload {
  name: string
  email: string
  phone?: string
  company?: string
  /** With a topic, `subject` and `message` become optional — the server fills them from it. */
  topic?: ContactTopic
  source?: ContactSource
  subject?: string
  message?: string
  /** Honeypot — left empty by people, filled by bots. */
  website?: string
}

export interface SupportPayload {
  name: string
  email: string
  phone?: string
  subject: string
  message: string
  category?: string
  website?: string
}

export type DataRequestType = 'export' | 'correction' | 'delete_data' | 'delete_account'

export interface DataRequestPayload {
  type: DataRequestType
  name: string
  email: string
  phone?: string
  details?: string
  website?: string
}

/** A reason a destructive request cannot proceed, in the requester's own language. */
export interface DeletionBlocker {
  code: string
  count: number
  ar: string
  en: string
}

export interface DataRequestResult {
  reference: string
  status: string
  blockers: DeletionBlocker[]
}

export const sendContact = (body: ContactPayload) => postData<{ received: boolean }>('/contact', body)

export const openSupportTicket = (body: SupportPayload) =>
  postData<{ reference: string }>('/support/tickets', body)

export const submitDataRequest = (body: DataRequestPayload) =>
  postData<DataRequestResult>('/data-requests', body)
