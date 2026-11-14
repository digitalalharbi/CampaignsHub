import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Download, Plus, RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { PageIntro } from '@/components/ui/PageIntro'
import { EmptyState, ErrorState, NoPermission } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { MetricLineChart, ProgressRing, CHART_SERIES } from '@/features/analytics/charts'
import { providerLabel } from '@/features/campaigns/labels'
import { useUi } from '@/stores/ui'
import { AddBudgetDialog } from './AddBudgetDialog'
import { downloadBudgets, useBudget, useBudgetMonth, useBudgets, type BudgetDetail, type BudgetRow } from './fundingApi'
import { L, STATUS, TONE_BAR, sar } from './labels'

type Lang = { ar: string; en: string }

/** A figure in riyals. The number is its own left-to-right island, so a minus stays in front of it in Arabic. */
function Sar({ n, ar, className = '' }: { n: number | null | undefined; ar: boolean; className?: string }) {
  if (n === null || n === undefined) return <span className={className}>—</span>

  return (
    <span className={className}>
      <bdi dir="ltr" className="tnum">{`${n < 0 ? '−' : ''}${sar(n)}`}</bdi> {ar ? L.sar.ar : L.sar.en}
    </span>
  )
}

/**
 * FIN-LOOP-001 — «الميزانيات»: every project budget on one screen.
 *
 * Four figures, then the projects ordered by what needs a decision, each drawn as a bar of spend
 * against its budget; the chosen project opens beside the list with its remaining day by day (a
 * renewal is a step up), its platforms, its payments and its month. No horizontal scroll at any width;
 * on a phone the list and the detail stack as cards. Every figure comes from the server.
 */
export function BudgetsPage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const tx = (v: Lang) => (ar ? v.ar : v.en)
  const [params, setParams] = useSearchParams()
  const { data, isLoading, error, refetch } = useBudgets()
  const rows = data?.rows ?? []
  const asked = params.get('project')
  // A project the reader cannot see (or no longer has a budget) falls back to the first one listed.
  const selected = (asked !== null && rows.some((r) => r.project_id === asked) ? asked : rows[0]?.project_id) ?? null
  const [adding, setAdding] = useState(false)
  const [exportFailed, setExportFailed] = useState(false)
  const exportFile = (format: 'xlsx' | 'csv') => void downloadBudgets(format).then((ok) => setExportFailed(!ok))
  const [renewing, setRenewing] = useState(false)

  const totals = data?.totals ?? null
  // A figure with its currency, or a dash while it is not known — never «— ر.س».
  const figure = (n: number | null | undefined) => <Sar n={n} ar={ar} />
  const choose = (id: string) => setParams((p) => { p.set('project', id); return p }, { replace: true })

  return (
    <div className="mx-auto flex w-full max-w-[1400px] min-w-0 flex-col gap-3" data-testid="budgets-page">
      <PageIntro
        title={tx(L.title)}
        testid="budgets-intro"
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="secondary" size="sm" onClick={() => exportFile('xlsx')} data-testid="budgets-export-xlsx">
              <Download className="h-4 w-4" aria-hidden /> XLSX
            </Button>
            <Button variant="secondary" size="sm" onClick={() => exportFile('csv')} data-testid="budgets-export-csv">
              <Download className="h-4 w-4" aria-hidden /> CSV
            </Button>
            {exportFailed && <span role="alert" className="text-xs font-semibold text-danger">{tx(L.exportFailed)}</span>}
            {data?.canManage && (
              <Button size="sm" onClick={() => setAdding(true)} data-testid="budgets-add">
                <Plus className="h-4 w-4" aria-hidden /> {tx(L.add)}
              </Button>
            )}
          </div>
        }
      />

      <div className="grid grid-cols-2 gap-2 lg:grid-cols-4" data-testid="budgets-tiles">
        <Tile label={tx(L.approved)} testid="tile-approved" accent="bg-brand-600">{figure(totals?.approved)}</Tile>
        <Tile label={tx(L.spent)} testid="tile-spent" accent="bg-info">{figure(totals?.spent)}</Tile>
        <Tile label={tx(L.remaining)} testid="tile-remaining" accent={(totals?.remaining ?? 0) < 0 ? 'bg-danger' : 'bg-success'} danger={(totals?.remaining ?? 0) < 0}>
          {figure(totals?.remaining)}
        </Tile>
        <Tile label={tx(L.alerts)} testid="tile-alerts" accent={(totals?.needs_attention ?? 0) > 0 ? 'bg-warning' : 'bg-border-strong'}>
          {totals ? String(totals.needs_attention) : '—'}
        </Tile>
      </div>

      {error ? (
        toApiError(error).status === 403 ? <NoPermission /> : <ErrorState error={error} onRetry={() => void refetch()} ar={ar} title={tx(L.title)} />
      ) : !isLoading && rows.length === 0 ? (
        <EmptyState title={tx(L.empty)} />
      ) : (
        <div className="grid min-w-0 grid-cols-1 gap-3 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
          <ul className="flex min-w-0 flex-col gap-1.5 lg:max-h-[calc(100vh-11rem)] lg:overflow-y-auto" data-testid="budgets-list">
            {rows.map((r) => (
              <li key={r.project_id} className="min-w-0">
                <BudgetRowButton row={r} active={r.project_id === selected} onClick={() => choose(r.project_id)} ar={ar} />
              </li>
            ))}
          </ul>
          {selected && <BudgetPanel projectId={selected} ar={ar} canManage={data?.canManage === true} onRenew={() => setRenewing(true)} />}
        </div>
      )}

      <AddBudgetDialog open={adding} onClose={() => setAdding(false)} onAdded={choose} />
      <RenewDialog projectId={selected} open={renewing} onClose={() => setRenewing(false)} />
    </div>
  )
}

