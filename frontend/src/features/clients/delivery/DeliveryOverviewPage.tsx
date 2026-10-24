import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowRight } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { PageIntro } from '@/components/ui/PageIntro'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { money } from '@/features/analytics/format'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'
import { getDeliveryOverview, type DeliveryOverviewRow, type NextAction } from './deliveryApi'

/**
 * DELIV-DASH-001 — every client's weekly delivery and billing on one screen.
 *
 * Built for the question an agency asks on a Monday: «who needs something from me?». Each row says where
 * this client's week stands, where their invoice stands, and the single next step — loudest first. It
 * reads the delivery and billing engines' own states and stores nothing; money is shown per client in the
 * invoice's own currency and never added across clients.
 */
const ACTION: Record<NextAction, { ar: string; en: string }> = {
  prepare_pack: { ar: 'جهّز تسليم الأسبوع الماضي', en: 'Prepare last week’s delivery' },
  review_pack: { ar: 'راجع التوصيات والتقرير', en: 'Review the report and recommendations' },
  approve_pack: { ar: 'اعتمد الحزمة', en: 'Approve the pack' },
  send_pack: { ar: 'أرسل الحزمة للعميل', en: 'Send the pack to the client' },
  follow_up_payment: { ar: 'تابع السداد مع العميل', en: 'Follow up the payment' },
  none: { ar: 'لا شيء مطلوب', en: 'Nothing needed' },
}

const PACK = { draft: { ar: 'مسودة', en: 'Draft' }, approved: { ar: 'معتمدة', en: 'Approved' }, sent: { ar: 'أُرسلت', en: 'Sent' } } as const

const INVOICE: Record<string, { ar: string; en: string }> = {
  issued: { ar: 'صادرة', en: 'Issued' }, sent: { ar: 'مُرسلة', en: 'Sent' }, due: { ar: 'مستحقة', en: 'Due' },
  overdue: { ar: 'متأخرة', en: 'Overdue' }, partially_paid: { ar: 'مدفوعة جزئيًا', en: 'Part paid' }, paid: { ar: 'مدفوعة', en: 'Paid' },
}

const RANK = { urgent: 0, attention: 1, ok: 2 } as const

function tone(v: DeliveryOverviewRow['visual']): 'danger' | 'warning' | 'success' {
  return v === 'urgent' ? 'danger' : v === 'attention' ? 'warning' : 'success'
}

export function DeliveryOverviewPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const q = useQuery({ queryKey: ['delivery', 'overview'], queryFn: getDeliveryOverview })
  const rows = [...(q.data ?? [])].sort((a, b) => RANK[a.visual] - RANK[b.visual] || a.client.localeCompare(b.client))

  return (
    <div className="space-y-5">
      <PageIntro
        title={ar ? 'التسليم الدوري والفوترة' : 'Periodic delivery & billing'}
        purpose={ar ? 'حالة تقرير كل عميل وفاتورته، والخطوة التالية المطلوبة منك.' : 'Where each client’s report and invoice stand, and the next step for you.'}
      />

      {q.isError ? (
        <QueryFailure error={q.error} ar={ar} onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّر تحميل التسليم الدوري' : 'Could not load the periodic delivery'} />
      ) : q.isLoading ? (
        <div className="h-40 animate-pulse rounded-2xl bg-surface-secondary" />
      ) : rows.length === 0 ? (
        <p className="rounded-2xl border border-border bg-surface p-8 text-center text-sm text-text-muted">{ar ? 'لا عملاء نشطون بعد.' : 'No active clients yet.'}</p>
      ) : (
        <ul className="grid gap-3" data-testid="delivery-overview">
          {rows.map((r) => <Row key={r.client_id} r={r} ar={ar} />)}
        </ul>
      )}
    </div>
  )
}

function Row({ r, ar }: { r: DeliveryOverviewRow; ar: boolean }) {
  const lang = ar ? 'ar' : 'en'
  const inv = r.invoice
  const action = ACTION[r.next_action]

  return (
    <li data-testid={`delivery-row-${r.client_id}`} data-visual={r.visual} className="grid gap-3 rounded-2xl border border-border bg-surface p-4 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-center">
      <div className="min-w-0">
        <div className="truncate font-bold text-text-primary">{r.client}</div>
        <div className="mt-0.5 text-xs text-text-muted">
          {r.pack === null
            ? (ar ? 'لا توجد حزمة بعد' : 'No pack yet')
            : `${r.pack.period.from} → ${r.pack.period.to}`}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-1.5 text-xs">
        {r.pack !== null && <Badge tone={r.pack.status === 'sent' ? 'success' : r.pack.status === 'approved' ? 'info' : 'neutral'}>{PACK[r.pack.status][lang]}</Badge>}
        {r.report !== null && r.report.undecided_recommendations > 0 && (
          <span className="text-warning">{ar ? `${r.report.undecided_recommendations} توصية بلا قرار` : `${r.report.undecided_recommendations} undecided`}</span>
        )}
        {r.evidence !== null && <span className="text-text-muted">{ar ? `إثبات: ${r.evidence}` : `Evidence: ${r.evidence}`}</span>}
        <span className="text-text-muted">
          {r.last_viewed_at === null ? (ar ? 'لم يُفتح بعد' : 'Not opened yet') : `${ar ? 'فُتح' : 'Opened'} ${fmtDateTime(r.last_viewed_at)}`}
        </span>
      </div>

      <div className="flex flex-wrap items-center gap-1.5 text-xs">
        {inv === null ? (
          <span className="text-text-muted">{ar ? 'لا فاتورة' : 'No invoice'}</span>
        ) : (
          <>
            <Badge tone={inv.status === 'overdue' ? 'danger' : inv.status === 'paid' ? 'success' : 'neutral'}>{(INVOICE[inv.status] ?? { ar: inv.status, en: inv.status })[lang]}</Badge>
            <span dir="ltr" className="tnum font-semibold text-text-primary">{inv.amount_due === null ? '—' : money(inv.amount_due, inv.currency)}</span>
            {inv.due_date !== null && <span className="text-text-muted">{inv.due_date}</span>}
          </>
        )}
      </div>

      <Link
        to={`/agency/clients/${r.client_id}`}
        data-testid={`delivery-action-${r.client_id}`}
        className="inline-flex items-center justify-between gap-2 rounded-xl border border-border px-3 py-2 text-sm font-semibold text-text-primary hover:bg-surface-hover"
      >
        <Badge tone={tone(r.visual)}>{action[lang]}</Badge>
        <ArrowRight size={14} className="rtl:rotate-180" />
      </Link>
    </li>
  )
}
