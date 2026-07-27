import { getData, postData } from '@/lib/api/client'

/**
 * Client Portal — Account API. Covers the verified client's billing (quotes + invoices + payments),
 * messaging (threads) and the per-request journey. Auth is the same httpOnly `client_portal` cookie the
 * rest of the portal uses (flows automatically via `withCredentials`; never read/stored in JS). Every
 * payload is client-safe. Backend: App\Domains\Requests\Http\Controllers\ClientPortalController.
 */

// ---------------------------------------------------------------------------
// Journey
// ---------------------------------------------------------------------------

export interface PortalJourney {
  reference: string
  stage: string
  stage_label: string
  progress: number
  is_terminal: boolean
  client_actions: { key: string; label: string }[]
}

export const getPortalJourney = (reference: string) =>
  getData<PortalJourney>(`/client/requests/${encodeURIComponent(reference)}/journey`)

/**
 * The main "happy path" of the request lifecycle, in order — used to render a progress bar. Off-ramp
 * stages (rejected/cancelled/payment_failed/refunded/on_hold/draft/…) are NOT on this line; for those we
 * show a status chip instead. Mirrors App\Domains\Requests\Journey\RequestStage (the backend source of truth).
 */
export const JOURNEY_MAIN_LINE = [
  'submitted',
  'under_review',
  'qualified',
  'proposal_sent',
  'awaiting_client_approval',
  'payment_pending',
  'paid',
  'onboarding',
  'in_progress',
  'client_review',
  'completed',
] as const

export type JourneyStageKey = (typeof JOURNEY_MAIN_LINE)[number]

export interface JourneyStep {
  key: string
  index: number
  done: boolean
  current: boolean
}

/** Whether a stage is off the main line (an off-ramp or a pre-submission stage). */
export function isOfframpStage(stage: string): boolean {
  return !(JOURNEY_MAIN_LINE as readonly string[]).includes(stage)
}

/**
 * The ordered main-line steps with done/current flags relative to `currentStage`. When the current stage
 * is off-ramp (e.g. cancelled), no step is marked current and completion reflects only prior main-line work
 * we can infer is impossible — so we return every step as not-done (the caller renders the off-ramp chip).
 */
export function journeySteps(currentStage: string): JourneyStep[] {
  const currentIndex = (JOURNEY_MAIN_LINE as readonly string[]).indexOf(currentStage)
  return JOURNEY_MAIN_LINE.map((key, index) => ({
    key,
    index,
    done: currentIndex >= 0 && index < currentIndex,
    current: currentIndex >= 0 && index === currentIndex,
  }))
}

// ---------------------------------------------------------------------------
// Billing — quotes
// ---------------------------------------------------------------------------

export interface PortalQuote {
  id: string
  number: string
  currency: string
  subtotal: string
  tax: string
  discount: string
  total: string
  status: string
  valid_until: string | null
  created_at: string | null
}

export const listPortalQuotes = async (): Promise<PortalQuote[]> =>
  (await getData<{ quotes: PortalQuote[] }>('/client/quotes')).quotes

export const getPortalQuote = async (id: string): Promise<PortalQuote> =>
  (await getData<{ quote: PortalQuote }>(`/client/quotes/${encodeURIComponent(id)}`)).quote

/** Approve a quote — the backend issues the invoice and returns BOTH. */
export const approvePortalQuote = (id: string) =>
  postData<{ quote: PortalQuote; invoice: PortalInvoice }>(`/client/quotes/${encodeURIComponent(id)}/approve`)

export const rejectPortalQuote = async (id: string): Promise<PortalQuote> =>
  (await postData<{ quote: PortalQuote }>(`/client/quotes/${encodeURIComponent(id)}/reject`)).quote

// ---------------------------------------------------------------------------
// Billing — invoices + payments
// ---------------------------------------------------------------------------

export interface PortalInvoice {
  id: string
  number: string
  currency: string
  subtotal: string
  tax: string
  discount: string
  total: string
  amount_paid: string
  status: string
  payment_status: 'paid' | 'partially_paid' | 'refunded' | 'void' | 'unpaid'
  due_date: string | null
  issued_at: string | null
  paid_at: string | null
}

export const listPortalInvoices = async (): Promise<PortalInvoice[]> =>
  (await getData<{ invoices: PortalInvoice[] }>('/client/invoices')).invoices

export const getPortalInvoice = async (id: string): Promise<PortalInvoice> =>
  (await getData<{ invoice: PortalInvoice }>(`/client/invoices/${encodeURIComponent(id)}`)).invoice

export interface PortalPayment {
  id: string
  status: string
  amount: string
  currency: string
  /**
   * HONEST payment state. With the Null provider (no real gateway wired) the backend returns
   * `awaiting_provider_credentials` and NOTHING is charged or marked paid. `processing` only appears once a
   * real provider is configured. There is never a fake `success`/receipt here.
   */
  payment_state: 'processing' | 'awaiting_provider_credentials'
  message: string
}

export const payPortalInvoice = async (id: string): Promise<PortalPayment> =>
  (await postData<{ payment: PortalPayment }>(`/client/invoices/${encodeURIComponent(id)}/pay`)).payment

// ---------------------------------------------------------------------------
// Messaging — threads
// ---------------------------------------------------------------------------

export interface PortalThread {
  id: string
  subject: string
  status: string
  last_message_at: string | null
  unread: number
}

export interface PortalMessage {
  id: string
  author_type: string
  body: string
  attachments: unknown
  created_at: string | null
  read_by_client_at: string | null
}

export const listPortalThreads = async (): Promise<PortalThread[]> =>
  (await getData<{ threads: PortalThread[] }>('/client/messages')).threads

export const getPortalThread = (id: string) =>
  getData<{ thread: PortalThread; messages: PortalMessage[] }>(`/client/messages/${encodeURIComponent(id)}`)

export const openPortalThread = async (subject: string, body: string): Promise<PortalThread> =>
  (await postData<{ thread: PortalThread }>('/client/messages', { subject, body })).thread

export const postPortalThreadMessage = async (id: string, body: string): Promise<PortalMessage> =>
  (await postData<{ message: PortalMessage }>(`/client/messages/${encodeURIComponent(id)}`, { body })).message

// ---------------------------------------------------------------------------
// Formatting helpers (Latin digits everywhere — 'en-US' keeps figures Latin).
// ---------------------------------------------------------------------------

/** Money as a Latin-digit string with the currency code, e.g. "1,500.00 SAR". */
export function formatMoney(amount: string | number, currency: string): string {
  const n = typeof amount === 'number' ? amount : Number(amount)
  if (!Number.isFinite(n)) return `${amount} ${currency}`
  return `${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`
}

/** ISO date/datetime → Latin-digit short date, or an em dash when null. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('en-CA')
}