/** A slim figure: a coloured edge, the name, the number. Four fit one row on a laptop, two on a phone. */
function Tile({ label, children, accent, testid, danger = false }: { label: string; children: ReactNode; accent: string; testid: string; danger?: boolean }) {
  return (
    <div className="relative flex min-w-0 flex-col gap-0.5 overflow-hidden rounded-[var(--radius-card)] border border-border bg-surface px-3 py-2" data-testid={testid}>
      <span className={`absolute inset-y-0 start-0 w-1 ${accent}`} aria-hidden />
      <span className="text-xs font-semibold text-text-secondary">{label}</span>
      <span className={`truncate text-lg font-extrabold ${danger ? 'text-danger' : 'text-text-primary'}`}>{children}</span>
    </div>
  )
}

function RenewDialog({ projectId, open, onClose }: { projectId: string | null; open: boolean; onClose: () => void }) {
  const rows = useBudgets().data?.rows ?? []
  const row = rows.find((r) => r.project_id === projectId)
  const clientId = row?.client_workspace_id
  const clientName = row?.client_name ?? ''
  const projectName = row?.project_name ?? ''
  // Built from the ids and names only, so a refetch of the figures does not reset an open form.
  const fixed = useMemo(
    () => (projectId && clientId ? { clientId, clientName, projectId, projectName } : undefined),
    [projectId, clientId, clientName, projectName],
  )
  if (!fixed) return null

  return <AddBudgetDialog open={open} onClose={onClose} fixed={fixed} />
}

/** One project: names, a bar of spend against the budget, the remaining and its state. */
function BudgetRowButton({ row, active, onClick, ar }: { row: BudgetRow; active: boolean; onClick: () => void; ar: boolean }) {
  const st = STATUS[row.status]
  const used = row.used_ratio === null ? null : Math.min(1, row.used_ratio)
  const red = row.status === 'over' || (row.remaining !== null && row.remaining < row.alert_floor)

  return (
    <button
      type="button"
      onClick={onClick}
      aria-current={active}
      data-testid={`budget-row-${row.project_id}`}
      className={`flex w-full min-w-0 flex-col gap-1.5 rounded-[var(--radius-card)] border px-3 py-2 text-start transition ${active ? 'border-brand-600 bg-surface' : 'border-border bg-surface hover:border-border-strong'}`}
    >
      <div className="flex min-w-0 items-center justify-between gap-2">
        <span className="flex min-w-0 items-baseline gap-1.5 text-sm">
          <span className="min-w-0 shrink truncate font-bold text-text-primary">{row.client_name}</span>
          <span className="min-w-0 shrink-[2] truncate text-xs text-text-muted">{row.project_name}</span>
        </span>
        <Badge tone={st.tone} className="shrink-0 whitespace-nowrap">{ar ? st.ar : st.en}</Badge>
      </div>
      {used !== null && (
        <div className="h-2 w-full overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
          <div className={`h-full rounded-full ${TONE_BAR[st.tone]}`} style={{ width: `${Math.round(used * 100)}%` }} />
        </div>
      )}
      <div className="flex min-w-0 flex-wrap items-baseline justify-between gap-x-3 text-xs text-text-secondary">
        <Sar n={row.remaining} ar={ar} className={`text-sm font-extrabold ${red ? 'text-danger' : 'text-text-primary'}`} />
        <span className="tnum">
          {sar(row.spent)} / {sar(row.approved)}
          {row.days_left !== null && ` · ${ar ? L.daysLeft.ar : L.daysLeft.en} ${row.days_left} ${ar ? L.days.ar : L.days.en}`}
        </span>
      </div>
    </button>
  )
}

