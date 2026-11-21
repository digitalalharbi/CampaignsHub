import { Link } from 'react-router-dom'
import { LiveChip, LiveDot, PlatformMarks, rowPlatforms } from '@/features/funding/BudgetBoard'
import { Num } from '@/components/ui/Num'
import type { DashboardUnit, DeliveryRow } from './api'
import { Panel, useFmt } from './parts'

/**
 * DELIVERY-STATE-001 + DELIVERY-ALL-ROLES-001 — Owner 2026-11-21: who is running and who stopped, merged with the budget.
 * One compact row per client: name and its «شغّالة / واقفة» chip · each platform's mark (green ring = running, grey =
 * stopped) · spent of the budget as one bar · what is left. Every role sees the state; the money only with the money key
 * (the server sends it null otherwise). A stopped unit with budget left comes first. No row → no card.
 */
export function DeliveryPanel({ rows: given, units, portal, className = '' }: { rows?: DeliveryRow[]; units?: DashboardUnit[]; portal: 'agency' | 'app'; money?: boolean; className?: string }) {
  const f = useFmt()
  const ar = f.ar
  const read: DeliveryRow[] = given ?? (units ?? []).filter((u) => u.delivering !== undefined).map((u) => ({
    id: u.id, kind: u.kind, name: u.name, delivering: !!u.delivering, delivering_platforms: u.delivering_platforms ?? {},
    budget: u.budget, spent: u.spent, remaining: u.remaining, ratio: u.ratio, currency: u.currency,
  }))
  if (read.length === 0) return null
  const idleWithMoney = (u: DeliveryRow) => !u.delivering && (u.remaining ?? 0) > 0
  const rank = (u: DeliveryRow) => (idleWithMoney(u) ? 0 : u.delivering ? 1 : 2)
  const rows = [...read].sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name)).slice(0, 10)
  const on = read.filter((u) => u.delivering).length
  const off = read.length - on
  const money = read.some((u) => u.budget !== null || u.remaining !== null)
  const path = (u: DeliveryRow) => (portal === 'agency' && u.kind === 'client' ? `/agency/clients/${u.id}` : `/${portal}/budgets`)

  return (
    <Panel title={ar ? 'الحملات: شغّالة ولا واقفة' : 'Running or stopped'} to={`/${portal}/budgets`} testId="dash-panel-delivery" className={className}>
      <div className="flex flex-wrap items-center gap-1.5 pb-1" data-testid="dash-delivery-counts">
        <span className="inline-flex items-center gap-1.5 rounded-[var(--radius-pill)] bg-success/10 px-2 py-0.5 text-xs font-bold text-success" data-testid="dash-delivery-on">
          <LiveDot on ar={ar} /> {ar ? 'شغّالة' : 'Running'} <Num>{f.count(on)}</Num>
        </span>
        <span className="inline-flex items-center gap-1.5 rounded-[var(--radius-pill)] bg-surface-secondary px-2 py-0.5 text-xs font-bold text-text-secondary" data-testid="dash-delivery-off">
          <LiveDot on={false} ar={ar} /> {ar ? 'واقفة' : 'Stopped'} <Num>{f.count(off)}</Num>
        </span>
      </div>
      <ul className="grid grid-cols-1 gap-x-4 gap-y-0.5 lg:grid-cols-2" data-testid="dash-delivery">
        {rows.map((u) => {
          const ratio = u.ratio === null ? null : Math.min(1, u.ratio)
          const tone = u.ratio === null ? '' : u.ratio >= 1 ? 'bg-danger' : u.ratio >= 0.85 ? 'bg-warning' : u.delivering ? 'bg-success' : 'bg-border-strong'
          return (
            <li key={u.id} className="min-w-0">
              <Link
                to={path(u)}
                data-testid={`dash-delivery-${u.id}`}
                data-on={u.delivering ? 'true' : 'false'}
                className={`grid min-w-0 items-center gap-2 rounded-lg px-1.5 py-1 text-xs hover:bg-surface-hover ${money ? 'grid-cols-[minmax(0,1fr)_auto_minmax(3.5rem,5rem)_minmax(4.5rem,auto)]' : 'grid-cols-[minmax(0,1fr)_auto]'}`}
              >
                <span className="flex min-w-0 items-center gap-1.5">
                  <LiveChip on={u.delivering} ar={ar} />
                  <span className={`truncate font-semibold ${u.delivering ? 'text-text-primary' : 'text-text-secondary'}`} title={u.name}>{u.name}</span>
                </span>
                <PlatformMarks platforms={rowPlatforms({ by_platform: [], delivering_platforms: u.delivering_platforms })} ar={ar} testId={`dash-delivery-platforms-${u.id}`} size={14} />
                {money && (
                  <span className="flex min-w-0 items-center gap-1" title={ratio === null ? undefined : `${ar ? 'صُرف' : 'Spent'} ${Math.round((u.ratio ?? 0) * 100)}%`}>
                    {ratio !== null ? (
                      <>
                        <span className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
                          <span className={`block h-full rounded-full ${tone}`} style={{ width: `${Math.round(ratio * 100)}%` }} />
                        </span>
                        <bdi dir="ltr" className="tnum shrink-0 font-bold text-text-secondary">{Math.round((u.ratio ?? 0) * 100)}%</bdi>
                      </>
                    ) : <span className="text-text-muted">—</span>}
                  </span>
                )}
                {money && (
                  <span
                    className={`justify-self-end whitespace-nowrap rounded-[var(--radius-pill)] px-1.5 py-0.5 font-bold ${idleWithMoney(u) ? 'bg-[var(--warning-background)] text-warning' : 'text-text-secondary'}`}
                    title={idleWithMoney(u) ? (ar ? 'واقفة ولها ميزانية متبقية' : 'Stopped with budget left') : (ar ? 'المتبقي' : 'Remaining')}
                    data-testid={idleWithMoney(u) ? `dash-delivery-left-${u.id}` : undefined}
                  >
                    {u.remaining === null ? '—' : <Num>{f.money(u.remaining, u.currency)}</Num>}
                  </span>
                )}
              </Link>
            </li>
          )
        })}
      </ul>
      {read.length > rows.length && <span className="text-xs text-text-muted">+<Num>{f.count(read.length - rows.length)}</Num></span>}
    </Panel>
  )
}
