import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CircleDollarSign, Hourglass, Users, Wallet } from 'lucide-react'
import { fetchClientBudgets, type ClientBudgetRow } from '@/features/agency/api'
import { listAlertEvents } from '@/features/alerts/api'
import { StatCard } from '@/components/ui/StatCard'
import { Skeleton } from '@/components/ui/States'
import { KpiSparkline } from '@/features/analytics/charts'
import { money } from '@/features/analytics/format'
import { days as countedDays } from '@/lib/counted'
import { budgetRatio, coverDays, isNearEnd, orderClients, totalsOf } from './overviewMath'

/**
 * «الرئيسية › كل العملاء» — four figures, then one card per client (NAV-HOME-PLANS-001, master task 13).
 *
 * Compact on purpose (Owner 2026-11-13): the strip and the cards share one quiet gap, a card is a single short row, and
 * the grid wraps instead of scrolling, so the whole estate reads on one screen and never needs a sideways swipe.
 *
 * The figures are summed from the very rows the cards draw (`totalsOf`), so the cards always add up to the strip.
 * Reads the same cached query as the rest of the agency dashboard — no second request, no second opinion.
 */
const COPY = {
  ar: {
    title: 'كل العملاء', spend: 'الإنفاق', left: 'المتبقي', running: 'عملاء شغالين', near: 'قرّبت تخلص',
    cover: (n: number) => (n === 0 ? 'خلصت' : `تكفي ${countedDays(n, 'ar')}`), noCover: 'بدون بيانات', of: 'من', mixed: 'عملات مختلفة', failed: 'ما قدرنا نحمّل العملاء.',
    open: (name: string) => `افتح ${name}`,
  },
  en: {
    title: 'All clients', spend: 'Spend', left: 'Remaining', running: 'Active clients', near: 'Running out',
    cover: (n: number) => (n === 0 ? 'Used up' : `${countedDays(n, 'en')} left`), noCover: 'No data', of: 'of', mixed: 'Mixed currencies', failed: 'Could not load the clients.',
    open: (name: string) => `Open ${name}`,
  },
}

type Tone = 'brand' | 'warning' | 'danger'

function toneOf(ratio: number | null): Tone {
  if (ratio === null) return 'brand'
  if (ratio >= 1) return 'danger'

  return ratio >= 0.9 ? 'warning' : 'brand'
}

const STROKE: Record<Tone, string> = { brand: 'var(--brand-600)', warning: 'var(--warning)', danger: 'var(--danger)' }

/** The budget ring: how much of the client's budget is spent. Small enough to sit beside a name. */
function BudgetRing({ ratio, size = 46 }: { ratio: number | null; size?: number }) {
  const stroke = 5
  const r = (size - stroke) / 2
  const c = 2 * Math.PI * r
  const pct = Math.max(0, Math.min(1, ratio ?? 0))
  const tone = toneOf(ratio)

  return (
    <span className="relative inline-flex shrink-0 items-center justify-center" style={{ width: size, height: size }} aria-hidden>
      <svg width={size} height={size} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--surface-secondary)" strokeWidth={stroke} />
        {ratio !== null && (
          <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={STROKE[tone]} strokeWidth={stroke} strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c * (1 - pct)} />
        )}
      </svg>
      <span className="tnum absolute text-xs font-bold text-text-primary">{ratio === null ? '—' : `${Math.round(ratio * 100)}%`}</span>
    </span>
  )
}

