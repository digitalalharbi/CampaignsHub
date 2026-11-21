import { Link } from 'react-router-dom'
import { LiveChip, LiveDot, PlatformMarks, rowPlatforms } from '@/features/funding/BudgetBoard'
import { Num } from '@/components/ui/Num'
import type { DashboardUnit, DeliveryRow } from './api'
import { Panel, useFmt } from './parts'

/**
 * DELIVERY-STATE-001 + DELIVERY-ALL-ROLES-001 — Owner 2026-11-21: who is running and who stopped, merged with the budget.
 * DELIVERY-TABLE-001 (Owner, same day: «مثل جدول الميزانيات») — a table: one header row (العميل · المشروع · الحالة ·
 * المنصات · الإنفاق · المتبقي) and one bordered row per client under it, in the budgets table's look. Each platform's
 * mark: green ring = running, grey = stopped. Every role sees the state; the money only with the money key
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
  // The same columns for the header and every row, so they line up as one table.
  const cols = money ? 'sm:grid-cols-[minmax(0,1.6fr)_5.5rem_minmax(0,1.2fr)_minmax(0,1.2fr)_minmax(5.5rem,auto)]' : 'sm:grid-cols-[minmax(0,1.6fr)_5.5rem_minmax(0,1.2fr)]'
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
      {/* DELIVERY-TABLE-001 — Owner 2026-11-21: «مثل جدول الميزانيات»: one header row, the clients under it, one per line. */}
      <div className="min-w-0" data-testid="dash-delivery">
        <div className={`hidden min-w-0 items-center gap-x-3 px-2.5 pb-1 leading-none sm:grid ${cols}`} data-testid="dash-delivery-header">
          {[
            [ar ? 'العميل · المشروع' : 'Client · project', ''],
            [ar ? 'الحالة' : 'Status', ''],
            [ar ? 'المنصات' : 'Platforms', ''],
            ...(money ? [[ar ? 'الإنفاق' : 'Spend', ''], [ar ? 'المتبقي' : 'Remaining', 'text-end']] : []),
          ].map(([h, c]) => <span key={h} className={`truncate text-xs font-bold text-text-muted ${c}`}>{h}</span>)}
        </div>
        <ul className="space-y-1">
          {rows.map((u) => {
            const ratio = u.ratio === null ? null : Math.min(1, u.ratio)
            const tone = u.ratio === null ? '' : u.ratio >= 1 ? 'bg-danger' : u.ratio >= 0.85 ? 'bg-warning' : u.delivering ? 'bg-success' : 'bg-border-strong'
            return (
              <li key={u.id} className="min-w-0">
                <Link
                  to={path(u)}
                  data-testid={`dash-delivery-${u.id}`}
                  data-on={u.delivering ? 'true' : 'false'}
                  className={`grid min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-[10px] border border-border bg-surface px-2.5 py-1.5 text-xs transition hover:border-border-strong ${cols}`}
                >
                  <span className="flex min-w-0 flex-col leading-tight">
                    <span className={`truncate text-sm font-bold ${u.delivering ? 'text-text-primary' : 'text-text-secondary'}`} title={u.name}>{u.name}</span>
                    <span className="text-xs text-text-muted">{u.kind === 'client' ? (ar ? 'عميل' : 'Client') : (ar ? 'مشروع' : 'Project')}</span>
                  </span>
                  <span className="justify-self-end sm:justify-self-start"><LiveChip on={u.delivering} ar={ar} /></span>
                  <PlatformMarks platforms={rowPlatforms({ by_platform: [], delivering_platforms: u.delivering_platforms })} ar={ar} testId={`dash-delivery-platforms-${u.id}`} size={16} />
                  {money && (
                    <span className="flex min-w-0 items-center gap-1.5" title={ratio === null ? undefined : `${ar ? 'صُرف' : 'Spent'} ${Math.round((u.ratio ?? 0) * 100)}%`}>
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
                      className={`justify-self-end whitespace-nowrap rounded-[var(--radius-pill)] px-1.5 py-0.5 text-sm font-bold ${idleWithMoney(u) ? 'bg-[var(--warning-background)] text-warning' : 'text-text-primary'}`}
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
      </div>
      {read.length > rows.length && <span className="text-xs text-text-muted">+<Num>{f.count(read.length - rows.length)}</Num></span>}
    </Panel>
  )
}