/** The chosen project, beside the list. */
function BudgetPanel({ projectId, ar, canManage, onRenew }: { projectId: string; ar: boolean; canManage: boolean; onRenew: () => void }) {
  const { data } = useBudget(projectId)
  const tx = (v: Lang) => (ar ? v.ar : v.en)
  if (!data) return <div className="min-h-[20rem] rounded-[var(--radius-card)] border border-border bg-surface" aria-busy />

  const st = STATUS[data.status]
  const used = data.used_ratio ?? 0
  const ringTone = data.status === 'over' ? 'danger' : data.status === 'low' ? 'warning' : 'success'

  return (
    <section className="flex min-w-0 flex-col gap-3 rounded-[var(--radius-card)] border border-border bg-surface p-3" data-testid="budget-panel">
      <div className="flex min-w-0 flex-wrap items-center gap-3">
        {data.used_ratio !== null && (
          <ProgressRing value={Math.min(1, used)} size={72} tone={ringTone} label={`${Math.round(used * 100)}%`} sublabel={tx(L.spent)} />
        )}
        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
          <span className="min-w-0 truncate text-sm font-bold text-text-primary">{data.project_name}</span>
          <span data-testid="budget-remaining">
            <Sar n={data.remaining} ar={ar} className={`text-2xl font-extrabold ${data.remaining !== null && data.remaining < 0 ? 'text-danger' : 'text-text-primary'}`} />
          </span>
          <span className="tnum text-xs text-text-secondary">
            {tx(L.spent)} {sar(data.spent)} · {tx(L.approved)} {sar(data.approved)}
            {data.pace_per_day !== null && ` · ${tx(L.pace)} ${sar(data.pace_per_day)}${tx(L.perDay)}`}
            {data.days_left !== null && ` · ${tx(L.daysLeft)} ${data.days_left} ${tx(L.days)}`}
          </span>
        </div>
        <div className="flex items-center gap-2">
          <Badge tone={st.tone} className="whitespace-nowrap">{tx(st)}</Badge>
          {canManage && (
            <Button size="sm" onClick={onRenew} data-testid="budget-renew">
              <RefreshCw className="h-4 w-4" aria-hidden /> {tx(L.renew)}
            </Button>
          )}
        </div>
      </div>

      {data.series.length > 1 && <BalanceChart data={data} ar={ar} />}

      <div className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2">
        <PlatformBar items={data.by_platform} ar={ar} />
        <Payments data={data} ar={ar} />
      </div>

      <MonthCard projectId={projectId} months={data.months} ar={ar} />
    </section>
  )
}

function BalanceChart({ data, ar }: { data: BudgetDetail; ar: boolean }) {
  const points = data.series.map((p) => ({ date: p.date, remaining: p.remaining, approved: p.approved }))

  return (
    <div className="min-w-0" data-testid="budget-chart">
      <MetricLineChart
        data={points}
        height={150}
        series={[
          { key: 'remaining', name: ar ? L.remaining.ar : L.remaining.en, color: CHART_SERIES[0], kind: 'money' },
          { key: 'approved', name: ar ? L.approved.ar : L.approved.en, color: CHART_SERIES[3], kind: 'money' },
        ]}
        currency="SAR"
      />
    </div>
  )
}

/** Where the money went, as one segmented bar with its legend. */
function PlatformBar({ items, ar }: { items: { provider: string; spent: number }[]; ar: boolean }) {
  const total = items.reduce((s, i) => s + i.spent, 0)
  if (total <= 0) return <div />

  return (
    <div className="flex min-w-0 flex-col gap-2" data-testid="budget-platforms">
      <span className="text-xs font-bold text-text-secondary">{ar ? L.platforms.ar : L.platforms.en}</span>
      <div className="flex h-3 w-full overflow-hidden rounded-full" aria-hidden>
        {items.map((i, n) => (
          <div key={i.provider} style={{ width: `${(i.spent / total) * 100}%`, background: CHART_SERIES[n % CHART_SERIES.length] }} />
        ))}
      </div>
      <ul className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-text-secondary">
        {items.map((i, n) => (
          <li key={i.provider} className="flex items-center gap-1">
            <span className="h-2 w-2 rounded-full" style={{ background: CHART_SERIES[n % CHART_SERIES.length] }} aria-hidden />
            {providerLabel(i.provider, ar ? 'ar' : 'en')} <span className="tnum font-semibold text-text-primary">{sar(i.spent)}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

function Payments({ data, ar }: { data: BudgetDetail; ar: boolean }) {
  return (
    <div className="flex min-w-0 flex-col gap-1.5" data-testid="budget-payments">
      <span className="text-xs font-bold text-text-secondary">{ar ? L.history.ar : L.history.en}</span>
      <ul className="flex max-h-40 flex-col gap-1 overflow-y-auto">
        {data.history.map((p) => (
          <li key={p.id} className="flex min-w-0 items-center justify-between gap-2 text-xs">
            <span className="tnum shrink-0 text-text-muted">{p.paid_on}</span>
            <span className="min-w-0 flex-1 truncate text-text-secondary">{p.reference ?? ''}{p.added_by ? ` · ${p.added_by}` : ''}</span>
            <bdi dir="ltr" className="tnum shrink-0 font-bold text-success">+{sar(p.amount_sar)}</bdi>
          </li>
        ))}
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
    <div className="flex min-w-0 flex-col gap-2 border-t border-border pt-2" data-testid="budget-month">
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
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {cell(L.start, data.remaining_start)}
          {cell(L.added, data.added, 'text-success')}
          {cell(L.spent, data.spent)}
          {cell(L.end, data.remaining_end, data.remaining_end !== null && data.remaining_end < 0 ? 'text-danger' : 'text-text-primary')}
        </div>
      )}
    </div>
  )
}
