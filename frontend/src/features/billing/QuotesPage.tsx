import { StatCard } from '@/components/ui/StatCard'
import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, FileText, Plus, Search, X } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { BillingTabs } from './BillingTabs'
import { DateField } from '@/components/ui/DateField'
import { DEFAULT_TREATMENT, SELECTABLE_TREATMENTS, isHistoricalTreatment, taxForTreatment, taxTreatmentLabel, taxTreatmentShort } from './taxTreatment'
import {
  approveQuote, createQuote, formatDate, formatMoney, listQuotes,
  type NewQuote, type Quote,
} from './api'

/** Bilingual copy — self-contained to this feature (Arabic-first). */
const COPY = {
  ar: {
    title: 'عروض الأسعار', subtitle: 'أنشئ عروض الأسعار وتابع حالتها. اعتماد العرض يُصدر الفاتورة تلقائيًا.',
    none: 'لا توجد عروض أسعار بعد.', error: 'تعذّر تحميل عروض الأسعار.', loading: 'جارٍ التحميل…',
    new_quote: 'عرض سعر جديد', number: 'الرقم', currency: 'العملة', subtotal: 'المجموع الفرعي',
    tax: 'الضريبة', discount: 'الخصم', total: 'الإجمالي', valid_until: 'صالح حتى', notes: 'ملاحظات',
    create: 'إنشاء العرض', creating: 'جارٍ الإنشاء…', view: 'عرض', approve: 'اعتماد وإصدار فاتورة',
    approving: 'جارٍ الاعتماد…', reject: 'رفض', reject_ref: 'الرفض إجراء يخص العميل — معروض للاطلاع فقط.',
    approved_note: 'تم الاعتماد وإصدار الفاتورة.', created_at: 'أُنشئ',
    optional: 'اختياري', close: 'إغلاق', details: 'تفاصيل العرض',
    search_ph: 'ابحث برقم العرض…', all: 'الكل', no_match: 'لا عروض تطابق البحث أو الفلتر.',
    sum_total: 'إجمالي العروض', sum_approved: 'معتمدة', sum_sent: 'مُرسلة', sum_draft: 'مسودات',
    tax_treatment: 'المعالجة الضريبية',
  },
  en: {
    title: 'Quotes', subtitle: 'Create quotes and track their status. Approving a quote issues the invoice automatically.',
    none: 'No quotes yet.', error: 'Could not load quotes.', loading: 'Loading…',
    new_quote: 'New quote', number: 'Number', currency: 'Currency', subtotal: 'Subtotal',
    tax: 'Tax', discount: 'Discount', total: 'Total', valid_until: 'Valid until', notes: 'Notes',
    create: 'Create quote', creating: 'Creating…', view: 'View', approve: 'Approve & issue invoice',
    approving: 'Approving…', reject: 'Reject', reject_ref: 'Rejecting is a client-side action — shown here for reference only.',
    approved_note: 'Approved — invoice issued.', created_at: 'Created',
    optional: 'optional', close: 'Close', details: 'Quote details',
    search_ph: 'Search by quote number…', all: 'All', no_match: 'No quotes match your search or filter.',
    sum_total: 'Total quotes', sum_approved: 'Approved', sum_sent: 'Sent', sum_draft: 'Drafts',
    tax_treatment: 'Tax treatment',
  },
}

export const QUOTE_STATUS: Record<string, { ar: string; en: string; tone: string }> = {
  draft: { ar: 'مسودة', en: 'Draft', tone: 'bg-surface-hover text-text-secondary' },
  sent: { ar: 'مُرسل', en: 'Sent', tone: 'bg-info/15 text-info' },
  approved: { ar: 'معتمد', en: 'Approved', tone: 'bg-success/15 text-success' },
  rejected: { ar: 'مرفوض', en: 'Rejected', tone: 'bg-danger/15 text-danger' },
  expired: { ar: 'منتهٍ', en: 'Expired', tone: 'bg-surface-hover text-text-muted' },
}

