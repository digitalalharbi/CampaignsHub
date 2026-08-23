import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Area,
  AreaChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { ArrowUpRight } from 'lucide-react'
import {
  useBudget,
  useCampaigns,
  useFreshness,
  useFunnel,
  useLastNDaysRange,
  usePlatforms,
  useSummary,
  useTimeseries,
} from '../analytics/hooks'
import { Panel, ProvenanceBadge, SERIES, tooltipProps } from '../analytics/components'
import { compact, money, num, percent, ratio } from '../analytics/format'
import { UnifiedCampaignOverview, providerName, type OverviewVM } from '@/features/campaigns/overview/UnifiedCampaignOverview'
import { SavedViewsBar } from './SavedViewsBar'
import { useSavedViews, type SavedView } from './savedViews'
import { dashboardMetrics } from '@/features/analytics/metricCatalog'
import { dash, funnelStageLabel } from '@/features/analytics/metricLabels'
import { FilterBar, FilterChips, FilterMulti, FilterSelect, type AppliedFilter } from '@/components/ui/FilterBar'
import { MetricStrip } from '@/components/ui/MetricStrip'
import { DataFreshness, PageIntro } from '@/components/ui/PageIntro'
import { useUi } from '@/stores/ui'
import { canonicalPlatform, sortPlatforms } from '@/lib/platforms'
import { useProject } from '@/stores/project'
import { listClientWorkspaces, listProjects } from '@/features/projects/api'
import { MARKETING_PATH_KEYS, marketingPathLabel, objectiveLabel, objectivesForPath, pathOfObjective } from '@/features/campaigns/labels'
import { CreativePulseSection } from '@/features/content/CreativePulseSection'
import type { LibraryQuery } from '@/features/content/api'
import { LivePerformanceNotice } from '@/features/disclaimers/PerformanceNotice'

/**
 * The six paid platforms CampaignsHub unifies — the dashboard platform filter.
 *
 * Ordered by the product's own order rather than by this file's opinion (PLATFORM-ORDER-001). The
 * keys keep their local spelling (`google_ads`) because that is what the API filter expects;
 * `sortPlatforms` canonicalises before it compares.
 */
export const PLATFORM_KEYS = sortPlatforms(['meta', 'google_ads', 'tiktok', 'snapchat', 'x', 'linkedin'])

/**
 * The objectives this page has a layout for.
 *
 * Deliberately the six with a `OBJECTIVE_LAYOUTS` entry rather than all fourteen enum cases: an
 * objective with no layout falls through to the mixed operational set, which would put four generic
 * cards under a heading naming one specific objective — the reader would reasonably conclude those
 * four ARE that objective's metrics.
 */
const OBJECTIVE_CHOICES = ['awareness', 'traffic', 'leads', 'sales', 'app_installs', 'engagement']

const axis = { stroke: 'var(--text-muted)', fontSize: 12 }

