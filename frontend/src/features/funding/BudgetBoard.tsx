import { FreshnessChip } from './FreshnessChip'
import { ProviderMark } from '@/components/ProviderMark'
import { providerLabel } from '@/features/campaigns/labels'
import { CHART_SERIES } from '@/features/analytics/charts'
import { Badge } from '@/components/ui/Badge'
import { useUi } from '@/stores/ui'
import type { BudgetRow } from './fundingApi'
import { L, MODE, PLAN_DIFF, STATUS, TONE_BAR } from './labels'
import { Money, moneyNumber } from './money'
import { withMark } from '@/lib/money/mark'
import { days as countedDays } from '@/lib/counted'

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
  const usd = i.spent_usd === null || i.spent_usd === undefined ? null : withMark(moneyNumber(i.spent_usd), 'USD')
  const raw = (i.unconverted ?? []).map((u) => withMark(moneyNumber(u.amount), u.currency))
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

/**
 * BUDGET-LIVE-001 — Owner 2026-11-20: is anything still running under this budget? Green = spend and impressions in the
 * last 3 days, grey = stopped — the colours the ad platforms use. No sentence; the meaning rides in the tooltip.
 */
export function LiveDot({ on, ar, testId }: { on: boolean | undefined; ar: boolean; testId?: string }) {
  if (on === undefined) return null
  const label = on ? (ar ? 'شغّالة — إنفاق وظهور آخر 3 أيام' : 'Running — spend and impressions in the last 3 days') : (ar ? 'متوقفة — لا إنفاق أو ظهور آخر 3 أيام' : 'Stopped — no spend or impressions in the last 3 days')
  return (
    <span className="relative inline-flex h-2.5 w-2.5 shrink-0" title={label} data-testid={testId} data-on={on ? 'true' : 'false'}>
      {on && <span aria-hidden className="absolute inline-flex h-full w-full animate-ping rounded-full bg-success opacity-50" />}
      <span aria-hidden className={`relative inline-flex h-2.5 w-2.5 rounded-full ${on ? 'bg-success' : 'bg-text-muted/60'}`} />
      <span className="sr-only">{label}</span>
    </span>
  )
}

