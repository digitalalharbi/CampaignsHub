import { useEffect, useState } from 'react'
import { Pencil, RefreshCw, Trash2, Undo2 } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { MetricLineChart, CHART_SERIES } from '@/features/analytics/charts'
import { providerLabel } from '@/features/campaigns/labels'
import { SlideOver } from './SlideOver'
import { PeriodChip, PlatformSplit, Sar } from './BudgetBoard'
import { downloadBudgets, useBudget, useBudgetMonth, type BudgetDetail } from './fundingApi'
import { KIND, L, STATUS, sar } from './labels'

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
              <PeriodChip start={data.period_start} end={data.period_end} stated={data.period_start_stated} ar={ar} />
            </span>
          </div>
        ) : <span className="text-sm font-bold">{tx(L.title)}</span>
      }
    >
      {!data ? <div className="min-h-[12rem]" aria-busy /> : (
        <div className="flex min-w-0 flex-col gap-3" data-testid="budget-panel">
          <div className="grid grid-cols-3 gap-2">
            <Fig label={L.approved} ar={ar}><Sar n={data.approved} ar={ar} /></Fig>
            <Fig label={L.spent} ar={ar}><Sar n={data.spent} ar={ar} /></Fig>
            <Fig label={L.remaining} ar={ar}>
              <span data-testid="budget-remaining"><Sar n={data.remaining} ar={ar} className={data.remaining !== null && data.remaining < 0 ? 'text-danger' : ''} /></span>
            </Fig>
          </div>

          {canManage && data.status !== 'void' && (
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="secondary" onClick={() => onEdit(data)} data-testid="budget-edit"><Pencil className="h-4 w-4" aria-hidden /> {tx(L.edit)}</Button>
              <Button size="sm" onClick={() => onRenew(data)} data-testid="budget-renew"><RefreshCw className="h-4 w-4" aria-hidden /> {tx(L.renew)}</Button>
              <Button size="sm" variant="ghost" onClick={() => onDelete(data)} data-testid="budget-delete" className="text-danger"><Trash2 className="h-4 w-4" aria-hidden /> {tx(L.del)}</Button>
            </div>
          )}

          {data.series.length > 1 && (
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

          {data.by_platform.length > 0 && (
            <div className="flex min-w-0 flex-col gap-1.5" data-testid="budget-platforms">
              <span className="text-xs font-bold text-text-secondary">{tx(L.platforms)}</span>
              <PlatformSplit items={data.by_platform} ar={ar} height="h-3" />
              <ul className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-text-secondary">
                {data.by_platform.map((i, n) => (
                  <li key={i.provider} className="flex items-center gap-1">
                    <span className="h-2 w-2 rounded-full" style={{ background: CHART_SERIES[n % CHART_SERIES.length] }} aria-hidden />
                    {providerLabel(i.provider, ar ? 'ar' : 'en')} <span className="tnum font-semibold text-text-primary">{sar(i.spent)}</span>
                    {!!i.unconverted_days && <span className="text-warning">· {tx(L.noRate)}</span>}
                  </li>
                ))}
              </ul>
            </div>
          )}

          <History data={data} ar={ar} canManage={canManage} onUndo={onUndo} pending={undoPending} />
          <MonthCard projectId={data.project_id} months={data.months} ar={ar} />
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
              <bdi dir="ltr" className={`tnum shrink-0 font-bold ${p.amount_sar < 0 ? 'text-danger' : 'text-success'} ${p.reversed ? 'line-through' : ''}`}>{sign}{sar(p.amount_sar)}</bdi>
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
