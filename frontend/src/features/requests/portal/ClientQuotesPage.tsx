import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowRight, ScrollText } from 'lucide-react'
import { listPortalQuotes, formatDate, formatMoney, type PortalQuote } from './portalAccountApi'
import { PortalShell } from './PortalShell'
import { usePortalGuard } from './usePortalGuard'
import { useUi } from '@/stores/ui'

const COPY = {
  ar: {
    title: 'عروض الأسعار', subtitle: 'راجع العروض المقدّمة لك ووافق عليها أو ارفضها.',
    none: 'لا توجد عروض أسعار بعد.', error: 'تعذّر تحميل عروض الأسعار.',
    total: 'الإجمالي', valid_until: 'صالح حتى', details: 'التفاصيل',
  },
  en: {
    title: 'Quotes', subtitle: 'Review the quotes prepared for you and approve or reject them.',
    none: 'No quotes yet.', error: 'Could not load quotes.',
    total: 'Total', valid_until: 'Valid until', details: 'Details',
  },
}

export const QUOTE_STATUS: Record<string, { ar: string; en: string; tone: string }> = {
  draft: { ar: 'مسودة', en: 'Draft', tone: 'bg-surface-secondary text-text-muted' },
  sent: { ar: 'بانتظار ردّك', en: 'Awaiting you', tone: 'bg-warning/15 text-warning' },
  approved: { ar: 'مقبول', en: 'Approved', tone: 'bg-success/15 text-success' },
  accepted: { ar: 'مقبول', en: 'Accepted', tone: 'bg-success/15 text-success' },
  rejected: { ar: 'مرفوض', en: 'Rejected', tone: 'bg-surface-secondary text-text-muted' },
  expired: { ar: 'منتهي', en: 'Expired', tone: 'bg-surface-secondary text-text-muted' },
}

export function quoteStatusMeta(status: string, ar: boolean) {
  const m = QUOTE_STATUS[status] ?? { ar: status, en: status, tone: 'bg-surface-secondary text-text-muted' }
  return { label: ar ? m.ar : m.en, tone: m.tone }
}

export function ClientQuotesPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const q = useQuery({ queryKey: ['client', 'quotes'], queryFn: listPortalQuotes, retry: false })
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
        <div className="rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-6 text-center text-sm text-danger">{t.error}</div>
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-border bg-surface p-12 text-center text-text-muted"><ScrollText size={26} /><span className="text-sm">{t.none}</span></div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {rows.map((quote) => <QuoteCard key={quote.id} quote={quote} ar={ar} t={t} />)}
        </div>
      )}
    </PortalShell>
  )
}

function QuoteCard({ quote, ar, t }: { quote: PortalQuote; ar: boolean; t: typeof COPY.ar }) {
  const status = quoteStatusMeta(quote.status, ar)
  return (
    <Link to={`/client/quotes/${quote.id}`} className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5 hover:border-brand-400">
      <div className="flex items-start justify-between gap-2">
        <div className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{quote.number}</div>
        <span className={`whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold ${status.tone}`}>{status.label}</span>
      </div>
      <div className="tnum text-xl font-extrabold text-text-primary" dir="ltr">{formatMoney(quote.total, quote.currency)}</div>
      <div className="flex items-center justify-between text-[11px] text-text-muted">
        <span>{t.valid_until}: <span className="tnum">{formatDate(quote.valid_until)}</span></span>
        <span className="flex items-center gap-1 font-semibold text-brand-600">{t.details} <ArrowRight size={13} className="rtl:rotate-180" /></span>
      </div>
    </Link>
  )
}