export function quoteStatusMeta(status: string, ar: boolean) {
  const m = QUOTE_STATUS[status] ?? { ar: status, en: status, tone: 'bg-surface-hover text-text-secondary' }
  return { label: ar ? m.ar : m.en, tone: m.tone }
}

type Copy = (typeof COPY)['ar']

export function QuotesPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const c = COPY[locale]
  const canManage = useAuth((s) => s.hasPermission('billing.manage'))
  const qc = useQueryClient()
  const [selected, setSelected] = useState<Quote | null>(null)
  const [creating, setCreating] = useState(false)
  const [term, setTerm] = useState('')
  const [statusFilter, setStatusFilter] = useState<'all' | string>('all')

  const q = useQuery({ queryKey: ['billing', 'quotes'], queryFn: listQuotes })
  const quotes = q.data ?? []

  const summary = {
    total: quotes.length,
    approved: quotes.filter((x) => x.status === 'approved').length,
    sent: quotes.filter((x) => x.status === 'sent').length,
    draft: quotes.filter((x) => x.status === 'draft').length,
  }
  const statusChips: string[] = ['all', ...Object.keys(QUOTE_STATUS)]
  const needle = term.trim().toLowerCase()
  const filtered = quotes.filter((x) => {
    if (statusFilter !== 'all' && x.status !== statusFilter) return false
    if (needle && !(x.number ?? '').toLowerCase().includes(needle)) return false
    return true
  })

  return (
    <div className="flex w-full flex-col gap-4">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
          <p className="text-sm text-text-secondary">{c.subtitle}</p>
        </div>
        {canManage ? (
          <button
            onClick={() => setCreating(true)}
            className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700"
          >
            <Plus size={15} /> {c.new_quote}
          </button>
        ) : null}
      </header>

      <BillingTabs />

      {/* Summary — quote pipeline at a glance. */}
      {!q.isLoading && !q.isError && quotes.length > 0 && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <QuoteSummaryCard label={c.sum_total} value={summary.total} tone="brand" />
          <QuoteSummaryCard label={c.sum_approved} value={summary.approved} tone="success" />
          <QuoteSummaryCard label={c.sum_sent} value={summary.sent} tone="info" />
          <QuoteSummaryCard label={c.sum_draft} value={summary.draft} tone="muted" />
        </div>
      )}

      {/* Search + status filters. */}
      {!q.isLoading && !q.isError && quotes.length > 0 && (
        <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-3 sm:flex-row sm:items-center sm:justify-between">
          <label className="relative flex w-full items-center sm:max-w-xs">
            <Search size={15} className="pointer-events-none absolute start-3 text-text-muted" aria-hidden />
            <input
              value={term}
              onChange={(e) => setTerm(e.target.value)}
              placeholder={c.search_ph}
              dir="ltr"
              className="w-full rounded-xl border border-border bg-surface-secondary py-2 pe-3 ps-9 text-sm text-text-primary placeholder:text-text-muted focus:border-brand-500 focus:outline-none"
            />
          </label>
          <div className="flex flex-wrap gap-2">
            {statusChips.map((f) => (
              <button
                key={f}
                onClick={() => setStatusFilter(f)}
                className={`rounded-full px-3 py-1 text-xs font-semibold ${
                  statusFilter === f ? 'bg-brand-500 text-white' : 'bg-surface-hover text-text-secondary hover:text-text-primary'
                }`}
              >
                {f === 'all' ? c.all : quoteStatusMeta(f, ar).label}
              </button>
            ))}
          </div>
        </div>
      )}

      <div className="flex flex-col gap-2">
          {q.isLoading ? (
            <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.loading}</p>
          ) : q.isError ? (
            <p className="rounded-xl border border-danger/30 bg-danger/5 p-8 text-center text-sm text-danger">{c.error}</p>
          ) : quotes.length === 0 ? (
            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border p-12 text-center text-text-secondary">
              <FileText size={24} /><span className="text-sm">{c.none}</span>
            </div>
          ) : filtered.length === 0 ? (
            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border p-12 text-center text-text-secondary">
              <FileText size={24} /><span className="text-sm">{c.no_match}</span>
            </div>
          ) : (
            filtered.map((quote) => {
              const status = quoteStatusMeta(quote.status, ar)
              return (
                <button
                  key={quote.id}
                  onClick={() => setSelected(quote)}
                  className={`flex items-center justify-between gap-3 rounded-2xl border bg-surface p-4 text-start transition-colors hover:border-brand-400 ${
                    selected?.id === quote.id ? 'border-brand-500' : 'border-border'
                  }`}
                >
                  <div className="flex flex-col gap-1">
                    <span className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{quote.number}</span>
                    <span className="tnum text-lg font-extrabold text-text-primary" dir="ltr">{formatMoney(quote.total, quote.currency)}</span>
                    <TaxTreatmentChip treatment={quote.tax_treatment} ar={ar} />
                    <span className="text-[11px] text-text-muted">{c.created_at}: <span className="tnum">{formatDate(quote.created_at)}</span></span>
                  </div>
                  <span className={`whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold ${status.tone}`}>{status.label}</span>
                </button>
              )
            })
          )}
      </div>

      {creating ? (
        <CreateQuoteDrawer
          c={c}
          ar={ar}
          onClose={() => setCreating(false)}
          onCreated={() => { qc.invalidateQueries({ queryKey: ['billing', 'quotes'] }); setCreating(false) }}
        />
      ) : null}

      {selected ? (
        <QuoteDrawer
          quote={selected}
          c={c}
          ar={ar}
          canManage={canManage}
          onClose={() => setSelected(null)}
          onApproved={() => { qc.invalidateQueries({ queryKey: ['billing', 'quotes'] }); qc.invalidateQueries({ queryKey: ['billing', 'invoices'] }) }}
        />
      ) : null}
    </div>
  )
}