const COPY = {
  ar: {
    title: 'لوحة التحكم',
    purpose: 'كل حملاتك الإعلانية المدفوعة عبر المنصات في مكان واحد — الإنفاق والنتائج والمحتوى، من مصدر بيانات واحد.',
    period: 'الفترة',
    client: 'العميل',
    project: 'المشروع',
    platform: 'المنصة',
    campaign: 'الحملة',
    path: 'المسار التسويقي',
    objective: 'الهدف',
    allClients: 'كل العملاء',
    allPaths: 'كل المسارات',
    allObjectives: 'كل الأهداف',
    noProject: 'لم يُختر مشروع',
    days7: '7 أيام',
    days30: '30 يوم',
    days90: '90 يوم',
    previous: (n: number) => `الـ${n} يومًا السابقة`,
    mixedNote: 'أهداف مختلطة — تُعرض المؤشرات التشغيلية المشتركة فقط، بلا تكلفة نتيجة أو عائد مُجمَّع.',
    campaigns: 'الحملات',
    analytics: 'التحليلات',
    savedViews: 'العروض المحفوظة',
    trend: 'الإنفاق مقابل الإيرادات',
    trendSub: 'الاتجاه اليومي خلال الفترة',
    funnel: 'قمع التحويل',
    funnelSub: 'من الظهور إلى الشراء',
    unreported: 'لم ترسل المنصة هذه المرحلة',
    store: 'المتجر المرتبط',
    storeSub: 'من سجل التاجر نفسه — لا من بكسل المنصات.',
    storeLink: 'الفانل والمتجر',
    storeRevenue: 'إيرادات المتجر',
    orders: 'الطلبات',
    aov: 'متوسط قيمة الطلب',
    roas: 'العائد على الإنفاق',
  },
  en: {
    title: 'Dashboard',
    purpose: 'Every paid campaign you run, across every platform, in one place — spend, results and creative from a single source.',
    period: 'Period',
    client: 'Client',
    project: 'Project',
    platform: 'Platform',
    campaign: 'Campaign',
    path: 'Marketing path',
    objective: 'Objective',
    allClients: 'All clients',
    allPaths: 'All paths',
    allObjectives: 'All objectives',
    noProject: 'No project selected',
    days7: '7 days',
    days30: '30 days',
    days90: '90 days',
    previous: (n: number) => `the previous ${n} days`,
    mixedNote: 'Mixed objectives — shared operational figures only, with no blended cost per result or return.',
    campaigns: 'Campaigns',
    analytics: 'Analytics',
    savedViews: 'Saved views',
    trend: 'Spend vs revenue',
    trendSub: 'Day by day over the period',
    funnel: 'Conversion funnel',
    funnelSub: 'From impression to purchase',
    unreported: 'This stage was never reported',
    store: 'Connected store',
    storeSub: 'From the merchant’s own ledger — not the platforms’ pixel.',
    storeLink: 'Funnel & store',
    storeRevenue: 'Store revenue',
    orders: 'Orders',
    aov: 'Average order value',
    roas: 'ROAS',
  },
}

/**
 * `/app/dashboard` — UX-DASH-001.
 *
 * ## The filters are on the page again, and one of them is new
 *
 * This page opened with a `Customise` button: the objective, the platforms and the saved views all
 * lived in a dialog (SIMPLIFY-001). The reasoning was sound about the symptom — three bands of
 * chips above the fold is a settings screen — and wrong about the cure. Period, client, project,
 * platform, campaign, path and objective are not configuration; they are the questions this product
 * is FOR, and hiding them made the system look thinner than it is. They are inline now, in one
 * compact bar, with the applied ones as removable chips. Saved views stay behind `More filters`,
 * which is what that control is for: the rare thing, not the daily one.
 *
 * The campaign filter did not exist at all. It is backend-supported (`?campaign=`) rather than
 * applied to rows already fetched, so the KPI row, the chart, the funnel and the pacing table all
 * narrow together — see the note in `MetricsController::campaignFilter()`.
 *
 * ## Client and project are one control each, and both are real
 *
 * The project was chosen in the sidebar and nowhere else, so a dashboard about one project never
 * said whose it was. Client narrows the project list and project sets the same store the sidebar
 * writes — no second source of truth, and switching client moves to that client's first project
 * rather than leaving the page showing another client's numbers under a new heading.
 *
 * ## The KPI row is now objective-aware, and says when it has nothing
 *
 * `dashboardMetrics` picks four to six cards for the money this campaign IS (§14.6) and folds the
 * rest one visible click away. `summary.reported` is what lets a card say «لم ترسله المنصة»
 * instead of the coalesced zero the sums produce.
 */
