import { providerLabel } from '@/features/campaigns/labels'
import { CHART_SERIES } from '@/features/analytics/charts'
import { Badge } from '@/components/ui/Badge'
import type { BudgetRow } from './fundingApi'
import { L, STATUS, TONE_BAR, sar } from './labels'

type Lang = { ar: string; en: string }

/** A figure in riyals. The number is its own left-to-right island, so a minus stays in front of it in Arabic. */
export function Sar({ n, ar, className = '' }: { n: number | null | undefined; ar: boolean; className?: string }) {
  if (n === null || n === undefined) return <span className={className}>—</span>

  return (
    <span className={className}>
      <bdi dir="ltr" className="tnum">{`${n < 0 ? '−' : ''}${sar(n)}`}</bdi> {ar ? L.sar.ar : L.sar.en}
    </span>
  )
}

/** «MM-DD → MM-DD» in Latin digits; an open end is «∞». The full dates are in the tooltip. */
export function PeriodChip({ start, end, stated, ar }: { start: string | null; end: string | null; stated?: boolean; ar: boolean }) {
  if (start === null) return <span className="text-xs text-text-muted">—</span>
  const short = (d: string) => d.slice(2).replaceAll('-', '/')
  const full = `${start} → ${end ?? (ar ? L.open.ar : L.open.en)}`

  return (
    <span
      dir="ltr"
      title={full}
      data-testid="period-chip"
      className={`tnum inline-flex items-center gap-1 whitespace-nowrap rounded-[var(--radius-pill)] border px-2 py-0.5 text-xs font-semibold ${stated ? 'border-brand-600/50 text-brand-700' : 'border-border text-text-secondary'}`}
    >
      {short(start)} <span aria-hidden>→</span> {end ? short(end) : '∞'}
    </span>
  )
}

/** The platforms' parts of one project's spend as a mini bar: a segment each, the name and figure in its tooltip. */
export function PlatformSplit({ items, ar, height = 'h-2.5' }: { items: BudgetRow['by_platform']; ar: boolean; height?: string }) {
  const total = items.reduce((s, i) => s + i.spent, 0)
  if (items.length === 0) return <span className="text-xs text-text-muted">—</span>

  return (
    <div className={`flex ${height} w-full min-w-[3rem] overflow-hidden rounded-full bg-surface-secondary`} data-testid="platform-split" dir="ltr">
      {items.map((i, n) => (
        <div
          key={i.provider}
          title={`${providerLabel(i.provider, ar ? 'ar' : 'en')} · ${sar(i.spent)} ${ar ? L.sar.ar : L.sar.en}${i.unconverted_days ? ` · ${ar ? L.noRate.ar : L.noRate.en}` : ''}`}
          data-testid={`split-${i.provider}`}
          style={{ width: total > 0 ? `${(i.spent / total) * 100}%` : `${100 / items.length}%`, background: CHART_SERIES[n % CHART_SERIES.length] }}
          className={i.unconverted_days ? 'opacity-60' : ''}
        />
      ))}
    </div>
  )
}

export const needsAttention = (r: BudgetRow) => r.status === 'over' || r.status === 'low' || r.status === 'incomplete'

/**
 * One project, one dense row: name, period, budget → spend → remaining as a bar with its percent, the
 * platforms' parts, the state, and a dot when something needs a decision. Opens the drawer; never moves the page.
 */
