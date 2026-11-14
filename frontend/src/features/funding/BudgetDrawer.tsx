import { useEffect, useState } from 'react'
import { Pencil, RefreshCw, Trash2, Undo2 } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { MetricLineChart, CHART_SERIES } from '@/features/analytics/charts'
import { providerLabel } from '@/features/campaigns/labels'
import { SlideOver } from './SlideOver'
import { ModeBadges, PeriodChip, PlatformSplit, Ring, Sar } from './BudgetBoard'
import { FinancialDisclaimer } from './FinancialDisclaimer'
import { downloadBudgets, useBudget, useBudgetMonth, useReallocateBudget, type BudgetDetail } from './fundingApi'
import { KIND, L, PLAN_DIFF, STATUS, TONE_BAR } from './labels'
import { Money, currencyMark, moneyNumber } from './money'

type Lang = { ar: string; en: string }

/**
 * One project, opened over the board: its figures and period, what each platform spent, the month, and the
 * ledger — every entry with who/when/why, «تراجع» on the latest only. Edit, renew and delete live here.
 */
export function BudgetDrawer({ projectId, onClose, ar, canManage, onEdit, onRenew, onDelete, onUndo, undoPending }: {
  projectId: string | null
  onClose: () => void
  ar: boolean
  canManage: boolean
  onEdit: (b: BudgetDetail) => void
  onRenew: (b: BudgetDetail) => void
  onDelete: (b: BudgetDetail) => void
  onUndo: (b: BudgetDetail, entryId: string) => void
  undoPending: boolean
}) {
  const { data } = useBudget(projectId)
  const tx = (v: Lang) => (ar ? v.ar : v.en)
  const st = data ? STATUS[data.status] : null

  return (
    <SlideOver
      open={projectId !== null}
      onClose={onClose}
      closeLabel={tx(L.close)}
      testid="budget-drawer"
      title={
        data && st ? (
          <div className="flex min-w-0 flex-col gap-0.5">
            <span className="truncate text-sm font-extrabold text-text-primary">{data.project_name}</span>
            <span className="flex flex-wrap items-center gap-2">
              <Badge tone={st.tone}>{tx(st)}</Badge>
              <ModeBadges row={data} ar={ar} />
              <PeriodChip start={data.period_start} end={data.period_end} stated={data.period_start_stated} ar={ar} />
            </span>
          </div>
        ) : <span className="text-sm font-bold">{tx(L.title)}</span>
      }
    >
      {!data ? <div className="min-h-[12rem]" aria-busy /> : (
        <div className="flex min-w-0 flex-col gap-3" data-testid="budget-panel">
          {data.payment_mode === 'credit' ? (
            <div className="flex flex-col gap-1.5" data-testid="credit-figures">
              <div className="grid grid-cols-2 gap-2">
                <Fig label={L.spentToDate} ar={ar}><Sar n={data.to_date} ar={ar} /></Fig>
                <Fig label={L.thisMonth} ar={ar}><span data-testid="budget-this-month"><Sar n={data.this_month} ar={ar} /></span></Fig>
              </div>
              {data.ceiling !== null && data.ceiling_ratio !== null && (
                <div className="flex items-center gap-2" data-testid="ceiling-bar">
                  <span className="text-xs font-semibold text-text-secondary">{tx(L.ceiling)} <Sar n={data.ceiling} ar={ar} /></span>
                  <span className="h-2 flex-1 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
                    <span className={`block h-full rounded-full ${TONE_BAR[data.status === 'credit_over' ? 'danger' : data.status === 'credit_near' ? 'warning' : 'success']}`} style={{ width: `${Math.min(100, Math.round(data.ceiling_ratio * 100))}%` }} />
                  </span>
                  <bdi dir="ltr" className="tnum text-xs font-extrabold">{Math.round(data.ceiling_ratio * 100)}%</bdi>
                </div>
              )}
            </div>
          ) : (
            <div className="flex items-center gap-3" data-testid="direct-figures">
              {data.used_ratio !== null && <Ring ratio={Math.min(1, data.used_ratio)} tone={data.status === 'over' ? 'danger' : st?.tone ?? 'neutral'} size={44} />}
              <div className="grid flex-1 grid-cols-3 gap-2">
                <Fig label={L.approved} ar={ar}><Sar n={data.approved} ar={ar} /></Fig>
                <Fig label={L.spent} ar={ar}><Sar n={data.spent} ar={ar} /></Fig>
                <Fig label={L.remaining} ar={ar}>
                  <span data-testid="budget-remaining"><Sar n={data.remaining} ar={ar} className={data.remaining !== null && data.remaining < 0 ? 'text-danger' : ''} /></span>
                </Fig>
              </div>
            </div>
          )}

          {canManage && data.status !== 'void' && (
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="secondary" onClick={() => onEdit(data)} data-testid="budget-edit"><Pencil className="h-4 w-4" aria-hidden /> {tx(L.edit)}</Button>
              <Button size="sm" onClick={() => onRenew(data)} data-testid="budget-renew"><RefreshCw className="h-4 w-4" aria-hidden /> {tx(L.renew)}</Button>
              <Button size="sm" variant="ghost" onClick={() => onDelete(data)} data-testid="budget-delete" className="text-danger"><Trash2 className="h-4 w-4" aria-hidden /> {tx(L.del)}</Button>
            </div>
          )}

          {data.payment_mode === 'direct' && data.series.length > 1 && (
            <div className="min-w-0" data-testid="budget-chart">
              <MetricLineChart
                data={data.series.map((p) => ({ date: p.date, remaining: p.remaining, approved: p.approved }))}
                height={120}
                series={[
                  { key: 'remaining', name: tx(L.remaining), color: CHART_SERIES[0], kind: 'money' },
                  { key: 'approved', name: tx(L.approved), color: CHART_SERIES[3], kind: 'money' },
                ]}
                currency="SAR"
              />
            </div>
          )}

          {data.plan && data.plan.length > 0 ? (
            <PlanBars data={data} ar={ar} canManage={canManage} />
          ) : data.by_platform.length > 0 && (
            <div className="flex min-w-0 flex-col gap-1.5" data-testid="budget-platforms">
              <span className="text-xs font-bold text-text-secondary">{tx(L.platforms)}</span>
              <PlatformSplit items={data.by_platform} ar={ar} height="h-3" />
              <ul className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-text-secondary">
                {data.by_platform.map((i, n) => (
                  <li key={i.provider} className="flex items-center gap-1">
                    <span className="h-2 w-2 rounded-full" style={{ background: CHART_SERIES[n % CHART_SERIES.length] }} aria-hidden />
                    {providerLabel(i.provider, ar ? 'ar' : 'en')} <PlatformFigure item={i} ar={ar} />
                  </li>
                ))}
              </ul>
            </div>
          )}

          {data.payment_mode === 'credit' && data.invoice_months.length > 0 && <InvoiceMonths data={data} ar={ar} />}

          <History data={data} ar={ar} canManage={canManage} onUndo={onUndo} pending={undoPending} />
          <MonthCard projectId={data.project_id} months={data.months} ar={ar} />
          <FinancialDisclaimer className="border-t border-border pt-2" />
        </div>
      )}
    </SlideOver>
  )
}

