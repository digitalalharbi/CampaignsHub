import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowRight, Receipt } from 'lucide-react'
import { listPortalInvoices, formatDate, formatMoney, type PortalInvoice } from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { usePortalGuard } from './usePortalGuard'
import { useUi } from '@/stores/ui'
import { useClientSpacePath } from './clientSpace'

const COPY = {
  ar: {
    title: 'الفواتير', subtitle: 'اطّلع على فواتيرك وحالة السداد.',
    none: 'لا توجد فواتير بعد.', error: 'تعذّر تحميل الفواتير.',
    due: 'تاريخ الاستحقاق', details: 'التفاصيل',
  },
  en: {
    title: 'Invoices', subtitle: 'See your invoices and their payment status.',
    none: 'No invoices yet.', error: 'Could not load invoices.',
    due: 'Due', details: 'Details',
  },
}

export const INVOICE_STATUS: Record<string, { ar: string; en: string; tone: string }> = {
  paid: { ar: 'مدفوعة', en: 'Paid', tone: 'bg-success/15 text-success' },
  partially_paid: { ar: 'مدفوعة جزئياً', en: 'Partially paid', tone: 'bg-warning/15 text-warning' },
  unpaid: { ar: 'غير مدفوعة', en: 'Unpaid', tone: 'bg-warning/15 text-warning' },
  refunded: { ar: 'مستردّة', en: 'Refunded', tone: 'bg-surface-secondary text-text-muted' },
  void: { ar: 'ملغاة', en: 'Void', tone: 'bg-surface-secondary text-text-muted' },
}

export function invoiceStatusMeta(status: string, ar: boolean) {
  const m = INVOICE_STATUS[status] ?? { ar: status, en: status, tone: 'bg-surface-secondary text-text-muted' }
  return { label: ar ? m.ar : m.en, tone: m.tone }
}

export function ClientInvoicesPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const q = useQuery({ queryKey: ['client', 'invoices'], queryFn: listPortalInvoices, retry: false })
  usePortalGuard(q.isError, q.error)

  const rows = q.data ?? []

  return (
    <PortalShell title={t.title} nav showLogout>
      <div className="mb-5">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t.title}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t.subtitle}</p>
      </div>

      {q.isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2">{[0, 1].map((i) => <div key={i} className="h-32 animate-pulse rounded-2xl bg-surface-secondary" />)}</div>
      ) : q.isError ? (
        <QueryFailure error={q.error} ar={ar} onRetry={() => void q.refetch()} fallbackTitle={t.error} testId="portal-failure" />
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-text-muted"><Receipt size={26} /><span className="text-sm">{t.none}</span></div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {rows.map((invoice) => <InvoiceCard key={invoice.id} invoice={invoice} ar={ar} t={t} />)}
        </div>
      )}
    </PortalShell>
  )
}

function InvoiceCard({ invoice, ar, t }: { invoice: PortalInvoice; ar: boolean; t: typeof COPY.ar }) {
  const spaceTo = useClientSpacePath()
  const status = invoiceStatusMeta(invoice.payment_status, ar)
  return (
    <Link to={spaceTo(`/invoices/${invoice.id}`)} className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5 hover:border-brand-400">
      <div className="flex items-start justify-between gap-2">
        <div className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{invoice.number}</div>
        <span className={`whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold ${status.tone}`}>{status.label}</span>
      </div>
      <div className="tnum text-xl font-extrabold text-text-primary" dir="ltr">{formatMoney(invoice.total, invoice.currency)}</div>
      <div className="flex items-center justify-between text-[11px] text-text-muted">
        <span>{t.due}: <span className="tnum">{formatDate(invoice.due_date)}</span></span>
        <span className="flex items-center gap-1 font-semibold text-brand-600">{t.details} <ArrowRight size={13} className="rtl:rotate-180" /></span>
      </div>
    </Link>
  )
}
