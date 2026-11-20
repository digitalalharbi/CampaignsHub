import { useMemo, useState } from 'react'
import { Skeleton } from '@/components/ui/States'
import { PageIntro } from '@/components/ui/PageIntro'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { FinancialDisclaimer } from '@/features/funding/FinancialDisclaimer'
import { useUi } from '@/stores/ui'
import { useAgencyClient } from '@/stores/agencyClient'
import { useProject } from '@/stores/project'
import { useRoleDashboard, type DashboardView, type RoleDashboard } from './api'
import { DateRangePicker } from '@/components/ui/DateRangePicker'
import { usePeriod } from '@/lib/period/usePeriod'
import { FinanceView } from './FinanceView'
import { ManagerView } from './ManagerView'
import { MarketerView } from './MarketerView'
import { PmView } from './PmView'
import { OpenAnalytics, useFitViewport } from './parts'
import { AnalyticsPopup } from '@/features/analyticsShared/AnalyticsPopup'
import type { AnalyticsSectionId } from '@/features/analyticsShared/homeSections'
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
  const period = usePeriod({ opening: 'this_month', writeUrl: false })
  const range = useMemo(() => ({ from: period.range.from, to: period.range.to }), [period.range.from, period.range.to])
  // ANALYTICS-POPUP-001 — a card that has an analytics counterpart opens that section here, over Home.
  const [popup, setPopup] = useState<AnalyticsSectionId | null>(null)
  // DASH-CLIENT-FILTER-001 — the client chosen in the watch board (one shared choice) narrows every figure above it too.
  const client = useAgencyClient((s) => s.currentClientId)
  // HOME-SCOPE-ISOLATION-001 — the project chosen at the top narrows Home too (the server ignores one outside the client).
  const project = useProject((s) => s.currentProjectId)
  // The project narrows only inside a chosen client: under «كل العملاء» the switcher still auto-picks one, and Home must not shrink to it.
  const scopedProject = portal === 'agency' && client ? project : null
  const q = useRoleDashboard(chosen, range, portal === 'agency' ? client : null, scopedProject)
  const { ref } = useFitViewport()
  const d = q.data

  return (
    <OpenAnalytics.Provider value={setPopup}>
    <div ref={ref} data-testid="role-dashboard" data-view={d?.view} className="grid min-w-0 grid-cols-1 content-start gap-2">
      <PageIntro
        testid="role-dashboard-intro"
        title={ar ? HOME.ar : HOME.en}
        actions={<div className="flex flex-wrap items-center gap-2">
          {/*
            DASH-DATE-RANGE-001 — Owner 2026-11-20: the shared date picker, as on every other page — presets (this month, last 7
            days, since launch…) with the from–to shown, or any range. Home opens on «هذا الشهر».
          */}
          <DateRangePicker opening="this_month" testId="role-dashboard-range" />
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
      {/*
        * FINANCE-HOME-SCOPE-001 — Owner 2026-11-19: the finance page shows finance only. The follow-up board is the
        * campaigns' own performance; it belongs to the manager, project-manager and marketer pages, never to «المالية» —
        * whoever opens it, including the workspace manager switching to that view.
        */}
      {d?.can_watch === true && d.view !== 'finance' && <WatchBoard portal={portal} range={range} />}
      {d?.can_money && <FinancialDisclaimer />}
      <AnalyticsPopup section={popup} days={period.days} onClose={() => setPopup(null)} />
    </div>
    </OpenAnalytics.Provider>
  )
}
