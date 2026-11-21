import { useEffect, useMemo, useState } from 'react'
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { api, getData } from '@/lib/api/client'
import { ReportAdsSection, type AdGroup, type ReportAd } from '@/features/reports/ReportAdsSection'
import { useOverviewData } from '@/features/analytics/OverviewCompositions'
import { ClientOverview } from '@/features/analytics/ClientOverview'
import { DateRangePicker } from '@/components/ui/DateRangePicker'
import { usePeriod } from '@/lib/period/usePeriod'
import { useUi } from '@/stores/ui'
import { fetchPortalOverview } from './portalOverviewApi'

/** The agency dashboard's metrics path, and the client's own route that answers it for this client. */
const AGENCY_METRICS = /^\/projects\/([^/?#]+)\/metrics\//
export const toClientMetrics = (url: string): string => url.replace(AGENCY_METRICS, '/client/projects/$1/metrics/')

/**
 * While the dashboard is on screen, every agency metrics request goes to the client's route instead. Installed once,
 * before any query below starts, and removed when the page leaves — the agency pages are never mounted beside it.
 */
let installed: number | null = null
let users = 0
function routeToClient(): () => void {
  users += 1
  if (installed === null) {
    installed = api.interceptors.request.use((config) => {
      if (typeof config.url === 'string') config.url = toClientMetrics(config.url)

      return config
    })
  }

  let done = false

  return () => {
    if (done) return
    done = true
    users -= 1
    if (users === 0 && installed !== null) {
      api.interceptors.request.eject(installed)
      installed = null
    }
  }
}

/**
 * CLIENT-DASHBOARD-PARITY-001 — the client's live dashboard, drawn by the agency dashboard itself.
 *
 * The figures, cards, curve and campaign comparison are the agency Overview's own (`useOverviewData` + `ClientOverview`),
 * read through `/client/projects/{id}/metrics`, which answers only this client's projects and removes what the agency
 * keeps from the space (money unless switched on, names unless switched on). Its own query cache, so an agency session in
 * the same browser can never hand this page a figure fetched under the agency's wider reach, or the reverse.
 */
export function ClientLiveDashboard() {
  const [cache] = useState(() => new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: 60_000, refetchOnWindowFocus: false } } }))
  // Installed during the first render, before any query below starts; removed when the page leaves.
  const [first] = useState(() => routeToClient())
  useEffect(() => {
    const release = routeToClient()
    first()

    return release
  }, [first])

  return (
    <QueryClientProvider client={cache}>
      <LiveBoard />
    </QueryClientProvider>
  )
}

function LiveBoard() {
  const ar = useUi((s) => s.locale) === 'ar'
  const period = usePeriod({ opening: 'this_month', writeUrl: false })
  const range = useMemo(() => ({ from: period.range.from, to: period.range.to }), [period.range.from, period.range.to])
  const overview = useQuery({ queryKey: ['client', 'overview', 'projects'], queryFn: () => fetchPortalOverview(), staleTime: 5 * 60_000 })
  const projects = overview.data?.dashboard_projects ?? []
  const [chosen, setChosen] = useState<string | null>(null)
  const projectId = chosen !== null && projects.some((p) => p.id === chosen) ? chosen : (projects[0]?.id ?? null)

  if (overview.isLoading) {
    return <div className="mb-3 h-40 animate-pulse rounded-2xl bg-surface-secondary" />
  }
  // No dashboard for this space (switched off, or no live project): the page goes on without it.
  if (overview.isError || projectId === null) return null

  return (
    <section data-testid="client-live-dashboard" className="mb-4 space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        {projects.length > 1 && (
          <div role="tablist" aria-label={ar ? 'المشروع' : 'Project'} className="flex flex-wrap gap-1.5">
            {projects.map((p, i) => (
              <button
                key={p.id}
                type="button"
                role="tab"
                aria-selected={p.id === projectId}
                data-testid={`client-live-project-${p.id}`}
                onClick={() => setChosen(p.id)}
                className={`rounded-full border px-3 py-1.5 text-xs font-bold transition-colors ${p.id === projectId ? 'border-brand-600 bg-brand-600/10 text-brand-600' : 'border-border text-text-secondary hover:bg-surface-hover'}`}
              >
                {p.name ?? (ar ? `المشروع ${i + 1}` : `Project ${i + 1}`)}
              </button>
            ))}
          </div>
        )}
        <div className="ms-auto">
          <DateRangePicker opening="this_month" testId="client-live-range" />
        </div>
      </div>
      <Board projectId={projectId} range={range} />
    </section>
  )
}

function Board({ projectId, range }: { projectId: string; range: { from: string; to: string } }) {
  const d = useOverviewData({ projectId, range, filters: {}, objective: 'all' })

  return (
    <>
      <ClientOverview {...d} />
      <ClientContent projectId={projectId} range={range} currency={d.reportingCurrency} />
    </>
  )
}

interface PortalContent {
  best: ReportAd[]
  worst: ReportAd[]
  groups?: Array<Omit<AdGroup, 'ads'> & { ads: ReportAd[] }>
  basis: { family: string | null } | null
}

/**
 * CLIENT-CONTENT-001 — Owner 2026-11-21: the client reads its overview and its content, never the campaign list. The
 * best content by the objective each was bought for and the weakest beside it, drawn as the agency's home and the report
 * draw them. Absent when the agency keeps content from this client or nothing ran.
 */
function ClientContent({ projectId, range, currency }: { projectId: string; range: { from: string; to: string }; currency: string | null }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const q = useQuery({
    queryKey: ['client', 'content', projectId, range.from, range.to],
    queryFn: () => getData<PortalContent | null>(`/client/projects/${projectId}/content?from=${range.from}&to=${range.to}`),
  })
  const c = q.data
  if (!c || !Array.isArray(c.best)) return null
  const groups = (c.groups ?? []).filter((g) => g.ads.length > 0)
  if (c.best.length === 0 && groups.length === 0 && c.worst.length === 0) return null

  return (
    <div className="mt-4 grid gap-3" data-testid="client-content">
      {(c.best.length > 0 || groups.length > 0) && (
        <section className="rounded-2xl border border-border bg-surface p-3" data-testid="client-content-best">
          <ReportAdsSection ads={c.best} groups={groups as unknown as AdGroup[]} currency={currency} locale={ar ? 'ar' : 'en'} title={ar ? 'أفضل المحتويات' : 'Best content'} limit={6} browsable={false} />
        </section>
      )}
      {c.worst.length > 0 && (
        <section className="rounded-2xl border border-border bg-surface p-3" data-testid="client-content-worst">
          <ReportAdsSection ads={c.worst} currency={currency} locale={ar ? 'ar' : 'en'} title={ar ? 'أضعف المحتويات' : 'Weakest content'} limit={6} browsable={false} family={c.basis?.family ?? null} />
        </section>
      )}
    </div>
  )
}
