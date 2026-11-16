import { useState } from 'react'
import { days as countedDays } from '@/lib/counted'
import { Skeleton } from '@/components/ui/States'
import { PageIntro } from '@/components/ui/PageIntro'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { FinancialDisclaimer } from '@/features/funding/FinancialDisclaimer'
import { useUi } from '@/stores/ui'
import { DASHBOARD_WINDOWS, useRoleDashboard, type DashboardView, type DashboardWindow, type RoleDashboard } from './api'
import { FinanceView } from './FinanceView'
import { ManagerView } from './ManagerView'
import { MarketerView } from './MarketerView'
import { PmView } from './PmView'
import { useFitViewport } from './parts'
import { WatchBoard } from './WatchBoard'

/**
 * ROLE-DASHBOARD-001 — «الرئيسية»: one landing page per role, charts and numbers only.
 *
 *   مدير          spend vs budgets, on / off plan, daily spend, alerts, integrations
 *   مدير مشروع    his clients' spend ratio (a gauge each), campaign status, tasks, approvals
 *   المالية       planned / spent / remaining, who runs out when, invoices
 *   المسوّق       his campaigns, results and cost per result by objective, platform comparison, best / worst
 *
 * Which one shows is the SERVER's decision (`GET /me/dashboard`): the role, the sections and the clients the member may
 * reach. Only the workspace manager is offered the other three, to look at what each role will see.
 *
 * Layout, top to bottom: figures that open their pages → charts and comparisons → a strip of statuses → «لوحة المتابعة»
 * (WATCH-BOARD-001: the best and weakest campaigns and content of every platform, per client) for whoever may open the
 * campaigns. The role's own part fills at least one screen; the board follows below it and the page scrolls vertically.
 */
const TITLES: Record<DashboardView, { ar: string; en: string }> = {
  manager: { ar: 'مدير', en: 'Manager' },
  pm: { ar: 'مدير مشروع', en: 'Project manager' },
  finance: { ar: 'المالية', en: 'Finance' },
  marketer: { ar: 'المسوّق', en: 'Marketer' },
}

const HOME = { ar: 'الرئيسية', en: 'Home' }

function Body({ d, portal }: { d: RoleDashboard; portal: 'agency' | 'app' }) {
  switch (d.view) {
    case 'pm':
      return <PmView d={d} portal={portal} />
    case 'finance':
      return <FinanceView d={d} portal={portal} />
    case 'marketer':
      return <MarketerView d={d} portal={portal} />
    case 'manager':
    default:
      return <ManagerView d={d} portal={portal} />
  }
}

export function RoleDashboardPage({ portal }: { portal: 'agency' | 'app' }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [chosen, setChosen] = useState<DashboardView | null>(null)
  const [days, setDays] = useState<DashboardWindow>(30)
  const q = useRoleDashboard(chosen, days)
  const { ref } = useFitViewport()
  const d = q.data

  return (
    <div ref={ref} data-testid="role-dashboard" data-view={d?.view} className="grid min-w-0 grid-cols-1 content-start gap-2">
      <PageIntro
        testid="role-dashboard-intro"
        title={ar ? HOME.ar : HOME.en}
        actions={<div className="flex flex-wrap items-center gap-2">
          {/* DASH-PERIOD-001 — the window the performance figures are read over, one tap each. */}
          <div role="radiogroup" aria-label={ar ? 'الفترة' : 'Period'} data-testid="role-dashboard-window" className="inline-flex rounded-xl border border-border bg-surface-secondary p-0.5">
            {DASHBOARD_WINDOWS.map((n) => (
              <button key={n} type="button" role="radio" aria-checked={days === n} data-testid={`role-window-${n}`} onClick={() => setDays(n)}
                className={`min-h-8 rounded-lg px-2.5 text-xs font-semibold tnum ${days === n ? 'bg-surface text-text-primary shadow-[var(--shadow-small)]' : 'text-text-secondary hover:text-text-primary'}`}>
                {countedDays(n, ar ? 'ar' : 'en')}
              </button>
            ))}
          </div>
          {d && d.available_views.length > 1 ? (
          <div role="tablist" aria-label={ar ? 'عرض الدور' : 'Role view'} data-testid="role-dashboard-views" className="inline-flex rounded-xl border border-border bg-surface-secondary p-0.5">
            {d.available_views.map((v) => (
              <button key={v} type="button" role="tab" aria-selected={d.view === v} data-testid={`role-view-${v}`} onClick={() => setChosen(v)}
                className={`min-h-8 rounded-lg px-3 text-xs font-semibold ${d.view === v ? 'bg-surface text-text-primary shadow-[var(--shadow-small)]' : 'text-text-secondary hover:text-text-primary'}`}>
                {ar ? TITLES[v].ar : TITLES[v].en}
              </button>
            ))}
          </div>
          ) : null}
        </div>}
      />

      {q.isLoading && <><Skeleton className="h-20" /><Skeleton className="h-64" /></>}
      {q.isError && <QueryFailure error={q.error} ar={ar} onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّر تحميل الرئيسية' : 'Could not load Home'} />}
      {d && <Body d={d} portal={portal} />}
      {d?.can_watch === true && <WatchBoard portal={portal} days={days} />}
      {d?.can_money && <FinancialDisclaimer />}
    </div>
  )
}
