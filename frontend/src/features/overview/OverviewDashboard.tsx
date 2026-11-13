import { Link, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Layers } from 'lucide-react'
import { lazyPage } from '@/app/lazyPage'
import { fetchClientBudgets, type ClientBudgetRow } from '@/features/agency/api'
import { useAgencyClient } from '@/stores/agencyClient'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

const AnalyticsPage = lazyPage(() => import('@/features/analytics/AnalyticsPage'), 'AnalyticsPage')
const AgencyDashboardPage = lazyPage(() => import('@/features/agency/AgencyDashboardPage'), 'AgencyDashboardPage')

/**
 * «الرئيسية» — one home, two levels, for every portal (NAV-HOME-PLANS-001, master task 13).
 *
 *   كل العملاء  — the agency's default: four figures and a card per client (`ClientCards`, inside the agency page).
 *   عميل واحد  — the existing dashboard (`AnalyticsPage surface="dashboard"`), opened by pressing a client's card.
 *                The address carries the level (`?client=<id>`), so a reload, Back and a shared link land on the same one,
 *                and «كل العملاء › اسم العميل» above it returns.
 *
 * The advertiser (`/app`) has no clients, so it is only ever the second level — the same component, without the first.
 * Nothing is copied per portal: the level switch lives here, the figures live in the components it mounts.
 *
 * The client's own portal keeps its page: it reads a different API (a client's session, not a tenant's), and a
 * session that cannot see the other clients must not be handed a component whose first level is about them.
 */
const COPY = {
  ar: { all: 'كل العملاء', summary: 'ملخص المشاريع', trail: 'مسار الرئيسية' },
  en: { all: 'All clients', summary: 'Projects summary', trail: 'Home trail' },
}

export function OverviewDashboard({ portal }: { portal: 'agency' | 'app' }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const [params, setParams] = useSearchParams()
  const setClient = useAgencyClient((s) => s.setCurrentClientId)
  const setProject = useProject((s) => s.setCurrentProjectId)
  const clientId = portal === 'agency' ? params.get('client') : null

  // Shared cache with the cards — the breadcrumb reads the name, it does not ask again.
  const budgets = useQuery({ queryKey: ['agency', 'client-budgets'], queryFn: fetchClientBudgets, retry: false, enabled: clientId !== null })
  const clientName = budgets.data?.find((r) => r.client_id === clientId)?.client_name

  const open = (row: ClientBudgetRow) => {
    setClient(row.client_id)
    // One project: open it. Several: leave the choice to the project switcher rather than guess.
    setProject(row.projects_breakdown.length === 1 ? row.projects_breakdown[0]!.project_id : null)
    setParams({ client: row.client_id })
  }

  const back = () => {
    setClient(null)
    setProject(null)
    setParams({})
  }

  const Chevron = ar ? ChevronLeft : ChevronRight

  return (
    <div className="grid gap-2" data-testid="overview-dashboard">
      <div className="flex min-h-8 flex-wrap items-center justify-between gap-x-3 gap-y-1">
        {portal === 'agency' && clientId !== null ? (
          <nav aria-label={t.trail} data-testid="overview-trail" className="flex min-w-0 items-center gap-1 text-sm font-semibold">
            <button type="button" onClick={back} className="text-brand-600 hover:text-brand-700">{t.all}</button>
            <Chevron size={14} className="shrink-0 text-text-muted" aria-hidden />
            <span className="truncate text-text-primary" aria-current="page">{clientName ?? '…'}</span>
          </nav>
        ) : (
          <span />
        )}
        <Link to={`/${portal}/portfolio`} data-testid="overview-summary-link" className="inline-flex items-center gap-1 text-xs font-semibold text-text-secondary hover:text-text-primary">
          <Layers size={14} aria-hidden /> {t.summary}
        </Link>
      </div>

      {portal === 'agency' && clientId === null ? <AgencyDashboardPage onOpenClient={open} /> : <AnalyticsPage surface="dashboard" />}
    </div>
  )
}