export function DashboardPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = COPY[ar ? 'ar' : 'en']
  const { currentProjectId, setCurrentProjectId } = useProject()

  const [days, setDays] = useState(30)
  const range = useLastNDaysRange(days)
  const [providers, setProviders] = useState<string[]>([])
  const [campaignIds, setCampaignIds] = useState<string[]>([])
  const [path, setPath] = useState('all')
  const [objective, setObjective] = useState('all')
  const [clientId, setClientId] = useState('all')

  // The workspace's own shelves — real records, so «Client» and «Project» are choices rather than
  // decoration. Failure is silent by design here: a filter that cannot load must not take the
  // dashboard down with it, and an axis with no options renders nothing at all.
  const projectsQuery = useQuery({ queryKey: ['projects', 'list'], queryFn: () => listProjects(false), retry: false })
  const clientsQuery = useQuery({ queryKey: ['client-workspaces'], queryFn: listClientWorkspaces, retry: false })
  const allProjects = projectsQuery.data ?? []
  const clients = clientsQuery.data ?? []
  const projects = clientId === 'all' ? allProjects : allProjects.filter((p) => p.client_workspace_id === clientId)

  /*
   * Choosing a client moves to one of that client's projects.
   *
   * Without this the heading says «Nakheel» while every figure below it is still the previous
   * client's — the single most dangerous state a multi-client dashboard can be in, because nothing
   * on the screen looks wrong.
   */
  useEffect(() => {
    if (clientId === 'all' || projects.length === 0) return
    if (!projects.some((p) => p.id === currentProjectId)) setCurrentProjectId(projects[0].id)
  }, [clientId, projects, currentProjectId, setCurrentProjectId])

  /**
   * The objective filter actually sent.
   *
   * A path is not a server axis — it is this path's objectives, on the objective filter the metrics
   * API already supports (see `objectivesForPath`). So picking «التحويل والمبيعات» narrows every
   * figure on the page exactly as picking its objectives one by one would.
   */
  const objectiveFilter = useMemo(() => {
    if (objective !== 'all') return [objective]

    return path === 'all' ? [] : objectivesForPath(path)
  }, [objective, path])

  const filters = useMemo(
    () => ({ provider: providers, objective: objectiveFilter, campaign: campaignIds }),
    [providers, objectiveFilter, campaignIds],
  )

  /** The same selection in the creative library's vocabulary (§15.11) — canonical platform keys. */
  const creativeFilters: LibraryQuery = useMemo(
    () => ({
      from: range.from,
      to: range.to,
      providers: providers.length > 0 ? providers.map(canonicalPlatform) : undefined,
      objectives: objectiveFilter.length > 0 ? objectiveFilter : undefined,
      campaign_ids: campaignIds.length > 0 ? campaignIds : undefined,
      project_ids: currentProjectId ? [currentProjectId] : undefined,
    }),
    [range.from, range.to, providers, objectiveFilter, campaignIds, currentProjectId],
  )

  // Saved views (DASH-010-E-FE): apply restores objective + platforms + date range.
  const savedViews = useSavedViews()
  const applyView = (v: SavedView) => {
    if (v.filters?.objective) {
      setObjective(v.filters.objective)
      setPath(v.filters.objective === 'all' ? 'all' : pathOfObjective(v.filters.objective))
    }
    if (v.filters?.provider) setProviders(v.filters.provider)
    if (v.date_range?.days) setDays(v.date_range.days)
  }
  const appliedDefault = useRef(false)
  useEffect(() => {
    if (appliedDefault.current) return
    const def = savedViews.data?.find((v) => v.is_default)
    if (def) {
      appliedDefault.current = true
      applyView(def)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [savedViews.data])

  const summary = useSummary(currentProjectId, range, filters)
  const series = useTimeseries(currentProjectId, range, filters)
  const platforms = usePlatforms(currentProjectId, range, filters)
  const campaigns = useCampaigns(currentProjectId, range, filters)
  const funnel = useFunnel(currentProjectId, range, filters)
  const budget = useBudget(currentProjectId, range, filters)
  const freshness = useFreshness(currentProjectId, range, filters)

  /*
   * The campaign OPTIONS come from an unnarrowed request.
   *
   * Reading them off `campaigns` would collapse the list to the one campaign already chosen, and a
   * multi-select you cannot add a second value to is a single-select that lies about it.
   */
  const campaignOptions = useCampaigns(currentProjectId, range, useMemo(
    () => ({ provider: providers, objective: objectiveFilter }),
    [providers, objectiveFilter],
  ))

  const commerce = summary.data?.commerce ?? null
  const points = series.data ?? []
  const metrics = useMemo(() => dashboardMetrics(objective, path, summary.data, ar), [objective, path, summary.data, ar])

  const alerts = useMemo(() => {
    const out: { kind: 'sync' | 'budget' | 'performance'; text: string }[] = []
    freshness.data?.forEach((f) => {
      const who = f.name ?? providerName(f.provider)
      if (f.last_sync_status === 'failed') out.push({ kind: 'sync', text: ar ? `فشل مزامنة ${who} — يتطلب إعادة ربط` : `${who} sync failed — needs reconnecting` })
    })
    budget.data?.forEach((b) => {
      if ((b.pace ?? 0) > 1.4) out.push({ kind: 'budget', text: `${b.campaign_name}: ${dash('pacingAhead', ar)} (${ratio(b.pace ?? 0, '×')})` })
    })
    campaigns.data?.forEach((c) => {
      if (c.spend > 3000 && c.conversions < 2) out.push({ kind: 'performance', text: `${c.campaign_name}: ${dash('spendNoConversions', ar)}` })
    })
    return out.slice(0, 4)
  }, [freshness.data, budget.data, campaigns.data, ar])

  const lastSync = freshness.data?.map((f) => f.last_sync_at).filter(Boolean).sort().at(-1)

  /*
   * The shared command-centre view model — comparisons, top campaigns, attention and alerts.
   *
   * `kpis` is empty on purpose: the objective-aware `MetricStrip` above owns the KPI row now, and
   * two rows of headline figures on one page is two answers to the same question.
   */
  const vm: OverviewVM = useMemo(
    () => ({
      currency: 'SAR',
      dataStatus: 'demo',
      lastSyncAt: lastSync ?? null,
      kpis: [],
      platforms: (platforms.data ?? []).map((p) => ({
        key: p.provider,
        name: p.provider,
        spend: p.spend,
        results: 0,
        roas: p.roas ?? null,
      })),
      spend: (platforms.data ?? []).map((p) => ({ name: p.provider, value: p.spend })),
      topCampaigns: (campaigns.data ?? []).slice(0, 6).map((c) => ({
        id: String(c.campaign_id),
        name: c.campaign_name ?? '—',
        provider: c.provider,
        spend: c.spend,
        results: c.conversions,
        cpa: c.cpa ?? null,
        roas: c.roas ?? null,
      })),
      needsAttention: (campaigns.data ?? [])
        .filter((c) => c.spend > 3000 && c.conversions < 2)
        .slice(0, 4)
        .map((c) => ({ id: String(c.campaign_id), name: c.campaign_name ?? '—', reason: dash('spendNoConversions', ar) })),
      alerts: alerts.map((a) => ({ severity: a.kind === 'budget' ? ('medium' as const) : ('high' as const), text: a.text })),
    }),
    [campaigns.data, platforms.data, alerts, lastSync, ar],
  )

  /** What is narrowing the page, each chip removing exactly its own value. */
  const applied: AppliedFilter[] = useMemo(() => {
    const out: AppliedFilter[] = []

    if (clientId !== 'all') {
      out.push({
        key: `client:${clientId}`,
        axis: t.client,
        label: clients.find((c) => c.id === clientId)?.name ?? clientId,
        onRemove: () => setClientId('all'),
      })
    }
    providers.forEach((p) => {
      out.push({
        key: `provider:${p}`,
        axis: t.platform,
        label: providerName(p),
        onRemove: () => setProviders((prev) => prev.filter((x) => x !== p)),
      })
    })
    campaignIds.forEach((id) => {
      out.push({
        key: `campaign:${id}`,
        axis: t.campaign,
        label: campaignOptions.data?.find((c) => String(c.campaign_id) === id)?.campaign_name ?? id,
        onRemove: () => setCampaignIds((prev) => prev.filter((x) => x !== id)),
      })
    })
    if (path !== 'all') {
      out.push({ key: `path:${path}`, axis: t.path, label: marketingPathLabel(path, ar ? 'ar' : 'en'), onRemove: () => setPath('all') })
    }
    if (objective !== 'all') {
      out.push({
        key: `objective:${objective}`,
        axis: t.objective,
        label: objectiveLabel(objective, ar ? 'ar' : 'en'),
        onRemove: () => setObjective('all'),
      })
    }

    return out
  }, [clientId, clients, providers, campaignIds, campaignOptions.data, path, objective, t, ar])

  const resetFilters = () => {
    setClientId('all')
    setProviders([])
    setCampaignIds([])
    setPath('all')
    setObjective('all')
  }

  // The objectives that belong to the chosen path — so the two controls cannot contradict.
  const objectiveChoices = OBJECTIVE_CHOICES.filter((key) => path === 'all' || pathOfObjective(key) === path)

  return (
    <div className="space-y-5">
      <PageIntro
        testid="dashboard-intro"
        title={t.title}
        purpose={t.purpose}
        badges={<ProvenanceBadge provenance={summary.data?.provenance} />}
        meta={<DataFreshness lastSyncAt={lastSync} ar={ar} />}
        actions={
          <>
            <Link
              to="/app/campaigns"
              className="inline-flex h-10 items-center gap-1.5 rounded-xl border border-border-strong bg-surface px-3 text-sm font-semibold text-text-primary hover:bg-surface-hover"
            >
              {t.campaigns} <ArrowUpRight size={16} aria-hidden />
            </Link>
            <Link
              to="/app/analytics"
              className="inline-flex h-10 items-center gap-1.5 rounded-xl border border-border-strong bg-surface px-3 text-sm font-semibold text-text-primary hover:bg-surface-hover"
            >
              {t.analytics} <ArrowUpRight size={16} aria-hidden />
            </Link>
          </>
        }
      />

      <FilterBar
        id="dashboard"
        ar={ar}
        applied={applied}
        onReset={resetFilters}
        advancedActive={savedViews.data?.some((v) => v.is_default) ?? false}
        advanced={
          <div className="grid gap-2">
            <span className="text-xs font-bold uppercase tracking-wide text-text-muted">{t.savedViews}</span>
            <SavedViewsBar current={{ objective, providers, days }} onApply={applyView} />
          </div>
        }
      >
        <FilterChips
          label={t.period}
          value={String(days)}
          testid="dashboard-period"
          options={[
            { value: '7', label: t.days7 },
            { value: '30', label: t.days30 },
            { value: '90', label: t.days90 },
          ]}
          onChange={(v) => setDays(Number(v))}
        />

        {clients.length > 1 && (
          <FilterSelect
            label={t.client}
            value={clientId}
            testid="dashboard-client"
            options={[{ value: 'all', label: t.allClients }, ...clients.map((c) => ({ value: c.id, label: c.name }))]}
            onChange={setClientId}
          />
        )}

        {/*
          Rendered before the projects arrive, not after — CLICK-STABLE-001.

          Gated on `projects.length > 0`, this control appeared the moment its query landed and moved
          every control to its right, `More filters` included. A control that arrives under a pointer
          already travelling towards its neighbour is how a click gets lost, so the axis holds its
          place from the first paint and fills in.
        */}
        <FilterSelect
          label={t.project}
          value={currentProjectId ?? ''}
          testid="dashboard-project"
          options={[
            ...(currentProjectId ? [] : [{ value: '', label: t.noProject }]),
            ...projects.map((p) => ({ value: p.id, label: p.name })),
          ]}
          onChange={setCurrentProjectId}
        />

        <FilterMulti
          label={t.platform}
          ar={ar}
          values={providers}
          testid="dashboard-platform"
          options={PLATFORM_KEYS.map((key) => ({ value: key, label: providerName(key) }))}
          onChange={setProviders}
        />

        <FilterMulti
          label={t.campaign}
          ar={ar}
          values={campaignIds}
          testid="dashboard-campaign"
          options={(campaignOptions.data ?? []).map((c) => ({
            value: String(c.campaign_id),
            label: c.campaign_name ?? String(c.campaign_id),
          }))}
          onChange={setCampaignIds}
        />

        <FilterSelect
          label={t.path}
          value={path}
          testid="dashboard-path"
          options={[
            { value: 'all', label: t.allPaths },
            ...MARKETING_PATH_KEYS.map((key) => ({ value: key, label: marketingPathLabel(key, ar ? 'ar' : 'en') })),
          ]}
          onChange={(v) => {
            setPath(v)
            // An objective outside the new path would make the two controls disagree, and the
            // objective is the narrower of the two — so it yields.
            if (v !== 'all' && objective !== 'all' && pathOfObjective(objective) !== v) setObjective('all')
          }}
        />

        <FilterSelect
          label={t.objective}
          value={objective}
          testid="dashboard-objective"
          options={[
            { value: 'all', label: t.allObjectives },
            ...objectiveChoices.map((key) => ({ value: key, label: objectiveLabel(key, ar ? 'ar' : 'en') })),
          ]}
          onChange={setObjective}
        />
      </FilterBar>

      <MetricStrip
        id="dashboard"
        ar={ar}
        primary={metrics.primary}
        secondary={metrics.secondary}
        comparisonLabel={t.previous(days)}
        note={objective === 'all' && path === 'all' ? t.mixedNote : undefined}
      />

      {/* The analysis: what happened day by day, and where people stopped. */}
      <div className="grid gap-4 lg:grid-cols-3">
        <Panel title={t.trend} description={t.trendSub} className="lg:col-span-2" loading={series.isLoading} error={series.isError} empty={!series.isLoading && points.length === 0}>
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={points} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
                <defs>
                  <linearGradient id="gSpend" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={SERIES.spend} stopOpacity={0.28} />
                    <stop offset="100%" stopColor={SERIES.spend} stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="gRev" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={SERIES.revenue} stopOpacity={0.28} />
                    <stop offset="100%" stopColor={SERIES.revenue} stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="date" tick={axis} tickFormatter={(d) => String(d).slice(5)} minTickGap={24} />
                <YAxis tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
                <Tooltip {...tooltipProps} formatter={(v: number) => num(v)} />
                <Legend wrapperStyle={{ fontSize: 13 }} />
                <Area name={ar ? 'الإنفاق' : 'Spend'} type="monotone" dataKey="spend" stroke={SERIES.spend} strokeWidth={2} fill="url(#gSpend)" isAnimationActive={false} />
                <Area name={ar ? 'الإيرادات' : 'Revenue'} type="monotone" dataKey="revenue" stroke={SERIES.revenue} strokeWidth={2} fill="url(#gRev)" isAnimationActive={false} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Panel>

        <Panel title={t.funnel} description={t.funnelSub} loading={funnel.isLoading} error={funnel.isError} empty={!funnel.isLoading && (funnel.data?.length ?? 0) === 0}>
          {/*
            FUNNEL-NULL-001 — a stage the platform never sent draws no bar at all. It used to scale
            every bar to `rows[0].count`, and `null / top` is 0 in JavaScript, so an unreported stage
            drew the minimum-width bar with «0» inside it, on the dashboard, above the fold.
          */}
          <div className="space-y-2">
            {(() => {
              const rows = funnel.data ?? []
              const measured = rows.map((s) => s.count).filter((c): c is number => c !== null)
              const top = measured.length > 0 ? Math.max(...measured) : 1
              return rows.map((s, i) => {
                const share = s.count === null ? 0 : (s.count / top) * 100
                /*
                 * The figure moves OUT of the bar once the bar is too small to hold it.
                 *
                 * A funnel narrows by design, so the later stages are always the thin ones — and
                 * `overflow-hidden` on the track was clipping «2.9K» down to «K» on exactly the
                 * stages a reader is most interested in. Caught by looking at the page, not by a
                 * test: every assertion about this funnel passed while it was unreadable.
                 */
                const inside = share >= 18

                return (
                <div key={s.stage} className="flex items-center gap-3">
                  <span className="w-28 shrink-0 text-sm text-text-secondary">{funnelStageLabel(s.stage, s.label, ar)}</span>
                  {s.count !== null ? (
                    <div className="flex h-8 flex-1 items-center gap-2">
                      <div className="h-full flex-1 overflow-hidden rounded-lg bg-surface-secondary">
                        <div
                          className="flex h-full items-center justify-end rounded-lg px-2 text-sm font-semibold text-white"
                          style={{ width: `${Math.max(4, share)}%`, background: `color-mix(in oklab, ${SERIES.spend} ${100 - i * 12}%, var(--brand-700))` }}
                        >
                          {inside && <span className="tnum">{compact(s.count)}</span>}
                        </div>
                      </div>
                      {!inside && <span className="tnum shrink-0 text-sm font-semibold text-text-primary">{compact(s.count)}</span>}
                    </div>
                  ) : (
                    <div className="flex h-8 flex-1 items-center rounded-lg border border-dashed border-border px-2 text-xs text-text-muted">
                      {t.unreported}
                    </div>
                  )}
                  <span className="tnum w-12 text-end text-xs text-text-muted">{s.step_rate === null ? '' : percent(s.step_rate, 0)}</span>
                </div>
                )
              })
            })()}
          </div>
        </Panel>
      </div>

      {/* The comparisons, the details and the alerts — shared with the marketing preview. */}
      <UnifiedCampaignOverview
        vm={vm}
        lang={ar ? 'ar' : 'en'}
        headerRight={
          <Link to="/app/analytics" className="inline-flex items-center gap-1 font-semibold text-text-secondary hover:text-text-primary">
            {t.analytics} <ArrowUpRight size={14} aria-hidden />
          </Link>
        }
      />

      {/*
        UNIFIED-001 — the connected store, from the funnel's own service.

        The KPI row above carries `revenue` as the ad platforms report it: a pixel's estimate of what
        it believes its clicks caused. The shop's ledger is a different and better number, and the
        product holds both. This strip is labelled as the store's, sits beside the platforms' figures
        rather than replacing them, and links to the section that explains where each came from.
      */}
      {commerce && (
        <div data-testid="dashboard-store" className="rounded-2xl border border-border bg-surface p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 className="text-lg font-bold text-text-primary">{t.store}</h2>
              <p className="text-[13px] text-text-secondary">{t.storeSub}</p>
            </div>
            <Link to="/app/analytics" className="inline-flex items-center gap-1 text-sm font-semibold text-text-secondary hover:text-text-primary">
              {t.storeLink} <ArrowUpRight size={14} aria-hidden />
            </Link>
          </div>

          <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {[
              // COMMERCE-FX-001 — the currency the SERVER says these are in, not a constant.
              { key: 'revenue', label: t.storeRevenue, value: commerce.revenue == null ? '—' : money(commerce.revenue, commerce.reporting_currency || 'SAR') },
              { key: 'orders', label: t.orders, value: num(commerce.orders) },
              { key: 'aov', label: t.aov, value: commerce.aov == null ? '—' : money(commerce.aov, commerce.reporting_currency || 'SAR') },
              { key: 'roas', label: t.roas, value: commerce.roas == null ? '—' : ratio(commerce.roas, '×') },
            ].map((k) => (
              <div key={k.key} data-testid={`store-kpi-${k.key}`} className="rounded-xl border border-border bg-surface-secondary px-3 py-2">
                <p className="text-[13px] text-text-secondary">{k.label}</p>
                <p className="tnum mt-0.5 text-xl font-bold text-text-primary">{k.value}</p>
              </div>
            ))}
          </div>

          {/*
            When the rest of the page is narrowed and this block is not, the block says so. An order
            does not belong to a platform the way a click does — a large share carry no attribution
            at all — so these figures cover the whole shop whatever the filter above says.
          */}
          {commerce.filtered_view && (
            <p data-testid="dashboard-store-unfiltered" className="mt-3 rounded-xl border border-border bg-surface-secondary px-3 py-2 text-[13px] text-text-secondary">
              {ar ? commerce.unfiltered_note_ar : commerce.unfiltered_note_en}
            </p>
          )}

          {commerce.unattributed_orders > 0 && (
            <p data-testid="dashboard-store-unattributed" className="mt-3 text-[13px] text-text-secondary">
              {ar
                ? `${num(commerce.unattributed_orders)} من ${num(commerce.orders)} طلبًا وصلت بلا إسناد لأي حملة.`
                : `${num(commerce.unattributed_orders)} of ${num(commerce.orders)} orders arrived with no campaign attribution.`}
            </p>
          )}

          {/* COMMERCE-TZ-001 — an order whose store states no timezone may belong to the day either
              side of where it is counted, and the reader is told rather than left to assume. */}
          {(commerce.orders_with_assumed_timezone ?? 0) > 0 && (
            <p data-testid="dashboard-store-assumed-tz" className="mt-2 text-[13px] text-warning">
              {ar
                ? `${num(commerce.orders_with_assumed_timezone ?? 0)} طلبًا لم يذكر متجرها المنطقة الزمنية، فاعتُبرت UTC.`
                : `${num(commerce.orders_with_assumed_timezone ?? 0)} order(s) come from a store that states no timezone, so UTC was assumed.`}
            </p>
          )}

          {/* The revenue above is SHORT by these orders, and a short total must never look whole. */}
          {(commerce.orders_with_money_withheld ?? 0) > 0 && (
            <p data-testid="dashboard-store-withheld" className="mt-2 text-[13px] text-warning">
              {ar
                ? `${num(commerce.orders_with_money_withheld)} طلبًا بعملة (${commerce.money_withheld_currencies.join('، ')}) لا يوجد لها سعر صرف مؤرّخ، فلم تُحتسب ضمن الإيرادات.`
                : `${num(commerce.orders_with_money_withheld)} order(s) in ${commerce.money_withheld_currencies.join(', ')} have no dated exchange rate and are not included in the revenue.`}
            </p>
          )}
        </div>
      )}

      {/* §15.11 — the creative section, on this page's own filters, linking into the library. */}
      <CreativePulseSection
        libraryPath="/app/content"
        axes={['clients', 'kinds']}
        filters={creativeFilters}
      />

      <LivePerformanceNotice variant="compact" />
    </div>
  )
}