/** Slide-over drawer that hosts the create-quote form — opened from the header button, keeping the page for data. */
function CreateQuoteDrawer({ c, ar, onClose, onCreated }: { c: Copy; ar: boolean; onClose: () => void; onCreated: () => void }) {
  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-black/30" onClick={onClose}>
      <div
        className="flex h-full w-full max-w-md flex-col gap-4 overflow-y-auto bg-surface p-5 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3">
          <h2 className="flex items-center gap-2 text-lg font-extrabold text-text-primary"><Plus size={18} /> {c.new_quote}</h2>
          <button onClick={onClose} className="rounded-lg p-1.5 text-text-secondary hover:bg-surface-hover" aria-label={c.close}><X size={18} /></button>
        </div>
        <CreateQuoteForm c={c} ar={ar} onCreated={onCreated} embedded />
      </div>
    </div>
  )
}

function CreateQuoteForm({ c, ar, onCreated, embedded }: { c: Copy; ar: boolean; onCreated: () => void; embedded?: boolean }) {
  const initial: NewQuote = { currency: 'SAR', subtotal: 0, discount: 0, tax_treatment: DEFAULT_TREATMENT }
  const [form, setForm] = useState<NewQuote>(initial)
  const [number, setNumber] = useState('')
  const [notes, setNotes] = useState('')

  const num = (v: number | undefined) => (typeof v === 'number' ? v : 0)
  // Tax is DERIVED from the treatment (never typed); total follows. The backend re-derives authoritatively.
  const derivedTax = taxForTreatment(form.tax_treatment, num(form.subtotal))
  const computedTotal = Math.max(0, num(form.subtotal) + derivedTax - num(form.discount))

  const createM = useMutation({
    mutationFn: () =>
      createQuote({
        ...form,
        tax: derivedTax,
        total: computedTotal,
        number: number.trim() || undefined,
        notes: notes.trim() || null,
      }),
    onSuccess: () => {
      onCreated()
      setForm(initial)
      setNumber('')
      setNotes('')
    },
  })

  return (
    <form
      onSubmit={(e) => { e.preventDefault(); createM.mutate() }}
      className={embedded ? 'flex flex-col gap-3' : 'flex h-fit flex-col gap-3 rounded-2xl border border-border bg-surface p-4'}
    >
      {!embedded && <h3 className="flex items-center gap-2 text-sm font-bold text-text-primary"><Plus size={15} /> {c.new_quote}</h3>}

      <Field label={`${c.number} (${c.optional})`}>
        <input value={number} onChange={(e) => setNumber(e.target.value)} placeholder="QUO-…" dir="ltr"
          className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
      </Field>
      <Field label={c.currency}>
        <input required value={form.currency} onChange={(e) => setForm((f) => ({ ...f, currency: e.target.value.toUpperCase() }))}
          maxLength={3} dir="ltr" className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm uppercase text-text-primary" />
      </Field>
      <div className="grid grid-cols-2 gap-2">
        <Field label={c.subtotal}>
          <input type="number" min={0} step="0.01" value={form.subtotal} onChange={(e) => setForm((f) => ({ ...f, subtotal: Number(e.target.value) }))}
            dir="ltr" className="tnum rounded-lg border border-border bg-background px-2 py-1.5 text-sm text-text-primary" />
        </Field>
        <Field label={c.discount}>
          <input type="number" min={0} step="0.01" value={form.discount} onChange={(e) => setForm((f) => ({ ...f, discount: Number(e.target.value) }))}
            dir="ltr" className="tnum rounded-lg border border-border bg-background px-2 py-1.5 text-sm text-text-primary" />
        </Field>
      </div>
      <Field label={c.tax_treatment}>
        <select
          value={form.tax_treatment} onChange={(e) => setForm((f) => ({ ...f, tax_treatment: e.target.value }))}
          className="w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary"
        >
          {SELECTABLE_TREATMENTS.map((k) => (
            <option key={k} value={k}>{taxTreatmentShort(k, ar)}</option>
          ))}
        </select>
        {/* Full treatment name — the extra detail, shown quietly under the compact selector. */}
        <span className="mt-1 text-[11px] text-text-muted">{taxTreatmentLabel(form.tax_treatment, ar)}</span>
      </Field>
      <div className="flex flex-col gap-1 rounded-lg bg-surface-hover px-3 py-2 text-sm">
        <div className="flex items-center justify-between text-text-secondary">
          <span>{c.tax}</span>
          <span className="tnum" dir="ltr">{formatMoney(derivedTax, form.currency ?? 'SAR')}</span>
        </div>
        <div className="flex items-center justify-between">
          <span className="font-semibold text-text-secondary">{c.total}</span>
          <span className="tnum font-extrabold text-text-primary" dir="ltr">{formatMoney(computedTotal, form.currency ?? 'SAR')}</span>
        </div>
      </div>
      <Field label={`${c.valid_until} (${c.optional})`}>
        <DateField value={form.valid_until ?? ''} onChange={(v) => setForm((f) => ({ ...f, valid_until: v || null }))} />
      </Field>
      <Field label={`${c.notes} (${c.optional})`}>
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} maxLength={2000}
          className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
      </Field>
      <button type="submit" disabled={createM.isPending}
        className="rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50">
        {createM.isPending ? c.creating : c.create}
      </button>
    </form>
  )
}

