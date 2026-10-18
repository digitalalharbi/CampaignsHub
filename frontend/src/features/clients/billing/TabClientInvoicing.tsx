import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileDown, Loader2 } from 'lucide-react'
import { listInvoices, type Invoice } from '@/features/billing/api'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Num } from '@/components/ui/Num'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import {
  displayStatus, draftInvoice, getBillingProfile, invoiceAction, invoicePdfUrl, previousIsoWeek, saveBillingProfile,
  type BillingProfile, type InvoiceAction,
} from './clientInvoiceApi'

/**
 * INV-PROFILE-001, INV-LIFECYCLE-001, INV-IMMUTABLE-001, INV-PDF-001 — how this client is billed, and the
 * fee invoices that come from it.
 *
 * The profile says HOW once; a draft is produced for a period rather than typed; the Owner reviews it before
 * it can be issued; an issued invoice offers no edit at all — only to send it, record a payment, or correct
 * it, which voids it with a reason and starts a new draft. «Due» and «overdue» are read from the calendar.
 */
const COPY = {
  ar: {
    profile: 'ملف الفوترة', fee: 'نموذج الرسوم', models: { fixed: 'مبلغ ثابت', percentage: 'نسبة من الإنفاق', manual: 'مبلغ يدوي' } as Record<string, string>,
    fixed: 'المبلغ الثابت', percentage: 'النسبة %', minimum: 'الحد الأدنى', cadence: 'الدورة', cadences: { weekly: 'أسبوعي', monthly: 'شهري', custom: 'مخصص' } as Record<string, string>,
    currency: 'العملة', tax: 'ضريبة القيمة المضافة', taxes: { basic_15: '15٪', zero_rated: 'صفرية', exempt: 'معفاة', out_of_scope: 'خارج النطاق' } as Record<string, string>,
    dueDays: 'أيام الاستحقاق', issuer: 'اسم الجهة المُصدِرة', issuerVat: 'الرقم الضريبي للمُصدِر', billTo: 'اسم العميل القانوني', billToVat: 'الرقم الضريبي للعميل',
    bank: 'البنك', beneficiary: 'المستفيد', iban: 'الآيبان', notes: 'ملاحظات', save: 'حفظ الملف', saved: 'تم الحفظ',
    invoices: 'الفواتير', draft: 'تجهيز فاتورة', from: 'من', to: 'إلى', amount: 'المبلغ (للمبلغ اليدوي)', none: 'لا فواتير رسوم لهذا العميل بعد.',
    status: { draft: 'مسودة', reviewed: 'روجعت', issued: 'مُصدرة', sent: 'مُرسلة', due: 'مستحقة', overdue: 'متأخرة', partially_paid: 'مدفوعة جزئياً', paid: 'مدفوعة', void: 'ملغاة' } as Record<string, string>,
    act: { review: 'تمت المراجعة', issue: 'إصدار', send: 'تم الإرسال', payment: 'تسجيل دفعة', correct: 'تصحيح' } as Record<string, string>,
    payAmount: 'المبلغ المستلم', reason: 'سبب التصحيح', pdfAr: 'PDF عربي', pdfEn: 'PDF English', confirm: 'تأكيد', cancel: 'إلغاء',
  },
  en: {
    profile: 'Billing profile', fee: 'Fee model', models: { fixed: 'Fixed amount', percentage: 'Percentage of spend', manual: 'Manual amount' } as Record<string, string>,
    fixed: 'Fixed amount', percentage: 'Percentage %', minimum: 'Minimum fee', cadence: 'Cadence', cadences: { weekly: 'Weekly', monthly: 'Monthly', custom: 'Custom' } as Record<string, string>,
    currency: 'Currency', tax: 'VAT', taxes: { basic_15: '15%', zero_rated: 'Zero-rated', exempt: 'Exempt', out_of_scope: 'Out of scope' } as Record<string, string>,
    dueDays: 'Days until due', issuer: 'Issuer legal name', issuerVat: 'Issuer VAT number', billTo: 'Client legal name', billToVat: 'Client VAT number',
    bank: 'Bank', beneficiary: 'Beneficiary', iban: 'IBAN', notes: 'Notes', save: 'Save profile', saved: 'Saved',
    invoices: 'Fee invoices', draft: 'Draft an invoice', from: 'From', to: 'To', amount: 'Amount (manual fee)', none: 'No fee invoices for this client yet.',
    status: { draft: 'Draft', reviewed: 'Reviewed', issued: 'Issued', sent: 'Sent', due: 'Due', overdue: 'Overdue', partially_paid: 'Partially paid', paid: 'Paid', void: 'Void' } as Record<string, string>,
    act: { review: 'Mark reviewed', issue: 'Issue', send: 'Mark sent', payment: 'Record payment', correct: 'Correct' } as Record<string, string>,
    payAmount: 'Amount received', reason: 'Reason for the correction', pdfAr: 'PDF Arabic', pdfEn: 'PDF English', confirm: 'Confirm', cancel: 'Cancel',
  },
}

