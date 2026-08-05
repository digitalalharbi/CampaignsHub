import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, CheckCircle2, Loader2, Receipt, ThumbsDown, ThumbsUp } from 'lucide-react'
import {
  approvePortalQuote, getPortalQuote, rejectPortalQuote, formatDate, formatMoney, type PortalInvoice,
} from './portalAccountApi'
import { quoteStatusMeta } from './ClientQuotesPage'
import { PortalShell } from './PortalShell'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { usePortalGuard } from './usePortalGuard'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { useClientSpacePath } from './clientSpace'

const COPY = {
  ar: {
    title: 'تفاصيل العرض', back: 'عروض الأسعار', error: 'تعذّر تحميل العرض.',
    subtotal: 'المجموع الفرعي', tax: 'الضريبة', discount: 'الخصم', total: 'الإجمالي',
    valid_until: 'صالح حتى', created: 'أُنشئ في',
    approve: 'الموافقة على العرض', reject: 'رفض العرض',
    approved_title: 'تمت الموافقة — صدرت الفاتورة',
    approved_note: 'شكراً لك. تم إصدار الفاتورة التالية بناءً على موافقتك.',
    view_invoice: 'عرض الفاتورة', invoice: 'الفاتورة',
    rejected_note: 'تم تسجيل رفضك لهذا العرض.',
    action_error: 'تعذّر تنفيذ الإجراء. حاول مرة أخرى.',
  },
  en: {
    title: 'Quote details', back: 'Quotes', error: 'Could not load the quote.',
    subtotal: 'Subtotal', tax: 'Tax', discount: 'Discount', total: 'Total',
    valid_until: 'Valid until', created: 'Created',
    approve: 'Approve quote', reject: 'Reject quote',
    approved_title: 'Approved — invoice issued',
    approved_note: 'Thank you. The invoice below was issued from your approval.',
    view_invoice: 'View invoice', invoice: 'Invoice',
    rejected_note: 'Your rejection of this quote has been recorded.',
    action_error: 'Could not complete the action. Please try again.',
  },
}

export function ClientQuoteDetailPage() {
  const spaceTo = useClientSpacePath()
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { id = '' } = useParams()
  const q = useQuery({ queryKey: ['client', 'quote', id], queryFn: () => getPortalQuote(id), retry: false })
  usePortalGuard(q.isError, q.error)

  const [issuedInvoice, setIssuedInvoice] = useState<PortalInvoice | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['client', 'quote', id] })
    qc.invalidateQueries({ queryKey: ['client', 'quotes'] })
    qc.invalidateQueries({ queryKey: ['client', 'invoices'] })
  }

  const approve = useMutation({
    mutationFn: () => approvePortalQuote(id),
    onSuccess: (r) => { setIssuedInvoice(r.invoice); setActionError(null); invalidate() },
    onError: (e) => setActionError(toApiError(e).message || t.action_error),
  })
  const reject = useMutation({
    mutationFn: () => rejectPortalQuote(id),
    onSuccess: () => { setActionError(null); invalidate() },
    onError: (e) => setActionError(toApiError(e).message || t.action_error),
  })

  if (q.isLoading) return <PortalShell title={t.title} nav showLogout><div className="h-64 animate-pulse rounded-2xl bg-surface-secondary" /></PortalShell>
  if (q.isError) return <PortalShell title={t.title} nav showLogout><QueryFailure error={q.error} ar={ar} onRetry={() => void q.refetch()} fallbackTitle={t.error} testId="portal-failure" /></PortalShell>
  const quote = q.data!
  const status = quoteStatusMeta(quote.status, ar)
  const canAct = ['draft', 'sent'].includes(quote.status)
  const pending = approve.isPending || reject.isPending

  return (
    <PortalShell title={t.title} nav showLogout>
      <Link to={spaceTo('/quotes')} className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-text-secondary hover:text-text-primary"><ArrowLeft size={15} className="rtl:rotate-180" /> {t.back}</Link>

      <div className="rounded-2xl border border-border bg-surface p-5 sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="font-mono text-sm font-semibold text-brand-600" dir="ltr">{quote.number}</div>
          <span className={`rounded-full px-3 py-1 text-xs font-semibold ${status.tone}`}>{status.label}</span>
        </div>

        <dl className="mt-5 space-y-2 text-sm">
          <Row label={t.subtotal} value={formatMoney(quote.subtotal, quote.currency)} />
          <Row label={t.tax} value={formatMoney(quote.tax, quote.currency)} />
          <Row label={t.discount} value={formatMoney(quote.discount, quote.currency)} />
          <div className="flex items-center justify-between border-t border-border pt-2">
            <dt className="font-bold text-text-primary">{t.total}</dt>
            <dd className="tnum text-lg font-extrabold text-text-primary" dir="ltr">{formatMoney(quote.total, quote.currency)}</dd>
          </div>
        </dl>

        <div className="mt-4 grid grid-cols-2 gap-3 text-[11px] text-text-muted">
          <span>{t.valid_until}: <span className="tnum">{formatDate(quote.valid_until)}</span></span>
          <span>{t.created}: <span className="tnum">{formatDate(quote.created_at)}</span></span>
        </div>

        {canAct && !issuedInvoice && (
          <div className="mt-5 flex flex-wrap gap-2">
            <button onClick={() => approve.mutate()} disabled={pending} className="flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
              {approve.isPending ? <Loader2 size={15} className="animate-spin" /> : <ThumbsUp size={15} />} {t.approve}
            </button>
            <button onClick={() => reject.mutate()} disabled={pending} className="flex items-center gap-1.5 rounded-xl border border-border px-4 py-2.5 text-sm font-semibold text-text-secondary hover:text-text-primary disabled:opacity-60">
              {reject.isPending ? <Loader2 size={15} className="animate-spin" /> : <ThumbsDown size={15} />} {t.reject}
            </button>
          </div>
        )}

        {actionError && <p className="mt-3 rounded-lg bg-[var(--negative-background)] px-3 py-2 text-sm text-danger">{actionError}</p>}

        {reject.isSuccess && !issuedInvoice && (
          <p className="mt-4 rounded-xl bg-surface-secondary px-4 py-3 text-sm text-text-secondary">{t.rejected_note}</p>
        )}
      </div>

      {issuedInvoice && (
        <div className="mt-4 rounded-2xl border border-success/30 bg-success/5 p-5">
          <div className="flex items-center gap-1.5 text-sm font-bold text-success"><CheckCircle2 size={16} /> {t.approved_title}</div>
          <p className="mt-1 text-sm text-text-secondary">{t.approved_note}</p>
          <div className="mt-3 flex items-center justify-between gap-3 rounded-xl border border-border bg-surface p-4">
            <div>
              <div className="text-[11px] text-text-muted">{t.invoice}</div>
              <div className="font-mono text-sm font-semibold text-brand-600" dir="ltr">{issuedInvoice.number}</div>
              <div className="tnum mt-0.5 text-sm font-bold text-text-primary" dir="ltr">{formatMoney(issuedInvoice.total, issuedInvoice.currency)}</div>
            </div>
            <button onClick={() => navigate(`/client/invoices/${issuedInvoice.id}`)} className="flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
              <Receipt size={15} /> {t.view_invoice}
            </button>
          </div>
        </div>
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
