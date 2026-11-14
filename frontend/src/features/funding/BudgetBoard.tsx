import { providerLabel } from '@/features/campaigns/labels'
import { CHART_SERIES } from '@/features/analytics/charts'
import { Badge } from '@/components/ui/Badge'
import { useUi } from '@/stores/ui'
import type { BudgetRow } from './fundingApi'
import { L, MODE, PLAN_DIFF, STATUS, TONE_BAR } from './labels'
import { Money, currencyMark, moneyNumber } from './money'

type Lang = { ar: string; en: string }

/** A figure in riyals, its mark beside it (the one money component: `Money`). */
export function Sar({ n, ar: _ar, className = '' }: { n: number | null | undefined; ar: boolean; className?: string }) {
  return <Money n={n} currency="SAR" className={className} />
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

/**
 * One platform's part as text: its own spend in USD (the platforms' one unit). With no rate for some of its days, the amount is
 * shown in its OWN currency's mark beside «بدون سعر صرف» — never a guessed conversion.
 */
export function platformTip(i: BudgetRow['by_platform'][number], ar: boolean): string {
  const name = providerLabel(i.provider, ar ? 'ar' : 'en')
  const usd = i.spent_usd === null || i.spent_usd === undefined ? null : `${moneyNumber(i.spent_usd)} ${currencyMark('USD')}`
  const raw = (i.unconverted ?? []).map((u) => `${moneyNumber(u.amount)} ${currencyMark(u.currency)}`)
  const parts = [usd, ...raw].filter(Boolean)

  return `${name} · ${parts.join(' + ') || '—'}${i.unconverted_days ? ` · ${ar ? L.noRate.ar : L.noRate.en}` : ''}`
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
          title={platformTip(i, ar)}
          data-testid={`split-${i.provider}`}
          style={{ width: total > 0 ? `${(i.spent / total) * 100}%` : `${100 / items.length}%`, background: CHART_SERIES[n % CHART_SERIES.length] }}
          className={i.unconverted_days ? 'opacity-60' : ''}
        />
      ))}
    </div>
  )
}

export const needsAttention = (r: BudgetRow) => ['over', 'low', 'incomplete', 'credit_over', 'credit_near'].includes(r.status) || r.plan_alerts > 0
const isRed = (r: BudgetRow) => r.status === 'over' || r.status === 'credit_over'

/** The mode chip of a row: مباشر / بالأجل, and مخطّطة beside it when the budget has a platform split. */
export function ModeBadges({ row, ar }: { row: BudgetRow; ar: boolean }) {
  const chip = (label: { ar: string; en: string }, testid: string) => (
    <span key={testid} data-testid={testid} className="whitespace-nowrap rounded-[var(--radius-pill)] bg-surface-secondary px-2 py-0.5 text-xs font-bold text-text-secondary">{ar ? label.ar : label.en}</span>
  )

  return (
    <span className="flex shrink-0 items-center gap-1">
      {chip(MODE[row.payment_mode ?? 'direct'], `mode-chip-${row.payment_mode ?? 'direct'}`)}
      {row.planned && chip(MODE.planned, 'mode-chip-planned')}
    </span>
  )
}

/** A small ring of the used share; the colour is the state's, the number is in the row. */
export function Ring({ ratio, tone, size = 34 }: { ratio: number; tone: 'success' | 'warning' | 'danger' | 'neutral'; size?: number }) {
  const r = 14
  const c = 2 * Math.PI * r
  const color = { success: 'var(--color-success, #0d8a6f)', warning: 'var(--color-warning, #d98a1c)', danger: 'var(--color-danger, #c8453b)', neutral: 'var(--color-border-strong, #b8b4a8)' }[tone]

  return (
    <svg width={size} height={size} viewBox="0 0 36 36" className="shrink-0 -rotate-90" data-testid="budget-ring" role="img" aria-label={`${Math.round(ratio * 100)}%`}>
      <circle cx="18" cy="18" r={r} fill="none" stroke="currentColor" strokeOpacity="0.12" strokeWidth="4" />
      <circle cx="18" cy="18" r={r} fill="none" stroke={color} strokeWidth="4" strokeLinecap="round" strokeDasharray={`${Math.min(1, ratio) * c} ${c}`} />
    </svg>
  )
}

function Fig({ label, children }: { label: { ar: string; en: string }; children: React.ReactNode }) {
  const ar = useUi((s) => s.locale) === 'ar'

  return (
    <span className="flex min-w-0 flex-col leading-tight">
      <span className="truncate text-xs font-semibold leading-none text-text-muted">{ar ? label.ar : label.en}</span>
      <span className="truncate text-sm font-extrabold leading-tight text-text-primary">{children}</span>
    </span>
  )
}

/** The column titles of the board, over the rows (large screens; on a phone each figure names itself). */
export function BoardHeader({ ar }: { ar: boolean }) {
  const h = (v: { ar: string; en: string }) => <span className="truncate text-xs font-bold text-text-muted">{ar ? v.ar : v.en}</span>

  return (
    <div className="hidden min-w-0 grid-cols-[minmax(0,1.5fr)_6.75rem_minmax(0,2.6fr)_minmax(0,1.3fr)_7.25rem] gap-x-3 px-2.5 leading-none lg:grid" data-testid="board-header">
      {h({ ar: 'العميل · المشروع', en: 'Client · project' })}{h(L.period)}{h({ ar: 'الأرقام', en: 'Figures' })}{h(L.platforms)}{h({ ar: 'الحالة', en: 'Status' })}
    </div>
  )
}