type FeeInvoice = Invoice & { source?: string; period_start?: string | null; period_end?: string | null }

/** The one next step each state offers; an issued invoice offers no edit. */
function nextActions(status: string): InvoiceAction[] {
  switch (status) {
    case 'draft': return ['review']
    case 'reviewed': return ['issue']
    case 'issued': return ['send', 'payment', 'correct']
    case 'sent':
    case 'partially_paid': return ['payment', 'correct']
    default: return []
  }
}

export function TabClientInvoicing({ clientId, canManage }: { clientId: string; canManage: boolean }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en

  return (
    <div data-testid="client-invoicing" className="space-y-6 text-sm">
      <Profile clientId={clientId} t={t} canManage={canManage} />
      <FeeInvoices clientId={clientId} t={t} ar={ar} canManage={canManage} />
    </div>
  )
}

function Profile({ clientId, t, canManage }: { clientId: string; t: typeof COPY.ar; canManage: boolean }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const profile = useQuery({ queryKey: ['billing', 'profile', clientId], queryFn: () => getBillingProfile(clientId) })
  const [form, setForm] = useState<Partial<BillingProfile> | null>(null)
  const [saved, setSaved] = useState(false)
  const save = useMutation({
    mutationFn: (body: Partial<BillingProfile>) => saveBillingProfile(clientId, body),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['billing', 'profile', clientId] }); setSaved(true); setTimeout(() => setSaved(false), 2500) },
  })

  if (profile.isLoading) return <div className="h-32 animate-pulse rounded-xl bg-surface-secondary" />
  if (profile.isError) return <QueryFailure error={profile.error} ar={ar} testId="billing-profile-failure" onRetry={() => void profile.refetch()} fallbackTitle={ar ? 'تعذّر تحميل ملف الفوترة.' : 'Could not load the billing profile.'} />

  const f: Partial<BillingProfile> = form ?? profile.data ?? { fee_model: 'fixed', cadence: 'weekly', currency: 'SAR', tax_treatment: 'basic_15', due_days: 7 }
  const set = (p: Partial<BillingProfile>) => setForm({ ...f, ...p })
  const nested = (key: 'issuer' | 'bill_to' | 'payment_details', field: string, value: string) => set({ [key]: { ...(f[key] ?? {}), [field]: value } } as Partial<BillingProfile>)
  const input = 'h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm'
  const disabled = !canManage

  return (
    <section>
      <h3 className="mb-2 font-bold text-text-primary">{t.profile}</h3>
      <form data-testid="billing-profile" onSubmit={(e) => { e.preventDefault(); save.mutate({ ...f, currency: String(f.currency ?? 'SAR').toUpperCase() }) }} className="grid gap-3 sm:grid-cols-3">
        <label className="text-xs font-semibold text-text-secondary">{t.fee}
          <select className={input} value={f.fee_model} onChange={(e) => set({ fee_model: e.target.value as BillingProfile['fee_model'] })} disabled={disabled}>
            {Object.entries(t.models).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
        {f.fee_model === 'fixed' && <label className="text-xs font-semibold text-text-secondary">{t.fixed}<input className={input} type="number" min="0" step="0.01" value={f.fixed_amount ?? ''} onChange={(e) => set({ fixed_amount: e.target.value })} disabled={disabled} dir="ltr" /></label>}
        {f.fee_model === 'percentage' && <label className="text-xs font-semibold text-text-secondary">{t.percentage}<input className={input} type="number" min="0" max="100" step="0.001" value={f.percentage ?? ''} onChange={(e) => set({ percentage: e.target.value })} disabled={disabled} dir="ltr" /></label>}
        <label className="text-xs font-semibold text-text-secondary">{t.minimum}<input className={input} type="number" min="0" step="0.01" value={f.minimum_fee ?? ''} onChange={(e) => set({ minimum_fee: e.target.value || null })} disabled={disabled} dir="ltr" /></label>
        <label className="text-xs font-semibold text-text-secondary">{t.cadence}
          <select className={input} value={f.cadence} onChange={(e) => set({ cadence: e.target.value as BillingProfile['cadence'] })} disabled={disabled}>
            {Object.entries(t.cadences).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
        <label className="text-xs font-semibold text-text-secondary">{t.currency}<input className={input} maxLength={3} value={f.currency ?? ''} onChange={(e) => set({ currency: e.target.value })} disabled={disabled} dir="ltr" /></label>
        <label className="text-xs font-semibold text-text-secondary">{t.tax}
          <select className={input} value={f.tax_treatment} onChange={(e) => set({ tax_treatment: e.target.value })} disabled={disabled}>
            {Object.entries(t.taxes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
        <label className="text-xs font-semibold text-text-secondary">{t.dueDays}<input className={input} type="number" min="0" max="180" value={f.due_days ?? 7} onChange={(e) => set({ due_days: Number(e.target.value) })} disabled={disabled} dir="ltr" /></label>
        <label className="text-xs font-semibold text-text-secondary">{t.issuer}<input className={input} value={f.issuer?.legal_name ?? ''} onChange={(e) => nested('issuer', 'legal_name', e.target.value)} disabled={disabled} /></label>
        <label className="text-xs font-semibold text-text-secondary">{t.issuerVat}<input className={input} value={f.issuer?.vat_number ?? ''} onChange={(e) => nested('issuer', 'vat_number', e.target.value)} disabled={disabled} dir="ltr" /></label>
        <label className="text-xs font-semibold text-text-secondary">{t.billTo}<input className={input} value={f.bill_to?.legal_name ?? ''} onChange={(e) => nested('bill_to', 'legal_name', e.target.value)} disabled={disabled} /></label>
        <label className="text-xs font-semibold text-text-secondary">{t.billToVat}<input className={input} value={f.bill_to?.vat_number ?? ''} onChange={(e) => nested('bill_to', 'vat_number', e.target.value)} disabled={disabled} dir="ltr" /></label>
        <label className="text-xs font-semibold text-text-secondary">{t.bank}<input className={input} value={f.payment_details?.bank ?? ''} onChange={(e) => nested('payment_details', 'bank', e.target.value)} disabled={disabled} /></label>
        <label className="text-xs font-semibold text-text-secondary">{t.beneficiary}<input className={input} value={f.payment_details?.beneficiary ?? ''} onChange={(e) => nested('payment_details', 'beneficiary', e.target.value)} disabled={disabled} /></label>
        <label className="text-xs font-semibold text-text-secondary">{t.iban}<input className={input} value={f.payment_details?.iban ?? ''} onChange={(e) => nested('payment_details', 'iban', e.target.value)} disabled={disabled} dir="ltr" /></label>
        <label className="text-xs font-semibold text-text-secondary sm:col-span-3">{t.notes}<input className={input} value={f.notes ?? ''} onChange={(e) => set({ notes: e.target.value })} disabled={disabled} /></label>
        {canManage && (
          <div className="flex items-center gap-3 sm:col-span-3">
            <button type="submit" data-testid="billing-profile-save" disabled={save.isPending} className="flex items-center gap-1.5 rounded-lg bg-brand-primary px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">
              {save.isPending && <Loader2 size={13} className="animate-spin" />} {t.save}
            </button>
            {saved && <span role="status" className="text-xs text-success">{t.saved}</span>}
            {save.isError && <span role="alert" className="text-xs text-danger">{toApiError(save.error).message}</span>}
          </div>
        )}
      </form>
    </section>
  )
}

function FeeInvoices({ clientId, t, ar, canManage }: { clientId: string; t: typeof COPY.ar; ar: boolean; canManage: boolean }) {
  const qc = useQueryClient()
  const week = previousIsoWeek()
  const [from, setFrom] = useState(week.from)
  const [to, setTo] = useState(week.to)
  const [amount, setAmount] = useState('')
  const invoices = useQuery({ queryKey: ['billing', 'invoices', 'all'], queryFn: () => listInvoices() })
  const refresh = () => void qc.invalidateQueries({ queryKey: ['billing', 'invoices', 'all'] })
  const draft = useMutation({ mutationFn: () => draftInvoice(clientId, { from, to, ...(amount !== '' ? { amount: Number(amount) } : {}) }), onSuccess: refresh })

  const mine = ((invoices.data?.items ?? []) as FeeInvoice[]).filter((i) => i.client_workspace_id === clientId && i.source === 'billing_profile')

  return (
    <section>
      <h3 className="mb-2 font-bold text-text-primary">{t.invoices}</h3>
      {canManage && (
        <form data-testid="invoice-draft" onSubmit={(e) => { e.preventDefault(); draft.mutate() }} className="mb-3 grid items-end gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
          <label className="text-xs font-semibold text-text-secondary">{t.from}<input type="date" className="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm" value={from} onChange={(e) => setFrom(e.target.value)} dir="ltr" /></label>
          <label className="text-xs font-semibold text-text-secondary">{t.to}<input type="date" className="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm" value={to} onChange={(e) => setTo(e.target.value)} dir="ltr" /></label>
          <label className="text-xs font-semibold text-text-secondary">{t.amount}<input type="number" min="0" step="0.01" className="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm" value={amount} onChange={(e) => setAmount(e.target.value)} dir="ltr" /></label>
          <button type="submit" disabled={draft.isPending} className="h-10 rounded-lg bg-brand-primary px-4 text-xs font-semibold text-white disabled:opacity-50">{t.draft}</button>
        </form>
      )}
      {draft.isError && <p role="alert" data-testid="invoice-draft-error" className="mb-2 text-xs text-danger">{toApiError(draft.error).message}</p>}

      {invoices.isError ? (
        <QueryFailure error={invoices.error} ar={ar} testId="fee-invoices-failure" onRetry={() => void invoices.refetch()} fallbackTitle={ar ? 'تعذّر تحميل الفواتير.' : 'Could not load the invoices.'} />
      ) : mine.length === 0 ? (
        <p className="rounded-xl border border-dashed border-border p-6 text-center text-text-secondary">{t.none}</p>
      ) : (
        <ul className="space-y-2">
          {mine.map((i) => <InvoiceRow key={i.id} invoice={i} t={t} canManage={canManage} onChange={refresh} />)}
        </ul>
      )}
    </section>
  )
}

function InvoiceRow({ invoice, t, canManage, onChange }: { invoice: FeeInvoice; t: typeof COPY.ar; canManage: boolean; onChange: () => void }) {
  const [asking, setAsking] = useState<InvoiceAction | null>(null)
  const [value, setValue] = useState('')
  const run = useMutation({
    mutationFn: ({ action, body }: { action: InvoiceAction; body?: { amount?: number; reason?: string } }) => invoiceAction(invoice.id, action, body),
    onSuccess: () => { setAsking(null); setValue(''); onChange() },
  })
  const shown = displayStatus(String(invoice.status), invoice.due_date)
  const tone = shown === 'overdue' ? 'bg-danger/15 text-danger' : shown === 'paid' ? 'bg-success/15 text-success' : 'bg-surface-secondary text-text-secondary'
  const go = (action: InvoiceAction) => (action === 'payment' || action === 'correct' ? setAsking(action) : run.mutate({ action }))

  return (
    <li data-testid={`fee-invoice-${invoice.id}`} className="rounded-xl border border-border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span>
          <span className="block font-mono text-xs font-semibold text-brand-600" dir="ltr">{invoice.number}</span>
          <span className="text-xs text-text-muted" dir="ltr"><Num>{invoice.period_start} → {invoice.period_end}</Num></span>
        </span>
        <span className="flex items-center gap-2">
          <span data-testid={`fee-status-${invoice.id}`} className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${tone}`}>{t.status[shown] ?? shown}</span>
          <span className="tnum font-bold" dir="ltr"><Num>{Number(invoice.total).toLocaleString('en-US')} {invoice.currency}</Num></span>
        </span>
      </div>
      <div className="mt-2 flex flex-wrap items-center gap-1.5">
        {canManage && nextActions(String(invoice.status)).map((a) => (
          <button key={a} type="button" onClick={() => go(a)} disabled={run.isPending} className="rounded-lg border border-border px-2.5 py-1 text-xs font-semibold hover:border-brand-400">{t.act[a]}</button>
        ))}
        <a href={invoicePdfUrl(invoice.id, 'ar')} target="_blank" rel="noreferrer" className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold"><FileDown size={12} /> {t.pdfAr}</a>
        <a href={invoicePdfUrl(invoice.id, 'en')} target="_blank" rel="noreferrer" className="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold"><FileDown size={12} /> {t.pdfEn}</a>
      </div>
      {asking && (
        <form onSubmit={(e) => { e.preventDefault(); run.mutate({ action: asking, body: asking === 'payment' ? { amount: Number(value) } : { reason: value } }) }} className="mt-2 flex flex-wrap items-center gap-2">
          <input aria-label={asking === 'payment' ? t.payAmount : t.reason} type={asking === 'payment' ? 'number' : 'text'} min="0" step="0.01" required value={value} onChange={(e) => setValue(e.target.value)} className="h-9 min-w-48 flex-1 rounded-lg border border-border bg-surface px-3 text-sm" placeholder={asking === 'payment' ? t.payAmount : t.reason} />
          <button type="submit" disabled={run.isPending || value === ''} className="h-9 rounded-lg bg-brand-primary px-3 text-xs font-semibold text-white disabled:opacity-50">{t.confirm}</button>
          <button type="button" onClick={() => setAsking(null)} className="h-9 rounded-lg border border-border px-3 text-xs font-semibold">{t.cancel}</button>
        </form>
      )}
      {run.isError && <p role="alert" className="mt-2 text-xs text-danger">{toApiError(run.error).message}</p>}
    </li>
  )
}
