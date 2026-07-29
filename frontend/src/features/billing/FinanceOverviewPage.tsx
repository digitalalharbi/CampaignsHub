import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertTriangle, ArrowLeftRight, CircleDollarSign, FileText, ReceiptText, Wallet } from 'lucide-react'
import { BillingTabs } from './BillingTabs'
import { getFinanceOverview, listReceivables, type FinanceOverview } from './api'
import { Badge } from '@/components/ui/Badge'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { compact, money } from '@/features/analytics/format'
import { useAuth } from '@/stores/auth'

/**
 * FINANCE-001 — the consolidated finance center. Quotes, invoices and payments were three unrelated
 * lists; this page answers the questions a finance owner actually asks: how much is owed, how late is
 * it, and how much of what we invoiced did we really collect.
 *
 * Honesty rules made visible here: outstanding money is derived from invoices (total − paid), a pending
 * or failed payment is shown in its own bucket and never counted as collected, and the collection rate
 * is blank rather than 0% when nothing has been invoiced.
 */

const QUOTE_STATUS: Record<string, string> = {
  draft: 'مسودة', sent: 'مُرسل', approved: 'معتمد', rejected: 'مرفوض', expired: 'منتهٍ',
}
const INVOICE_STATUS: Record<string, string> = {
  draft: 'مسودة', issued: 'صادرة', sent: 'مُرسلة', partially_paid: 'مدفوعة جزئيًا',
  paid: 'مدفوعة', overdue: 'متأخرة', cancelled: 'ملغاة',
}
const PAYMENT_STATUS: Record<string, { ar: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }> = {
  succeeded: { ar: 'ناجحة', tone: 'success' },
  pending: { ar: 'قيد الانتظار', tone: 'warning' },
  awaiting_provider_credentials: { ar: 'بانتظار مزوّد دفع', tone: 'warning' },
  failed: { ar: 'فاشلة', tone: 'danger' },
  refunded: { ar: 'مستردة', tone: 'neutral' },
}

const AGING_LABEL: Array<[keyof FinanceOverview['aging'], string, string]> = [
  ['current', 'غير مستحقة بعد', 'bg-success'],
  ['d1_30', 'متأخرة ١–٣٠ يومًا', 'bg-warning'],
  ['d31_60', 'متأخرة ٣١–٦٠ يومًا', 'bg-orange-500'],
  ['d61_90', 'متأخرة ٦١–٩٠ يومًا', 'bg-danger'],
  ['d90_plus', 'متأخرة أكثر من ٩٠ يومًا', 'bg-red-800'],
]

