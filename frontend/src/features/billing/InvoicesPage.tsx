import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Receipt, X } from 'lucide-react'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { useUi } from '@/stores/ui'
import { BillingTabs } from './BillingTabs'
import { TaxTreatmentChip } from './QuotesPage'
import { taxTreatmentLabel } from './taxTreatment'
import { formatDate, formatMoney, isPayable, listInvoices, type Invoice, type InvoiceStatus } from './api'

const COPY = {
  ar: {
    title: 'الفواتير', subtitle: 'تابع الفواتير الصادرة وحالة السداد والمبلغ المدفوع.',
    none: 'لا توجد فواتير.', error: 'تعذّر تحميل الفواتير.', loading: 'جارٍ التحميل…',
    all: 'الكل', number: 'الرقم', total: 'الإجمالي', paid: 'المدفوع', outstanding: 'المتبقي',
    subtotal: 'المجموع الفرعي', tax: 'الضريبة', discount: 'الخصم', due: 'الاستحقاق', issued: 'صدرت',
    paid_at: 'تاريخ السداد', created_at: 'أُنشئت', details: 'تفاصيل الفاتورة', close: 'إغلاق',
    tax_treatment: 'المعالجة الضريبية',
    payable_note: 'قابلة للسداد — ابدأ الدفع من صفحة المدفوعات.',
  },
  en: {
    title: 'Invoices', subtitle: 'Track issued invoices, their status, and the amount paid.',
    none: 'No invoices.', error: 'Could not load invoices.', loading: 'Loading…',
    all: 'All', number: 'Number', total: 'Total', paid: 'Paid', outstanding: 'Outstanding',
    subtotal: 'Subtotal', tax: 'Tax', discount: 'Discount', due: 'Due', issued: 'Issued',
    paid_at: 'Paid at', created_at: 'Created', details: 'Invoice details', close: 'Close',
    tax_treatment: 'Tax treatment',
    payable_note: 'Payable — start a payment from the Payments page.',
  },
}

export const INVOICE_STATUS: Record<string, { ar: string; en: string; tone: string }> = {
  draft: { ar: 'مسودة', en: 'Draft', tone: 'bg-surface-hover text-text-secondary' },
  issued: { ar: 'صادرة', en: 'Issued', tone: 'bg-info/15 text-info' },
  partially_paid: { ar: 'مدفوعة جزئياً', en: 'Partially paid', tone: 'bg-warning/15 text-warning' },
  paid: { ar: 'مدفوعة', en: 'Paid', tone: 'bg-success/15 text-success' },
  void: { ar: 'ملغاة', en: 'Void', tone: 'bg-surface-hover text-text-muted' },
  refunded: { ar: 'مستردّة', en: 'Refunded', tone: 'bg-surface-hover text-text-muted' },
}

export function invoiceStatusMeta(status: string, ar: boolean) {
  const m = INVOICE_STATUS[status] ?? { ar: status, en: status, tone: 'bg-surface-hover text-text-secondary' }
  return { label: ar ? m.ar : m.en, tone: m.tone }
}

const FILTERS: (InvoiceStatus | 'all')[] = ['all', 'issued', 'partially_paid', 'paid', 'refunded', 'void', 'draft']

type Copy = (typeof COPY)['ar']

