import { useEffect, useRef } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Layers } from 'lucide-react'
import { lazyPage } from '@/app/lazyPage'
import { fetchClientBudgets, type ClientBudgetRow } from '@/features/agency/api'
import { useAgencyClient } from '@/stores/agencyClient'
import { useAuth } from '@/stores/auth'
import { Skeleton } from '@/components/ui/States'
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
  const storedClient = useAgencyClient((s) => s.currentClientId)
  const setClient = useAgencyClient((s) => s.setCurrentClientId)
  const nav = useAuth((s) => s.user?.account?.nav)
  const setProject = useProject((s) => s.setCurrentProjectId)
  const clientId = portal === 'agency' ? params.get('client') : null

  // Shared cache with the cards — the breadcrumb reads the name, it does not ask again.
  const budgets = useQuery({ queryKey: ['agency', 'client-budgets'], queryFn: fetchClientBudgets, retry: false, enabled: clientId !== null })
  const clientName = budgets.data?.find((r) => r.client_id === clientId)?.client_name

  /*
   * The address names the client; the stores decide what the dashboard below reads. A link opened cold, a reload with
   * cleared storage or a bookmark would otherwise show the stored client's figures under this client's name — so the
   * stores are brought into line with the address before the dashboard renders, and an address naming a client this
   * reader cannot reach is dropped rather than shown with somebody else's numbers.
   */
  // Set by «كل العملاء» so the sync below cannot re-select the client during the render before the address updates.
  const leaving = useRef(false)
  useEffect(() => {
    if (clientId === null) leaving.current = false
    if (portal !== 'agency' || clientId === null || leaving.current) return
    if (budgets.isError) {
      // Without the budget permission the list cannot vouch for the id; the server still narrows every figure.
      if (storedClient !== clientId) setClient(clientId)

      return
    }
    if (!budgets.data) return

    const row = budgets.data.find((r) => r.client_id === clientId)
    if (!row) {
      setParams({}, { replace: true })

      return
    }
    if (storedClient !== row.client_id) {
      setClient(row.client_id)
      setProject(row.projects_breakdown.length === 1 ? row.projects_breakdown[0]!.project_id : null)
    }
  }, [portal, clientId, budgets.data, budgets.isError, storedClient, setClient, setProject, setParams])

  const settled = portal !== 'agency' || clientId === null || storedClient === clientId

  const open = (row: ClientBudgetRow) => {
    setClient(row.client_id)
    // One project: open it. Several: leave the choice to the project switcher rather than guess.
    setProject(row.projects_breakdown.length === 1 ? row.projects_breakdown[0]!.project_id : null)
    setParams({ client: row.client_id })
  }

  const back = () => {
    leaving.current = true
    setClient(null)
    setProject(null)
    setParams({})
  }

  const Chevron = ar ? ChevronLeft : ChevronRight

  return (
    <div className="grid min-w-0 grid-cols-1 gap-2" data-testid="overview-dashboard">
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
        {(portal === 'agency' || !nav || nav.includes('projects')) && (
        <Link to={`/${portal}/portfolio`} data-testid="overview-summary-link" className="inline-flex items-center gap-1 text-xs font-semibold text-text-secondary hover:text-text-primary">
          <Layers size={14} aria-hidden /> {t.summary}
        </Link>
        )}
      </div>

      {portal === 'agency' && clientId === null ? <AgencyDashboardPage onOpenClient={open} /> : settled ? <AnalyticsPage surface="dashboard" /> : <Skeleton className="h-64" />}
    </div>
  )
}
