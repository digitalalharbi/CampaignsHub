import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertTriangle, ArrowLeftRight, CircleDollarSign, FileText, ReceiptText, Wallet } from 'lucide-react'
import { BillingTabs } from './BillingTabs'
import { getFinanceOverview, listReceivables, type FinanceOverview } from './api'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { Badge } from '@/components/ui/Badge'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { compact, money } from '@/features/analytics/format'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * FINANCE-001 — the consolidated finance center. Quotes, invoices and payments were three unrelated
 * lists; this page answers the questions a finance owner actually asks: how much is owed, how late is
 * it, and how much of what we invoiced did we really collect.
 *
 * Honesty rules made visible here: outstanding money is derived from invoices (total − paid), a pending
 * or failed payment is shown in its own bucket and never counted as collected, and the collection rate
 * is blank rather than 0% when nothing has been invoiced.
 */

const QUOTE_STATUS: Record<string, { ar: string; en: string }> = {
  draft: { ar: 'مسودة', en: 'Draft' }, sent: { ar: 'مُرسل', en: 'Sent' }, approved: { ar: 'معتمد', en: 'Approved' },
  rejected: { ar: 'مرفوض', en: 'Rejected' }, expired: { ar: 'منتهٍ', en: 'Expired' },
}
const INVOICE_STATUS: Record<string, { ar: string; en: string }> = {
  draft: { ar: 'مسودة', en: 'Draft' }, issued: { ar: 'صادرة', en: 'Issued' }, sent: { ar: 'مُرسلة', en: 'Sent' },
  partially_paid: { ar: 'مدفوعة جزئيًا', en: 'Partly paid' },
  paid: { ar: 'مدفوعة', en: 'Paid' }, overdue: { ar: 'متأخرة', en: 'Overdue' }, cancelled: { ar: 'ملغاة', en: 'Cancelled' },
}
const PAYMENT_STATUS: Record<string, { ar: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }> = {
  succeeded: { ar: 'ناجحة', tone: 'success' },
  pending: { ar: 'قيد الانتظار', tone: 'warning' },
  awaiting_provider_credentials: { ar: 'بانتظار مزوّد دفع', tone: 'warning' },
  failed: { ar: 'فاشلة', tone: 'danger' },
  refunded: { ar: 'مستردة', tone: 'neutral' },
}

const AGING_LABEL: Array<[keyof FinanceOverview['aging'], string, string, string]> = [
  ['current', 'غير مستحقة بعد', 'Not due yet', 'bg-success'],
  ['d1_30', 'متأخرة 1–30 يومًا', '1–30 days late', 'bg-warning'],
  ['d31_60', 'متأخرة 31–60 يومًا', '31–60 days late', 'bg-orange-500'],
  ['d61_90', 'متأخرة 61–90 يومًا', '61–90 days late', 'bg-danger'],
  ['d90_plus', 'متأخرة أكثر من 90 يومًا', 'More than 90 days late', 'bg-red-800'],
]