export function InvoicesPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const c = COPY[locale]
  const [filter, setFilter] = useState<InvoiceStatus | 'all'>('all')
  const [selected, setSelected] = useState<Invoice | null>(null)

  // Fetch the full ledger once — summary cards + filters stay client-side.
  const q = useQuery({ queryKey: ['billing', 'invoices', 'all'], queryFn: () => listInvoices() })
  const all = q.data ?? []
  const invoices = filter === 'all' ? all : all.filter((i) => i.status === filter)

  const outstandingOf = (i: Invoice) => Math.max(0, Number(i.total) - Number(i.amount_paid))
  const summary = {
    total: all.length,
    issued: all.filter((i) => i.status === 'issued' || i.status === 'partially_paid').length,
    paid: all.filter((i) => i.status === 'paid').length,
    outstanding: all.reduce((s, i) => s + (['issued', 'partially_paid'].includes(i.status) ? outstandingOf(i) : 0), 0),
  }

  const filterLabel = (f: InvoiceStatus | 'all') => (f === 'all' ? c.all : invoiceStatusMeta(f, ar).label)

  return (
    <div className="flex w-full flex-col gap-4">
      <header className="flex flex-col gap-1">
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      <BillingTabs />

      {/* Summary — the invoice ledger at a glance (outstanding = unpaid remainder of payable invoices). */}
      {!q.isLoading && !q.isError && all.length > 0 && (
        <StatGrid>
          <StatCard dot tone="brand" label={ar ? 'إجمالي الفواتير' : 'Total invoices'} value={String(summary.total)} />
          <StatCard dot tone="info" label={ar ? 'قابلة للسداد' : 'Payable'} value={String(summary.issued)} />
          <StatCard dot tone="success" label={ar ? 'مدفوعة' : 'Paid'} value={String(summary.paid)} />
          <StatCard dot tone="warning" label={ar ? 'المتبقي' : 'Outstanding'} value={formatMoney(summary.outstanding, all[0]?.currency ?? 'SAR')} />
        </StatGrid>
      )}

      <div className="flex flex-wrap gap-2">
        {FILTERS.map((f) => (
          <button
            key={f}
            onClick={() => setFilter(f)}
            className={`rounded-full px-3 py-1 text-xs font-semibold ${
              filter === f ? 'bg-brand-500 text-white' : 'bg-surface-hover text-text-secondary hover:text-text-primary'
            }`}
          >
            {filterLabel(f)}
          </button>
        ))}
      </div>

      {q.isLoading ? (
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.loading}</p>
      ) : q.isError ? (
        <p className="rounded-xl border border-danger/30 bg-danger/5 p-8 text-center text-sm text-danger">{c.error}</p>
      ) : invoices.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border p-12 text-center text-text-secondary">
          <Receipt size={24} /><span className="text-sm">{c.none}</span>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-2xl border border-border">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="bg-surface-hover text-xs text-text-secondary">
              <tr>
                <th className="p-3 text-start font-semibold">{c.number}</th>
                <th className="p-3 text-start font-semibold">{c.total}</th>
                <th className="p-3 text-start font-semibold">{c.paid}</th>
                <th className="p-3 text-start font-semibold">{c.outstanding}</th>
                <th className="p-3 text-start font-semibold">{c.due}</th>
                <th className="p-3 text-start font-semibold" />
              </tr>
            </thead>
            <tbody>
              {invoices.map((inv) => {
                const status = invoiceStatusMeta(inv.status, ar)
                const outstanding = Math.max(0, Number(inv.total) - Number(inv.amount_paid))
                return (
                  <tr key={inv.id} className="cursor-pointer border-t border-border hover:bg-surface-hover" onClick={() => setSelected(inv)}>
                    <td className="p-3">
                      <div className="flex flex-col gap-1">
                        <span className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{inv.number}</span>
                        <span className={`w-fit rounded-full px-2 py-0.5 text-[10px] font-semibold ${status.tone}`}>{status.label}</span>
                        <TaxTreatmentChip treatment={inv.tax_treatment} ar={ar} />
                      </div>
                    </td>
                    <td className="p-3 tnum text-text-primary" dir="ltr">{formatMoney(inv.total, inv.currency)}</td>
                    <td className="p-3 tnum text-text-secondary" dir="ltr">{formatMoney(inv.amount_paid, inv.currency)}</td>
                    <td className="p-3 tnum text-text-primary" dir="ltr">{formatMoney(outstanding, inv.currency)}</td>
                    <td className="p-3 tnum text-xs text-text-muted" dir="ltr">{formatDate(inv.due_date)}</td>
                    <td className="p-3 text-xs">{isPayable(inv) ? <span className="text-brand-600">•</span> : null}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {selected ? <InvoiceDrawer invoice={selected} c={c} ar={ar} onClose={() => setSelected(null)} /> : null}
    </div>
  )
}

function InvoiceDrawer({ invoice, c, ar, onClose }: { invoice: Invoice; c: Copy; ar: boolean; onClose: () => void }) {
  const status = invoiceStatusMeta(invoice.status, ar)
  const outstanding = Math.max(0, Number(invoice.total) - Number(invoice.amount_paid))
  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-black/30" onClick={onClose}>
      <div className="flex h-full w-full max-w-md flex-col gap-4 overflow-y-auto bg-surface p-5 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 className="text-lg font-extrabold text-text-primary">{c.details}</h2>
            <span className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{invoice.number}</span>
          </div>
          <button onClick={onClose} className="rounded-lg p-1.5 text-text-secondary hover:bg-surface-hover" aria-label={c.close}><X size={18} /></button>
        </div>

        <span className={`w-fit rounded-full px-2.5 py-1 text-[11px] font-semibold ${status.tone}`}>{status.label}</span>

        <dl className="flex flex-col gap-2 rounded-2xl border border-border p-4 text-sm">
          <Row label={c.subtotal} value={formatMoney(invoice.subtotal, invoice.currency)} />
          <Row label={c.tax_treatment} value={taxTreatmentLabel(invoice.tax_treatment, ar) ?? '—'} />
          <Row label={c.tax} value={formatMoney(invoice.tax, invoice.currency)} />
          <Row label={c.discount} value={formatMoney(invoice.discount, invoice.currency)} />
          <div className="my-1 border-t border-border" />
          <Row label={c.total} value={formatMoney(invoice.total, invoice.currency)} strong />
          <Row label={c.paid} value={formatMoney(invoice.amount_paid, invoice.currency)} />
          <Row label={c.outstanding} value={formatMoney(outstanding, invoice.currency)} />
          <div className="my-1 border-t border-border" />
          <Row label={c.issued} value={formatDate(invoice.issued_at)} />
          <Row label={c.due} value={formatDate(invoice.due_date)} />
          <Row label={c.paid_at} value={formatDate(invoice.paid_at)} />
          <Row label={c.created_at} value={formatDate(invoice.created_at)} />
        </dl>

        {isPayable(invoice) ? (
          <p className="rounded-xl bg-info/10 px-3 py-2 text-sm text-info">{c.payable_note}</p>
        ) : null}
      </div>
    </div>
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