function Fig({ label, children, ar }: { label: Lang; children: React.ReactNode; ar: boolean }) {
  return (
    <div className="flex min-w-0 flex-col rounded-[10px] bg-surface-secondary px-2 py-1.5">
      <span className="text-xs font-semibold text-text-secondary">{ar ? label.ar : label.en}</span>
      <span className="truncate text-sm font-extrabold text-text-primary">{children}</span>
    </div>
  )
}

/** The ledger, newest first. An undone entry is struck through; the undo itself is an entry too. */
function History({ data, ar, canManage, onUndo, pending }: { data: BudgetDetail; ar: boolean; canManage: boolean; onUndo: (b: BudgetDetail, id: string) => void; pending: boolean }) {
  const tx = (v: Lang) => (ar ? v.ar : v.en)

  return (
    <div className="flex min-w-0 flex-col gap-1.5" data-testid="budget-payments">
      <span className="text-xs font-bold text-text-secondary">{tx(L.history)}</span>
      <ul className="flex max-h-56 flex-col gap-1 overflow-y-auto">
        {data.history.map((p) => {
          const kind = KIND[p.kind]
          const sign = p.amount_sar < 0 ? '−' : '+'

          return (
            <li key={p.id} data-testid={`history-${p.kind}`} className={`flex min-w-0 items-center gap-2 text-xs ${p.reversed ? 'opacity-50' : ''}`}>
              <span className="tnum shrink-0 text-text-muted">{p.paid_on}</span>
              <span className="shrink-0 rounded-md bg-surface-secondary px-1.5 py-0.5 font-bold text-text-secondary">{tx(kind)}</span>
              <span className="min-w-0 flex-1 truncate text-text-secondary" title={p.reason ?? p.note ?? ''}>
                {[p.reference, p.reason ?? p.note, p.added_by].filter(Boolean).join(' · ')}
              </span>
              {p.amount_sar !== 0 && <span className={`shrink-0 font-bold ${p.amount_sar < 0 ? 'text-danger' : 'text-success'} ${p.reversed ? 'line-through' : ''}`}><bdi dir="ltr">{sign}</bdi><Money n={Math.abs(p.amount_sar)} currency="SAR" /></span>}
              {canManage && p.can_undo && (
                <button
                  type="button"
                  onClick={() => onUndo(data, p.id)}
                  disabled={pending}
                  data-testid="history-undo"
                  className="flex shrink-0 items-center gap-1 rounded-md border border-border px-1.5 py-0.5 font-bold text-brand-700 hover:bg-surface-hover disabled:opacity-50"
                >
                  <Undo2 className="h-3 w-3" aria-hidden /> {tx(L.undo)}
                </button>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

/** The month: live while it runs, then fixed once it closes. */
function MonthCard({ projectId, months, ar }: { projectId: string; months: string[]; ar: boolean }) {
  const [month, setMonth] = useState<string | null>(months[0] ?? null)
  useEffect(() => { setMonth(months[0] ?? null) }, [projectId, months])
  const { data } = useBudgetMonth(projectId, month)
  const [failed, setFailed] = useState(false)
  const tx = (v: Lang) => (ar ? v.ar : v.en)
  if (months.length === 0) return null

  const cell = (label: Lang, value: number | null, tone = 'text-text-primary') => (
    <div className="flex min-w-0 flex-col">
      <span className="text-xs text-text-muted">{tx(label)}</span>
      <Sar n={value} ar={ar} className={`text-sm font-extrabold ${tone}`} />
    </div>
  )

  return (
    <div className="flex min-w-0 flex-col gap-1.5 border-t border-border pt-2" data-testid="budget-month">
      <div className="flex min-w-0 flex-wrap items-center gap-1.5">
        <span className="text-xs font-bold text-text-secondary">{tx(L.month)}</span>
        {months.slice(0, 3).map((m) => (
          <button
            key={m}
            type="button"
            onClick={() => setMonth(m)}
            aria-pressed={m === month}
            className={`tnum rounded-[8px] border px-2 py-0.5 text-xs font-semibold ${m === month ? 'border-brand-600 text-brand-600' : 'border-border text-text-secondary'}`}
          >
            {m}
          </button>
        ))}
        {months.length > 3 && (
          <select
            aria-label={tx(L.month)}
            value={months.slice(0, 3).includes(month ?? '') ? '' : (month ?? '')}
            onChange={(e) => e.target.value && setMonth(e.target.value)}
            className="tnum rounded-[8px] border border-border bg-surface px-1.5 py-0.5 text-xs text-text-secondary"
          >
            <option value="">…</option>
            {months.slice(3).map((m) => <option key={m} value={m}>{m}</option>)}
          </select>
        )}
        {data && <Badge tone={data.fixed ? 'info' : 'neutral'}>{data.fixed ? tx(L.fixed) : tx(L.live)}</Badge>}
        <button
          type="button"
          onClick={() => data && void downloadBudgets('xlsx', { project_id: projectId, from: data.from, to: data.to }).then((ok) => setFailed(!ok))}
          disabled={!data}
          className="ms-auto text-xs font-semibold text-brand-600"
          data-testid="budget-month-export"
        >
          {failed ? tx(L.exportFailed) : tx(L.export)}
        </button>
      </div>
      {data && (
        <div className="grid grid-cols-4 gap-2">
          {cell(L.start, data.remaining_start)}
          {cell(L.added, data.added, 'text-success')}
          {cell(L.spent, data.spent)}
          {cell(L.end, data.remaining_end, data.remaining_end !== null && data.remaining_end < 0 ? 'text-danger' : 'text-text-primary')}
        </div>
      )}
    </div>
  )
}

/** A platform's own spend: USD (the platforms' one unit); a day with no rate keeps its own currency and says «بدون سعر صرف». */
export function PlatformFigure({ item, ar }: { item: BudgetDetail['by_platform'][number]; ar: boolean }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-1">
      {item.spent_usd !== null && item.spent_usd !== undefined && <Money n={item.spent_usd} currency="USD" className="font-semibold text-text-primary" />}
      {(item.unconverted ?? []).map((u) => (
        <span key={u.currency} className="font-semibold text-text-primary"><bdi dir="ltr" className="tnum">{moneyNumber(u.amount)}</bdi> {currencyMark(u.currency)}</span>
      ))}
      {!!item.unconverted_days && <Badge tone="warning">{ar ? L.noRate.ar : L.noRate.en}</Badge>}
    </span>
  )
}

/** مخطّطة: one bar per platform — planned against spent, the share in %, and at most one «فرق عن الخطة» chip. «أعد التوزيع» is one click. */
function PlanBars({ data, ar, canManage }: { data: BudgetDetail; ar: boolean; canManage: boolean }) {
  const tx = (v: Lang) => (ar ? v.ar : v.en)
  const realloc = useReallocateBudget()
  const [failed, setFailed] = useState(false)
  const rows = data.plan ?? []
  const off = rows.some((r) => r.flag !== null)

  return (
    <div className="flex min-w-0 flex-col gap-1.5" data-testid="budget-plan">
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-bold text-text-secondary">{tx(L.planVsActual)}</span>
        <span className="flex items-center gap-1.5">
          {off && <Badge tone="warning" data-testid="plan-diff-chip">{tx(PLAN_DIFF)}</Badge>}
          {canManage && data.status !== 'void' && (
            <Button
              size="sm"
              variant="secondary"
              loading={realloc.isPending}
              data-testid="budget-reallocate"
              onClick={() => { setFailed(false); realloc.mutate({ projectId: data.project_id, reason: tx(L.reallocAuto) }, { onError: () => setFailed(true) }) }}
            >
              {tx(L.realloc)}
            </Button>
          )}
        </span>
      </div>
      {failed && <span role="alert" className="text-xs font-semibold text-danger">{tx(L.undoFailed)}</span>}
      <ul className="flex flex-col gap-2">
        {rows.map((r, n) => {
          const share = r.share_used === null ? null : Math.round(r.share_used * 100)
          const over = (r.share_used ?? 0) >= 1

          return (
            <li key={r.provider} className="flex min-w-0 flex-col gap-0.5" data-testid={`plan-bar-${r.provider}`}>
              <span className="flex min-w-0 items-baseline justify-between gap-2 text-xs">
                <span className="flex items-center gap-1 font-bold text-text-primary">
                  <span className="h-2 w-2 rounded-full" style={{ background: CHART_SERIES[n % CHART_SERIES.length] }} aria-hidden />
                  {providerLabel(r.provider, ar ? 'ar' : 'en')}
                </span>
                <span className="tnum min-w-0 truncate text-text-secondary">
                  {r.unconverted_days > 0 ? <Badge tone="warning">{tx(L.noRate)}</Badge> : <Money n={r.spent_usd} currency="USD" />}
                  {r.planned_usd !== null && <> / <Money n={r.planned_usd} currency="USD" /></>}
                  {share !== null && <bdi dir="ltr" className="ms-1.5 font-extrabold text-text-primary">{share}%</bdi>}
                </span>
              </span>
              <span className="h-2 w-full overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
                <span className={`block h-full rounded-full ${over ? 'bg-danger' : (r.share_used ?? 0) >= 0.9 ? 'bg-warning' : 'bg-success'}`} style={{ width: `${Math.min(100, share ?? 0)}%` }} />
              </span>
            </li>
          )
        })}
      </ul>
    </div>
  )
}

/** بالأجل: the spend of each month, in riyals — what is invoiced from. The running month is marked; the file is one click. */
function InvoiceMonths({ data, ar }: { data: BudgetDetail; ar: boolean }) {
  const tx = (v: Lang) => (ar ? v.ar : v.en)
  const [failed, setFailed] = useState(false)

  return (
    <div className="flex min-w-0 flex-col gap-1.5" data-testid="budget-invoice-months">
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-bold text-text-secondary">{tx(L.invoiceMonths)}</span>
        <button type="button" data-testid="invoice-export" className="text-xs font-semibold text-brand-600" onClick={() => void downloadBudgets('csv', { project_id: data.project_id, sheet: 'invoice' }).then((ok) => setFailed(!ok))}>
          {failed ? tx(L.exportFailed) : tx(L.export)}
        </button>
      </div>
      <ul className="flex flex-col gap-1">
        {[...data.invoice_months].reverse().map((m) => (
          <li key={m.month} data-testid={`invoice-month-${m.month}`} className="flex min-w-0 items-center gap-2 text-xs">
            <span className="tnum w-16 shrink-0 text-text-muted">{m.month}</span>
            <Badge tone={m.closed ? 'success' : 'neutral'}>{m.closed ? tx(L.monthClosed) : tx(L.monthRunning)}</Badge>
            {m.unconverted_days > 0 && <Badge tone="warning">{tx(L.noRate)}</Badge>}
            <span className="ms-auto text-sm font-extrabold text-text-primary"><Sar n={m.spent} ar={ar} /></span>
          </li>
        ))}
      </ul>
    </div>
  )
}