/** BUDGET-LIVE-001 — each platform's mark with its own running/stopped dot. */
export function LivePlatforms({ platforms, ar }: { platforms: Record<string, boolean> | undefined; ar: boolean }) {
  const entries = Object.entries(platforms ?? {})
  if (entries.length === 0) return null
  return (
    <span className="mt-1 flex flex-wrap items-center gap-1.5" data-testid="live-platforms">
      {entries.sort((a, b) => Number(b[1]) - Number(a[1])).map(([provider, on]) => (
        <span key={provider} className={`relative inline-flex ${on ? '' : 'opacity-50 grayscale'}`} title={`${providerLabel(provider, ar ? 'ar' : 'en')} · ${on ? (ar ? 'شغّالة' : 'Running') : (ar ? 'متوقفة' : 'Stopped')}`} data-testid={`live-platform-${provider}`} data-on={on ? 'true' : 'false'}>
          <ProviderMark provider={provider} size={16} />
          <span aria-hidden className={`absolute -bottom-0.5 -end-0.5 h-1.5 w-1.5 rounded-full ring-1 ring-[var(--surface)] ${on ? 'bg-success' : 'bg-text-muted'}`} />
        </span>
      ))}
    </span>
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
    <div className="hidden min-w-0 grid-cols-[minmax(0,1.5fr)_10.25rem_minmax(0,2.6fr)_minmax(0,1.3fr)_7.25rem] gap-x-3 px-2.5 leading-none lg:grid" data-testid="board-header">
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
  // BUDGET-ROW-001 — under the alert floor is red only once money has been spent: a small budget that is untouched is not «قرّبت تخلص».
  const red = isRed(row) || (!credit && row.spent > 0 && row.remaining !== null && row.remaining < row.alert_floor)
  const off = row.plan_alerts > 0 || (row.plan ?? []).some((p) => p.flag !== null)

  return (
    <button
      type="button"
      onClick={onOpen}
      aria-current={active}
      data-testid={`budget-row-${row.project_id}`}
      className={`grid w-full min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0 rounded-[10px] border px-2.5 py-0.5 text-start transition lg:grid-cols-[minmax(0,1.5fr)_10.25rem_minmax(0,2.6fr)_minmax(0,1.3fr)_7.25rem] ${active ? 'border-brand-600 bg-surface' : 'border-border bg-surface hover:border-border-strong'}`}
    >
      <span className="flex min-w-0 items-center gap-1.5">
        <span
          className={`h-2 w-2 shrink-0 rounded-full ${needsAttention(row) ? (isRed(row) ? 'bg-danger' : 'bg-warning') : 'bg-transparent'}`}
          data-testid={needsAttention(row) ? 'alert-dot' : undefined}
          aria-hidden
        />
        <span className="flex min-w-0 flex-col leading-tight">
          <span className="flex min-w-0 items-center gap-1.5">
            <LiveDot on={row.delivering} ar={ar} testId={`budget-live-${row.project_id}`} />
            <span className="truncate text-sm font-bold text-text-primary">{row.client_name}</span>
          </span>
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
        {!credit && used !== null && <span className="hidden shrink-0 xl:inline-flex"><Ring ratio={used} tone={red ? 'danger' : st.tone} size={30} /></span>}
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
            // BUDGET-ROW-001 — the whole track is the budget and the filled part is what was spent, named «صُرف» so the
            // percentage is never read as what is left.
            <span className="mt-0.5 flex min-w-0 items-center gap-1.5" title={`${ar ? 'صُرف' : 'Spent'} ${Math.round(used * 100)}% ${ar ? 'من الميزانية' : 'of the budget'}`}>
              <span className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
                <span className={`block h-full rounded-full ${TONE_BAR[red ? 'danger' : st.tone]}`} style={{ width: `${Math.round(used * 100)}%` }} />
              </span>
              <span className="shrink-0 whitespace-nowrap text-xs font-semibold leading-none text-text-muted">{ar ? 'صُرف' : 'Spent'}</span>
              <bdi dir="ltr" className="tnum shrink-0 text-xs font-extrabold leading-none text-text-primary" data-testid="used-percent">{Math.round(used * 100)}%</bdi>
              {credit && row.ceiling !== null && <span className="text-xs text-text-muted">{ar ? L.ceiling.ar : L.ceiling.en} <Money n={row.ceiling} currency="SAR" /></span>}
            </span>
          )}
        </span>
      </span>

      <span className="col-span-2 min-w-0 lg:col-span-1"><PlatformSplit items={row.by_platform} ar={ar} /><LivePlatforms platforms={row.delivering_platforms} ar={ar} /></span>

      <span className="col-span-2 flex flex-wrap items-center justify-self-start gap-1 lg:col-span-1 lg:justify-self-end">
        <span title={ar ? STATUS_WHY[row.status].ar : STATUS_WHY[row.status].en} data-testid="row-status"><Badge tone={st.tone} className="whitespace-nowrap">{ar ? st.ar : st.en}</Badge></span>
        <PaceNote row={row} ar={ar} />
        {/* PROJECT-LIFECYCLE-001 §7 — what the spend excludes (no rate) and the old rate used, named; the drawer shows the full statement. */}
        {row.fx && row.fx.excluded.length > 0 && (
          <span className="max-w-[16rem] truncate text-[11px] text-warning" title={(ar ? row.fx.statement : (row.fx.statement_en ?? row.fx.statement)) ?? undefined} data-testid="row-fx-excluded">
            {row.fx.excluded.map((e) => `${e.currency ?? '?'} · ${e.account_name}`).join('، ')}
          </span>
        )}
        {off && <Badge tone="warning" className="whitespace-nowrap" data-testid="plan-diff-chip">{ar ? PLAN_DIFF.ar : PLAN_DIFF.en}</Badge>}
        <FreshnessChip freshness={row.freshness} testid="row-freshness" />
      </span>
    </button>
  )
}

/**
 * BUDGET-LOGIC-001 — Owner 2026-11-19: the board says WHY a budget is in its class, not only the class. Each status reads its
 * rule (the same rule the server applies in `FundingNotifier`), shown on the badge.
 */
export const STATUS_WHY: Record<BudgetRow['status'], Lang> = {
  ok: { ar: 'المتبقي فوق حد التنبيه ويكفي أكثر من 7 أيام بسرعة الصرف الحالية', en: 'Remaining is above the alert floor and lasts over 7 days at the current pace' },
  low: { ar: 'المتبقي تحت حد التنبيه أو يخلص خلال أقل من 7 أيام بسرعة الصرف الحالية', en: 'Remaining is under the alert floor or runs out within 7 days at the current pace' },
  over: { ar: 'الإنفاق تجاوز الميزانية المعتمدة', en: 'Spend is above the approved budget' },
  incomplete: { ar: 'جزء من الإنفاق بعملة بلا سعر صرف، فالمتبقي غير مكتمل', en: 'Part of the spend has no exchange rate, so the remaining is incomplete' },
  no_budget: { ar: 'المشروع ينفق ولا توجد له ميزانية معتمدة', en: 'The project spends with no approved budget' },
  direct: { ar: 'العميل يدفع للمنصة مباشرة؛ الإنفاق للمتابعة فقط', en: 'The client pays the platform directly; spend is tracked only' },
  unset: { ar: 'طريقة الدفع لم تُحدد بعد', en: 'The payment method is not set yet' },
  void: { ar: 'ميزانية محذوفة', en: 'A deleted budget' },
  credit: { ar: 'بالأجل: يُفوتر الإنفاق بعده، ولم يقترب من السقف', en: 'On credit: spend is invoiced afterwards and is not near the ceiling' },
  credit_near: { ar: 'بالأجل: الإنفاق قريب من السقف', en: 'On credit: spend is near the ceiling' },
  credit_over: { ar: 'بالأجل: الإنفاق تجاوز السقف', en: 'On credit: spend is above the ceiling' },
}

