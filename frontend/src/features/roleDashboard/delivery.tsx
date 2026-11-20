import { Link } from 'react-router-dom'
import { LiveDot, LivePlatforms } from '@/features/funding/BudgetBoard'
import { Num } from '@/components/ui/Num'
import type { DashboardUnit } from './api'
import { unitPath } from './ManagerView'
import { Panel, useFmt } from './parts'

/**
 * DELIVERY-STATE-001 — Owner 2026-11-20: who is running and who stopped, per client or project. Running = spend AND
 * impressions in the last 3 days. A stopped unit that still has budget left comes first, with what is left beside it.
 * No unit carries the reading → no card.
 */
export function DeliveryPanel({ units, portal, money = true, className = '' }: { units: DashboardUnit[]; portal: 'agency' | 'app'; money?: boolean; className?: string }) {
  const f = useFmt()
  const ar = f.ar
  const read = units.filter((u) => u.delivering !== undefined)
  if (read.length === 0) return null
  const idleWithMoney = (u: DashboardUnit) => u.delivering === false && (u.remaining ?? 0) > 0
  const rank = (u: DashboardUnit) => (idleWithMoney(u) ? 0 : u.delivering ? 1 : 2)
  const rows = [...read].sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name)).slice(0, 10)
  const on = read.filter((u) => u.delivering).length
  const off = read.length - on

  return (
    <Panel title={ar ? 'الحملات: شغّالة ولا واقفة' : 'Running or stopped'} to={`/${portal}/budgets`} testId="dash-panel-delivery" className={className}>
      <div className="flex flex-wrap items-center gap-1.5 pb-1.5" data-testid="dash-delivery-counts">
        <span className="inline-flex items-center gap-1.5 rounded-[var(--radius-pill)] bg-success/10 px-2 py-0.5 text-xs font-bold text-success" data-testid="dash-delivery-on">
          <LiveDot on ar={ar} /> {ar ? 'شغّالة' : 'Running'} <Num>{f.count(on)}</Num>
        </span>
        <span className="inline-flex items-center gap-1.5 rounded-[var(--radius-pill)] bg-surface-secondary px-2 py-0.5 text-xs font-bold text-text-secondary" data-testid="dash-delivery-off">
          <LiveDot on={false} ar={ar} /> {ar ? 'واقفة' : 'Stopped'} <Num>{f.count(off)}</Num>
        </span>
      </div>
      <ul className="flex flex-col gap-0.5" data-testid="dash-delivery">
        {rows.map((u) => (
          <li key={u.id}>
            <Link to={unitPath(portal, u)} data-testid={`dash-delivery-${u.id}`} data-on={u.delivering ? 'true' : 'false'} className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2 rounded-lg px-1.5 py-1 text-xs hover:bg-surface-hover">
              <LiveDot on={u.delivering} ar={ar} />
              <span className="min-w-0">
                <span className={`block truncate font-semibold ${u.delivering ? 'text-text-primary' : 'text-text-secondary'}`}>{u.name}</span>
                <LivePlatforms platforms={u.delivering_platforms} ar={ar} />
              </span>
              {money && idleWithMoney(u) ? (
                <span className="whitespace-nowrap rounded-[var(--radius-pill)] bg-[var(--warning-background)] px-2 py-0.5 font-bold text-warning" title={ar ? 'واقفة ولها ميزانية متبقية' : 'Stopped with budget left'} data-testid={`dash-delivery-left-${u.id}`}>
                  {ar ? 'متبقي' : 'Left'} <Num>{f.money(u.remaining, u.currency)}</Num>
                </span>
              ) : <span />}
            </Link>
          </li>
        ))}
      </ul>
      {read.length > rows.length && <span className="text-xs text-text-muted">+<Num>{f.count(read.length - rows.length)}</Num></span>}
    </Panel>
  )
}