export function BudgetBoardRow({ row, active, onOpen, ar }: { row: BudgetRow; active: boolean; onOpen: () => void; ar: boolean }) {
  const st = STATUS[row.status]
  const used = row.used_ratio === null ? null : Math.min(1, row.used_ratio)
  const red = row.status === 'over' || (row.remaining !== null && row.remaining < row.alert_floor)

  return (
    <button
      type="button"
      onClick={onOpen}
      aria-current={active}
      data-testid={`budget-row-${row.project_id}`}
      className={`grid w-full min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-[10px] border px-2.5 py-1 text-start transition lg:grid-cols-[minmax(0,1.5fr)_6.75rem_minmax(0,2.6fr)_minmax(0,1.3fr)_7.25rem] ${active ? 'border-brand-600 bg-surface' : 'border-border bg-surface hover:border-border-strong'}`}
    >
      <span className="flex min-w-0 items-center gap-1.5">
        <span
          className={`h-2 w-2 shrink-0 rounded-full ${needsAttention(row) ? (row.status === 'over' ? 'bg-danger' : 'bg-warning') : 'bg-transparent'}`}
          data-testid={needsAttention(row) ? 'alert-dot' : undefined}
          aria-hidden
        />
        <span className="flex min-w-0 flex-col leading-tight">
          <span className="truncate text-sm font-bold text-text-primary">{row.client_name}</span>
          <span className="truncate text-xs text-text-muted">{row.project_name}</span>
        </span>
      </span>

      <span className="justify-self-end lg:justify-self-start">
        <PeriodChip start={row.period_start} end={row.period_end} stated={row.period_start_stated} ar={ar} />
      </span>

      <span className="col-span-2 flex min-w-0 flex-col gap-0.5 lg:col-span-1">
        <span className="flex min-w-0 items-baseline justify-between gap-2 text-xs text-text-secondary">
          <Sar n={row.remaining} ar={ar} className={`text-sm font-extrabold ${red ? 'text-danger' : 'text-text-primary'}`} />
          <span className="tnum min-w-0 truncate">
            <bdi dir="ltr">{sar(row.spent)} / {sar(row.approved)}</bdi>
            {used !== null && <bdi dir="ltr" className="ms-1.5 font-extrabold text-text-primary" data-testid="used-percent">{Math.round(used * 100)}%</bdi>}
          </span>
        </span>
        <span className="h-1.5 w-full overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
          {used !== null && <span className={`block h-full rounded-full ${TONE_BAR[st.tone]}`} style={{ width: `${Math.round(used * 100)}%` }} />}
        </span>
      </span>

      <span className="col-span-2 min-w-0 lg:col-span-1"><PlatformSplit items={row.by_platform} ar={ar} /></span>

      <span className="col-span-2 justify-self-start lg:col-span-1 lg:justify-self-end">
        <Badge tone={st.tone} className="whitespace-nowrap">{ar ? st.ar : st.en}</Badge>
      </span>
    </button>
  )
}

/** The slim totals line: budget, spend, remaining, alerts — one row that never leaves the screen. */
export function TotalsStrip({ totals, ar }: { totals: { approved: number; spent: number; remaining: number; needs_attention: number } | null; ar: boolean }) {
  const tx = (v: Lang) => (ar ? v.ar : v.en)
  const cell = (label: Lang, node: React.ReactNode, testid: string, accent: string) => (
    <span className="flex min-w-0 items-center gap-1.5" data-testid={testid}>
      <span className={`h-3.5 w-1 shrink-0 rounded-full ${accent}`} aria-hidden />
      <span className="text-xs font-semibold text-text-secondary">{tx(label)}</span>
      <span className="truncate text-sm font-extrabold text-text-primary">{node}</span>
    </span>
  )
  const negative = (totals?.remaining ?? 0) < 0

  return (
    <div className="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1" data-testid="budgets-tiles">
      {cell(L.approved, <Sar n={totals?.approved} ar={ar} />, 'tile-approved', 'bg-brand-600')}
      {cell(L.spent, <Sar n={totals?.spent} ar={ar} />, 'tile-spent', 'bg-info')}
      {cell(L.remaining, <Sar n={totals?.remaining} ar={ar} className={negative ? 'text-danger' : ''} />, 'tile-remaining', negative ? 'bg-danger' : 'bg-success')}
      {cell(L.alerts, totals ? String(totals.needs_attention) : '—', 'tile-alerts', (totals?.needs_attention ?? 0) > 0 ? 'bg-warning' : 'bg-border-strong')}
    </div>
  )
}
