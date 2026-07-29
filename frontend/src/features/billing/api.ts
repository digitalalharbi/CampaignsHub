import { api, getData, postData } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'

/**
 * Billing API layer — quotes → invoices → payments. Mirrors the tenant-scoped backend
 * (routes/api/billing.php). Self-contained to this feature. Honesty rule (enforced by the backend and
 * surfaced here): a payment is NEVER marked paid by starting a session — with the Null provider it stays
 * `pending` and the UI reads it as `awaiting_provider_credentials`. Only a verified webhook settles a payment.
 */

// ---------------------------------------------------------------------------
// Quotes
// ---------------------------------------------------------------------------

export type QuoteStatus = 'draft' | 'sent' | 'approved' | 'rejected' | 'expired'

/** A priced offer to a client. Mirrors backend Quote. */
export interface Quote {
  id: string
  tenant_id: string | null
  client_workspace_id: string | null
  request_id: string | null
  project_id: string | null
  number: string
  currency: string
  subtotal: string
  tax: string
  tax_treatment: string | null
  discount: string
  total: string
  status: QuoteStatus | string
  valid_until: string | null
  notes: string | null
  created_by: string | null
  created_at: string | null
}

export interface NewQuote {
  client_workspace_id?: string | null
  request_id?: string | null
  project_id?: string | null
  number?: string
  currency?: string
  subtotal?: number
  tax?: number
  tax_treatment?: string
  discount?: number
  total?: number
  valid_until?: string | null
  notes?: string | null
}

export const listQuotes = () => getData<Quote[]>('/billing/quotes')

export const createQuote = (body: NewQuote) => postData<Quote>('/billing/quotes', body)

/** Approve a quote → the backend issues (and returns) the Invoice. Staff-gated (billing.manage). */
export const approveQuote = (quoteId: string) =>
  postData<Invoice>(`/billing/quotes/${encodeURIComponent(quoteId)}/approve`)

// ---------------------------------------------------------------------------
// Invoices
// ---------------------------------------------------------------------------

export type InvoiceStatus = 'draft' | 'issued' | 'partially_paid' | 'paid' | 'void' | 'refunded'

/** An issued bill. Mirrors backend Invoice. amount_paid tracks partial settlement. */
export interface Invoice {
  id: string
  tenant_id: string | null
  client_workspace_id: string | null
  quote_id: string | null
  number: string
  currency: string
  subtotal: string
  tax: string
  tax_treatment: string | null
  discount: string
  total: string
  amount_paid: string
  status: InvoiceStatus | string
  due_date: string | null
  issued_at: string | null
  paid_at: string | null
  created_at: string | null
}

/** An invoice is only payable while issued or partially paid (mirrors BillingService::startPayment). */
export function isPayable(invoice: Invoice): boolean {
  return invoice.status === 'issued' || invoice.status === 'partially_paid'
}

export async function listInvoices(status?: InvoiceStatus): Promise<Invoice[]> {
  const res = await api.get<ApiEnvelope<Invoice[]>>('/billing/invoices', {
    params: status ? { status } : {},
  })
  return res.data.data ?? []
}

// ---------------------------------------------------------------------------
// Payments
// ---------------------------------------------------------------------------

export type PaymentStatus = 'pending' | 'processing' | 'paid' | 'failed' | 'refunded'

/** One attempt to collect an invoice through a gateway. Mirrors backend Payment. */
export interface Payment {
  id: string
  tenant_id: string | null
  invoice_id: string
  provider: string
  provider_session_id: string | null
  provider_payment_id: string | null
  amount: string
  currency: string
  status: PaymentStatus | string
  idempotency_key: string | null
  error: string | null
  paid_at: string | null
  created_at: string | null
}

/**
 * Open a payment session for an issued invoice. With the Null provider this returns a `pending` payment on
 * the `null` provider — it moves NO money. We pass a stable idempotency key per invoice so a retried start
 * returns the same payment (demonstrating the backend's idempotency) rather than spawning duplicates.
 */
export const startPayment = (invoiceId: string) =>
  postData<Payment>(`/billing/invoices/${encodeURIComponent(invoiceId)}/pay`, {
    idempotency_key: `chub-inv-${invoiceId}`,
  })

/**
 * The honest display state of a payment. The Null provider (key `null`, and no session id) means credentials
 * are not wired — we surface `awaiting_provider_credentials` instead of a misleading "pending", and NEVER a
 * fabricated "paid". Any other provider falls back to the payment's own real status.
 */
export type PaymentDisplayState = 'awaiting_provider_credentials' | PaymentStatus | string

export function paymentDisplayState(payment: Payment): PaymentDisplayState {
  const unconfigured = payment.provider === 'null' || payment.provider === '' || !payment.provider
  if (payment.status === 'pending' && (unconfigured || !payment.provider_session_id)) {
    return 'awaiting_provider_credentials'
  }
  return payment.status
}

// ---------------------------------------------------------------------------
// Formatting helpers (Latin digits everywhere — a product rule)
// ---------------------------------------------------------------------------

/** Money with Latin digits and two fraction digits, e.g. "1,150.00 SAR". */
export function formatMoney(amount: string | number, currency: string): string {
  const n = typeof amount === 'number' ? amount : Number(amount)
  if (!Number.isFinite(n)) return `${amount} ${currency}`
  return `${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`
}

/** ISO → Latin-digit short date (en-CA = YYYY-MM-DD), or an em dash when null. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('en-CA')
}

/** ISO → Latin-digit date + time, or an em dash when null. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return `${d.toLocaleDateString('en-CA')} ${d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`
}

// ---------------------------------------------------------------------------
// FINANCE-001 — the consolidated finance center read model
// ---------------------------------------------------------------------------

export interface StatusBucket { count: number; total: number }

export interface FinanceOverview {
  quotes: { by_status: Record<string, StatusBucket>; count: number; total: number; approved_total: number }
  invoices: {
    by_status: Record<string, StatusBucket>
    count: number
    total: number
    collected: number
    outstanding: number
    overdue_count: number
    /** null (not 0) when nothing was invoiced — an undefined rate is not a zero rate. */
    collection_rate: number | null
  }
  payments: { by_status: Record<string, StatusBucket>; count: number; succeeded_total: number }
  aging: { current: number; d1_30: number; d31_60: number; d61_90: number; d90_plus: number }
  currency: string
}

export interface PaymentRow {
  id: string
  provider: string
  provider_payment_id: string | null
  amount: number
  currency: string
  status: string
  error: string | null
  paid_at: string | null
  created_at: string | null
  invoice: { id: string; number: string; total: number } | null
}

export interface ReceivableRow {
  id: string
  number: string
  client: string | null
  status: string
  total: number
  amount_paid: number
  due: number
  currency: string
  due_date: string | null
  days_late: number
}

export const getFinanceOverview = () => getData<FinanceOverview>('/billing/overview')
export const listPaymentsLedger = (query = '') => getData<PaymentRow[]>(`/billing/payments${query ? `?${query}` : ''}`)
export const listReceivables = () => getData<ReceivableRow[]>('/billing/receivables')