function QuoteDrawer({
  quote, c, ar, canManage, onClose, onApproved,
}: {
  quote: Quote; c: Copy; ar: boolean; canManage: boolean; onClose: () => void; onApproved: () => void
}) {
  const status = quoteStatusMeta(quote.status, ar)
  const approveM = useMutation({ mutationFn: () => approveQuote(quote.id), onSuccess: onApproved })
  const canApprove = canManage && !['approved', 'rejected', 'expired'].includes(quote.status)

  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-black/30" onClick={onClose}>
      <div
        className="flex h-full w-full max-w-md flex-col gap-4 overflow-y-auto bg-surface p-5 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 className="text-lg font-extrabold text-text-primary">{c.details}</h2>
            <span className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{quote.number}</span>
          </div>
          <button onClick={onClose} className="rounded-lg p-1.5 text-text-secondary hover:bg-surface-hover" aria-label={c.close}><X size={18} /></button>
        </div>

        <span className={`w-fit rounded-full px-2.5 py-1 text-[11px] font-semibold ${status.tone}`}>{status.label}</span>

        <dl className="flex flex-col gap-2 rounded-2xl border border-border p-4 text-sm">
          <Row label={c.subtotal} value={formatMoney(quote.subtotal, quote.currency)} />
          <Row label={c.tax_treatment} value={taxTreatmentLabel(quote.tax_treatment, ar) ?? '—'} />
          <Row label={c.tax} value={formatMoney(quote.tax, quote.currency)} />
          <Row label={c.discount} value={formatMoney(quote.discount, quote.currency)} />
          <div className="my-1 border-t border-border" />
          <Row label={c.total} value={formatMoney(quote.total, quote.currency)} strong />
          <Row label={c.valid_until} value={formatDate(quote.valid_until)} />
          <Row label={c.created_at} value={formatDate(quote.created_at)} />
        </dl>

        {quote.notes ? <p className="rounded-xl bg-surface-hover px-3 py-2 text-sm text-text-secondary">{quote.notes}</p> : null}

        {approveM.isSuccess ? (
          <p className="rounded-xl bg-success/10 px-3 py-2 text-sm font-semibold text-success">{c.approved_note}</p>
        ) : (
          <div className="flex flex-col gap-2">
            {canApprove ? (
              <button onClick={() => approveM.mutate()} disabled={approveM.isPending}
                className="flex items-center justify-center gap-2 rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50">
                <Check size={15} /> {approveM.isPending ? c.approving : c.approve}
              </button>
            ) : null}
            {/* Reject has no staff endpoint — shown for reference only (a client-portal action). */}
            <button disabled title={c.reject_ref}
              className="flex cursor-not-allowed items-center justify-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-muted opacity-60">
              <X size={15} /> {c.reject}
            </button>
            <span className="text-[11px] text-text-muted">{c.reject_ref}</span>
          </div>
        )}
      </div>
    </div>
  )
}

