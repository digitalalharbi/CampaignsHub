import { useEffect, useMemo, useRef } from 'react'
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
import { useCampaignOptionSource } from '../analytics/useCampaignOptionSource'
import { Panel, ProvenanceBadge, SERIES, tooltipProps } from '../analytics/components'
import { compact, money, num, percent, ratio } from '../analytics/format'
import { UnifiedCampaignOverview, providerName, type OverviewVM } from '@/features/campaigns/overview/UnifiedCampaignOverview'
import { SavedViewsBar } from './SavedViewsBar'
import { useSavedViews, type SavedView } from './savedViews'
import { dashboardMetrics } from '@/features/analytics/metricCatalog'
import { dash, funnelStageLabel } from '@/features/analytics/metricLabels'
import { FilterBar, FilterChips, FilterMulti, FilterSelect, type AppliedFilter } from '@/components/ui/FilterBar'
import { FilterPlatforms } from '@/components/ui/FilterPlatforms'
import { displaySpend, withheldCurrencyOf } from './platformMoney'
import { MetricStrip } from '@/components/ui/MetricStrip'

import { ConciseFindingLine } from './ConciseFindingLine'
import { DataFreshness, PageIntro } from '@/components/ui/PageIntro'
import { useUi } from '@/stores/ui'
import { days as countedDays } from '@/lib/counted'
import { sortPlatforms } from '@/lib/platforms'
import { useUrlList, useUrlNumber, useUrlState } from '@/features/analytics/filterUrlState'
import { useProject } from '@/stores/project'
import { listClientWorkspaces, listProjects } from '@/features/projects/api'
import {
  CANONICAL_OBJECTIVE_KEYS,
  canonicalObjectiveLabel,
  rawObjectivesFor,
  type CanonicalObjectiveKey,
} from '@/features/campaigns/canonicalObjectives'
import { LivePerformanceNotice } from '@/features/disclaimers/PerformanceNotice'

/**
 * The six paid platforms CampaignsHub unifies — the dashboard platform filter.
 *
 * Ordered by the product's own order rather than by this file's opinion (PLATFORM-ORDER-001). The
 * keys keep their local spelling (`google_ads`) because that is what the API filter expects;
 * `sortPlatforms` canonicalises before it compares.
 */
export const PLATFORM_KEYS = sortPlatforms(['meta', 'google_ads', 'tiktok', 'snapchat', 'x', 'linkedin'])


const axis = { stroke: 'var(--text-muted)', fontSize: 12 }

