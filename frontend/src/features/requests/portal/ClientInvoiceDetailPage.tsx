import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, ArrowLeft, CreditCard, Loader2 } from 'lucide-react'
import { getPortalInvoice, payPortalInvoice, formatDate, formatMoney, type PortalPayment } from './portalAccountApi'
import { invoiceStatusMeta } from './ClientInvoicesPage'
import { PortalShell } from './PortalShell'
import { taxTreatmentLabel } from '@/features/billing/taxTreatment'
import { usePortalGuard } from './usePortalGuard'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { useClientSpacePath } from './clientSpace'

const COPY = {
  ar: {
    title: 'تفاصيل الفاتورة', back: 'الفواتير', error: 'تعذّر تحميل الفاتورة.',
    subtotal: 'المجموع الفرعي', tax: 'الضريبة', tax_treatment: 'المعالجة الضريبية', discount: 'الخصم', total: 'الإجمالي',
    paid_amount: 'المدفوع', due: 'تاريخ الاستحقاق', issued: 'صدرت في', paid_at: 'دُفعت في',
    pay: 'ادفع الآن',
    // HONEST payment states — never a fake receipt.
    awaiting_title: 'الدفع الإلكتروني غير متاح بعد',
    awaiting_note: 'الدفع الإلكتروني غير متاح حالياً — يجري ربط مزوّد الدفع. لم يتم إجراء أي خصم. سنُعلمك فور تفعيله.',
    processing_title: 'تم فتح جلسة الدفع',
    action_error: 'تعذّر بدء الدفع. حاول مرة أخرى.',
  },
  en: {
    title: 'Invoice details', back: 'Invoices', error: 'Could not load the invoice.',
    subtotal: 'Subtotal', tax: 'Tax', tax_treatment: 'Tax treatment', discount: 'Discount', total: 'Total',
    paid_amount: 'Paid', due: 'Due', issued: 'Issued', paid_at: 'Paid at',
    pay: 'Pay now',
    awaiting_title: 'Online payment isn’t available yet',
    awaiting_note: 'Online payment isn’t available yet — the provider is being connected. No charge was made. We’ll let you know as soon as it’s live.',
    processing_title: 'Payment session opened',
    action_error: 'Could not start the payment. Please try again.',
  },
}

export function ClientInvoiceDetailPage() {
  const spaceTo = useClientSpacePath()
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()
  const { id = '' } = useParams()
  const q = useQuery({ queryKey: ['client', 'invoice', id], queryFn: () => getPortalInvoice(id), retry: false })
  usePortalGuard(q.isError, q.error)

  const [payment, setPayment] = useState<PortalPayment | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  const pay = useMutation({
    mutationFn: () => payPortalInvoice(id),
    onSuccess: (p) => {
      setPayment(p); setActionError(null)
      qc.invalidateQueries({ queryKey: ['client', 'invoice', id] })
      qc.invalidateQueries({ queryKey: ['client', 'invoices'] })
    },
    onError: (e) => setActionError(toApiError(e).message || t.action_error),
  })

  if (q.isLoading) return <PortalShell title={t.title} nav showLogout><div className="h-64 animate-pulse rounded-2xl bg-surface-secondary" /></PortalShell>
  if (q.isError) return <PortalShell title={t.title} nav showLogout><div className="rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-6 text-center text-sm text-danger">{t.error}</div></PortalShell>
  const invoice = q.data!
  const status = invoiceStatusMeta(invoice.payment_status, ar)
  const canPay = ['unpaid', 'partially_paid'].includes(invoice.payment_status)

  return (
    <PortalShell title={t.title} nav showLogout>
      <Link to={spaceTo('/invoices')} className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {t.back}</Link>

      <div className="rounded-2xl border border-border bg-surface p-5 sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="font-mono text-sm font-semibold text-brand-600" dir="ltr">{invoice.number}</div>
          <span className={`rounded-full px-3 py-1 text-xs font-semibold ${status.tone}`}>{status.label}</span>
        </div>

        <dl className="mt-5 space-y-2 text-sm">
          <Row label={t.subtotal} value={formatMoney(invoice.subtotal, invoice.currency)} />
          {taxTreatmentLabel(invoice.tax_treatment, ar) ? (
            <Row label={t.tax_treatment} value={taxTreatmentLabel(invoice.tax_treatment, ar) as string} />
          ) : null}
          <Row label={t.tax} value={formatMoney(invoice.tax, invoice.currency)} />
          <Row label={t.discount} value={formatMoney(invoice.discount, invoice.currency)} />
          <div className="flex items-center justify-between border-t border-border pt-2">
            <dt className="font-bold text-text-primary">{t.total}</dt>
            <dd className="tnum text-lg font-extrabold text-text-primary" dir="ltr">{formatMoney(invoice.total, invoice.currency)}</dd>
          </div>
          <Row label={t.paid_amount} value={formatMoney(invoice.amount_paid, invoice.currency)} />
        </dl>

        <div className="mt-4 grid grid-cols-2 gap-3 text-[11px] text-text-muted sm:grid-cols-3">
          <span>{t.due}: <span className="tnum">{formatDate(invoice.due_date)}</span></span>
          <span>{t.issued}: <span className="tnum">{formatDate(invoice.issued_at)}</span></span>
          <span>{t.paid_at}: <span className="tnum">{formatDate(invoice.paid_at)}</span></span>
        </div>

        {canPay && (
          <div className="mt-5">
            <button onClick={() => pay.mutate()} disabled={pay.isPending} className="flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
              {pay.isPending ? <Loader2 size={15} className="animate-spin" /> : <CreditCard size={15} />} {t.pay}
            </button>
          </div>
        )}

        {actionError && <p className="mt-3 rounded-lg bg-[var(--negative-background)] px-3 py-2 text-sm text-danger">{actionError}</p>}
      </div>

      {/* HONEST payment result — with the Null provider this is `awaiting_provider_credentials`, never a fake success. */}
      {payment && (
        payment.payment_state === 'awaiting_provider_credentials' ? (
          <div className="mt-4 rounded-2xl border border-warning/30 bg-warning/5 p-5">
            <div className="flex items-center gap-1.5 text-sm font-bold text-warning"><AlertCircle size={16} /> {t.awaiting_title}</div>
            <p className="mt-1 text-sm text-text-secondary">{t.awaiting_note}</p>
          </div>
        ) : (
          <div className="mt-4 rounded-2xl border border-border bg-surface p-5">
            <div className="flex items-center gap-1.5 text-sm font-bold text-text-primary"><CreditCard size={16} /> {t.processing_title}</div>
            <p className="mt-1 text-sm text-text-secondary">{payment.message}</p>
          </div>
        )
      )}
    </PortalShell>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between">
      <dt className="text-text-secondary">{label}</dt>
      <dd className="tnum text-text-primary" dir="ltr">{value}</dd>
    </div>
  )
}