/**
 * One project, one dense row. The figures follow the mode — and only the minimum:
 *  - مباشر: الميزانية · الإنفاق · المتبقي, a ring and a bar;
 *  - بالأجل: الإنفاق حتى الآن · هذا الشهر (للفوترة), and a bar only when a ceiling is set;
 *  - مخطّطة: the same as its mode, plus one «فرق عن الخطة» chip when a platform is off its share.
 * Opens the drawer; never moves the page. The month-by-month table is in the drawer.
 */
export function BudgetBoardRow({ row, active, onOpen, ar }: { row: BudgetRow; active: boolean; onOpen: () => void; ar: boolean }) {
  const st = STATUS[row.status]
  const credit = row.payment_mode === 'credit'
  const used = row.used_ratio === null ? null : Math.min(1, row.used_ratio)
  const red = isRed(row) || (!credit && row.remaining !== null && row.remaining < row.alert_floor)
  const off = row.plan_alerts > 0 || (row.plan ?? []).some((p) => p.flag !== null)

  return (
    <button
      type="button"
      onClick={onOpen}
      aria-current={active}
      data-testid={`budget-row-${row.project_id}`}
      className={`grid w-full min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0 rounded-[10px] border px-2.5 py-0.5 text-start transition lg:grid-cols-[minmax(0,1.5fr)_6.75rem_minmax(0,2.6fr)_minmax(0,1.3fr)_7.25rem] ${active ? 'border-brand-600 bg-surface' : 'border-border bg-surface hover:border-border-strong'}`}
    >
      <span className="flex min-w-0 items-center gap-1.5">
        <span
          className={`h-2 w-2 shrink-0 rounded-full ${needsAttention(row) ? (isRed(row) ? 'bg-danger' : 'bg-warning') : 'bg-transparent'}`}
          data-testid={needsAttention(row) ? 'alert-dot' : undefined}
          aria-hidden
        />
        <span className="flex min-w-0 flex-col leading-tight">
          <span className="truncate text-sm font-bold text-text-primary">{row.client_name}</span>
          <span className="flex min-w-0 items-center gap-1.5">
            <span className="truncate text-xs text-text-muted">{row.project_name}</span>
            <ModeBadges row={row} ar={ar} />
          </span>
        </span>
      </span>

      <span className="justify-self-end lg:justify-self-start">
        <PeriodChip start={row.period_start} end={row.period_end} stated={row.period_start_stated} ar={ar} />
      </span>

      <span className="col-span-2 flex min-w-0 items-center gap-2 lg:col-span-1" data-testid="row-figures" data-mode={row.payment_mode}>
        {!credit && used !== null && <Ring ratio={used} tone={red ? 'danger' : st.tone} size={30} />}
        <span className="flex min-w-0 flex-1 flex-col gap-0">
          {credit ? (
            <span className="grid grid-cols-2 gap-2">
              <Fig label={L.spentToDate}><Money n={row.to_date} currency="SAR" /></Fig>
              <Fig label={L.thisMonth}><Money n={row.this_month} currency="SAR" /></Fig>
            </span>
          ) : (
            <span className="grid grid-cols-3 gap-2">
              <Fig label={L.approved}><Money n={row.approved} currency="SAR" /></Fig>
              <Fig label={L.spent}><Money n={row.spent} currency="SAR" /></Fig>
              <Fig label={L.remaining}><Money n={row.remaining} currency="SAR" className={red ? 'text-danger' : ''} /></Fig>
            </span>
          )}
          {used !== null && (
            <span className="flex items-center gap-1.5">
              <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
                <span className={`block h-full rounded-full ${TONE_BAR[red ? 'danger' : st.tone]}`} style={{ width: `${Math.round(used * 100)}%` }} />
              </span>
              <bdi dir="ltr" className="tnum text-xs font-extrabold text-text-primary" data-testid="used-percent">{Math.round(used * 100)}%</bdi>
              {credit && row.ceiling !== null && <span className="text-xs text-text-muted">{ar ? L.ceiling.ar : L.ceiling.en} <Money n={row.ceiling} currency="SAR" /></span>}
            </span>
          )}
        </span>
      </span>

      <span className="col-span-2 min-w-0 lg:col-span-1"><PlatformSplit items={row.by_platform} ar={ar} /></span>

      <span className="col-span-2 flex flex-wrap items-center justify-self-start gap-1 lg:col-span-1 lg:justify-self-end">
        <Badge tone={st.tone} className="whitespace-nowrap">{ar ? st.ar : st.en}</Badge>
        {off && <Badge tone="warning" className="whitespace-nowrap" data-testid="plan-diff-chip">{ar ? PLAN_DIFF.ar : PLAN_DIFF.en}</Badge>}
      </span>
    </button>
  )
}

/** The slim totals line: budget, spend, remaining, alerts — one row that never leaves the screen. */
export function TotalsStrip({ totals, ar }: { totals: { approved: number; spent: number; remaining: number; needs_attention: number; credit_count?: number; credit_spent?: number } | null; ar: boolean }) {
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
      {(totals?.credit_count ?? 0) > 0 && cell({ ar: 'بالأجل · الإنفاق', en: 'Credit · spent' }, <Sar n={totals?.credit_spent} ar={ar} />, 'tile-credit', 'bg-warning')}
      {cell(L.alerts, totals ? String(totals.needs_attention) : '—', 'tile-alerts', (totals?.needs_attention ?? 0) > 0 ? 'bg-warning' : 'bg-border-strong')}
    </div>
  )
}