export function FinanceOverviewPage() {
  const ar = useUi((u) => u.locale) === 'ar'
  const canView = useAuth((s) => s.hasPermission('billing.view'))

  const overview = useQuery({ queryKey: ['finance-overview'], queryFn: getFinanceOverview, enabled: canView })
  const receivables = useQuery({ queryKey: ['finance-receivables'], queryFn: listReceivables, enabled: canView })

  if (!canView) {
    return <EmptyState title={ar ? 'لا تملك صلاحية الاطلاع على المالية' : 'You may not view finance'} description={ar ? 'تحتاج صلاحية billing.view لعرض هذه الصفحة.' : 'This page needs the billing.view permission.'} />
  }

  const d = overview.data
  const cur = d?.currency ?? 'SAR'
  const agingTotal = d ? Object.values(d.aging).reduce((a, b) => a + b, 0) : 0

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{ar ? 'المالية' : 'Finance'}</h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'صورة موحّدة لعروض الأسعار والفواتير والتحصيل — المبالغ المستحقة محسوبة من الفواتير نفسها، ولا يُحتسب أي مبلغ محصَّلًا قبل تأكيد الدفع.'
            : 'One view of quotes, invoices and collection — what is outstanding is computed from the invoices themselves, and nothing counts as collected until the payment is confirmed.'}
        </p>
      </div>

      <BillingTabs />

      {overview.isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-24" />)}</div>
      ) : overview.isError || !d ? (
        <EmptyState title={ar ? 'تعذّر تحميل ملخص المالية' : 'The finance summary could not be loaded'} description={ar ? 'حاول تحديث الصفحة.' : 'Try refreshing the page.'} />
      ) : (
        <>
          {/* Headline KPIs */}
          <StatGrid>
            <Kpi
              icon={FileText} label={ar ? 'عروض معتمدة' : 'Approved quotes'} value={money(d.quotes.approved_total, cur)}
              sub={ar ? `${d.quotes.count} عرضًا بإجمالي ${compact(d.quotes.total)}` : `${d.quotes.count} quotes totalling ${compact(d.quotes.total)}`} to="/app/billing/quotes"
            />
            <Kpi
              icon={ReceiptText} label={ar ? 'إجمالي الفواتير' : 'Invoices total'} value={money(d.invoices.total, cur)}
              sub={ar ? `${d.invoices.count} فاتورة` : `${d.invoices.count} invoices`} to="/app/billing/invoices"
            />
            <Kpi
              icon={Wallet} label={ar ? 'المحصَّل' : 'Collected'} value={money(d.invoices.collected, cur)} tone="success"
              sub={d.invoices.collection_rate !== null
                ? (ar ? `نسبة التحصيل ${Math.round(d.invoices.collection_rate * 100)}%` : `${Math.round(d.invoices.collection_rate * 100)}% collected`)
                : (ar ? 'لا توجد فواتير بعد لحساب النسبة' : 'No invoices yet to compute a rate')}
            />
            <Kpi
              icon={CircleDollarSign} label={ar ? 'المستحق غير المحصَّل' : 'Outstanding'} value={money(d.invoices.outstanding, cur)}
              tone={d.invoices.outstanding > 0 ? 'warning' : undefined}
              sub={d.invoices.overdue_count > 0
                ? (ar ? `${d.invoices.overdue_count} فاتورة متأخرة` : `${d.invoices.overdue_count} overdue`)
                : (ar ? 'لا فواتير متأخرة' : 'Nothing overdue')}
            />
          </StatGrid>

          {/* Aging — where the outstanding money actually sits. */}
          <section className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="font-bold text-text-primary">{ar ? 'أعمار الديون' : 'Debt ageing'}</h2>
              {/*
                NUMBER-PRESENTATION-001 — the figure is marked, not the sentence.

                «الإجمالي 47.5K SAR» rendered as «47.5الإجمالي K SAR»: an unmarked amount with a
                currency after it is reordered by the bidi algorithm, and it splits around the Arabic
                word beside it. Marking the WHOLE line `ltr` would put the label on the wrong side
                instead, so only the amount carries the direction.
              */}
              <span className="text-sm text-text-secondary">
                {ar ? 'الإجمالي' : 'Total'} <span dir="ltr" className="tnum">{money(agingTotal, cur)}</span>
              </span>
            </div>
            {agingTotal === 0 ? (
              <p className="mt-3 text-sm text-text-muted">{ar ? 'لا توجد مبالغ مستحقة حاليًا.' : 'Nothing is outstanding right now.'}</p>
            ) : (
              <>
                <div data-testid="aging-bar" className="mt-3 flex h-3 overflow-hidden rounded-full bg-surface-secondary">
                  {AGING_LABEL.map(([key, labelAr, labelEn, color]) => {
                    const v = d.aging[key]
                    return v > 0 ? <span key={key} title={`${ar ? labelAr : labelEn} — ${money(v, cur)}`} className={color} style={{ width: `${(v / agingTotal) * 100}%` }} /> : null
                  })}
                </div>
                <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                  {AGING_LABEL.map(([key, labelAr, labelEn, color]) => (
                    <li key={key} className="rounded-xl bg-surface-secondary p-2.5">
                      <span className="flex items-center gap-1.5 text-[11px] text-text-muted">
                        <span className={`h-2 w-2 rounded-full ${color}`} /> {ar ? labelAr : labelEn}
                      </span>
                      <span className="tnum mt-0.5 block text-sm font-bold text-text-primary">{money(d.aging[key], cur)}</span>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </section>

          <div className="grid gap-4 lg:grid-cols-2">
            {/* Status breakdowns — every state shown as recorded, including the awkward ones. */}
            <section className="space-y-3 rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
              <h2 className="font-bold text-text-primary">{ar ? 'توزيع الحالات' : 'Status breakdown'}</h2>
              <StatusList title={ar ? 'عروض الأسعار' : 'Quotes'} buckets={d.quotes.by_status} labels={QUOTE_STATUS} currency={cur} />
              <StatusList title={ar ? 'الفواتير' : 'Invoices'} buckets={d.invoices.by_status} labels={INVOICE_STATUS} currency={cur} />
              <div>
                <h3 className="text-xs font-bold text-text-secondary">{ar ? 'المدفوعات' : 'Payments'}</h3>
                {Object.keys(d.payments.by_status).length === 0 ? (
                  <p className="mt-1 text-xs text-text-muted">{ar ? 'لا توجد محاولات دفع مسجّلة.' : 'No payment attempts recorded.'}</p>
                ) : (
                  <ul className="mt-1.5 space-y-1">
                    {Object.entries(d.payments.by_status).map(([status, b]) => {
                      const meta = PAYMENT_STATUS[status] ?? { ar: status, tone: 'neutral' as const }
                      return (
                        <li key={status} className="flex items-center justify-between gap-2 rounded-lg bg-surface-secondary px-2.5 py-1.5 text-sm">
                          <span className="flex items-center gap-2"><Badge tone={meta.tone}>{meta.ar}</Badge><span className="tnum text-text-muted">×{b.count}</span></span>
                          <span className="tnum font-semibold text-text-primary">{money(b.total, cur)}</span>
                        </li>
                      )
                    })}
                  </ul>
                )}
                <p className="mt-1.5 text-[11px] text-text-muted">
                  {ar
                    ? `يُحتسب ضمن المحصَّل ما نجح فعليًا فقط (${money(d.payments.succeeded_total, cur)}) — المعلّق والفاشل يظهران في خانتيهما ولا يُضافان للإيراد.`
                    : `Only what actually succeeded counts as collected (${money(d.payments.succeeded_total, cur)}) — pending and failed are shown in their own columns and never added to revenue.`}
                </p>
              </div>
            </section>

            {/* Collections worklist — ordered by how late each invoice is. */}
            <section className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
              <div className="flex items-center justify-between gap-2">
                <h2 className="font-bold text-text-primary">{ar ? 'قائمة التحصيل' : 'Collection list'}</h2>
                <Link to="/app/billing/invoices" className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline">
                  {ar ? 'كل الفواتير' : 'All invoices'} <ArrowLeftRight size={13} />
                </Link>
              </div>
              {receivables.isLoading ? (
                <Skeleton className="mt-3 h-40" />
              ) : (receivables.data ?? []).length === 0 ? (
                <p className="mt-3 text-sm text-text-muted">{ar ? 'لا توجد فواتير مستحقة — كل ما صدر تم تحصيله.' : 'Nothing is outstanding — everything issued has been collected.'}</p>
              ) : (
                <ul data-testid="receivables" className="mt-3 space-y-2">
                  {(receivables.data ?? []).slice(0, 8).map((r) => (
                    <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border p-2.5">
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-semibold text-text-primary">{r.number}</span>
                        <span className="block text-[11px] text-text-muted">{r.client ?? '—'} · {ar ? 'استحقاق' : 'due'} {r.due_date ?? (ar ? 'غير محدد' : 'not set')}</span>
                      </span>
                      <span className="flex items-center gap-2">
                        {r.days_late > 0 && (
                          <Badge tone={r.days_late > 60 ? 'danger' : 'warning'}>
                            <AlertTriangle size={11} /> {ar ? 'متأخرة' : 'late by'} <span className="tnum">{r.days_late}</span> {ar ? 'يومًا' : 'days'}
                          </Badge>
                        )}
                        <span className="tnum font-bold text-text-primary">{money(r.due, r.currency)}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </div>
        </>
      )}
    </div>
  )
}

/**
 * UX-KPI-PRESENTATION-001 — the finance headline is the product's card now, not this page's.
 *
 * What is left here is what actually belongs to finance: which icon names the figure, which tone it
 * carries, and where pressing it goes. The size of the number, the size of the label, the padding
 * and the height are the shared card's — this page's copy had a 14px label against the product's
 * 13px and its own padding, so a finance row and a dashboard row did not line up.
 */
function Kpi({ icon: Icon, label, value, sub, tone, to }: {
  icon: typeof Wallet; label: string; value: string; sub?: string
  tone?: 'success' | 'warning'; to?: string
}) {
  const card = (
    <StatCard
      label={<><Icon size={15} className="shrink-0" /> {label}</>}
      value={value}
      hint={sub}
      tone={tone ?? 'neutral'}
    />
  )

  return to ? <Link to={to} className="block h-full transition-colors hover:opacity-90">{card}</Link> : card
}

function StatusList({ title, buckets, labels, currency }: {
  title: string; buckets: Record<string, { count: number; total: number }>; labels: Record<string, { ar: string; en: string }>; currency: string
}) {
  const ar = useUi((u) => u.locale) === 'ar'
  const entries = Object.entries(buckets)
  return (
    <div>
      <h3 className="text-xs font-bold text-text-secondary">{title}</h3>
      {entries.length === 0 ? (
        <p className="mt-1 text-xs text-text-muted">{ar ? 'لا توجد سجلات.' : 'No records.'}</p>
      ) : (
        <ul className="mt-1.5 space-y-1">
          {entries.map(([status, b]) => (
            <li key={status} className="flex items-center justify-between gap-2 rounded-lg bg-surface-secondary px-2.5 py-1.5 text-sm">
              <span className="flex items-center gap-2">
                <span className="font-medium text-text-primary">{labels[status] ? (ar ? labels[status].ar : labels[status].en) : status}</span>
                <span className="tnum text-text-muted">×{b.count}</span>
              </span>
              <span className="tnum font-semibold text-text-primary">{money(b.total, currency)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