const COPY = {
  ar: {
    title: 'لوحة التحكم',
    purpose: 'كل حملاتك الإعلانية المدفوعة عبر المنصات في مكان واحد — الإنفاق والنتائج والإعلان، من مصدر بيانات واحد.',
    period: 'الفترة',
    client: 'العميل',
    project: 'المشروع',
    platform: 'المنصة',
    campaign: 'الحملة',
    objective: 'الهدف',
    allClients: 'كل العملاء',
    allPlatforms: 'الكل',
    allObjectives: 'كل الأهداف',
    noProject: 'لم يُختر مشروع',
    days7: '7 أيام',
    days30: '30 يوم',
    days90: '90 يوم',
    previous: (n: number) => `الـ${countedDays(n, 'ar')} السابقة`,
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
    purpose: 'Every paid campaign you run, across every platform, in one place — spend, results and ads from a single source.',
    period: 'Period',
    client: 'Client',
    project: 'Project',
    platform: 'Platform',
    campaign: 'Campaign',
    objective: 'Objective',
    allClients: 'All clients',
    allPlatforms: 'All',
    allObjectives: 'All objectives',
    noProject: 'No project selected',
    days7: '7 days',
    days30: '30 days',
    days90: '90 days',
    previous: (n: number) => `the previous ${countedDays(n, 'en')}`,
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
 * platform, campaign and objective are not configuration; they are the questions this product
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

  /*
   * ANALYTICS-FILTER-TRUTH-001 — the filters live in the URL, so a refresh, Back and a shared link
   * all show the same page. They were `useState` only: narrowing to one platform and one objective
   * and then reloading gave the unfiltered page back, and the link a reader sent a colleague showed
   * that colleague a different answer to the question they were discussing.
   */
  const [days, setDays] = useUrlNumber('days', 30)
  const range = useLastNDaysRange(days)
  const [providers, setProviders] = useUrlList('provider')
  const [campaignIds, setCampaignIds] = useUrlList('campaign')
  const [objectiveRaw, setObjective] = useUrlState('objective', 'all')
  const objective = objectiveRaw as CanonicalObjectiveKey | 'all'
  const [clientId, setClientId] = useUrlState('client', 'all')

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
   * The objective filter actually sent — ANALYTICS-OBJECTIVE-SYSTEM-001.
   *
   * The canonical key is what the reader chose; the metrics API filters on RAW objectives, so it is
   * expanded here. Sending the canonical key itself would leave every figure unscoped beneath a
   * heading claiming otherwise — the frontend-only filtering ANALYTICS-FILTER-TRUTH-001 forbids.
   */
  const objectiveFilter = useMemo(() => rawObjectivesFor(objective), [objective])

  const filters = useMemo(
    () => ({ provider: providers, objective: objectiveFilter, campaign: campaignIds }),
    [providers, objectiveFilter, campaignIds],
  )


  // Saved views (DASH-010-E-FE): apply restores objective + platforms + date range.
  const savedViews = useSavedViews()
  const applyView = (v: SavedView) => {
    if (v.filters?.objective) setObjective(v.filters.objective as CanonicalObjectiveKey | 'all')
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
   * The campaign OPTIONS come from the server's option endpoint, unnarrowed.
   *
   * Reading them off `campaigns` would collapse the list to the one campaign already chosen, and a
   * multi-select you cannot add a second value to is a single-select that lies about it. The
   * endpoint is also unwindowed by the period, so a campaign that reported nothing this month is
   * still selectable — which is the point, since its silence is what the reader is investigating.
   */
  const campaignSource = useCampaignOptionSource(currentProjectId, campaignIds)

  const commerce = summary.data?.commerce ?? null
  const points = series.data ?? []
  const metrics = useMemo(() => dashboardMetrics(objective, summary.data, ar), [objective, summary.data, ar])

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
  /*
   * DASH-PLATFORM-MONEY-001 — three hardcoded values, all three visibly wrong on a live project.
   *
   * The summary card read «4,768.84 USD» and the platform comparison directly beneath it read
   * «0 SAR», with an empty spend donut and a flat spend/revenue chart. One project, one window, two
   * answers — and the reader has no way to know which to believe.
   *
   *   currency: 'SAR'   — this account reports in USD. The label was a guess, and it was wrong.
   *   dataStatus: 'demo' — stamped «معاينة توضيحية ببيانات تجريبية» across a project holding 1,956
   *                        live rows and zero demo rows. `ANALYTICS-PROVENANCE-001` made this badge
   *                        derive from the data everywhere else; this call site never got the memo.
   *   spend: p.spend    — the coalesced 0. `PlatformRow` extends `MoneyProvenance` and the backend
   *                        has been sending `spend_original`, `spend_withheld_rows` and
   *                        `money_original_currency` per provider all along. The view model dropped
   *                        them on the floor.
   *
   * FX-001 withholds a converted figure when no rate exists rather than inventing one, so `spend` is
   * legitimately 0 here. What was NOT legitimate was rendering that 0 under a currency this account
   * does not report in, beside a card showing the true amount.
   */
  const platformRows = platforms.data ?? []

  /*
   * The currency the comparison is actually denominated in.
   *
   * When every row is withheld in one currency, that currency IS the figure's currency — and it is
   * what the summary card above already prints. A mixture of originals has no single name, so the
   * project's own currency stays and each withheld row falls back to «—» rather than being summed
   * under a label that fits none of them.
   */
  const withheldCurrency = useMemo(() => withheldCurrencyOf(platformRows), [platformRows])

  const vm: OverviewVM = useMemo(
    () => ({
      currency: withheldCurrency ?? summary.data?.currency ?? 'SAR',
      /*
       * Derived, like every other surface. `provenance.source` is `live` when the rows are the
       * customer's own, and a live project carries no warning — which is the whole point of the
       * badge existing.
       */
      /*
       * `DataStatus` has three cases — demo, live, stale — and «mixed» is not one of them. A project
       * holding demo rows BESIDE real ones must still carry the warning, because the totals add them
       * together, so mixed maps to `demo` rather than being widened with a cast. A cast here would
       * have put a value through the type that the badge has no branch for.
       */
      dataStatus: summary.data?.provenance?.source === 'live' ? 'live' : 'demo',
      lastSyncAt: lastSync ?? null,
      kpis: [],
      platforms: platformRows.map((p) => ({
        key: p.provider,
        name: p.provider,
        spend: displaySpend(p),
        results: 0,
        roas: p.roas ?? null,
      })),
      spend: platformRows.map((p) => ({ name: p.provider, value: displaySpend(p) })),
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
        label: campaignSource.labelOf(id),
        onRemove: () => setCampaignIds((prev) => prev.filter((x) => x !== id)),
      })
    })
    if (objective !== 'all') {
      out.push({
        key: `objective:${objective}`,
        axis: t.objective,
        label: canonicalObjectiveLabel(objective, ar ? 'ar' : 'en'),
        onRemove: () => setObjective('all'),
      })
    }

    return out
  }, [clientId, clients, providers, campaignIds, campaignSource, objective, t, ar])

  const resetFilters = () => {
    setClientId('all')
    setProviders([])
    setCampaignIds([])
    setObjective('all')
  }

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

        {/*
          UX-FILTERS-001 — six platforms are not a long enough list to hide behind a click.
          The chips carry the same colours the charts below use, so switching one off and watching
          its arc leave the donut is one recognisable action rather than two unrelated ones.
        */}
        <FilterPlatforms
          label={t.platform}
          allLabel={t.allPlatforms}
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
          options={campaignSource.options}
          search={campaignSource.search}
          onChange={setCampaignIds}
        />

        <FilterSelect
          label={t.objective}
          value={objective}
          testid="dashboard-objective"
          options={[
            { value: 'all', label: t.allObjectives },
            ...CANONICAL_OBJECTIVE_KEYS.map((key) => ({ value: key, label: canonicalObjectiveLabel(key, ar ? 'ar' : 'en') })),
          ]}
          onChange={(v) => setObjective(v as CanonicalObjectiveKey | 'all')}
        />
      </FilterBar>

      {/*
        ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — one line, from the SAME engine the panel reads.
        A second, smaller rule set for the headline would eventually disagree with the panel one
        click away, and the reader could not tell which was lying. This chooses; it does not reason.
      */}
      <ConciseFindingLine
        objective={objective}
        totals={summary.data?.current as Record<string, number | null | undefined> | undefined}
        reported={summary.data?.reported}
        rowsInScope={summary.data?.rows_in_scope}
        pending={summary.isPending || summary.isError}
        ar={ar}
      />
      <MetricStrip
        id="dashboard"
        ar={ar}
        primary={metrics.primary}
        secondary={metrics.secondary}
        comparisonLabel={t.previous(days)}
        note={objective === 'all' ? t.mixedNote : undefined}
        /*
          METRICS-EMPTY-SCOPE-001 — a filter that matches nothing says so ONCE, about the filter.
          Without this every card reads its absence from `reported`, which over an empty scope
          answers every key false — so narrowing to an objective this project never bought made the
          dashboard claim the platform sends no impressions.
        */
        hasRows={summary.data === undefined ? undefined : summary.data.rows_in_scope}
        /*
          METRICS-REQUEST-STATE-001 — and a request that failed or has not answered says so.

          `data` is undefined for a failure and for a load alike, so without these the row rendered with
          nothing to read and every card printed «لا توجد بيانات» — a confident statement about this
          account's advertising, made by a request that never came back.
        */
        loading={summary.isPending}
        error={summary.isError ? summary.error : undefined}
        onRetry={() => void summary.refetch()}
      />

      {/*
        DASH-ORDER-001 — the answer first, the working underneath.
        
        The page used to open on a day-by-day trend chart and a funnel, and put «which platform, which
        campaign, where the money went» below them. That is the order the data was BUILT in, not the
        order it is read in: an operator opening this page is asking which campaign is winning and
        where the spend went, and was made to scroll past two charts explaining a shape they had not
        been given yet.
        
        The comparisons now sit directly under the KPI row — platform bars, the spend donut and the
        campaign table in one band — and the trend and funnel follow as the working behind them.
      */}
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
                  {/*
                    FUNNEL-NOT-NESTED-001 — a step over 100% is flagged, not printed as a conversion.

                    3,048 checkouts against 1,806 add-to-carts is 166%, and a funnel that widens is
                    telling the reader these two events do not nest — not that 166% of people
                    converted. The figure stays visible because it is real; the «▲» and the tooltip
                    say why it is not a drop-off.
                  */}
                  <span
                    className={`tnum w-12 text-end text-xs ${s.exceeds_previous ? 'text-warning' : 'text-text-muted'}`}
                    title={s.exceeds_previous
                      ? (ar
                          ? 'هذه المرحلة أكبر من التي فوقها — الحدثان لا يتداخلان (شراء مباشر، أو نافذة إسناد مختلفة).'
                          : 'This stage counted more than the one above it — the two events do not nest (buy-now flows, or a different attribution window).')
                      : undefined}
                  >
                    {s.step_rate === null ? '' : `${s.exceeds_previous ? '▲ ' : ''}${percent(s.step_rate, 0)}`}
                  </span>
                </div>
                )
              })
            })()}
          </div>
        </Panel>
      </div>

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

      {/*
        DASH-CLUTTER-001 — an entire creative analysis lived here, and it was 62% of the page.
        
        «تحليل المحتوى الإعلاني» carried best image, best video, what the numbers say, fastest
        growing, declining, fatigue, spend by content type and images-vs-video. The dashboard ran to
        3,648px — three and a half screens — and everything below the first 1,375 of them was this
        one section.
        
        All of it already exists twice over: the Content library IS that analysis, and Analytics has
        a Creative tab reading the same rows. Three places answering one question is the clutter the
        owner keeps asking to be rid of, and the cost fell on the surface that is supposed to answer
        «how are we doing» at a glance.
        
        Nothing is lost — the link goes to the section that owns it, carrying nothing but the reader.
      */}
      <div className="flex items-center justify-between gap-3 rounded-xl border border-border bg-surface px-4 py-3">
        <div className="min-w-0">
          <h2 className="text-sm font-bold text-text-primary">{ar ? 'أداء الإعلانات' : 'Ad performance'}</h2>
          <p className="mt-0.5 text-xs text-text-secondary">
            {ar
              ? 'أفضل الصور والفيديوهات، الإجهاد، والاتجاهات — في مكتبة الإعلان.'
              : 'Best images and videos, fatigue and trends — in the content library.'}
          </p>
        </div>
        <Link
          to="/app/content"
          className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary hover:border-brand-300 hover:text-text-primary"
        >
          {ar ? 'افتح الإعلانات' : 'Open ads'} <ArrowUpRight size={13} aria-hidden />
        </Link>
      </div>

      <LivePerformanceNotice variant="compact" />
    </div>
  )
}