/** The order the classes are read in: what needs action first. */
export const STATUS_ORDER: BudgetRow['status'][] = ['over', 'credit_over', 'low', 'credit_near', 'incomplete', 'no_budget', 'unset', 'ok', 'credit', 'direct', 'void']

/**
 * BUDGET-LOGIC-001 — the pace behind the class, under the badge: the day the balance runs out at the last 3 days' rate, and
 * that rate. Warning-coloured when it runs out before the budget's own period ends. Nothing is shown without a real pace.
 */
export function PaceNote({ row, ar }: { row: BudgetRow; ar: boolean }) {
  if (row.payment_mode === 'credit' || row.status === 'void' || row.remaining === null || row.remaining <= 0) return null
  const pace = row.pace_3d ?? row.pace_per_day
  if (pace === null || pace === undefined || pace <= 0) return null
  const early = !!row.run_out_date && !!row.period_end && row.run_out_date < row.period_end
  const when = row.run_out_date
    ? <>{ar ? 'يخلص' : 'Runs out'} <bdi dir="ltr" className="tnum">{row.run_out_date.slice(5).replace('-', '/')}</bdi></>
    : row.days_left !== null ? <>{ar ? `يكفي ${countedDays(row.days_left, 'ar')}` : `Lasts ${countedDays(row.days_left, 'en')}`}</> : null

  return (
    <span
      className={`whitespace-nowrap text-xs font-semibold ${early ? 'text-warning' : 'text-text-muted'}`}
      data-testid="row-pace"
      data-early={early ? 'true' : undefined}
      title={ar ? 'بمعدل صرف آخر 3 أيام مكتملة' : 'At the last 3 full days\' pace'}
    >
      {when}{when && ' · '}<Money n={pace} currency="SAR" />{ar ? '/يوم' : '/day'}
    </span>
  )
}

/** BUDGET-LOGIC-001 — the classes on the board with their counts; pressing one shows only its budgets. */
export function ClassStrip({ rows, value, onChange, ar }: { rows: BudgetRow[]; value: BudgetRow['status'] | null; onChange: (v: BudgetRow['status'] | null) => void; ar: boolean }) {
  const counts = new Map<BudgetRow['status'], number>()
  for (const r of rows) counts.set(r.status, (counts.get(r.status) ?? 0) + 1)
  const present = STATUS_ORDER.filter((s) => counts.has(s))
  if (present.length < 2) return null
  const chip = (key: string, label: string, n: number, active: boolean, onClick: () => void, title?: string, tone?: string) => (
    <button
      key={key}
      type="button"
      onClick={onClick}
      aria-pressed={active}
      title={title}
      data-testid={`budget-class-${key}`}
      className={`inline-flex items-center gap-1 whitespace-nowrap rounded-[var(--radius-pill)] border px-2.5 py-0.5 text-xs font-bold transition ${active ? 'border-brand-600 bg-brand-600/10 text-text-primary' : 'border-border text-text-secondary hover:border-border-strong'}`}
    >
      {tone && <span className={`h-1.5 w-1.5 rounded-full ${tone}`} aria-hidden />}
      {label} <bdi dir="ltr" className="tnum">{n}</bdi>
    </button>
  )

  return (
    <div className="flex min-w-0 flex-wrap items-center gap-1" role="group" aria-label={ar ? 'تصنيف الميزانيات' : 'Budget classes'} data-testid="budget-classes">
      {chip('all', ar ? 'الكل' : 'All', rows.length, value === null, () => onChange(null))}
      {present.map((s) => chip(s, ar ? STATUS[s].ar : STATUS[s].en, counts.get(s) ?? 0, value === s, () => onChange(value === s ? null : s), ar ? STATUS_WHY[s].ar : STATUS_WHY[s].en, TONE_BAR[STATUS[s].tone]))}
    </div>
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
