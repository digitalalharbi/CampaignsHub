import { getData, postData, putData } from '@/lib/api/client'

/** INV-PROFILE-001 / INV-LIFECYCLE-001 — the agency-to-client fee invoice, as the agency API answers it. */
export interface BillingProfile {
  fee_model: 'fixed' | 'percentage' | 'manual'
  fixed_amount: string | null
  percentage: string | null
  minimum_fee: string | null
  cadence: 'weekly' | 'monthly' | 'custom'
  currency: string
  tax_treatment: string
  due_days: number
  issuer: { legal_name?: string; vat_number?: string; address?: string } | null
  bill_to: { legal_name?: string; vat_number?: string; address?: string } | null
  payment_details: { bank?: string; beneficiary?: string; iban?: string; account_number?: string } | null
  notes: string | null
}

export type InvoiceAction = 'review' | 'issue' | 'send' | 'payment' | 'correct'

export const getBillingProfile = (clientId: string) => getData<BillingProfile | null>(`/billing/clients/${clientId}/profile`)
export const saveBillingProfile = (clientId: string, body: Partial<BillingProfile>) => putData<BillingProfile>(`/billing/clients/${clientId}/profile`, body)
export const draftInvoice = (clientId: string, body: { from: string; to: string; amount?: number }) => postData<{ id: number; number: string }>(`/billing/clients/${clientId}/invoices/draft`, body)
export const invoiceAction = (id: number | string, action: InvoiceAction, body?: { amount?: number; reason?: string }) => postData<{ id: number; status: string }>(`/billing/invoices/${id}/${action}`, body)
export const invoicePdfUrl = (id: number | string, lang: 'ar' | 'en') => `/api/v1/billing/invoices/${id}/pdf?lang=${lang}`

/** The status a person reads: the stored lifecycle plus «due» and «overdue», which only the calendar decides. */
export function displayStatus(status: string, dueDate: string | null, today: Date = new Date()): string {
  if (!['issued', 'sent', 'partially_paid'].includes(status) || !dueDate) return status
  const due = new Date(`${dueDate}T23:59:59`)

  return today > due ? 'overdue' : status === 'partially_paid' ? status : 'due'
}

/** The ISO week (Monday to Sunday) before the one containing `anchor` — the default period of a weekly fee invoice. */
export function previousIsoWeek(anchor: Date = new Date()): { from: string; to: string } {
  const d = new Date(Date.UTC(anchor.getFullYear(), anchor.getMonth(), anchor.getDate()))
  const sinceMonday = (d.getUTCDay() + 6) % 7
  const monday = new Date(d)
  monday.setUTCDate(d.getUTCDate() - sinceMonday - 7)
  const sunday = new Date(monday)
  sunday.setUTCDate(monday.getUTCDate() + 6)
  const iso = (x: Date) => x.toISOString().slice(0, 10)

  return { from: iso(monday), to: iso(sunday) }
}