function ClientCard({ row, ar, onOpen, alerts }: { row: ClientBudgetRow; ar: boolean; onOpen: (row: ClientBudgetRow) => void; alerts: number }) {
  const t = ar ? COPY.ar : COPY.en
  const ratio = budgetRatio(row)
  const days = coverDays(row)
  const near = isNearEnd(row)
  const line = row.daily_spend_7d ?? []

  return (
    <button
      type="button"
      data-testid={`overview-client-${row.client_id}`}
      aria-label={t.open(row.client_name)}
      onClick={() => onOpen(row)}
      className="flex min-w-0 items-center gap-2.5 rounded-xl border border-border bg-surface p-2.5 text-start transition-colors hover:border-brand-400"
    >
      <BudgetRing ratio={ratio} />
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-1.5">
          <span className="truncate text-sm font-bold text-text-primary">{row.client_name}</span>
          {alerts > 0 && <span className="tnum shrink-0 rounded-full bg-[var(--warning-background)] px-1.5 text-xs font-bold text-warning" title={ar ? 'تنبيهات' : 'Alerts'}>{alerts}</span>}
        </span>
        <span className={`block truncate text-xs font-semibold ${near ? 'text-warning' : 'text-text-secondary'}`}>
          {days === null ? t.noCover : t.cover(days)}
        </span>
        <span className="tnum block truncate text-xs text-text-muted">
          {money(row.spent, row.currency)} {t.of} {money(row.budget, row.currency)}
        </span>
      </span>
      {line.length > 1 && <span className="hidden w-16 shrink-0 sm:block" aria-hidden><KpiSparkline points={line} height={30} color={STROKE[toneOf(ratio)]} /></span>}
    </button>
  )
}

export function ClientCards({ ar, onOpenClient }: { ar: boolean; onOpenClient: (row: ClientBudgetRow) => void }) {
  const t = ar ? COPY.ar : COPY.en
  const budgets = useQuery({ queryKey: ['agency', 'client-budgets'], queryFn: fetchClientBudgets, retry: false })
  const alerts = useQuery({ queryKey: ['agency', 'alerts', 'open'], queryFn: () => listAlertEvents('open'), retry: false })

  const rows = useMemo(() => budgets.data ?? [], [budgets.data])
  const alertsByClient = useMemo(() => {
    const clientOfProject = new Map<string, string>()
    for (const r of rows) for (const p of r.projects_breakdown) clientOfProject.set(p.project_id, r.client_id)
    const out = new Map<string, number>()
    for (const e of alerts.data?.events ?? []) {
      if (e.status !== 'open' || !e.project_id) continue
      const client = clientOfProject.get(e.project_id)
      if (client) out.set(client, (out.get(client) ?? 0) + 1)
    }

    return out
  }, [rows, alerts.data])

  const totals = useMemo(() => totalsOf(rows), [rows])
  const ordered = useMemo(() => orderClients(rows, (id) => alertsByClient.get(id) ?? 0), [rows, alertsByClient])

  if (budgets.isLoading) return <Skeleton className="h-40" />
  if (budgets.isError) return <p role="alert" className="rounded-xl border border-border bg-surface p-3 text-sm text-text-muted">{t.failed}</p>
  if (rows.length === 0) return null

  const fig = (n: number | null) => (totals.mixedCurrency ? t.mixed : money(n, totals.currency))
  const icon = (I: typeof Wallet) => (
    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-surface-secondary text-text-secondary"><I size={15} aria-hidden /></span>
  )

  return (
    <section data-testid="overview-all-clients" aria-label={t.title} className="grid gap-2">
      <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
        <StatCard testid="overview-spend" label={t.spend} value={fig(totals.spent)} trailing={icon(CircleDollarSign)} />
        <StatCard testid="overview-remaining" label={t.left} value={fig(totals.remaining)} trailing={icon(Wallet)} />
        <StatCard testid="overview-running" label={t.running} value={totals.running} trailing={icon(Users)} />
        <StatCard testid="overview-near" label={t.near} value={totals.nearEnd} tone={totals.nearEnd > 0 ? 'warning' : 'neutral'} trailing={icon(Hourglass)} />
      </div>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
        {ordered.map((row) => (
          <ClientCard key={row.client_id} row={row} ar={ar} onOpen={onOpenClient} alerts={alertsByClient.get(row.client_id) ?? 0} />
        ))}
      </div>
    </section>
  )
}