export function FinanceOverviewPage() {
  const canView = useAuth((s) => s.hasPermission('billing.view'))

  const overview = useQuery({ queryKey: ['finance-overview'], queryFn: getFinanceOverview, enabled: canView })
  const receivables = useQuery({ queryKey: ['finance-receivables'], queryFn: listReceivables, enabled: canView })

  if (!canView) {
    return <EmptyState title="لا تملك صلاحية الاطلاع على المالية" description="تحتاج صلاحية billing.view لعرض هذه الصفحة." />
  }

  const d = overview.data
  const cur = d?.currency ?? 'SAR'
  const agingTotal = d ? Object.values(d.aging).reduce((a, b) => a + b, 0) : 0

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">المالية</h1>
        <p className="mt-1 text-sm text-text-secondary">
          صورة موحّدة لعروض الأسعار والفواتير والتحصيل — المبالغ المستحقة محسوبة من الفواتير نفسها، ولا يُحتسب أي مبلغ محصَّلًا قبل تأكيد الدفع.
        </p>
      </div>

      <BillingTabs />

      {overview.isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-24" />)}</div>
      ) : overview.isError || !d ? (
        <EmptyState title="تعذّر تحميل ملخص المالية" description="حاول تحديث الصفحة." />
      ) : (
        <>
          {/* Headline KPIs */}
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi
              icon={FileText} label="عروض معتمدة" value={money(d.quotes.approved_total, cur)}
              sub={`${d.quotes.count} عرضًا بإجمالي ${compact(d.quotes.total)}`} to="/app/billing/quotes"
            />
            <Kpi
              icon={ReceiptText} label="إجمالي الفواتير" value={money(d.invoices.total, cur)}
              sub={`${d.invoices.count} فاتورة`} to="/app/billing/invoices"
            />
            <Kpi
              icon={Wallet} label="المحصَّل" value={money(d.invoices.collected, cur)} tone="success"
              sub={d.invoices.collection_rate !== null
                ? `نسبة التحصيل ${Math.round(d.invoices.collection_rate * 100)}%`
                : 'لا توجد فواتير بعد لحساب النسبة'}
            />
            <Kpi
              icon={CircleDollarSign} label="المستحق غير المحصَّل" value={money(d.invoices.outstanding, cur)}
              tone={d.invoices.outstanding > 0 ? 'warning' : undefined}
              sub={d.invoices.overdue_count > 0 ? `${d.invoices.overdue_count} فاتورة متأخرة` : 'لا فواتير متأخرة'}
            />
          </div>

          {/* Aging — where the outstanding money actually sits. */}
          <section className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="font-bold text-text-primary">أعمار الديون</h2>
              <span className="tnum text-sm text-text-secondary">الإجمالي {money(agingTotal, cur)}</span>
            </div>
            {agingTotal === 0 ? (
              <p className="mt-3 text-sm text-text-muted">لا توجد مبالغ مستحقة حاليًا.</p>
            ) : (
              <>
                <div data-testid="aging-bar" className="mt-3 flex h-3 overflow-hidden rounded-full bg-surface-secondary">
                  {AGING_LABEL.map(([key, label, color]) => {
                    const v = d.aging[key]
                    return v > 0 ? <span key={key} title={`${label} — ${money(v, cur)}`} className={color} style={{ width: `${(v / agingTotal) * 100}%` }} /> : null
                  })}
                </div>
                <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                  {AGING_LABEL.map(([key, label, color]) => (
                    <li key={key} className="rounded-xl bg-surface-secondary p-2.5">
                      <span className="flex items-center gap-1.5 text-[11px] text-text-muted">
                        <span className={`h-2 w-2 rounded-full ${color}`} /> {label}
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
              <h2 className="font-bold text-text-primary">توزيع الحالات</h2>
              <StatusList title="عروض الأسعار" buckets={d.quotes.by_status} labels={QUOTE_STATUS} currency={cur} />
              <StatusList title="الفواتير" buckets={d.invoices.by_status} labels={INVOICE_STATUS} currency={cur} />
              <div>
                <h3 className="text-xs font-bold text-text-secondary">المدفوعات</h3>
                {Object.keys(d.payments.by_status).length === 0 ? (
                  <p className="mt-1 text-xs text-text-muted">لا توجد محاولات دفع مسجّلة.</p>
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
                  يُحتسب ضمن المحصَّل ما نجح فعليًا فقط ({money(d.payments.succeeded_total, cur)}) — المعلّق والفاشل يظهران في خانتيهما ولا يُضافان للإيراد.
                </p>
              </div>
            </section>

            {/* Collections worklist — ordered by how late each invoice is. */}
            <section className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
              <div className="flex items-center justify-between gap-2">
                <h2 className="font-bold text-text-primary">قائمة التحصيل</h2>
                <Link to="/app/billing/invoices" className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline">
                  كل الفواتير <ArrowLeftRight size={13} />
                </Link>
              </div>
              {receivables.isLoading ? (
                <Skeleton className="mt-3 h-40" />
              ) : (receivables.data ?? []).length === 0 ? (
                <p className="mt-3 text-sm text-text-muted">لا توجد فواتير مستحقة — كل ما صدر تم تحصيله.</p>
              ) : (
                <ul data-testid="receivables" className="mt-3 space-y-2">
                  {(receivables.data ?? []).slice(0, 8).map((r) => (
                    <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border p-2.5">
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-semibold text-text-primary">{r.number}</span>
                        <span className="block text-[11px] text-text-muted">{r.client ?? '—'} · استحقاق {r.due_date ?? 'غير محدد'}</span>
                      </span>
                      <span className="flex items-center gap-2">
                        {r.days_late > 0 && (
                          <Badge tone={r.days_late > 60 ? 'danger' : 'warning'}>
                            <AlertTriangle size={11} /> متأخرة <span className="tnum">{r.days_late}</span> يومًا
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

function Kpi({ icon: Icon, label, value, sub, tone, to }: {
  icon: typeof Wallet; label: string; value: string; sub?: string
  tone?: 'success' | 'warning'; to?: string
}) {
  const body = (
    <div className="h-full rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
      <span className="flex items-center gap-2 text-sm text-text-secondary"><Icon size={15} /> {label}</span>
      <div className={`tnum mt-1.5 text-2xl font-extrabold ${tone === 'success' ? 'text-success' : tone === 'warning' ? 'text-warning' : 'text-text-primary'}`}>{value}</div>
      {sub && <div className="mt-0.5 text-xs text-text-muted">{sub}</div>}
    </div>
  )
  return to ? <Link to={to} className="block transition-colors hover:opacity-90">{body}</Link> : body
}

function StatusList({ title, buckets, labels, currency }: {
  title: string; buckets: Record<string, { count: number; total: number }>; labels: Record<string, string>; currency: string
}) {
  const entries = Object.entries(buckets)
  return (
    <div>
      <h3 className="text-xs font-bold text-text-secondary">{title}</h3>
      {entries.length === 0 ? (
        <p className="mt-1 text-xs text-text-muted">لا توجد سجلات.</p>
      ) : (
        <ul className="mt-1.5 space-y-1">
          {entries.map(([status, b]) => (
            <li key={status} className="flex items-center justify-between gap-2 rounded-lg bg-surface-secondary px-2.5 py-1.5 text-sm">
              <span className="flex items-center gap-2">
                <span className="font-medium text-text-primary">{labels[status] ?? status}</span>
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