/** Compact badge showing a document's VAT rate/status (with a «تاريخي» tag for the legacy rate). */
export function TaxTreatmentChip({ treatment, ar }: { treatment: string | null; ar: boolean }) {
  const short = taxTreatmentShort(treatment, ar)
  if (!short) return null
  const historical = isHistoricalTreatment(treatment)
  return (
    <span
      title={taxTreatmentLabel(treatment, ar) ?? undefined}
      className={`inline-flex w-fit items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-semibold ${
        historical ? 'bg-warning/15 text-warning' : 'bg-surface-hover text-text-secondary'
      }`}
    >
      {short}{historical ? <span className="opacity-80">· {ar ? 'تاريخي' : 'historical'}</span> : null}
    </span>
  )
}

/** UX-KPI-PRESENTATION-001 — the product's card, with this page's tone on its dot. */
function QuoteSummaryCard({ label, value, tone }: { label: string; value: number; tone: 'brand' | 'success' | 'info' | 'muted' }) {
  return <StatCard label={label} value={value} tone={tone === 'muted' ? 'neutral' : tone} dot />
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
      {label}
      {children}
    </label>
  )
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <dt className="text-text-secondary">{label}</dt>
      <dd className={`tnum ${strong ? 'text-base font-extrabold text-text-primary' : 'text-text-primary'}`} dir="ltr">{value}</dd>
    </div>
  )
}
