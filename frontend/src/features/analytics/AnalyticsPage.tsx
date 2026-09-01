import { useEffect, useMemo, useRef, useState } from 'react'
import { fmtDate, fmtDateTime } from '@/lib/datetime'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import {
  useAccountBudgets,
  useBudget,
  useBudgetExplanation,
  useAccounts,
  useCampaigns,
  useEntities,
  useFreshness,
  useFunnel,
  useLastNDaysRange,
  useAttribution,
  useNormalization,
  useObjectiveExplanations,
  useObjectiveLeaders,
  useObjectiveTrend,
  usePlatformObjectives,
  usePlatforms,
  useSummary,
  useTimeseries,
  type EntityRow,
  type MetricFilters,
} from './hooks'
import { findingsFor, windowConfidence, type QualityFinding, type WindowConfidence } from './dataQualityFindings'
import { efficiencyFor, returnFor } from './pathEfficiency'
import { platformReadings } from './platformReading'
import { attributionFindings, type AttributionFinding } from './attributionFindings'
import { distributionFor } from './familyDistribution'
import type { FreshnessRow, PlatformObjectives } from './api'
import { scopeNote, type FilterScope } from './filterScope'
import { CAMPAIGN_RELEVANCE_ORDER, orderByRelevanceWith, relevanceOf } from '@/features/campaigns/campaignRelevance'
import { useCampaignOptionSource } from './useCampaignOptionSource'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { Panel, ProvenanceBadge, RateTrend, SERIES, platformColor, tooltipProps } from './components'
import { BudgetReading } from './BudgetReading'
import { PathAnalysis } from './PathAnalysis'
import { PathTrends } from './PathTrends'
import { listCreatives, type CreativeCard } from '@/features/content/api'
import { compact, money, moneyExact, num, percent, ratio, rowCostPer, rowMoney, rowRoas } from './format'
import { funnelStageLabel } from './metricLabels'
import { plotSeries } from './timeseriesMoney'
import { useUrlList, useUrlNumber, useUrlState, useUrlWriter } from './filterUrlState'
import { familyMoney, familyTotal, type FamilyRow, familySpend } from './familyTotals'
import { readCostPer, readMoney, readRoas } from '@/lib/money/contract'

/** The two KPI keys that are money rather than a quantity or a rate. */
const MONEY_KPIS = new Set(['spend', 'revenue'])
import { SavedViewsBar } from '@/features/dashboard/SavedViewsBar'
import { useSavedViews, type SavedView } from '@/features/dashboard/savedViews'
import { MetricStrip } from '@/components/ui/MetricStrip'
import { UnifiedCampaignOverview } from '@/features/campaigns/overview/UnifiedCampaignOverview'
import { useOverviewVm } from '@/features/campaigns/overview/useOverviewVm'
import { SPECS, dashboardMetrics, layoutFor } from './metricCatalog'
import { FilterBar, FilterChips, FilterMulti, FilterSelect, type AppliedFilter } from '@/components/ui/FilterBar'
import { FilterPlatforms } from '@/components/ui/FilterPlatforms'
import { PageIntro } from '@/components/ui/PageIntro'
import { listProjects } from '@/features/projects/api'
import { canonicalPlatform, sortPlatforms } from '@/lib/platforms'
import {
  CANONICAL_OBJECTIVE_KEYS,
  canonicalObjectiveLabel,
  rawObjectivesFor,
  type CanonicalObjectiveKey,
} from '@/features/campaigns/canonicalObjectives'
import { providerLabel } from '@/features/campaigns/labels'

/** The six platforms this product unifies, in the product's own order (PLATFORM-ORDER-001). */
const ANALYTICS_PLATFORMS = sortPlatforms(['meta', 'google_ads', 'tiktok', 'snapchat', 'x', 'linkedin'])

/** The objectives with a layout elsewhere in the product — the same six the dashboard offers. */
import { accounts as countedAccounts, countedAr, countedEn, days as countedDays } from '@/lib/counted'
import { useUi } from '@/stores/ui'
import { SyncStatusPill } from '@/components/ui/SyncStatusPill'
import { useProject } from '@/stores/project'
import { LivePerformanceNotice } from '@/features/disclaimers/PerformanceNotice'
import { useQuery } from '@tanstack/react-query'
import { StoreFunnelTab } from './StoreFunnelTab'
import { AttributionPanel } from './AttributionPanel'
import { DiagnosticPanel } from './DiagnosticPanel'
import { AdPoster } from '@/features/content/AdPoster'
import { AdPreviewDialog } from '@/features/content/AdPreviewDialog'
import {
  creativeScope, decodePath, drillInto, drillUpTo, encodePath, nextLevel, parentFor, rememberName,
  stepLabel, withNames,
  type DrillLevel, type DrillStep,
} from './drilldown'

/*
 * UX-ANALYTICS-TABS-001 — twelve tabs on one line, and six of them began with the same word.
 *
 * «تحليل المنصات · تحليل الحسابات · تحليل الحملات · تحليل المجموعات الإعلانية · تحليل الإعلانات ·
 * تحليل الأهداف · تحليل المحتوى». The repeated «تحليل» is on a page already titled «التحليلات», so
 * it carried no information and cost roughly a third of the bar's width — which is why the row
 * wrapped and every label competed with every other.
 *
 * Two changes, and neither removes a tab: the noun stands alone, and the tabs are grouped by the
 * QUESTION they answer. The groups matter more than the shortening — five of these tabs are one
 * hierarchy (platform → account → campaign → ad set → ad → creative), and a flat row gave a reader
 * no way to see that the thing they wanted was one level up from where they were looking.
 */
const TAB_GROUPS = [
  {
    key: 'overview',
    ar: 'الأداء', en: 'Performance',
    tabs: [
      { id: 'performance', ar: 'نظرة عامة', en: 'Overview' },
      { id: 'objective', ar: 'الأهداف', en: 'Objectives' },
      { id: 'budget', ar: 'الميزانية', en: 'Budget' },
    ],
  },
  {
    // The drill-down, in the order the platforms themselves nest it.
    key: 'breakdown',
    ar: 'التفصيل', en: 'Breakdown',
    tabs: [
      { id: 'platforms', ar: 'المنصات', en: 'Platforms' },
      { id: 'accounts', ar: 'الحسابات', en: 'Accounts' },
      { id: 'campaigns', ar: 'الحملات', en: 'Campaigns' },
      { id: 'ad_sets', ar: 'المجموعات', en: 'Ad sets' },
      { id: 'ads', ar: 'الإعلانات', en: 'Ads' },
      { id: 'creative', ar: 'الإعلان', en: 'Ad' },
    ],
  },
  {
    key: 'conversion',
    ar: 'التحويل', en: 'Conversion',
    tabs: [
      { id: 'funnel', ar: 'القمع', en: 'Funnel' },
      { id: 'store', ar: 'المتجر', en: 'Store' },
    ],
  },
  {
    key: 'trust',
    ar: 'الجودة', en: 'Quality',
    tabs: [{ id: 'quality', ar: 'جودة البيانات والإسناد', en: 'Data quality & attribution' }],
  },
] as const

/**
 * Flattened, so nothing that had a tab before has lost one — asserted in the test.
 *
 * Typed through a widening alias rather than inferred: `flatMap` over a `readonly` tuple of tuples
 * infers each group's own tuple type, and TypeScript will not union six of those into one element
 * type on its own.
 */
type AnalyticsTab = { readonly id: string; readonly ar: string; readonly en: string }

const TABS: readonly AnalyticsTab[] = TAB_GROUPS.flatMap((g) => g.tabs as readonly AnalyticsTab[])

const axis = { stroke: 'var(--text-muted)', fontSize: 12 }

/** The reader's language. Each tab below is its own component, so each one asks. */
const useAr = () => useUi((u) => u.locale) === 'ar'

/**
 * `/app/analytics` and `/agency/analytics` — UX-SWEEP-001.
 *
 * The page could be narrowed by ONE axis: the period. Everything else it can answer — which
 * platform, which campaign, which objective, which marketing path — was reachable only by going to
 * the dashboard, setting it there, and coming back. So the product's deepest analysis surface was
 * also its least filterable, which is the wrong way round.
 *
 * The filters live here rather than in each tab because they must narrow every tab at once: a
 * platform chosen on «Platform analysis» that reset itself on «Conversions & funnel» would be a
 * control that appears to work and does not.
 */
/**
 * ANALYTICS-AS-DASHBOARD-001 — this page is also «لوحة التحكم».
 *
 * The two surfaces had grown into the same thing said twice: a project picker, a period, platform
 * chips, a path and an objective, over a KPI strip. The dashboard's copy carried the operational
 * detail; this one carried the depth — twelve tabs down to ad level, the funnel, the store and the
 * attribution. Keeping both meant the same question could be asked on two screens and answered
 * differently, which is exactly the class of defect this codebase keeps finding.
 *
 * So the depth is the dashboard, and the operational overview moves INTO its first tab rather than
 * being deleted with it.
 *
 * `surface` exists because the filter controls are addressed by testid, and those ids are a contract
 * the suite holds against `/app/dashboard`. Mounting the same component under both routes with a
 * prefixed id keeps that contract literal instead of renaming assertions to follow an implementation
 * detail.
 */
export function AnalyticsPage({ surface = 'analytics' }: { surface?: 'analytics' | 'dashboard' } = {}) {
  const ar = useAr()
  const { currentProjectId, setCurrentProjectId } = useProject()
  /*
   * ANALYTICS-FILTER-TRUTH-001 — the filters live in the URL, so a refresh, Back and a shared link
   * all show the same page. They were `useState` only: narrowing to one platform and one objective
   * and then reloading gave the unfiltered page back, and the link a reader sent a colleague showed
   * that colleague a different answer to the question they were discussing.
   */
  const [days, setDays] = useUrlNumber('days', 30)
  const [tab, setTab] = useUrlState('tab', 'performance') as [(typeof TABS)[number]['id'], (v: string) => void]
  const [providers, setProviders] = useUrlList('provider')
  const [campaignIds, setCampaignIds] = useUrlList('campaign')
  const [objectiveRaw, setObjective] = useUrlState('objective', 'all')
  const objective = objectiveRaw as CanonicalObjectiveKey | 'all'

  /*
   * ANALYTICS-AS-DASHBOARD-001 — saved views came WITH the dashboard.
   *
   * They lived behind «مزيد من الفلاتر» on the old board and the analytics filter bar had no such
   * drawer, so mounting this page at `/app/dashboard` would have quietly removed a feature people
   * had already saved into. The default view is applied once on arrival, exactly as before.
   */
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
  const range = useLastNDaysRange(days)

  const projectsQuery = useQuery({ queryKey: ['projects', 'list'], queryFn: () => listProjects(false), retry: false })
  const projects = projectsQuery.data ?? []

  /*
   * ANALYTICS-OBJECTIVE-SYSTEM-001 — one control, and it sends RAW objectives.
   *
   * The metrics API filters by raw `CampaignObjective` values, so a canonical choice has to expand
   * into all of them. Sending the canonical key itself would narrow the label and leave the query
   * untouched — the KPI row would move and the chart beneath it would not, which is the frontend-only
   * filtering ANALYTICS-FILTER-TRUTH-001 forbids.
   */
  const objectiveFilter = useMemo(() => rawObjectivesFor(objective), [objective])

  const filters: MetricFilters = useMemo(
    () => ({ provider: providers, objective: objectiveFilter, campaign: campaignIds }),
    [providers, objectiveFilter, campaignIds],
  )

  /*
   * The campaign axis reads the server's option endpoint, NOT the breakdown.
   *
   * It is unnarrowed by campaign, so choosing one does not collapse the list it was chosen from —
   * and unwindowed by the period, so a campaign that reported nothing in the range is still
   * reachable. That silence is usually why the reader came looking for it.
   */
  const campaignSource = useCampaignOptionSource(currentProjectId, campaignIds)


  const applied: AppliedFilter[] = useMemo(() => {
    const out: AppliedFilter[] = []
    providers.forEach((v) => out.push({
      key: `provider:${v}`,
      axis: ar ? 'المنصة' : 'Platform',
      label: providerLabel(canonicalPlatform(v), ar ? 'ar' : 'en'),
      onRemove: () => setProviders((prev) => prev.filter((x) => x !== v)),
    }))
    campaignIds.forEach((v) => out.push({
      key: `campaign:${v}`,
      axis: ar ? 'الحملة' : 'Campaign',
      label: campaignSource.labelOf(v),
      onRemove: () => setCampaignIds((prev) => prev.filter((x) => x !== v)),
    }))
    if (objective !== 'all') {
      out.push({ key: `objective:${objective}`, axis: ar ? 'الهدف' : 'Objective', label: canonicalObjectiveLabel(objective, ar ? 'ar' : 'en'), onRemove: () => setObjective('all') })
    }
    return out
  }, [providers, campaignIds, campaignSource, objective, ar])

  /*
   * ANALYTICS-PROVENANCE-001 — the badge needs the summary, and the summary lives in the tab below.
   *
   * React Query dedupes on the key, so asking here costs no extra request: the Performance tab's own
   * `useSummary` with the same project, range and filters resolves to the same cached entry.
   */
  const provenanceSummary = useSummary(currentProjectId, range, filters)

  return (
    <div className="space-y-5">
      {/*
        The page names the surface the reader arrived at, not the file it lives in.

        One component now answers both «لوحة التحكم» and «التحليلات». Left as it was, clicking the
        first rail item — the one most people open first — landed on a heading saying «التحليلات»,
        which reads as a mis-click. Same board, two doors, and each door says where it went.
      */}
      <PageIntro
        testid={`${surface}-intro`}
        title={surface === 'dashboard' ? (ar ? 'لوحة التحكم' : 'Dashboard') : (ar ? 'التحليلات' : 'Analytics')}
        purpose={surface === 'dashboard'
          ? (ar
              ? 'حالة الحساب الآن: الإنفاق والنتائج والعائد، ثم المنصات والحملات والقمع — كل رقم بأساسه.'
              : 'Where the account stands: spend, results and return, then platforms, campaigns and the funnel — every figure with its basis.')
          : ar
            ? 'استكشاف تفصيلي للأداء: المنصات، الحملات، القمع، المتجر، الميزانيات، وأساس كل رقم.'
            : 'A detailed look at performance — platforms, campaigns, the funnel, the store, budgets, and the basis of every figure.'}
        badges={<ProvenanceBadge provenance={provenanceSummary.data?.provenance} />}
      />

      <FilterBar
        id={surface}
        ar={ar}
        applied={applied}
        onReset={() => { setProviders([]); setCampaignIds([]); setObjective('all') }}
        advancedActive={savedViews.data?.some((v) => v.is_default) ?? false}
        advanced={
          <div className="grid gap-2">
            <span className="text-xs font-bold uppercase tracking-wide text-text-muted">{ar ? 'العروض المحفوظة' : 'Saved views'}</span>
            <SavedViewsBar current={{ objective, providers, days }} onApply={applyView} />
          </div>
        }
      >
        <FilterChips
          label={ar ? 'الفترة' : 'Period'}
          value={String(days)}
          testid={`${surface}-period`}
          options={[
            { value: '7', label: ar ? '7 أيام' : '7 days' },
            { value: '30', label: ar ? '30 يوم' : '30 days' },
            { value: '90', label: ar ? '90 يوم' : '90 days' },
          ]}
          onChange={(v) => setDays(Number(v))}
        />

        {/*
          Present from the first paint — CLICK-STABLE-001. Gated on the query, this control landed
          late, wrapped the bar onto a second row, and took the tab strip below it down 68px with it.
          A tab that moves between a press and its release is never clicked at all, which is how the
          quality tab «did not open» on firefox while every other browser passed.
        */}
        <FilterSelect
          label={ar ? 'المشروع' : 'Project'}
          value={currentProjectId ?? ''}
          testid={`${surface}-project`}
          options={[
            ...(currentProjectId ? [] : [{ value: '', label: ar ? 'لا مشروع' : 'No project' }]),
            ...projects.map((pr) => ({ value: pr.id, label: pr.name })),
          ]}
          onChange={setCurrentProjectId}
        />

        {/* UX-FILTERS-001 — the same visible chips the dashboard uses, and the same colours the
            charts below key off. One filter bar, one vocabulary, across both pages. */}
        <FilterPlatforms
          label={ar ? 'المنصة' : 'Platform'}
          allLabel={ar ? 'الكل' : 'All'}
          values={providers}
          testid={`${surface}-platform`}
          options={ANALYTICS_PLATFORMS.map((key) => ({ value: canonicalPlatform(key), label: providerLabel(canonicalPlatform(key), ar ? 'ar' : 'en') }))}
          onChange={setProviders}
        />

        <FilterMulti
          label={ar ? 'الحملة' : 'Campaign'}
          ar={ar}
          values={campaignIds}
          testid={`${surface}-campaign`}
          options={campaignSource.options}
          search={campaignSource.search}
          onChange={setCampaignIds}
        />

        

        <FilterSelect
          label={ar ? 'الهدف' : 'Objective'}
          value={objective}
          testid={`${surface}-objective`}
          options={[
            { value: 'all', label: ar ? 'كل الأهداف' : 'All objectives' },
            ...CANONICAL_OBJECTIVE_KEYS.map((key) => ({ value: key, label: canonicalObjectiveLabel(key, ar ? 'ar' : 'en') })),
          ]}
          onChange={(v) => setObjective(v as CanonicalObjectiveKey | 'all')}
        />
      </FilterBar>

      {/*
        The groups are separated by a rule rather than by a heading — a heading over each cluster
        would add four more lines of text to a bar whose problem was too much text. The gap plus the
        divider is enough to read them as clusters, and the labels stay the only words in the row.
      */}
      <div
        role="tablist"
        aria-label={ar ? 'أقسام التحليلات' : 'Analytics sections'}
        /*
         * `flex-wrap` AND `overflow-x-auto` was a contradiction: a wrapping row never scrolls, so on
         * a phone eleven tabs became three stacked lines and the last one still sat under the edge.
         * One row that scrolls below `sm`, the wrap restored from `sm` up where the row fits.
         */
        className="flex snap-x snap-mandatory items-end gap-x-1 gap-y-1.5 overflow-x-auto border-b border-border pb-px [-ms-overflow-style:none] [scrollbar-width:none] sm:flex-wrap sm:snap-none [&::-webkit-scrollbar]:hidden"
      >
        {TAB_GROUPS.map((group, index) => (
          <div key={group.key} className="flex items-end">
            {index > 0 && <span aria-hidden className="mx-2 mb-2 h-4 w-px shrink-0 bg-border" />}
            <div className="flex items-end gap-0.5">
              {group.tabs.map((t) => (
                <button
                  key={t.id}
                  type="button"
                  role="tab"
                  aria-selected={tab === t.id}
                  onClick={() => setTab(t.id)}
                  className={`relative shrink-0 snap-start whitespace-nowrap rounded-t-lg px-3 py-3 text-sm font-semibold transition-colors sm:px-2.5 sm:py-2 ${
                    tab === t.id ? 'text-brand-600' : 'text-text-secondary hover:text-text-primary'
                  }`}
                >
                  {ar ? t.ar : t.en}
                  {tab === t.id && <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-brand-600" />}
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>

      {tab === 'performance' && <PerformanceTab projectId={currentProjectId} range={range} filters={filters} objective={objective} />}
      {tab === 'platforms' && <PlatformsTab projectId={currentProjectId} range={range} filters={filters} />}
      {tab === 'accounts' && <AccountsTab projectId={currentProjectId} range={range} filters={filters} />}
      {tab === 'campaigns' && <CampaignsTab projectId={currentProjectId} range={range} filters={filters} />}
      {tab === 'ad_sets' && <EntityTab projectId={currentProjectId} range={range} filters={filters} level="ad_set" />}
      {tab === 'ads' && <EntityTab projectId={currentProjectId} range={range} filters={filters} level="ad" />}
      {tab === 'objective' && <ObjectiveTab projectId={currentProjectId} range={range} filters={filters} />}
      {tab === 'creative' && <CreativeTab projectId={currentProjectId} range={range} filters={filters} />}
      {tab === 'funnel' && <FunnelTab projectId={currentProjectId} range={range} filters={filters} />}
      {tab === 'store' && <StoreFunnelTab projectId={currentProjectId} range={range} />}
      {tab === 'budget' && <BudgetTab projectId={currentProjectId} range={range} filters={filters} />}
      {tab === 'quality' && <QualityTab projectId={currentProjectId} range={range} filters={filters} />}

      <LivePerformanceNotice variant="compact" />
    </div>
  )
}

type TabProps = { projectId: string | null; range: { from: string; to: string }; filters: MetricFilters }

/** The overview tab also needs the objective, because its KPI row is chosen BY the objective. */
type OverviewTabProps = TabProps & { objective: string }

/*
 * The store tab takes no filters, and that is the same rule the dashboard's store strip follows:
 * spend narrows to a platform and an order does not — a large share of orders carry no attribution
 * at all, so «Meta's share of the shop's revenue» is not a quantity that exists.
 */

function PerformanceTab({ projectId, range, filters, objective }: OverviewTabProps) {
  const ar = useAr()
  const s = useSummary(projectId, range, filters)
  const ts = useTimeseries(projectId, range, filters)
  const campaigns = useCampaigns(projectId, range, filters)
  const platformRows = usePlatforms(projectId, range, filters)
  const freshness = useFreshness(projectId, range, filters)
  const budget = useBudget(projectId, range, filters)

  const reportingCurrency = s.data?.currency ?? null
  const points = ts.data ?? []

  /*
   * ANALYTICS-TRUTH-002 — the charts read the same source the cards read.
   *
   * `points` carries the aggregator's coalesced zeros. Everything plotted below comes from this
   * reading instead, so a line and the card above it cannot disagree about the same window.
   */
  const series = plotSeries(points, reportingCurrency, ar)
  const chartCurrency = series.currency ?? reportingCurrency ?? 'SAR'

  /*
   * ANALYTICS-COMPARE-001 — six mute dashes were the page's way of saying «there is no yesterday».
   *
   * Production holds 15 days of rows and offers a 30-day range, so the entire comparison window sits
   * before the first row that exists. Every delta divided by zero and came back null, and each card
   * printed «— —» beneath a heading promising a comparison — indistinguishable from a month that did
   * not move. When the comparison window is empty the pills are not rendered at all and the page
   * says why once, above the strip.
   */
  const comparable = s.data?.previous_rows_in_scope !== false


  /*
   * ANALYTICS-AS-DASHBOARD-001 — the headline row is chosen BY the objective.
   *
   * Six fixed cards — spend, revenue, ROAS, results, CPA, CTR — answer a sales campaign well and an
   * awareness campaign not at all: they report a return on ad spend for a campaign that was never
   * bought to return anything, and never mention reach or frequency, which is what it WAS bought
   * for. `dashboardMetrics` picks the row the objective is actually judged on, and it is the same
   * function and the same `MetricStrip` the dashboard used, not a second reading of the same totals.
   */
  const metrics = useMemo(() => dashboardMetrics(objective, s.data, ar), [objective, s.data, ar])

  /*
   * With no comparison window, a delta is not «unchanged» — it does not exist. `undefined` removes
   * the pill; `null` would still render the «— —» this is here to remove.
   */
  const strip = useMemo(
    () =>
      comparable
        ? metrics
        : {
            primary: metrics.primary.map((m) => ({ ...m, delta: undefined })),
            secondary: metrics.secondary.map((m) => ({ ...m, delta: undefined })),
          },
    [metrics, comparable],
  )

  const vm = useOverviewVm({
    campaigns: campaigns.data,
    platforms: platformRows.data,
    freshness: freshness.data,
    budget: budget.data,
    currency: reportingCurrency,
    source: s.data?.provenance?.source,
    ar,
  })

  return (
    <div className="space-y-4">
      {!comparable && s.data && (
        <p
          data-testid="no-comparison-period"
          className="rounded-lg border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary"
        >
          <span className="font-semibold text-text-primary">{ar ? 'لا توجد مقارنة: ' : 'No comparison: '}</span>
          {ar
            ? `الفترة السابقة (${s.data.previous_range.from} → ${s.data.previous_range.to}) لا تحتوي أي بيانات، فلا يوجد شيء تُقاس عليه هذه الفترة.`
            : `The previous period (${s.data.previous_range.from} → ${s.data.previous_range.to}) holds no data, so there is nothing for this one to be measured against.`}
        </p>
      )}
      <MetricStrip
        id="dashboard"
        ar={ar}
        primary={strip.primary}
        secondary={strip.secondary}
        hasRows={s.data === undefined ? undefined : s.data.rows_in_scope}
        /*
          METRICS-REQUEST-STATE-001 — and a request that failed or has not answered says so.

          `data` is undefined for a failure and for a load alike, so without these the row rendered with
          nothing to read and every card printed «لا توجد بيانات» — a confident statement about this
          account's advertising, made by a request that never came back.
        */
        loading={s.isPending}
        error={s.isError ? s.error : undefined}
        onRetry={() => void s.refetch()}
      />
      {/*
       * ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — directly beneath the figures that raise the question.
       *
       * The strip above says WHAT happened. This says where along the journey it went wrong, reading
       * the same `useSummary` entry the strip read — the same totals and the same `reported` map, so
       * the two cannot disagree about the account they are both describing. The request state is
       * forwarded rather than re-derived for the same reason.
       */}
      <DiagnosticPanel
        objective={objective}
        totals={s.data?.current as Record<string, number | null | undefined> | undefined}
        reported={s.data?.reported}
        rowsInScope={s.data?.rows_in_scope}
        loading={s.isPending}
        error={s.isError ? s.error : undefined}
        onRetry={() => void s.refetch()}
        ar={ar}
      />
      {/* The comparisons, the details and the alerts — «أ», shared with the marketing preview. */}
      <UnifiedCampaignOverview vm={vm} lang={ar ? 'ar' : 'en'} />
      {/*
       * REPORT-OBJECTIVE-005 — «النتائج» above is the SUM of what each platform claimed.
       *
       * One sale clicked from two platforms is reported in full by both, and there is no shared key
       * that would prove they are the same sale — so the figure is not a count of unique orders, and
       * it must not be read as one. The sentence comes from the API rather than being written here, so
       * the dashboard, the report and the client's link cannot end up saying different things about
       * the same number. Shown only when more than one platform contributed: a single platform cannot
       * overlap with itself, and a warning about an impossible risk trains readers to ignore warnings.
       */}
      {s.data?.conversions_basis.may_double_count && (
        <p
          data-testid="conversions-basis"
          className="rounded-lg border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary"
        >
          <span className="font-semibold text-text-primary">{ar ? '«النتائج»: ' : 'Results: '}</span>
          {ar ? s.data.conversions_basis.note_ar : s.data.conversions_basis.note_en}
        </p>
      )}
      {/*
        ANALYTICS-TRUTH-002 — the chart the KPI strip contradicted.

        It plotted `dataKey="spend"` off the raw row and withdrew both money lines whenever the
        window's money was withheld, leaving a single «النتائج» line under a title naming three. The
        money was never missing — it was unconverted, and the card above already stated it. Both
        lines are drawn from the same reading, in whatever currency that reading is honestly in, and
        the axis says which.
      */}
      <Panel
        title={ar ? 'الإنفاق والنتائج والإيرادات' : 'Spend, results and revenue'}
        description={
          series.basis === 'original'
            ? ar
              ? `المال معروض بعملة المنصة (${series.currency}) — ${series.note ?? ''}`
              : `Money shown in the platform's own currency (${series.currency}) — ${series.note ?? ''}`
            : ar
              ? 'الاتجاه اليومي للإنفاق والإيرادات والنتائج'
              : 'Spend, revenue and results, day by day'
        }
        loading={ts.isLoading}
        error={ts.isError}
        empty={!ts.isLoading && series.rows.length === 0}
      >
        <div className="h-80">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={series.rows} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis dataKey="date" tick={axis} tickFormatter={(v) => String(v).slice(5)} minTickGap={24} />
              {/*
                Money and counts are different units, so they get different axes. Drawing 4,787 USD
                and 218 results against one scale flattens the smaller series onto the floor — which
                is the shape the old chart had even before the money went missing.
              */}
              <YAxis yAxisId="money" tick={axis} tickFormatter={(v) => compact(Number(v))} width={52} />
              <YAxis yAxisId="count" orientation={ar ? 'left' : 'right'} tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
              <Tooltip
                {...tooltipProps}
                formatter={(v: number, name: string) =>
                  name === (ar ? 'النتائج' : 'Results') ? num(v) : money(v, chartCurrency)
                }
              />
              <Legend wrapperStyle={{ fontSize: 13 }} />
              {series.hasMoney && series.basis !== 'mixed' && (
                <Line yAxisId="money" name={ar ? 'الإنفاق' : 'Spend'} type="monotone" dataKey="spend" stroke={SERIES.spend} strokeWidth={2} dot={false} connectNulls />
              )}
              {series.hasMoney && series.basis !== 'mixed' && (
                <Line yAxisId="money" name={ar ? 'الإيرادات' : 'Revenue'} type="monotone" dataKey="revenue" stroke={SERIES.revenue} strokeWidth={2} dot={false} connectNulls />
              )}
              <Line yAxisId="count" name={ar ? 'النتائج' : 'Results'} type="monotone" dataKey="conversions" stroke={SERIES.conversions} strokeWidth={2} dot={false} connectNulls />
            </LineChart>
          </ResponsiveContainer>
        </div>
        {series.basis === 'mixed' && (
          <p data-testid="series-currency-mixed" className="mt-2 text-xs text-text-secondary">{series.note}</p>
        )}
      </Panel>
      {/*
        Three metrics, three units — «3.20x», «21.96 USD» and «0.72%» share no axis. On one scale the
        two small numbers lie flat on the floor and the chart says nothing, which is what shipped:
        a single line at zero under a title naming three metrics, one of which was never plotted.

        Each gets its own panel and its own scale, so each is readable.
      */}
      <div className="grid gap-3 lg:grid-cols-3">
        <RateTrend title="ROAS" data={series.rows} dataKey="roas" color={SERIES.revenue} loading={ts.isLoading} error={ts.isError} format={(v: number) => ratio(v)} />
        <RateTrend title="CPA" data={series.rows} dataKey="cpa" color={SERIES.conversions} loading={ts.isLoading} error={ts.isError} format={(v: number) => money(v, chartCurrency)} />
        <RateTrend title="CTR" data={series.rows} dataKey="ctr" color={SERIES.spend} loading={ts.isLoading} error={ts.isError} format={(v: number) => `${v.toFixed(2)}%`} />
      </div>
    </div>
  )
}


/**
 * Each platform inside each marketing path — PLATFORM-DECISION-ANALYTICS-001.
 *
 * The shape is the argument. There is no list here that contains platforms from two paths, because a
 * «best platform» card can only be written against one, and a platform buying awareness is not worse
 * than one buying sales — what differs is the work each was given.
 *
 * A path where one platform spent shows its figures and NO comparison: «Meta is the best platform
 * for awareness», said of the only platform that ran awareness, is a sentence with no evidence
 * behind it, and it is exactly what a reader writes for themselves when handed a sorted list of one.
 */
function PlatformPaths({
  data,
  freshness,
  currency,
  loading,
  error,
  ar,
}: {
  data: PlatformObjectives | undefined
  /**
   * MONEY-USD-001 — the currency these figures were measured in, passed rather than assumed.
   *
   * Every money figure in this block used `money()` with no currency, so it printed the helper's
   * default — «6.67 SAR» beside an account denominated in USD. Nothing converted it and nothing
   * claimed a rate: the unit was simply invented, on a page whose Arabic locale made that look
   * deliberate. Null where the scope has not stated one; the figure then goes out without a unit,
   * which is incomplete rather than wrong.
   */
  currency: string | null
  /**
   * PLATFORM-DECISION-ANALYTICS-001 — a comparison between a complete platform and a half-reported
   * one is not a comparison, and the reader cannot see the difference from the figures.
   *
   * The same rows the data-quality tab reads, on the surface where the comparison is actually made.
   * Stating it here is the point: sending somebody to another tab to find out whether the ranking
   * they are looking at is sound means they will read the ranking.
   */
  freshness: FreshnessRow[]
  loading: boolean
  error: boolean
  ar: boolean
}) {
  const REASON: Record<string, { ar: string; en: string }> = {
    only_one_platform_spent: {
      ar: 'منصة واحدة فقط أنفقت على هذا المسار — رقم، وليس مقارنة.',
      en: 'Only one platform spent on this path — a figure, not a comparison.',
    },
    nothing_spent_on_this_path: {
      ar: 'لم يُنفق شيء على هذا المسار في هذه الفترة.',
      en: 'Nothing was spent on this path in this period.',
    },
  }

  const paths = (data?.paths ?? []).filter((p) => p.platforms.length > 0)
  /* The range each path's own rows imply — computed once, keyed by path. */
  const readings = new Map(platformReadings(paths).map((r) => [r.path, r]))

  /** What this window is missing, per platform — keyed the way the payload names them. */
  const gaps = new Map<string, { missing: number; status: string | null }>()
  for (const row of freshness) {
    const key = canonicalPlatform(row.provider)
    const prior = gaps.get(key)
    const missing = (prior?.missing ?? 0) + (row.missing_days ?? 0)
    const status = row.last_sync_status === 'fresh' ? (prior?.status ?? null) : (row.last_sync_status ?? null)
    gaps.set(key, { missing, status })
  }

  return (
    <Panel
      title={ar ? 'مساهمة المنصات حسب الهدف' : 'Platform contribution by objective'}
      description={ar
        ? 'داخل كل مسار، لا عبره — المقارنة الوحيدة التي تصحّ'
        : 'Inside each path, never across them — the only comparison that holds'}
      loading={loading}
      error={error}
      empty={!loading && paths.length === 0}
    >
      <div data-testid="platform-paths" className="flex flex-col gap-4">
        {paths.map((path) => (
          <div key={path.path} data-testid={`platform-path-${path.path}`} className="rounded-xl border border-border p-3.5">
            <div className="mb-2 flex flex-wrap items-center gap-2">
              <span className="text-sm font-bold text-text-primary">{ar ? path.label_ar : path.label_en}</span>
              <span className="text-[11px] text-text-muted" dir="ltr">{money(path.spend, currency)}</span>
              {!path.comparable && (
                <span
                  data-testid={`platform-path-${path.path}-not-comparable`}
                  className="rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] text-text-secondary"
                >
                  {ar ? REASON[path.comparable_reason]?.ar : REASON[path.comparable_reason]?.en}
                </span>
              )}
            </div>

            {/*
              FUNNEL-ANALYTICAL-PATTERN-001 — the range this list implies, said once above it.

              The rows carry each platform's cost; which of them is dearest, and by how much, is the
              question the reader was left to answer from the column. It is stated inside ONE path
              and on ONE metric, because a range with two units is not a range and a comparison
              across paths is the one this section exists to refuse.
            */}
            {(() => {
              const reading = readings.get(path.path)

              if (reading === undefined) return null

              /*
               * INSUFFICIENT-DATA-EXPLAINED-001 — the silence says WHICH silence.
               *
               * `platformReadings` has always computed why it could not state a range — the path's
               * platforms are not comparable, or no platform reported the cost this path is judged
               * on — and this rendered nothing at all. An absent sentence where the other paths have
               * one reads as a page that failed to load, and the reader goes looking for a sync
               * problem. Both reasons are ordinary, and both are actionable in completely different
               * ways.
               */
              if (!('cheapest' in reading)) {
                return (
                  <p data-testid={`platform-path-${path.path}-silent`} className="mb-2 text-xs text-text-muted">
                    {reading.silentReason === 'not_comparable'
                      ? (ar
                          ? 'منصات هذا المسار غير قابلة للمقارنة على مقياس واحد، فلا يُذكر فارق.'
                          : 'The platforms on this path are not comparable on one metric, so no spread is stated.')
                      : (ar
                          ? 'لم تُبلِّغ أي منصة عن التكلفة التي يُقاس بها هذا المسار في هذه الفترة.'
                          : 'No platform reported the cost this path is judged on in this window.')}
                  </p>
                )
              }

              /*
               * A spread of 1× is «they cost the same», which is a real answer and not a silence —
               * saying it is worth more than the sentence costs, because a reader who sees nothing
               * assumes the comparison was not made.
               */
              if (reading.spread <= 1) {
                return (
                  <p data-testid={`platform-path-${path.path}-reading`} className="mb-2 text-xs text-text-secondary">
                    {ar
                      ? 'تتقارب تكلفة المنصات على هذا المسار — لا فارق يُذكر.'
                      : 'The platforms on this path cost about the same — no meaningful spread.'}
                  </p>
                )
              }

              return (
                <p data-testid={`platform-path-${path.path}-reading`} className="mb-2 text-xs text-text-secondary">
                  {ar
                    ? `${providerLabel(reading.dearest.provider, 'ar')} أغلى ${reading.spread}× من ${providerLabel(reading.cheapest.provider, 'ar')} على هذا المسار.`
                    : `${providerLabel(reading.dearest.provider, 'en')} costs ${reading.spread}× what ${providerLabel(reading.cheapest.provider, 'en')} costs on this path.`}
                </p>
              )
            })()}

            <ul className="flex flex-col gap-1">
              {path.platforms.map((row) => {
                const efficiency = efficiencyFor(path.path, row)
                const returned = returnFor(path.path, row)
                const gap = gaps.get(canonicalPlatform(row.provider))

                return (
                  <li key={row.provider} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-sm">
                    <span className="flex items-center gap-2">
                      <PlatformCell provider={row.provider} />
                      {/*
                        The gap, beside the platform it belongs to. A platform three days short in
                        this window is not a platform that performed worse, and the share above says
                        nothing about which of the two it is.
                      */}
                      {gap !== undefined && (gap.missing > 0 || gap.status !== null) && (
                        <span
                          data-testid={`path-gap-${path.path}-${row.provider}`}
                          className="rounded-full bg-warning/10 px-2 py-0.5 text-[11px] text-warning"
                        >
                          {gap.missing > 0
                            ? (ar ? `ناقص ${countedDays(gap.missing, 'ar')}` : `${countedDays(gap.missing, 'en')} missing`)
                            : (ar ? 'آخر مزامنة لم تنجح' : 'last sync did not succeed')}
                        </span>
                      )}
                    </span>

                    <span className="flex flex-wrap items-center gap-3">
                      <span className="tnum text-text-secondary" dir="ltr">{money(row.spend, currency)}</span>
                      {/* Share of the PATH. «40% of awareness» is a decision somebody made; «40% of
                          everything» is the mix, and reading the second as performance is the defect. */}
                      <span className="tnum text-text-muted" dir="ltr">
                        {row.spend_share === null ? '—' : percent(row.spend_share, 0)}
                      </span>

                      {/*
                        The cost that names what this path was BUYING — never a fixed four columns
                        with two of them empty, which is the cross-objective comparison this section
                        exists to refuse, printed one column at a time.
                      */}
                      <span
                        data-testid={`path-efficiency-${path.path}-${row.provider}`}
                        className="tnum text-[11px] text-text-muted"
                        dir="ltr"
                      >
                        {(ar ? efficiency.labelAr : efficiency.labelEn)}
                        {' '}
                        {/*
                          `moneyExact`, not `money`: a per-unit cost is where rounding lies. `money`
                          compacts, and a cost per thousand of 6.67 printed «7 SAR» — a seven per cent
                          error in the figure the comparison is actually about.
                        */}
                        {efficiency.value === null ? '—' : moneyExact(efficiency.value, currency)}
                      </span>

                      {returned.value !== null && (
                        <span className="tnum text-[11px] text-text-muted" dir="ltr">
                          {ratio(returned.value)}
                        </span>
                      )}
                    </span>
                  </li>
                )
              })}
            </ul>
          </div>
        ))}

        {/*
          Said on the page, not only in the payload. It is the sentence a «best platform» card would
          have to contradict in order to exist.
        */}
        <p data-testid="platform-paths-cross" className="text-[11px] text-text-tertiary">
          {ar ? data?.cross_path_reason_ar : data?.cross_path_reason_en}
        </p>
      </div>
    </Panel>
  )
}

function PlatformsTab({ projectId, range, filters }: TabProps) {
  const ar = useAr()
  const p = usePlatforms(projectId, range, filters)
  const byPath = usePlatformObjectives(projectId, range, filters)
  const rows = p.data ?? []
  /*
   * PLATFORM-DECISION-ANALYTICS-001 — the chart above compares platforms across the whole
   * programme, which answers «how is each platform doing» and cannot answer «which platform is
   * contributing most to THIS objective». The path blocks below answer that, inside each path,
   * and say so where a path cannot support a comparison at all.
   */
  const leaders = useObjectiveLeaders(projectId, range, filters)
  const explanations = useObjectiveExplanations(projectId, range, filters)
  const summary = useSummary(projectId, range, filters)
  /* The same rows the data-quality tab reads — the comparison is made here, so the gaps are said here. */
  const freshness = useFreshness(projectId, range, filters)

  return (
    <div className="space-y-4">
      {/*
        PLATFORM-DECISION-ANALYTICS-001 — «which platform is contributing most to THIS objective».
        Above the comparison chart, because that chart answers a different question: how each
        platform is doing over every objective at once. Read as a ranking it compares a platform
        buying awareness against one buying sales, which is a verdict about the work each was given.
      */}
      <PlatformPaths
        data={byPath.data}
        freshness={freshness.data ?? []}
        currency={summary.data?.currency ?? null}
        loading={byPath.isLoading}
        error={byPath.isError}
        ar={ar}
      />
      <Panel title={ar ? 'مقارنة المنصات' : 'Platform comparison'} description={ar ? 'الإنفاق مقابل ROAS لكل منصة' : 'Spend against ROAS, per platform'} loading={p.isLoading} error={p.isError} empty={!p.isLoading && rows.length === 0}>
        <div className="h-72">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={rows} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis dataKey="provider" tick={axis} />
              <YAxis yAxisId="l" tick={axis} tickFormatter={(v) => compact(Number(v))} width={44} />
              {/*
                The secondary axis follows the READER, like the one above it: an RTL page puts its
                primary axis on the right, so a second axis pinned to «right» lands on top of it and
                the chart reads as two scales for one side. And ROAS is a ratio — «3.6x» says what
                the bare 3.6 beside a spend axis does not.
              */}
              <YAxis
                yAxisId="r"
                orientation={ar ? 'left' : 'right'}
                tick={axis}
                tickFormatter={(v) => ratio(Number(v))}
                width={44}
              />
              <Tooltip {...tooltipProps} />
              <Legend wrapperStyle={{ fontSize: 13 }} />
              <Bar yAxisId="l" name={ar ? 'الإنفاق' : 'Spend'} dataKey="spend" radius={[6, 6, 0, 0]}>
                {rows.map((r) => (
                  <Cell key={r.provider} fill={platformColor(r.provider)} />
                ))}
              </Bar>
              <Line yAxisId="r" name="ROAS" type="monotone" dataKey="roas" stroke={SERIES.revenue} strokeWidth={2} dot />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </Panel>
      <Panel title={ar ? 'جدول المنصات' : 'Platforms table'} loading={p.isLoading} error={p.isError} empty={!p.isLoading && rows.length === 0}>
        <MetricTable
          head={ar ? ['المنصة', 'الإنفاق', 'النتائج', 'CPA', 'ROAS', 'CTR', 'CPM', 'المساهمة'] : ['Platform', 'Spend', 'Results', 'CPA', 'ROAS', 'CTR', 'CPM', 'Contribution']}
          rows={rows.map((r) => [
            <PlatformCell key="p" provider={r.provider} />,
            /*
             * MONEY-TRUTH-002 — per-row provenance, same contract as the summary cards.
             *
             * These read `money(r.spend)` on a field the aggregator coalesces to 0 when withheld, so
             * a platform that spent 4,128.93 USD ranked as having spent nothing, in a table directly
             * beneath a card showing the real figure. The rows now carry `*_withheld_rows` and
             * `*_original` (backend half of this unit), so the same reader serves both.
             */
            rowMoney(r, 'spend'),
            num(r.conversions),
            rowCostPer(r, 'cpa', 'conversions'),
            rowRoas(r),
            percent(r.ctr, 2),
            // Derived from spend, so it carried spend's withholding — missed when this row was fixed.
            rowCostPer(r, 'cpm', Number(r.impressions ?? 0) / 1000),
            percent(r.spend_share, 1),
          ])}
          /* The raw figures behind those cells, so the header can sort what the eye is comparing. */
          values={rows.map((r) => [
            providerLabel(canonicalPlatform(r.provider), ar ? 'ar' : 'en'),
            readMoney(r, 'spend', null, ar).amount,
            r.conversions ?? null,
            readCostPer(r, 'cpa', 'conversions', null, ar).amount,
            readRoas(r, ar).value,
            r.ctr ?? null,
            readCostPer(r, 'cpm', Number(r.impressions ?? 0) / 1000, null, ar).amount,
            r.spend_share ?? null,
          ])}
          initialSort={{ column: 1, dir: 'desc' }}
        />
      </Panel>

      {/*
        The contribution per path is `PlatformPaths` above; this is the half it does not answer —
        which campaign inside the path is carrying it, and what the figures say about the distance
        between the two ends.
      */}
      <PathAnalysis
        locale={ar ? 'ar' : 'en'}
        currency={summary.data?.currency ?? null}
        leaders={leaders.data?.paths ?? []}
        explanations={explanations.data?.paths ?? []}
        loading={leaders.isLoading}
        error={leaders.isError}
      />
    </div>
  )
}

function CampaignsTab({ projectId, range, filters }: TabProps) {
  const ar = useAr()
  const c = useCampaigns(projectId, range, filters)
  const rows = c.data ?? []
  const best = rows[0]
  const worst = [...rows].filter((r) => r.spend > 0).sort((a, b) => (a.roas ?? 0) - (b.roas ?? 0))[0]
  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <Panel title={ar ? 'أفضل حملة (ROAS)' : 'Best campaign (ROAS)'} loading={c.isLoading} error={c.isError}>
          {best && (
            <div>
              <div className="text-lg font-bold text-text-primary">{best.campaign_name}</div>
              <div className="mt-1 text-sm text-text-secondary">
                ROAS <span className="tnum font-semibold text-success">{rowRoas(best)}</span> · {ar ? 'إنفاق' : 'spend'} {rowMoney(best, 'spend')}
              </div>
            </div>
          )}
        </Panel>
        <Panel title={ar ? 'تحتاج مراجعة (أدنى ROAS)' : 'Needs a look (lowest ROAS)'} loading={c.isLoading} error={c.isError}>
          {worst && (
            <div>
              <div className="text-lg font-bold text-text-primary">{worst.campaign_name}</div>
              <div className="mt-1 text-sm text-text-secondary">
                ROAS <span className="tnum font-semibold text-danger">{rowRoas(worst)}</span> · {ar ? 'إنفاق' : 'spend'} {rowMoney(worst, 'spend')}
              </div>
            </div>
          )}
        </Panel>
      </div>
      <Panel title={ar ? 'ترتيب الحملات' : 'Campaign ranking'} description={ar ? 'مرتّبة حسب الإنفاق' : 'Ordered by spend'} loading={c.isLoading} error={c.isError} empty={!c.isLoading && rows.length === 0}>
        <MetricTable
          head={ar ? ['الحملة', 'المنصة', 'الإنفاق', 'الإيرادات', 'النتائج', 'CPA', 'ROAS'] : ['Campaign', 'Platform', 'Spend', 'Revenue', 'Results', 'CPA', 'ROAS']}
          rows={rows.map((r) => [
            <span key="n" className="font-semibold text-text-primary">{r.campaign_name ?? '—'}</span>,
            <PlatformCell key="p" provider={r.provider} />,
            /*
             * MONEY-TRUTH-002, continued — this table sits directly beneath the platform table that
             * was fixed for exactly this, and was left reading the raw fields. On an account whose
             * money is withheld every campaign ranked as having spent 0 with a 0.00× return, in a
             * ranking ordered BY spend, under a card stating the real total. Same rows, same
             * provenance fields, same readers.
             */
            rowMoney(r, 'spend'),
            rowMoney(r, 'revenue'),
            num(r.conversions),
            rowCostPer(r, 'cpa', 'conversions'),
            <span key="ro" className="tnum font-semibold">{rowRoas(r)}</span>,
          ])}
          values={rows.map((r) => [
            r.campaign_name ?? '',
            providerLabel(canonicalPlatform(r.provider), ar ? 'ar' : 'en'),
            readMoney(r, 'spend', null, ar).amount,
            readMoney(r, 'revenue', null, ar).amount,
            r.conversions ?? null,
            readCostPer(r, 'cpa', 'conversions', null, ar).amount,
            readRoas(r, ar).value,
          ])}
          initialSort={{ column: 2, dir: 'desc' }}
        />
      </Panel>
    </div>
  )
}

function FunnelTab({ projectId, range, filters }: TabProps) {
  const ar = useAr()
  const f = useFunnel(projectId, range, filters)
  const rows = f.data ?? []

  /*
   * FUNNEL-WITHHELD-001 (frontend half) — the unit the stage costs are in.
   *
   * Every `cost_per` divides the window's spend, and that spend is not always in the project's
   * currency: when no rate exists it is the platform's own. `money()` defaults to SAR, so «تكلفة
   * 22.03 SAR» appeared against dollars.
   *
   * Read from the summary rather than plumbed through the funnel's `meta`, because it is the same
   * spend over the same window and the money contract already answers «what currency is this
   * project's spend actually in» — a second path to the same answer is how two surfaces come to
   * disagree.
   */
  // Named `summary`, not `s`: the stage rows below are mapped as `s`, and the shadow compiles.
  const summary = useSummary(projectId, range, filters)
  const spendReading = readMoney(summary.data?.current as Record<string, unknown> | undefined, 'spend', summary.data?.currency ?? null, ar)
  const costCurrency = spendReading.currency ?? summary.data?.currency ?? undefined
  /*
   * FUNNEL-NULL-001 — scaled against the largest REPORTED count, not `rows[0].count`.
   *
   * A stage nobody sent has a null count, and `null / top` is 0 in JavaScript: the old line drew the
   * 8% minimum-width bar with «—» written inside it, so «this platform does not count basket adds»
   * and «almost nobody added to a basket» were the same picture. An unreported stage now gets no bar
   * and says so in words instead.
   */
  const reported = rows.map((s) => s.count).filter((c): c is number => c !== null)
  const top = reported.length > 0 ? Math.max(...reported) : 1
  const unreported = rows.filter((s) => !s.reported)
  return (
    <Panel title={ar ? 'قمع التحويل' : 'Conversion funnel'} description={ar ? 'الظهور ← النقرة ← صفحة الهبوط ← السلة ← الدفع ← الشراء' : 'Impression → Click → Landing → Add to cart → Checkout → Purchase'} loading={f.isLoading} error={f.isError} empty={!f.isLoading && rows.length === 0}>
      <div className="space-y-3">
        {rows.map((s, i) => (
          <div key={s.stage} className="flex items-center gap-3" data-testid={`ad-funnel-stage-${s.stage}`}>
            <span className="w-32 shrink-0 text-sm font-medium text-text-secondary">{funnelStageLabel(s.stage, s.label, ar)}</span>
            {s.count !== null ? (
              <div className="h-10 flex-1 overflow-hidden rounded-xl bg-surface-secondary">
                <div
                  className="flex h-full items-center justify-between rounded-xl px-3 text-sm font-semibold text-white"
                  style={{ width: `${Math.max(8, (s.count / top) * 100)}%`, background: `color-mix(in oklab, ${SERIES.spend} ${100 - i * 10}%, var(--brand-700))` }}
                >
                  <span className="tnum">{num(s.count)}</span>
                </div>
              </div>
            ) : (
              <div className="flex h-10 flex-1 items-center rounded-xl border border-dashed border-border px-3 text-xs text-text-muted" data-testid={`ad-funnel-unreported-${s.stage}`}>
                {ar ? 'لم ترسل المنصة هذه المرحلة' : 'This stage was never reported'}
              </div>
            )}
            <div className="w-40 shrink-0 text-end text-xs text-text-muted">
              {s.step_rate !== null && <span>{ar ? 'انتقال' : 'step'} {percent(s.step_rate, 0)}</span>}
              {s.cost_per !== null && <span className="ms-2">{ar ? 'تكلفة' : 'cost'} {money(s.cost_per, costCurrency)}</span>}
            </div>
          </div>
        ))}
      </div>
      {unreported.length > 0 && (
        // Named beneath the chart as well as drawn, because a reader who scans the bars needs to be
        // told once, in a sentence, that the gaps are the platform's silence and not their results.
        <p className="mt-4 text-xs text-text-muted" data-testid="ad-funnel-unreported-note">
          {ar
            ? `لم ترسل أي منصة هذه المراحل في هذه الفترة: ${unreported.map((s) => funnelStageLabel(s.stage, s.label, true)).join('، ')}. الفراغ ليس صفرًا.`
            : `No platform reported these stages in this period: ${unreported.map((s) => funnelStageLabel(s.stage, s.label, false)).join(', ')}. A gap is not a zero.`}
        </p>
      )}
    </Panel>
  )
}

/**
 * BUDGET-ACCOUNTS-001 — how close each ad account is to the ceiling the platform enforces.
 *
 * The campaign table below answers «is this campaign pacing to the plan we typed». This answers the
 * question that actually interrupts delivery: an account reaching the cap its platform holds stops
 * spending, whatever the plan said. It rolls up to the account because that is where the payment
 * method and the cap live.
 *
 * An account whose campaigns state no ceiling shows «لم تُرسل» rather than a bar at 0% — no cap is
 * not an exhausted cap, and a progress bar at zero says the opposite of what is true.
 */
function AccountBudgets({ projectId, range, filters }: TabProps) {
  const ar = useAr()
  const q = useAccountBudgets(projectId, range, filters)
  const rows = q.data ?? []

  /** Near the ceiling, or heading past it before the window ends — the two reasons to intervene. */
  const atRisk = rows.filter((r) => (r.consumed_pct !== null && r.consumed_pct >= 0.8) || (r.pace !== null && r.pace > 1))

  return (
    <Panel
      title={ar ? 'الحسابات الإعلانية مقابل حدّ المنصة' : 'Ad accounts against the platform ceiling'}
      description={ar
        ? 'الحدّ الذي تفرضه المنصة هو ما يوقف الصرف فعليًا — لا الخطة المكتوبة هنا.'
        : 'The ceiling the platform enforces is what actually stops delivery — not the plan typed here.'}
      loading={q.isLoading}
      error={q.isError}
      empty={!q.isLoading && rows.length === 0}
    >
      {atRisk.length > 0 && (
        <p data-testid="budget-at-risk" className="mb-3 rounded-lg border border-warning/40 bg-[var(--warning-background)] px-3 py-2 text-xs text-text-primary">
          <span className="font-semibold">{ar ? 'تنبيه: ' : 'Heads up: '}</span>
          {ar
            ? `${countedAccounts(atRisk.length, 'ar')} اقترب من حدّه أو يسير لتجاوزه قبل نهاية الفترة.`
            : `${atRisk.length} account(s) are near their ceiling or on course to pass it before the period ends.`}
        </p>
      )}

      <MetricTable
        head={ar
          ? ['الحساب', 'المنصة', 'المصروف', 'حدّ المنصة', 'المتبقي', 'الاستهلاك', 'السرعة', 'المتوقع', 'الحملات']
          : ['Account', 'Platform', 'Spent', 'Platform cap', 'Remaining', 'Consumed', 'Pace', 'Projected', 'Campaigns']}
        rows={rows.map((r) => {
          const unit = r.spent_currency ?? undefined
          const noCap = ar ? 'لم تُرسل' : 'Not sent'

          return [
            <span key="a" className="font-semibold text-text-primary">
              {r.account_name ?? (ar ? 'حساب لم يعد متاحًا' : 'Account no longer available')}
            </span>,
            providerLabel(canonicalPlatform(r.provider), ar ? 'ar' : 'en'),
            <span key="s" title={r.spend_withheld ? (ar ? 'بعملة المنصة — التحويل غير متاح' : "In the platform's own currency") : undefined}>
              {money(r.spent, unit)}
            </span>,
            r.cap === null
              ? <span key="c" className="text-text-muted" title={ar ? 'لم تُصرّح أي حملة على هذا الحساب بحدّ' : 'No campaign on this account states a ceiling'}>{noCap}</span>
              : money(r.cap, unit),
            r.remaining === null ? <span key="rm" className="text-text-muted">—</span> : money(r.remaining, unit),
            r.consumed_pct === null
              ? <span key="cp" className="text-text-muted">—</span>
              : (
                <span key="cp" className="inline-flex items-center gap-2">
                  <span className="h-1.5 w-14 overflow-hidden rounded-full bg-surface-secondary">
                    <span
                      className={`block h-full rounded-full ${r.consumed_pct >= 0.9 ? 'bg-danger' : r.consumed_pct >= 0.8 ? 'bg-warning' : 'bg-brand-500'}`}
                      style={{ width: `${Math.min(100, r.consumed_pct * 100)}%` }}
                    />
                  </span>
                  <span className="tnum text-xs">{percent(r.consumed_pct, 0)}</span>
                </span>
              ),
            r.pace === null
              ? <span key="p" className="text-text-muted">—</span>
              : <span key="p" className={`tnum font-semibold ${r.pace > 1 ? 'text-danger' : r.pace > 0.9 ? 'text-warning' : 'text-success'}`}>{ratio(r.pace, '×')}</span>,
            money(r.projected_spend, unit),
            <span key="n" className="tnum">
              {r.capped_campaigns < r.campaigns
                ? `${r.campaigns} (${ar ? `${r.capped_campaigns} بحدّ` : `${r.capped_campaigns} capped`})`
                : String(r.campaigns)}
            </span>,
          ]
        })}
        values={rows.map((r) => [
          r.account_name ?? '',
          providerLabel(canonicalPlatform(r.provider), ar ? 'ar' : 'en'),
          r.spent,
          r.cap,
          r.remaining,
          r.consumed_pct,
          r.pace,
          r.projected_spend,
          r.campaigns,
        ])}
        /* Most consumed first: the account about to stop delivering is why this table is open. */
        initialSort={{ column: 5, dir: 'desc' }}
      />
    </Panel>
  )
}

function BudgetTab({ projectId, range, filters }: TabProps) {
  const ar = useAr()
  const b = useBudget(projectId, range, filters)
  const reading = useBudgetExplanation(projectId, range, filters)
  const rows = b.data ?? []
  return (
    <div className="space-y-4">
    <AccountBudgets projectId={projectId} range={range} filters={filters} />
    {/*
      FUNNEL-ANALYTICAL-PATTERN-001 — the reading ABOVE the table it reads.

      A reader who meets the percentages first has already formed a view of them, and the four steps
      that follow arrive as a justification rather than as the reading they are.
    */}
    {!reading.isLoading && !reading.isError && (
      <BudgetReading reading={reading.data} locale={ar ? 'ar' : 'en'} />
    )}
    <Panel title={ar ? 'تحليل الميزانية' : 'Budget analysis'} description={ar ? 'المخطط مقابل المصروف وسرعة الصرف (Pacing)' : 'Planned against spent, and how fast it is going (pacing)'} loading={b.isLoading} error={b.isError} empty={!b.isLoading && rows.length === 0}>
      {/*
        BUDGET-WITHHELD-001 — every figure here is now stated in the unit it is actually in.

        `money()` defaults to SAR, and the row carried no currency, so a campaign budgeted in USD
        read «80K SAR». Worse, `spent` was the aggregator's coalesced zero: on an account whose money
        awaits a rate — production's, every row of it — this table reported 0 spent, 0% consumed and
        pacing 0.00× against real spend. That is the one wrong number on this product somebody acts
        on, because a campaign that has spent nothing and is pacing at zero is one they top up.

        Pacing is blank rather than wrong when the plan and the spend are denominated differently,
        and the row says which case it is instead of leaving a reader to guess at an empty cell.
      */}
      <MetricTable
        head={ar ? ['الحملة', 'الميزانية', 'المصروف', 'المتبقي', 'الاستهلاك', 'السرعة', 'المتوقع'] : ['Campaign', 'Budget', 'Spent', 'Remaining', 'Consumed', 'Pace', 'Projected']}
        rows={rows.map((r) => {
          const basisNote = r.pacing_basis === 'currency_mismatch'
            ? (ar
                ? `المصروف بعملة ${r.spent_currency ?? '—'} والميزانية بعملة ${r.budget_currency ?? '—'} — لا تُقارَنان`
                : `Spent in ${r.spent_currency ?? '—'}, budgeted in ${r.budget_currency ?? '—'} — not comparable`)
            : r.pacing_basis === 'no_budget'
              ? (ar ? 'لا توجد ميزانية محددة لهذه الحملة' : 'No budget was set for this campaign')
              : r.pacing_basis === 'partial'
                ? (ar ? 'جزء من المصروف محوَّل وجزء بانتظار سعر صرف — لا يوجد إجمالي واحد' : 'Part of the spend is converted and part awaits an FX rate — no single total')
                : r.pacing_basis === 'mixed_currency'
                  ? (ar ? 'المصروف بعملات متعددة لا يمكن جمعها' : 'Spend is in several currencies that cannot be summed')
                  : undefined

          return [
            <span key="n" className="font-semibold text-text-primary">{r.campaign_name}</span>,
            money(r.budget, r.budget_currency ?? undefined),
            r.spent === null
              ? <span key="s" className="text-text-muted" title={basisNote}>—</span>
              : <span key="s" title={r.spend_withheld ? (ar ? 'بعملة المنصة — التحويل غير متاح' : "In the platform's own currency — conversion unavailable") : undefined}>
                  {money(r.spent, r.spent_currency ?? undefined)}
                </span>,
            r.remaining === null
              ? <span key="rm" className="text-text-muted" title={basisNote}>—</span>
              : money(r.remaining, r.budget_currency ?? undefined),
            r.consumed_pct === null
              ? <span key="c" className="text-text-muted" title={basisNote}>—</span>
              : (
                <div key="c" className="flex items-center gap-2">
                  <div className="h-1.5 w-16 overflow-hidden rounded-full bg-surface-secondary">
                    <div className="h-full rounded-full bg-brand-500" style={{ width: `${Math.min(100, r.consumed_pct * 100)}%` }} />
                  </div>
                  <span className="tnum text-xs">{percent(r.consumed_pct, 0)}</span>
                </div>
              ),
            r.pace === null
              ? <span key="p" className="text-text-muted" title={basisNote}>—</span>
              : (
                <span key="p" className={`tnum font-semibold ${r.pace > 1.2 ? 'text-danger' : r.pace < 0.8 ? 'text-warning' : 'text-success'}`}>
                  {ratio(r.pace, '×')}
                </span>
              ),
            r.projected_spend === null
              ? <span key="pr" className="text-text-muted" title={basisNote}>—</span>
              : money(r.projected_spend, r.spent_currency ?? undefined),
          ]
        })}
        values={rows.map((r) => [
          r.campaign_name,
          r.budget ?? null,
          r.spent ?? null,
          r.remaining,
          r.consumed_pct,
          r.pace,
          r.projected_spend ?? null,
        ])}
        /* Fastest-burning first: a campaign about to overrun is why somebody opens this table. */
        initialSort={{ column: 5, dir: 'desc' }}
      />
    </Panel>
    </div>
  )
}

export function QualityTab({ projectId, range, filters }: TabProps) {
  const ar = useAr()
  const f = useFreshness(projectId, range, filters)
  const rows = f.data ?? []
  /*
   * DATA-QUALITY-OPERATOR-UX-001 · CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — the OTHER half of this tab.
   *
   * Freshness answers «did the data arrive». Attribution answers «was it counted the same way», and
   * that half was a table of claims: platform, orders claimed, orders confirmed, the difference. The
   * account manager reading it has a client asking why the numbers disagree, and the table implies
   * the answer without ever stating it.
   */
  const attribution = useAttribution(projectId, range, filters)
  const attributionNotes = useMemo(() => attributionFindings(attribution.data), [attribution.data])
  /*
   * DATA-QUALITY-OPERATOR-UX-001 — the findings, above the table that implies them.
   *
   * The table below is true and administrator-shaped: platform · latest date · last sync · days with
   * data · missing days · status. The person who opens this tab is an account manager whose client
   * has just asked why last week looks thin, and they need what is wrong, what it affects, how much
   * it matters, what they can check and who can end it — derived, every time, from six columns whose
   * relationship they have to already know.
   *
   * Nothing is hidden: the table stays underneath, and the findings are read from the same rows. A
   * second source for «is this platform healthy» is how two surfaces come to disagree about one
   * account.
   */
  const findings = useMemo(() => findingsFor(rows, new Date()), [rows])

  /*
   * How much of the window is actually measured — a LEVEL, with the numbers that produced it.
   *
   * The window's length comes from the range the reader chose rather than from the rows: a source
   * that reported nothing at all still has days missing, and counting only the days somebody
   * reported would rate a half-silent window as complete.
   */
  const windowDays = useMemo(() => {
    const from = Date.parse(range.from)
    const to = Date.parse(range.to)
    return Number.isFinite(from) && Number.isFinite(to) ? Math.round((to - from) / 86_400_000) + 1 : 0
  }, [range.from, range.to])

  const confidence = useMemo(
    () => windowConfidence(rows, windowDays, findings),
    [rows, windowDays, findings],
  )

  return (
    <div>
      {!f.isLoading && rows.length > 0 && <WindowConfidenceLine c={confidence} windowDays={windowDays} ar={ar} />}
      <QualityFindings findings={findings} ar={ar} loading={f.isLoading} />
      <AttributionFindings findings={attributionNotes} ar={ar} loading={attribution.isLoading} />
      <Panel title={ar ? 'جودة البيانات والإسناد' : 'Data quality & attribution'} description={ar ? 'آخر مزامنة، حداثة البيانات، والأيام الناقصة لكل منصة' : 'Last sync, how fresh the data is, and the missing days per platform'} loading={f.isLoading} error={f.isError} empty={!f.isLoading && rows.length === 0}>
      <MetricTable
        head={ar ? ['المنصة', 'آخر تاريخ', 'آخر مزامنة', 'أيام ببيانات', 'أيام ناقصة', 'الحالة'] : ['Platform', 'Latest date', 'Last sync', 'Days with data', 'Missing days', 'Status']}
        rows={rows.map((r) => [
          <PlatformCell key="p" provider={r.provider} />,
          r.latest_metric_date ?? '—',
          r.last_sync_at ? fmtDateTime(r.last_sync_at) : '—',
          num(r.days_with_data),
          r.missing_days === null ? '—'
            : r.missing_days > 0 ? <span key="m" className="font-semibold text-warning">{r.missing_days}</span> : '0',
          /*
           * INTEG-RUNTIME §8 — the status cell reads the shared meaning instead of its own ternary.
           *
           * The chain it replaces had a green default, so EVERY status it did not know about — and
           * after the split that includes `no_data`, `partial_mapping` and `awaiting_assignment` —
           * was painted as a success. A raw English key on a positive green pill was the whole
           * report a customer got about an account that had never been assigned to a project.
           */
          <SyncStatusPill key="s" status={r.last_sync_status} ar={ar} />,
        ])}
        values={rows.map((r) => [
          providerLabel(canonicalPlatform(r.provider), ar ? 'ar' : 'en'),
          r.latest_metric_date ?? null,
          r.last_sync_at ?? null,
          r.days_with_data ?? null,
          r.missing_days,
          r.last_sync_status ?? null,
        ])}
        /* Most missing days first: the platform with the biggest hole is the reason to open this. */
        initialSort={{ column: 4, dir: 'desc' }}
      />
      <p className="mt-3 text-xs text-text-muted">{ar ? 'لا يتم جمع Reach عبر المنصات كوصول فريد — يُعرض لكل منصة على حدة.' : 'Reach is not summed across platforms as unique reach — it is shown per platform.'}</p>
      </Panel>
      <NormalizationPanel projectId={projectId} range={range} filters={filters} />
      {/*
       * REPORT-OBJECTIVE-005 — on this tab because it is literally «جودة البيانات والإسناد», and
       * because a reader who has just been told which currency and window produced a figure is the
       * reader who needs to be told which SYSTEM produced it.
       */}
      <AttributionSection projectId={projectId} range={range} filters={filters} />
    </div>
  )
}

/**
 * What is wrong, what it affects, what you can check — and who can end it.
 *
 * The owner is the answer an operator needs first, and it is the one the old table could not give at
 * all: «the platform», «you», «somebody with the credentials», «the system, on its own». A finding
 * that does not say which of those it is sends its reader to the wrong place, and a reader sent to
 * the wrong place twice stops opening the tab.
 */
/**
 * DATA-QUALITY-OPERATOR-UX-001 — «how much of this can I trust», answered with its own arithmetic.
 *
 * A bare «84%» is a figure nobody can check and nobody can act on, and it claims a precision this
 * data does not have — a missing day is not distinguishable from a day nobody was running. So the
 * level leads, the days that produced it follow, and the source most responsible is named, because a
 * reader with a name has somewhere to go and a reader with a percentage has an argument.
 */
/** The counted forms of «source», which this line says twice. */
const SOURCE_AR = { one: 'مصدر', two: 'مصدران', few: 'مصادر', many: 'مصدرًا' }

function WindowConfidenceLine({ c, windowDays, ar }: { c: WindowConfidence; windowDays: number; ar: boolean }) {
  const LEVEL: Record<WindowConfidence['level'], { ar: string; en: string; tone: string }> = {
    high: { ar: 'عالية', en: 'High', tone: 'border-success/30 bg-success/5 text-success' },
    moderate: { ar: 'متوسطة', en: 'Moderate', tone: 'border-warning/40 bg-warning/5 text-warning' },
    low: { ar: 'منخفضة', en: 'Low', tone: 'border-danger/40 bg-danger/5 text-danger' },
  }

  const level = LEVEL[c.level]
  const worst = c.worst === null ? null : (c.worst.name ?? providerLabel(c.worst.provider, ar ? 'ar' : 'en'))

  return (
    <div
      data-testid="quality-confidence"
      data-level={c.level}
      className={`mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border p-3.5 text-sm ${level.tone}`}
    >
      <span className="font-bold">
        {ar ? 'الثقة في أرقام هذه الفترة: ' : 'Confidence in this window: '}{ar ? level.ar : level.en}
      </span>

      <span className="text-text-secondary">
        {c.coverage === null
          ? (ar
              ? 'لا مصدر تُقاس تغطيته بالأيام في هذه الفترة.'
              : 'No source in this window is measured in days.')
          : (ar
              ? `${c.daysWithData} من ${countedDays(c.daysExpected, 'ar')} عبر ${countedAr(c.sourcesCounted, SOURCE_AR)} (${countedDays(windowDays, 'ar')} لكل مصدر).`
              : `${c.daysWithData} of ${countedDays(c.daysExpected, 'en')} across ${countedEn(c.sourcesCounted, 'source', 'sources')} (${countedDays(windowDays, 'en')} each).`)}
      </span>

      {worst !== null && (
        <span className="text-text-secondary">
          {ar ? `الأثر الأكبر: ${worst}.` : `Most of the gap: ${worst}.`}
        </span>
      )}

      {c.sourcesNotCountable > 0 && (
        <span className="text-text-muted">
          {ar
            ? `${countedAr(c.sourcesNotCountable, SOURCE_AR)} لا تُقاس تغطيته بالأيام (المتاجر) وخارج هذه النسبة.`
            : `${countedEn(c.sourcesNotCountable, 'source', 'sources')} are not measured in days (stores) and are outside this figure.`}
        </span>
      )}
    </div>
  )
}

/**
 * The attribution half's findings, in the same shape as the freshness half's.
 *
 * Same card, same owner vocabulary, same ordering — because they are the same KIND of statement
 * about the same window, and a reader who has learnt to read one should not have to learn the other.
 * What differs is the question they answer: «did the data arrive» against «was it counted the same
 * way», and the second is the one a client asks when two platforms disagree.
 */
function AttributionFindings({
  findings, ar, loading,
}: {
  findings: AttributionFinding[]
  ar: boolean
  loading: boolean
}) {
  if (loading || findings.length === 0) return null

  const OWNER: Record<AttributionFinding['owner'], { ar: string; en: string }> = {
    system: { ar: 'النظام — تلقائيًا', en: 'The system, on its own' },
    operator: { ar: 'أنت — خطوة واحدة', en: 'You — one step' },
    provider: { ar: 'يحتاج فحصًا على المنصة', en: 'Needs a look on the platform' },
    nobody: { ar: 'طبيعي — للمتابعة فقط', en: 'Ordinary — for watching only' },
  }

  const TONE: Record<AttributionFinding['severity'], string> = {
    critical: 'border-danger/40 bg-danger/5',
    attention: 'border-warning/40 bg-warning/5',
    watch: 'border-border bg-surface-secondary',
  }

  return (
    <ul data-testid="attribution-findings" className="mb-4 flex flex-col gap-2">
      {findings.map((finding) => (
        <li
          key={finding.key}
          data-testid={`attribution-finding-${finding.key}`}
          className={`rounded-xl border p-3.5 ${TONE[finding.severity]}`}
        >
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-bold text-text-primary">
              {finding.provider === null
                ? (ar ? 'عبر المنصات' : 'Across platforms')
                : providerLabel(finding.provider, ar ? 'ar' : 'en')}
            </span>
            <span className="rounded-full bg-surface px-2 py-0.5 text-[11px] font-semibold text-text-secondary">
              {ar ? OWNER[finding.owner].ar : OWNER[finding.owner].en}
            </span>
          </div>
          <p className="mt-1 text-sm text-text-primary">{ar ? finding.what.ar : finding.what.en}</p>
          <p className="mt-0.5 text-xs text-text-secondary">{ar ? finding.affects.ar : finding.affects.en}</p>
          <p className="mt-0.5 text-xs text-text-muted">{ar ? finding.check.ar : finding.check.en}</p>
        </li>
      ))}
    </ul>
  )
}

function QualityFindings({ findings, ar, loading }: { findings: QualityFinding[]; ar: boolean; loading: boolean }) {
  if (loading) {
    return null
  }

  const OWNER: Record<QualityFinding['owner'], { ar: string; en: string }> = {
    system: { ar: 'النظام — تلقائيًا', en: 'The system, on its own' },
    operator: { ar: 'أنت — خطوة واحدة', en: 'You — one step' },
    credentials: { ar: 'يحتاج بيانات اعتماد', en: 'Needs credentials' },
    provider: { ar: 'يحتاج فحصًا على المنصة', en: 'Needs a look on the platform' },
  }

  const TONE: Record<QualityFinding['severity'], string> = {
    critical: 'border-danger/40 bg-danger/5',
    attention: 'border-warning/40 bg-warning/5',
    watch: 'border-border bg-surface-secondary',
  }

  if (findings.length === 0) {
    return (
      <p data-testid="quality-findings-clear" className="mb-4 rounded-xl border border-success/30 bg-success/5 p-3.5 text-sm text-text-secondary">
        {/*
          «لا يوجد شيء» would read as a page that failed to load. Every source being current IS the
          answer, and saying which sources were checked is what makes it believable.
        */}
        {ar
          ? 'كل مصدر في هذه الفترة محدَّث ومكتمل — لا شيء يحتاج انتباهك هنا.'
          : 'Every source in this window is current and complete — nothing here needs your attention.'}
      </p>
    )
  }

  return (
    <ul data-testid="quality-findings" className="mb-4 flex flex-col gap-2">
      {findings.map((finding) => (
        <li key={finding.key} data-testid={`quality-finding-${finding.provider}`} className={`rounded-xl border p-3.5 ${TONE[finding.severity]}`}>
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-bold text-text-primary">{finding.name ?? finding.provider}</span>
            <span className="rounded-full bg-surface px-2 py-0.5 text-[11px] font-semibold text-text-secondary">
              {ar ? OWNER[finding.owner].ar : OWNER[finding.owner].en}
            </span>
            {finding.coverage !== null && (
              <span className="rounded-full bg-surface px-2 py-0.5 text-[11px] text-text-muted" dir="ltr">
                {ar ? 'التغطية' : 'Coverage'} {Math.round(finding.coverage * 100)}%
              </span>
            )}
          </div>
          <p className="mt-1 text-sm text-text-primary">{ar ? finding.what.ar : finding.what.en}</p>
          <p className="mt-0.5 text-xs text-text-secondary">{ar ? finding.affects.ar : finding.affects.en}</p>
          <p className="mt-0.5 text-xs text-text-muted">{ar ? finding.check.ar : finding.check.en}</p>
        </li>
      ))}
    </ul>
  )
}

function AttributionSection({ projectId, range, filters }: TabProps) {
  const locale = useUi((u) => u.locale)
  const a = useAttribution(projectId, range, filters)

  return (
    <>
      <AttributionPanel
        data={a.data}
        loading={a.isLoading}
        error={a.isError}
        locale={locale}
        className="mt-4"
      />
      <ScopeNote scope={a.data?.filter_scope} testid="attribution-scope" />
    </>
  )
}

/**
 * NORM-001 — the basis of every figure on this page.
 *
 * The normalisation layer has always existed: each `daily_metrics` row records the currency it arrived
 * in, the one it was converted to and the rate used, the platform's timezone and the project's, the
 * attribution window that counted its conversions, and whether it came from an API or from demo data.
 * None of it was ever shown. Spend appeared converted with nothing saying a conversion had happened,
 * and the API announced «SAR» as a constant.
 *
 * The point of this panel is the difference between a figure and a figure's basis. Two campaigns whose
 * conversions were counted under different attribution windows are not comparable, and a dashboard
 * that puts them side by side without saying so is not wrong in its arithmetic — it is wrong in what
 * the reader will conclude. So each row states what is ACTUALLY in the range, and the awkward cases
 * (a second currency, a second attribution window, demo rows among real ones) are called out rather
 * than resolved quietly.
 */
/**
 * What a panel says when it could not honour one of the filters it was sent.
 *
 * Silence was the defect: three panels read only the provider off a request carrying an objective
 * and a campaign too, so they sat under chips naming one campaign and answered for the whole
 * project. Declining an axis can be right — see `filterScope` for the two cases where narrowing
 * would invent a figure rather than reveal one — but declining without saying so is not.
 */
function ScopeNote({ scope, testid }: { scope?: FilterScope; testid: string }) {
  const ar = useAr()
  const note = scopeNote(scope, ar)
  if (note === null) return null

  return (
    <p data-testid={testid} className="text-xs font-semibold text-text-muted">
      {note}
    </p>
  )
}

function NormalizationPanel({ projectId, range, filters }: TabProps) {
  const ar = useAr()
  const n = useNormalization(projectId, range, filters)
  const d = n.data

  const converted = (d?.currencies ?? []).filter((c) => c.converted)
  /*
   * FX-001 — figures LEFT OUT of every money total here, because no rate for their date could be
   * vouched for. This is the one number on the panel whose absence would itself be a false claim: a
   * total missing them looks identical to a complete one, so silence would read as «nothing to say».
   */
  const withheld = (d?.currencies ?? []).reduce((sum, c) => sum + (c.withheld ?? 0), 0)
  const withheldFrom = (d?.currencies ?? []).filter((c) => (c.withheld ?? 0) > 0).map((c) => c.from)
  const shifted = (d?.timezones ?? []).filter((t) => t.shifted)
  const windows = d?.attribution_windows ?? []
  const demoRows = (d?.sources ?? []).filter((s) => s.is_demo).reduce((sum, s) => sum + s.rows, 0)
  const objectives = d?.objectives

  return (
    <Panel
      title={ar ? 'أساس الأرقام' : 'How these numbers were produced'}
      description={ar
        ? 'العملة والمنطقة الزمنية ونافذة الإسناد ومصدر كل رقم قبل عرضه'
        : 'The currency, timezone, attribution window and source behind every figure above'}
      loading={n.isLoading}
      error={n.isError}
      className="mt-4"
    >
      <div data-testid="normalization" className="grid gap-3 text-sm">
        <ScopeNote scope={d?.filter_scope} testid="normalization-scope" />
        {/* Currency. Silence here is a claim: a converted figure that says nothing reads as native. */}
        <Basis label={ar ? 'العملة' : 'Currency'}>
          {converted.length > 0
            ? converted.map((c) => (
                <span key={`${c.from}-${c.to}`} className="block">
                  {ar
                    ? `حُوّل ${num(c.rows)} صفًا من ${c.from} إلى ${c.to}${c.rate_min !== null ? ` بسعر ${c.rate_min === c.rate_max ? c.rate_min : `${c.rate_min}–${c.rate_max}`}` : ''}`
                    : `${num(c.rows)} rows converted from ${c.from} to ${c.to}${c.rate_min !== null ? ` at ${c.rate_min === c.rate_max ? c.rate_min : `${c.rate_min}–${c.rate_max}`}` : ''}`}
                </span>
              ))
            : d?.project_currency
              ? (ar ? `كل المبالغ بعملة ${d.project_currency} أصلًا — لم يُجرَ أي تحويل.` : `Every amount was already in ${d.project_currency}. Nothing was converted.`)
              : (ar ? 'لا توجد مبالغ مالية في هذه الفترة.' : 'There are no money figures in this period.')}
          {withheld > 0 && (
            <span data-testid="normalization-withheld" className="mt-1 block font-semibold text-warning">
              {ar
                ? `${num(withheld)} صفًا (${withheldFrom.join(' · ')}) لا يوجد له سعر صرف موثّق لتاريخه، ولم يُدرج في أي مبلغ أعلاه. المبالغ المعروضة ناقصة بهذا القدر.`
                : `${num(withheld)} rows (${withheldFrom.join(' · ')}) have no trustworthy rate for their date and are in none of the amounts above. The figures shown are short by that much.`}
            </span>
          )}
          {/*
            `d?.project_currencies?.length`, not `d?.project_currencies.length`.
            The optional chain stopped at `d`, so a payload that HAS data and lacks this one array
            threw `Cannot read properties of undefined` from inside the render — and took the whole
            Data quality tab down with it, findings, table and all. The `?? 0` beside it reads as a
            guard and guards nothing: it only ever catches `d` being absent.
          */}
          {(d?.project_currencies?.length ?? 0) > 1 && (
            <span className="mt-1 block font-semibold text-warning">
              {ar
                ? `هذه الفترة تحتوي أكثر من عملة عرض (${d?.project_currencies?.join(' · ')}) — لا تُجمع المبالغ كرقم واحد.`
                : `This period holds more than one display currency (${d?.project_currencies?.join(' · ')}) — the amounts are not one total.`}
            </span>
          )}
        </Basis>

        {/* Timezone — what "a day" means. A row dated by the platform's midnight is not the project's. */}
        <Basis label={ar ? 'حدود اليوم' : 'Where a day starts'}>
          {shifted.length > 0
            ? shifted.map((t) => (
                <span key={`${t.from}-${t.to}`} className="block">
                  {ar
                    ? `تُجمع الأيام بتوقيت ${t.to}، والمنصة تُبلّغ بتوقيت ${t.from}.`
                    : `Days are counted in ${t.to}; the platform reports in ${t.from}.`}
                </span>
              ))
            : (ar ? 'المنصة والمشروع على التوقيت نفسه.' : 'The platform and the project keep the same clock.')}
        </Basis>

        {/* Attribution — more than one window in a range is a comparability defect, not a detail. */}
        <Basis label={ar ? 'نافذة الإسناد' : 'Attribution window'}>
          {windows.length === 0
            ? (ar ? 'لا توجد بيانات في هذه الفترة.' : 'There is no data in this period.')
            : windows.map((w) => (
                <span key={w.window} className="block">
                  <code className="rounded bg-surface-secondary px-1.5 py-0.5 text-[12px]">{w.window}</code>
                  <span className="ms-2 text-text-muted">{ar ? `${num(w.rows)} صف` : `${num(w.rows)} rows`}</span>
                </span>
              ))}
          {windows.length > 1 && (
            <span className="mt-1 block font-semibold text-warning">
              {ar
                ? 'أكثر من نافذة إسناد في الفترة نفسها — التحويلات هنا لا تُقارن مباشرة.'
                : 'More than one attribution window in the same period — these conversions are not directly comparable.'}
            </span>
          )}
        </Basis>

        {/* Objective comparability: name the metrics that survive, rather than allow or refuse silently. */}
        {objectives && objectives.present.length > 0 && (
          <Basis label={ar ? 'المقارنة بين الأهداف' : 'Comparing across objectives'}>
            {objectives.mixed ? (
              <>
                <span className="block">
                  {ar
                    ? `الفترة تضم ${num(objectives.present.length)} أهداف مختلفة. ما يقارن بينها: ${objectives.comparable_metrics.join('، ')}.`
                    : `This period spans ${num(objectives.present.length)} different objectives. Comparable across all of them: ${objectives.comparable_metrics.join(', ')}.`}
                </span>
                <span className="mt-1 block text-text-muted">
                  {ar
                    ? 'أما التحويلات وتكلفتها فتعني حدثًا مختلفًا في كل هدف، فلا تُجمع ولا تُقارن.'
                    : 'Conversions and their costs count a different event under each objective, so they are neither summed nor compared.'}
                </span>
              </>
            ) : (
              <span>
                {ar
                  ? `كل الحملات في هذه الفترة لهدف واحد (${objectives.present[0]?.objective}) — كل المؤشرات قابلة للمقارنة.`
                  : `Every campaign in this period shares one objective (${objectives.present[0]?.objective}), so every metric compares.`}
              </span>
            )}
          </Basis>
        )}

        {/* Demo rows are labelled here too, not only by a badge in the corner of the page. */}
        <Basis label={ar ? 'المصدر' : 'Source'}>
          {(d?.sources ?? []).length === 0
            ? (ar ? 'لا توجد بيانات في هذه الفترة.' : 'There is no data in this period.')
            : (d?.sources ?? []).map((s) => (
                <span key={`${s.source_type}-${String(s.is_demo)}`} className="block">
                  {ar
                    ? `${num(s.rows)} صف — ${s.is_demo ? 'بيانات تجريبية' : sourceLabel(s.source_type, true)}`
                    : `${num(s.rows)} rows — ${s.is_demo ? 'demo data' : sourceLabel(s.source_type, false)}`}
                </span>
              ))}
          {demoRows > 0 && (
            <span className="mt-1 block font-semibold text-warning">
              {ar
                ? `${num(demoRows)} صفًا من هذه الأرقام بيانات تجريبية، لا نتائج حقيقية.`
                : `${num(demoRows)} of these rows are demo data, not real results.`}
            </span>
          )}
        </Basis>

        {/* Stored but read by nothing. An empty answer is stated, never left as an empty space. */}
        <Basis label={ar ? 'مقاييس غير محسوبة' : 'Metrics nothing reads'}>
          {(d?.unread_metric_keys?.length ?? 0) === 0
            ? (ar ? 'كل مقياس في بياناتك يدخل في مؤشر واحد على الأقل.' : 'Every metric key in your data feeds at least one KPI.')
            : (ar
                ? `مخزّنة ولا يقرؤها أي مؤشر: ${d?.unread_metric_keys?.join('، ')}.`
                : `Stored but read by no KPI: ${d?.unread_metric_keys?.join(', ')}.`)}
        </Basis>

        {/* The catalogue: what a metric means and whether it may be summed at all. */}
        {d?.catalogue?.available && (
          <details className="rounded-xl border border-border bg-surface-secondary px-4 py-3">
            <summary className="cursor-pointer text-sm font-semibold text-text-primary">
              {ar ? `تعريفات المقاييس (${num(d.catalogue.metrics.length)})` : `Metric definitions (${num(d.catalogue.metrics.length)})`}
            </summary>
            <p className="mt-2 text-xs text-text-muted">
              {ar
                ? 'المقاييس القابلة للجمع تُجمع عبر الأيام والحملات؛ أما النسب فتُحسب من مجاميعها في كل مرة ولا تُجمع أبدًا — مجموع تكلفة النقرة عبر ثلاثين يومًا ليس تكلفة النقرة للشهر.'
                : 'Additive metrics are summed across days and campaigns; ratios are recomputed from their base sums every time and never summed — adding up thirty daily CPCs does not give you the month’s CPC.'}
            </p>
            <div className="mt-3 grid gap-1.5 sm:grid-cols-2">
              {d.catalogue.metrics.map((m) => (
                <div key={m.key} className="flex items-baseline justify-between gap-2 text-xs">
                  <span className="font-semibold text-text-primary">{m.name}</span>
                  <span className={m.is_additive ? 'text-text-muted' : 'font-semibold text-brand-600'}>
                    {m.is_additive ? (ar ? 'قابل للجمع' : 'additive') : (ar ? 'يُعاد حسابه' : 'recomputed')}
                  </span>
                </div>
              ))}
            </div>
          </details>
        )}
      </div>
    </Panel>
  )
}

/** `api | manual | estimated | modeled` — column values, given words. */
function sourceLabel(source: string, ar: boolean): string {
  const table: Record<string, { ar: string; en: string }> = {
    api: { ar: 'مسحوبة من المنصة', en: 'pulled from the platform' },
    manual: { ar: 'مُدخلة يدويًا', en: 'entered by hand' },
    estimated: { ar: 'مقدَّرة', en: 'estimated' },
    modeled: { ar: 'محسوبة بنموذج', en: 'modelled' },
  }

  return table[source] ? (ar ? table[source].ar : table[source].en) : source
}

function Basis({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid gap-0.5 border-b border-border pb-3 last:border-0 last:pb-0 sm:grid-cols-[180px_1fr] sm:gap-4">
      <span className="text-xs font-bold uppercase tracking-wide text-text-muted">{label}</span>
      <div className="text-text-secondary">{children}</div>
    </div>
  )
}

/**
 * PLATFORM-CELL-001 — the cell whose entire job is to name a platform printed its key.
 *
 * Three tables use this — platforms, campaign ranking and data quality — so «meta» appeared as the
 * platform name beside an accounts table on the next tab reading «ميتا», and in the filter chips
 * above them, which have always used `providerLabel`. The dot was localized and the word was not.
 *
 * The same reader as everywhere else. `canonicalPlatform` first, because the breakdowns return the
 * provider as stored (`google_ads`) while the labels are keyed canonically (`google`) — a mismatch
 * that would have fallen back to the key and looked exactly like the defect being fixed.
 */
function PlatformCell({ provider }: { provider: string }) {
  const ar = useAr()

  return (
    <span className="inline-flex items-center gap-1.5 font-semibold text-text-primary">
      <span className="h-2.5 w-2.5 rounded-full" style={{ background: platformColor(provider) }} />
      {providerLabel(canonicalPlatform(provider), ar ? 'ar' : 'en')}
    </span>
  )
}


/**
 * ANALYTICS-DRILLDOWN-001 — the ad-squad and ad rungs, on screen.
 *
 * These two tabs did not exist, and could not have: `daily_metrics` is campaign-grain and
 * `creative_daily_metrics` is creative-grain, so the 187 ad squads and 5,706 ads on the live account
 * had no table to read from. `entity_daily_metrics` and its aggregator now answer for them.
 *
 * Money goes through the canonical reader, so a withheld ad-squad spend prints «412.50 USD» with the
 * conversion note rather than «0 SAR» — the same sentence a dashboard KPI would give for the same
 * money. Every ratio renders through `metricOrDash`, which prints «—» for a null rather than a zero,
 * because a CPM that could not be computed is not a CPM of nothing.
 */
/**
 * A count, or «—». Never 0 for a figure nobody reported — that is a measurement nobody made.
 *
 * These live at module scope now. They were declared inside `EntityTab`, so `AccountsTab` reached
 * them only by hoisting across a sibling's body — which held until the sibling was rewritten and
 * took them with it. Two tabs sharing a formatter should not depend on which one happens to declare
 * it.
 *
 * NUMERAL-PREFERENCE-002 note: these still format through `toLocaleString('en-US')` rather than the
 * canonical numeral layer, and are in scope for that sweep.
 */
function metricOrDash(value: number | null | undefined, digits = 0): string {
  return typeof value === 'number'
    ? value.toLocaleString('en-US', { maximumFractionDigits: digits })
    : '—'
}

/** A rate, or «—». Same rule: an unavailable ratio is not a ratio of zero. */
function rateOrDash(value: number | null | undefined): string {
  return typeof value === 'number' ? `${(value * 100).toFixed(2)}%` : '—'
}


/** Rung order, for deciding which crumbs sit above the list on screen. */
const ORDER_INDEX: Record<DrillLevel, number> = { campaign: 0, ad_set: 1, ad: 2, creative: 3 }

/** Which tab shows each rung. The drill path and the tab are one statement about where the reader is. */
const TAB_FOR: Record<DrillLevel, string> = {
  campaign: 'campaigns',
  ad_set: 'ad_sets',
  ad: 'ads',
  creative: 'creative',
}

/**
 * Where the reader is, and the way back out.
 *
 * Rendered only when a path exists — an always-present «All» crumb over an unnarrowed list would
 * imply a filter nobody applied. Each crumb steps OUT to that level, keeping everything above it, so
 * leaving an ad returns to its ad set rather than to the top.
 */
function DrillCrumbs({ path, level, ar, onUpTo }: { path: DrillStep[]; level: DrillLevel; ar: boolean; onUpTo: (level: DrillLevel) => void }) {
  /*
   * Only the steps ABOVE the list being shown.
   *
   * A reader who drills into ads and then clicks the «Ad sets» tab directly has a path still holding
   * an ad set, while the ad-set list is unnarrowed. Rendering that crumb would caption a
   * project-wide list with one ad set's name — the lie this whole module exists to prevent. The path
   * is kept so stepping back down restores it; it is simply not claimed here.
   */
  const shown = path.filter((s) => ORDER_INDEX[s.level] < ORDER_INDEX[level])

  if (shown.length === 0) return null

  const LEVEL_LABEL: Record<DrillLevel, { ar: string; en: string }> = {
    campaign: { ar: 'حملة', en: 'Campaign' },
    ad_set: { ar: 'مجموعة', en: 'Ad set' },
    ad: { ar: 'إعلان', en: 'Ad' },
    creative: { ar: 'إعلان', en: 'Ad' },
  }

  return (
    <div className="flex flex-wrap items-center gap-2 text-sm" data-testid="drill-crumbs">
      <button
        type="button"
        className="text-text-muted hover:underline"
        data-testid="drill-clear"
        onClick={() => onUpTo('campaign')}
      >
        {ar ? 'كل المشروع' : 'Whole project'}
      </button>
      {shown.map((step) => (
        <span key={`${step.level}:${step.id}`} className="flex items-center gap-2">
          <span className="text-text-muted">/</span>
          <button
            type="button"
            className="rounded-full border border-border px-2 py-0.5 text-text-primary hover:underline"
            data-testid={`drill-crumb-${step.level}`}
            onClick={() => onUpTo(step.level)}
          >
            <span className="text-text-muted">{(ar ? LEVEL_LABEL[step.level].ar : LEVEL_LABEL[step.level].en) + ': '}</span>
            {/* An entity with no name shows its id — a dash would read as «nothing here». */}
            {stepLabel(step)}
          </button>
        </span>
      ))}
    </div>
  )
}

/**
 * What an ad set or ad is doing right now, in one word.
 *
 * The same three states the campaigns workspace uses, from the same rule — a stopped ad that
 * outspent everything running is still stopped, and an operator scanning this table needs to see
 * that without inferring it from row order.
 */
function EntityState({ row, windowEnd, ar }: { row: EntityRow; windowEnd: string; ar: boolean }) {
  const state = relevanceOf(row, windowEnd)

  if (state === 'serving') return <span className="text-text-muted">—</span>

  return (
    <span
      data-testid={`entity-state-${row.entity_id}`}
      className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
        state === 'stopped' ? 'bg-surface-secondary text-text-secondary' : 'bg-warning/10 text-warning'
      }`}
    >
      {state === 'stopped' ? (ar ? 'متوقف' : 'Stopped') : (ar ? 'خامل' : 'Idle')}
    </span>
  )
}

function EntityTab({ projectId, range, filters, level }: TabProps & { level: 'ad_set' | 'ad' }) {
  const ar = useAr()
  /*
   * HIERARCHY-ENTITY-ANALYTICS-DRILLDOWN — the parent comes from the URL, and it changes the QUERY.
   *
   * `parent` was hardcoded `undefined` here since the endpoint shipped, so four levels the backend
   * could already narrow were served as four flat lists. It is part of the query key as well as the
   * request, so a cached unfiltered response is never handed back for a drilled-down question.
   */
  const [rawPath] = useUrlState('drill', '')
  const path = useMemo(() => withNames(decodePath(rawPath)), [rawPath])
  const parent = parentFor(level, path)
  const q = useEntities(projectId, range, level, parent, filters)
  /*
   * ENTITY-RELEVANCE-ORDERING-001 — «currently-serving ads must not be mixed with stopped historical
   * ads without clear grouping».
   *
   * This table rendered the rows exactly as the aggregator returned them: spend-first, which is
   * right for a report and wrong for an operator. An ad that stopped three weeks ago and outspent
   * everything still running led the list, so the first row somebody saw was one they could do
   * nothing about — and nothing on it said so.
   *
   * The rule is `campaignRelevance`, unchanged and not re-implemented: it reads `status` and
   * `last_active_on`, which every rung carries, and the campaigns workspace already orders by it.
   * Two surfaces disagreeing about which things are live is worse than either order alone.
   */
  /*
   * Judged against the window the RESPONSE describes, not the one the picker currently shows.
   *
   * They are the same in the ordinary case and diverge exactly when it matters: while a new range is
   * in flight the rows on screen are still the previous window's, and reading «is this still
   * serving» against a window those figures do not cover would relabel every row for as long as the
   * request takes. The response is the authority on what its own numbers are about.
   */
  const windowEnd = q.data?.period?.to ?? range.to
  const rows = useMemo(
    () => orderByRelevanceWith(q.data?.entities ?? [], windowEnd, (row) => row.entity_id),
    [q.data?.entities, windowEnd],
  )
  const currency = q.data?.currency ?? null
  const child = nextLevel(level)

  /*
   * AD-PREVIEW-001 — the ads table had no media at all.
   *
   * It is the one surface in the product that lists ads by name and number and shows nothing of what
   * ran: a reader deciding which of eleven ads to stop was choosing between eleven strings. The
   * pictures come from the same library endpoint every other surface reads — filtered by the ads ON
   * SCREEN, so the request scales with the page rather than with the estate — and open in place,
   * because this is a comparison table and a navigation costs the reader the comparison.
   *
   * Ad sets have no media of their own, so the query is asked for ads only.
   */
  const adIds = useMemo(
    () => (level === 'ad' ? rows.map((row) => row.external_id).filter((id): id is string => Boolean(id)) : []),
    [level, rows],
  )
  const previews = useQuery({
    queryKey: ['analytics', 'ad-previews', projectId, range.from, range.to, adIds.join(',')],
    queryFn: () => listCreatives(
      { from: range.from, to: range.to, per_page: Math.max(adIds.length, 1), ad_ids: adIds },
      projectId ?? undefined,
    ),
    enabled: level === 'ad' && adIds.length > 0 && Boolean(projectId),
  })

  /*
   * An ad's creative, by the ad's own provider id.
   *
   * One creative can be carried by several ads — that relation is why `creative.ads` is a list — so
   * the map is built from the relation rather than from a column naming one ad.
   */
  const creativeByAd = useMemo(() => {
    const map = new Map<string, CreativeCard>()
    for (const creative of previews.data?.creatives ?? []) {
      for (const ad of creative.ads ?? []) {
        if (ad.external_id) map.set(ad.external_id, creative)
      }
    }
    return map
  }, [previews.data])

  const [openAd, setOpenAd] = useState<{ creative: CreativeCard; figures: { label: string; value: string }[] } | null>(null)
  /*
   * Drilling moves the TAB as well as the path.
   *
   * Setting only the path would narrow a list the reader is no longer looking at: they clicked an ad
   * set and would still be on the ad-set tab, now showing one row. The tab is URL state too, so both
   * halves of «where am I» stay in the link.
   */
  const write = useUrlWriter()
  const go = (next: DrillStep[], to: DrillLevel) => write({
    drill: { value: encodePath(next), fallback: '' },
    tab: { value: TAB_FOR[to], fallback: 'performance' },
  })

  const heading = level === 'ad_set'
    ? (ar ? 'المجموعات الإعلانية' : 'Ad sets')
    : (ar ? 'الإعلانات' : 'Ads')

  /*
   * ANALYTICS-TABLES-001 — the canonical table, for the same reasons as the Accounts tab.
   *
   * Hand-rolled, numeric columns `text-start` (so the figures and their headings sit against
   * opposite edges under RTL), and unsortable — on the two levels where an operator most wants to
   * re-order, because an ad set list is long and the interesting row is rarely the first one.
   *
   * Sorting reads the values array, so a withheld spend stays last in both directions instead of
   * being read as zero, and a derived cost is sortable only where both its parts are real.
   */
  const head = [
    ar ? 'الاسم' : 'Name',
    ar ? 'الحالة' : 'State',
    ar ? 'الإنفاق' : 'Spend',
    ar ? 'الظهور' : 'Impressions',
    ar ? 'الوصول' : 'Reach',
    ar ? 'التكرار' : 'Frequency',
    ar ? 'النقرات' : 'Clicks',
    'CTR',
    'CPC',
    'CPM',
    ar ? 'النتائج' : 'Results',
    'CPA',
  ]

  const cells = rows.map((row) => [
    <div key={row.entity_id} className="flex items-center gap-2">
      {/*
        The still, where there is one. A row whose ad the library has no creative for renders no
        thumbnail at all rather than a grey placeholder — an empty frame beside eleven real ones
        reads as «this ad has no media», which is a claim about the ad and not about our sync.
      */}
      {level === 'ad' && creativeByAd.has(row.external_id ?? '') && (
        <button
          type="button"
          data-testid={`ad-preview-open-${row.entity_id}`}
          title={ar ? 'معاينة الإعلان' : 'Preview this ad'}
          onClick={() => {
            const creative = creativeByAd.get(row.external_id ?? '')
            if (!creative) return
            setOpenAd({
              creative,
              // The row's own figures — the numbers the reader is looking at, not a second query.
              figures: [
                { label: ar ? 'الإنفاق' : 'Spend', value: rowMoney(row, 'spend', currency) },
                { label: ar ? 'الظهور' : 'Impressions', value: metricOrDash(row.impressions) },
                { label: 'CTR', value: rateOrDash(row.ctr) },
              ],
            })
          }}
          className="shrink-0 overflow-hidden rounded-lg border border-border transition-colors hover:border-brand-500"
        >
          <AdPoster
            preview={creativeByAd.get(row.external_id ?? '')?.preview}
            name={row.name ?? row.external_id ?? ''}
            className="h-9 w-9"
            testid={`ad-thumb-${row.entity_id}`}
          />
        </button>
      )}
      <div className="min-w-0">
      {/*
        Only a row with a level BENEATH it is a button. An ad is the last rung this table serves, and
        a control that looks clickable and does nothing is worse than plain text.
      */}
      {child !== null ? (
        <button
          type="button"
          className="text-start font-medium text-brand-600 hover:underline"
          data-testid={`drill-into-${row.entity_id}`}
          onClick={() => {
            rememberName(row.entity_id, row.name)
            go(drillInto(path, { level, id: row.entity_id, name: row.name }), child)
          }}
        >
          {row.name ?? row.external_id}
        </button>
      ) : (
        <div className="font-medium text-text-primary">
          {/* An entity the sweep has removed keeps its provider id rather than being called
              «Unknown», which would hide that it is gone. */}
          {row.name ?? row.external_id}
        </div>
      )}
      <div className="text-xs text-text-secondary">{row.external_id}</div>
      </div>
    </div>,
    /*
     * The state, beside the row rather than only in its position.
     *
     * Ordering alone is not «clear grouping»: a reader who sorts by spend, or lands mid-table, loses
     * it entirely. `—` for serving, because the ordinary case does not need a badge and a table where
     * every row is decorated says nothing.
     */
    <EntityState key={`state-${row.entity_id}`} row={row} windowEnd={windowEnd} ar={ar} />,
    rowMoney(row, 'spend', currency),
    metricOrDash(row.impressions),
    metricOrDash(row.reach),
    metricOrDash(row.frequency, 2),
    metricOrDash(row.clicks),
    rateOrDash(row.ctr),
    rowCostPer(row, 'cpc', row.clicks ?? 0, currency),
    rowCostPer(row, 'cpm', (row.impressions ?? 0) / 1000, currency),
    metricOrDash(row.conversions),
    rowCostPer(row, 'cpa', row.conversions ?? 0, currency),
  ])

  /*
   * Named `spendOf`, not `money`.
   *
   * It was `money` — a local shadow of the FORMATTER, returning a raw number. Two meanings of one
   * word in one file is precisely how «18.05 SAR» on a USD account survived review: a reader
   * checking «does this call state its currency» sees `money(row)` and moves on.
   */
  const spendOf = (row: (typeof rows)[number]) => (typeof row.spend === 'number' ? row.spend : null)
  const per = (row: (typeof rows)[number], denom: number) => {
    const spend = spendOf(row)

    return spend !== null && denom > 0 ? spend / denom : null
  }

  const values: SortValues[] = rows.map((row) => [
    row.name ?? row.external_id ?? '',
    /*
     * The state sorts by how much attention it deserves — serving, idle, stopped — not
     * alphabetically. A reader sorting this column is asking «what is running», and «Idle» before
     * «Stopped» before nothing is the alphabet answering a different question.
     */
    CAMPAIGN_RELEVANCE_ORDER.indexOf(relevanceOf(row, windowEnd)),
    spendOf(row),
    row.impressions ?? null,
    row.reach ?? null,
    row.frequency ?? null,
    row.clicks ?? null,
    row.ctr ?? null,
    per(row, row.clicks ?? 0),
    per(row, (row.impressions ?? 0) / 1000),
    row.conversions ?? null,
    per(row, row.conversions ?? 0),
  ])

  return (
    <div className="space-y-4">
      <DrillCrumbs path={path} level={level} ar={ar} onUpTo={(lvl) => go(drillUpTo(path, lvl), lvl)} />
      <Panel
        title={heading}
        description={ar ? 'الأعلى إنفاقًا أولًا — ويمكن الترتيب بأي عمود' : 'Highest spend first — sortable by any column'}
        loading={q.isLoading}
        error={q.isError}
        /*
          A narrowed list with no rows is NOT «no data».
          The unnarrowed empty state says the project reported nothing; drilled, it must say this
          parent reported nothing — otherwise a reader concludes the account is dead when what they
          are looking at is one quiet ad set.
        */
        empty={!q.isLoading && rows.length === 0 && parent === null}
      >
        {!q.isLoading && !q.isError && rows.length === 0 && parent !== null ? (
          <p className="rounded-xl border border-border p-3 text-sm text-text-muted" data-testid={`entity-empty-under-parent-${level}`}>
            {ar
              ? 'لم تُسجَّل أي بيانات تحت هذا المستوى في هذه الفترة. هذا ليس «لا توجد بيانات» للمشروع.'
              : 'Nothing was reported under this parent in this period. That is not «no data» for the project.'}
          </p>
        ) : (
          <div data-testid={`entity-table-${level}`}>
            {/*
              No initial sort. The rows arrive in the operational order — serving first, then by
              spend — and re-sorting them by spend here would discard it, which is what happened when
              the state column was added and the table went on sorting by «column 1». Every column is
              still sortable; the reader chooses, and the default is the answer to the question they
              opened the tab with.
            */}
            <MetricTable head={head} rows={cells} values={values} />

            {openAd && (
              <AdPreviewDialog
                creative={openAd.creative}
                locale={ar ? 'ar' : 'en'}
                figures={openAd.figures}
                onClose={() => setOpenAd(null)}
              />
            )}
          </div>
        )}
      </Panel>
    </div>
  )
}

function ObjectiveTab({ projectId, range, filters }: TabProps) {
  const ar = useAr()
  const c = useCampaigns(projectId, range, filters)
  const s = useSummary(projectId, range, filters)
  const currency = s.data?.currency ?? null
  const rows = c.data ?? []
  /*
   * OBJECTIVE-ANALYTICS-DEPTH-001 · FUNNEL-ANALYTICAL-PATTERN-001 — the family cards below say what
   * each objective spent and produced. They do not say which campaign inside a family is carrying it,
   * which is the question an operator opens this tab with, and they do not read the figures back.
   */
  const leaders = useObjectiveLeaders(projectId, range, filters)
  const explanations = useObjectiveExplanations(projectId, range, filters)
  const trend = useObjectiveTrend(projectId, range, filters)

  /*
   * READY-3 — the catalogue owns which KPIs an objective is judged by, and this kept its own copy.
   *
   * The private list named a narrower set than `metricCatalog`'s layouts: no `cpl` for leads, no
   * `cpa`/`aov` for sales, no `cpe`, no `cpi`, no `landing_page_views`, no `registrations`. Two maps
   * of the same thing drift, and the weaker one was the one on screen — a leads family judged
   * without its cost per lead, on the single screen whose whole purpose is to judge each family by
   * what it was bought for.
   *
   * `layoutFor` is what the rest of the product reads, so a metric added to an objective now appears
   * here without anybody remembering this file exists.
   *
   * Ordered so the funnel reads top to bottom; a family with no campaigns is not shown at all.
   */
  const FAMILY_LABELS: Record<string, { ar: string; en: string }> = {
    awareness: { ar: 'الوعي', en: 'Awareness' },
    traffic: { ar: 'الزيارات', en: 'Traffic' },
    engagement: { ar: 'التفاعل', en: 'Engagement' },
    video: { ar: 'المشاهدات', en: 'Video' },
    leads: { ar: 'العملاء المحتملون', en: 'Leads' },
    sales: { ar: 'المبيعات', en: 'Sales' },
    app: { ar: 'التطبيق', en: 'App' },
    unknown: { ar: 'غير مصنَّف', en: 'Unclassified' },
  }

  const FAMILIES = Object.keys(FAMILY_LABELS).map((key) => ({
    key,
    ar: FAMILY_LABELS[key]!.ar,
    en: FAMILY_LABELS[key]!.en,
    kpis: layoutFor(key).primary,
  }))

  const grouped = FAMILIES.map((f) => ({
    ...f,
    campaigns: rows.filter((r) => (r.objective_family ?? 'unknown') === f.key),
  })).filter((f) => f.campaigns.length > 0)

  return (
    <div className="space-y-4">
      <Panel
        title={ar ? 'الأداء حسب الهدف' : 'Performance by objective'}
        description={ar
          ? 'كل عائلة تُقاس بما اشتُريت من أجله — لا يُحكم على حملة وعي بعائد الإنفاق'
          : 'Each family is judged by what it was bought for — an awareness campaign is not judged on ROAS'}
        loading={c.isLoading}
        error={c.isError}
        empty={!c.isLoading && grouped.length === 0}
      >
        <div className="space-y-4" data-testid="objective-families">
          {grouped.map((f) => {

            return (
              <div key={f.key} className="rounded-xl border border-border p-3" data-testid={`objective-family-${f.key}`}>
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <h3 className="font-semibold text-text-primary">{ar ? f.ar : f.en}</h3>
                  <span className="text-xs text-text-secondary">
                    {f.campaigns.length} {ar ? 'حملة' : f.campaigns.length === 1 ? 'campaign' : 'campaigns'}
                    {' · '}
                    <span className="tnum" dir="ltr">
                      {/* Withheld money keeps its own currency; a converted total uses the project's. */}
                      {/*
                        READY-4 — this stated two things it had not checked.
                        It hardcoded `money_original_currencies: 1`, asserting every withheld campaign in the family
                        shared one currency, and took the currency NAME from whichever campaign was found first: two
                        campaigns withheld in USD and EUR were summed and labelled with one of them.
                        And on a PARTIAL family — some converted, some withheld — it dropped the converted spend
                        entirely and printed the withheld half as the family's total.
                        `familySpend` builds the real provenance from the rows, so a partial or multi-currency family
                        fails closed to «—», exactly as every other total in this product does.
                      */}
                      {rowMoney(familySpend(f.campaigns as unknown as FamilyRow[]), 'spend', currency)}
                    </span>
                  </span>
                </div>

                {/* The verdict metrics for THIS family — not the same row of KPIs for every objective. */}
                {/*
                  OBJECTIVE-TOTALS-001 — three defects in one reduce.

                  It added every KPI together. Correct for a count and meaningless for a rate, and
                  every family's list holds one: two sales campaigns returning 3× and 5× printed
                  «8», and two at 1.2% CTR printed «2.4%». `familyTotal` rebuilds a rate from the
                  summed bases, which is the rule `MetricsAggregator::withDerived()` already states
                  on the server and this was the one place re-aggregating without it.

                  It printed the metric KEY as the label, so the row read «conversions 176 revenue
                  56,320 roas 15.36» in an Arabic page.

                  And it formatted money with `toLocaleString` and no currency — «56,320» of
                  nothing — over `spend`, which is the coalesced 0 on a withheld row. So a family
                  whose money awaits a rate would have shown a confident zero next to a revenue the
                  KPI strip states correctly.
                */}
                <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                  {f.kpis.map((k: string) => {
                    const rows = f.campaigns as unknown as FamilyRow[]
                    const spec = SPECS[k]
                    const money = MONEY_KPIS.has(k)
                    const total = money
                      ? readMoney(familyMoney(rows), k as 'spend' | 'revenue', currency, ar).amount
                      : familyTotal(rows, k)
                    const unit = money
                      ? (readMoney(familyMoney(rows), k as 'spend' | 'revenue', currency, ar).currency ?? currency ?? '')
                      : ''

                    return (
                      <div key={k} className="flex flex-col">
                        <dt className="text-text-secondary">
                          {spec ? (ar ? spec.label.ar : spec.label.en) : k}
                        </dt>
                        <dd className="tnum text-text-primary" dir="ltr">
                          {/* Null stays «—»: an unavailable figure is not a figure of zero. */}
                          {total === null
                            ? '—'
                            : money
                              ? `${total.toLocaleString('en-US', { maximumFractionDigits: 2 })} ${unit}`.trim()
                              : spec
                                ? spec.format(total)
                                : total.toLocaleString('en-US', { maximumFractionDigits: 2 })}
                        </dd>
                      </div>
                    )
                  })}
                </dl>

                {/*
                  OBJECTIVE-ANALYTICS-DEPTH-001 — where this family's money actually went.

                  The card above says what the family spent; a list of names says which campaigns
                  exist. Neither answers «where is the budget concentrated», which is the question an
                  operator opens this tab with — eight sales campaigns with ninety per cent of the
                  money in one of them is a fact about a decision, and it is invisible in a total.

                  The share is of THIS FAMILY, never of the account: «40% of sales» is a decision
                  somebody made, «40% of everything» is the mix, and reading the second as
                  concentration makes a small family look concentrated and a large one look spread.
                */}
                <FamilySpend
                  family={f.key}
                  campaigns={f.campaigns.map((r) => ({
                    name: (r.campaign_name ?? r.campaign_id) as string,
                    spend: typeof r.spend === 'number' ? r.spend : 0,
                  }))}
                  ar={ar}
                />
              </div>
            )
          })}
        </div>
      </Panel>

      {/*
        OBJECTIVE-ANALYTICS-DEPTH-001 — each path over time, before the reading of it.

        The blocks below say which campaign inside a path is carrying it; this says whether the path
        itself is going anywhere, which is the question that comes first.
      */}
      <PathTrends
        paths={trend.data?.paths ?? []}
        locale={ar ? 'ar' : 'en'}
        loading={trend.isLoading}
        error={trend.isError}
      />

      <PathAnalysis
        locale={ar ? 'ar' : 'en'}
        currency={currency}
        leaders={leaders.data?.paths ?? []}
        explanations={explanations.data?.paths ?? []}
        loading={leaders.isLoading}
        error={leaders.isError}
      />
    </div>
  )
}

/**
 * A family's spend, and where inside it the money sits.
 *
 * Drawn as bars rather than a pie: three shares read against a common baseline are comparable at a
 * glance, and a pie of eight campaigns is a legend with a decoration attached. A family with fewer
 * than two spending campaigns is told it is not a distribution rather than shown a single bar at
 * a hundred per cent, which is the shape of an answer to a question nobody asked.
 */
function FamilySpend({
  family, campaigns, ar,
}: {
  family: string
  campaigns: { name: string; spend: number }[]
  ar: boolean
}) {
  const d = distributionFor(campaigns)

  if (!d.meaningful) {
    return (
      <ul className="mt-2 space-y-0.5 text-xs text-text-secondary">
        {campaigns.slice(0, 5).map((c) => <li key={c.name} className="truncate">{c.name}</li>)}
      </ul>
    )
  }

  const slices = [...d.slices, ...(d.rest === null ? [] : [d.rest])]

  return (
    <div className="mt-2 flex flex-col gap-1.5" data-testid={`family-distribution-${family}`}>
      <div className="flex h-2 overflow-hidden rounded-full bg-surface-secondary">
        {slices.map((slice, i) => (
          <span
            key={slice.name}
            className={i === 0 ? 'bg-brand-500' : i === 1 ? 'bg-brand-400' : i === 2 ? 'bg-brand-300' : 'bg-border-strong'}
            style={{ width: `${Math.max(1, slice.share * 100)}%` }}
            aria-hidden
          />
        ))}
      </div>

      <ul className="space-y-0.5 text-xs text-text-secondary">
        {slices.map((slice) => (
          <li key={slice.name} className="flex items-center justify-between gap-2">
            <span className="truncate">
              {slice.name === 'rest' ? (ar ? 'بقية الحملات' : 'The rest') : slice.name}
            </span>
            <span className="tnum shrink-0 text-text-muted" dir="ltr">{percent(slice.share, 0)}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/**
 * ANALYTICS-CREATIVE-VISIBLE-001 — the creative rung, inside Analytics.
 *
 * The last level of the drill-down: platform → campaign → ad set → ad → CREATIVE. It reads the same
 * `CreativeAnalysisController` the Content library reads, deliberately — §15.17 calls an independent
 * source an architectural defect rather than a discrepancy, and a second query here would let
 * Analytics and Content disagree about the same creative.
 *
 * Figures come from `creative_daily_metrics` only. Nothing is projected down from the campaign or
 * the ad: a creative that the platform does not break out shows «—», because inventing its share of
 * a campaign total would be a number nobody measured.
 */
function CreativeTab({ projectId, range, filters }: TabProps) {
  const ar = useAr()
  /*
   * HIERARCHY-ENTITY-ANALYTICS-DRILLDOWN — the last rung of campaign → ad set → ad → creative.
   *
   * The library speaks `ad_ids` / `ad_set_ids` rather than the metrics API's single `parent`, so the
   * path is translated rather than passed through, and it takes the DEEPEST pinned rung. It is part
   * of the query key for the same reason `parent` is elsewhere: it changes the request, so a cached
   * unnarrowed response must never answer a drilled-down question.
   */
  const [rawPath] = useUrlState('drill', '')
  const path = useMemo(() => withNames(decodePath(rawPath)), [rawPath])
  const scope = creativeScope(path)
  const write = useUrlWriter()
  const narrowed = scope.ad_ids !== undefined || scope.ad_set_ids !== undefined

  /*
   * ANALYTICS-CREATIVE-SCOPE-001 — this tab ignored the filter bar entirely.
   *
   * It took only `projectId` and `range`, so selecting TikTok left it listing Meta creatives with
   * Meta's figures under a bar that said TikTok. The filter was not weak here, it was decorative —
   * and a table that contradicts the control above it is worse than an empty one, because the
   * reader has no way to know which of the two is lying.
   *
   * The library speaks a different dialect for the same axes — `providers` and `campaign_ids`
   * against the metrics API's `provider` and `campaign` — so they are translated here rather than
   * passed through. `objective` is deliberately not forwarded: the library filters objectives by
   * the CAMPAIGN's objective through its own axis, and mapping the metric filter onto it would
   * narrow twice for one choice.
   */
  const q = useQuery({
    queryKey: [
      'analytics', 'creatives', projectId, range.from, range.to, filters.provider, filters.campaign,
      scope.ad_ids?.join(',') ?? '', scope.ad_set_ids?.join(',') ?? '',
    ],
    queryFn: () => listCreatives(
      {
        from: range.from,
        to: range.to,
        per_page: 24,
        providers: filters.provider?.length ? filters.provider : undefined,
        campaign_ids: filters.campaign?.length ? filters.campaign : undefined,
        // The drill path, in the library's own dialect — the deepest pinned rung only.
        ...scope,
      },
      projectId,
    ),
    enabled: Boolean(projectId),
  })

  const rows = q.data?.creatives ?? []
  const currency = q.data?.currency ?? null

  return (
    <div className="space-y-4">
      <DrillCrumbs path={path} level="creative" ar={ar} onUpTo={(lvl) => write({
        drill: { value: encodePath(drillUpTo(path, lvl)), fallback: '' },
        tab: { value: TAB_FOR[lvl], fallback: 'performance' },
      })} />
      <Panel
        title={ar ? 'أداء الإعلانات' : 'Ad performance'}
        description={ar ? 'من بيانات الإعلان نفسه — لا تُنسب أرقام الحملة إلى إعلان' : 'From ad-level data — campaign figures are never attributed to a ad'}
        loading={q.isLoading}
        error={q.isError}
        /* Narrowed and empty is «nothing under this ad», never «no creatives at all». */
        empty={!q.isLoading && rows.length === 0 && !narrowed}
      >
        {!q.isLoading && !q.isError && rows.length === 0 && narrowed && (
          <p className="rounded-xl border border-border p-3 text-sm text-text-muted" data-testid="creative-empty-under-parent">
            {ar
              ? 'لا يوجد إعلان مسجَّل تحت هذا المستوى في هذه الفترة. هذا ليس «لا يوجد إعلان» للمشروع.'
              : 'No ad was reported under this level in this period. That is not «no ads» for the project.'}
          </p>
        )}
        {/*
          ANALYTICS-TABLES-001 — the canonical table, last of the four hand-rolled ones.
          Numeric columns were `text-start`, so under RTL the figures and their headings sat against
          opposite edges; and the list could not be re-ordered, on the tab where «which creative did
          best» is the entire question being asked.
        */}
        <div data-testid="creative-analysis-table">
          <MetricTable
            head={[
              ar ? 'الإعلان' : 'Ad',
              ar ? 'الحملة' : 'Campaign',
              ar ? 'الهدف' : 'Objective',
              ar ? 'الإنفاق' : 'Spend',
              ar ? 'الظهور' : 'Impressions',
              ar ? 'النقرات' : 'Clicks',
              'CTR',
              ar ? 'آخر نشاط' : 'Last active',
            ]}
            rows={rows.map((cr) => [
              <span key={cr.id} className="block max-w-56 truncate font-medium text-text-primary">{cr.name}</span>,
              <span key={`${cr.id}-c`} className="block max-w-40 truncate text-text-secondary">{cr.campaign_name ?? '—'}</span>,
              cr.objective ?? '—',
              rowMoney(cr.metrics ?? undefined, 'spend', currency),
              metricOrDash(cr.metrics?.impressions ?? null),
              metricOrDash(cr.metrics?.clicks ?? null),
              rateOrDash(cr.metrics?.ctr ?? null),
              cr.freshness?.last_active_at ? fmtDate(cr.freshness.last_active_at) : '—',
            ])}
            values={rows.map((cr) => [
              cr.name ?? '',
              cr.campaign_name ?? '',
              cr.objective ?? '',
              typeof cr.metrics?.spend === 'number' ? cr.metrics.spend : null,
              cr.metrics?.impressions ?? null,
              cr.metrics?.clicks ?? null,
              cr.metrics?.ctr ?? null,
              // A date sorts as a date, not as the string it is printed as.
              cr.freshness?.last_active_at ? Date.parse(cr.freshness.last_active_at) : null,
            ])}
            initialSort={{ column: 3, dir: 'desc' }}
          />
        </div>
      </Panel>
    </div>
  )
}

/**
 * ANALYTICS-DRILLDOWN-001 — the ad accounts beneath a platform.
 *
 * The chain read Platform → Campaign, skipping the level an operator manages. A customer can hold
 * several ad accounts on one platform, and «Snapchat spent X» is not an answer when two accounts run
 * different markets from different budgets.
 *
 * Grouped on the account each row was INGESTED for, so the attribution is real rather than inferred
 * from a campaign name. An account removed since ingestion keeps its spend and shows no name, rather
 * than being called «Unknown» — which would hide that it is gone.
 */
function AccountsTab({ projectId, range, filters }: TabProps) {
  const ar = useAr()
  const a = useAccounts(projectId, range, filters)
  const s = useSummary(projectId, range, filters)
  const currency = s.data?.currency ?? null
  const rows = a.data ?? []

  /*
   * ANALYTICS-TABLES-001 — the canonical table, not a hand-rolled one.
   *
   * This built its own `<table>` and so missed everything `MetricTable` had learned. Its numeric
   * columns were `text-start`: under RTL the figures sat against one edge while their headings sat
   * against the other, which is the alignment defect #109 fixed everywhere it was already used. And
   * the rows could not be sorted at all.
   *
   * It also said «مرتّبة حسب الإنفاق» — ordered by spend — while sorting by impressions. A caption
   * that had drifted from its own list, with nothing in place to catch it.
   *
   * Sorting reads the values array; the rendered cells stay formatted strings, so money keeps its
   * provenance and an absent figure stays «—» instead of sorting as a zero.
   */
  const head = [
    ar ? 'الحساب' : 'Account',
    ar ? 'المنصة' : 'Platform',
    ar ? 'الإنفاق' : 'Spend',
    ar ? 'الظهور' : 'Impressions',
    ar ? 'النقرات' : 'Clicks',
    'CTR',
    'CPM',
  ]

  const cells = rows.map((r) => [
    r.account_name ?? (ar ? 'حساب لم يعد متاحًا' : 'Account no longer available'),
    providerLabel(r.provider, ar ? 'ar' : 'en'),
    rowMoney(r, 'spend', currency),
    metricOrDash(r.impressions),
    metricOrDash(r.clicks),
    rateOrDash(r.ctr),
    rowCostPer(r, 'cpm', (r.impressions ?? 0) / 1000, currency),
  ])

  const values: SortValues[] = rows.map((r) => [
    r.account_name ?? '',
    providerLabel(r.provider, ar ? 'ar' : 'en'),
    typeof r.spend === 'number' ? r.spend : null,
    r.impressions ?? null,
    r.clicks ?? null,
    r.ctr ?? null,
    // CPM is derived, so it is only sortable where both parts are real — never from a coalesced zero.
    (r.impressions ?? 0) > 0 && typeof r.spend === 'number' ? r.spend / ((r.impressions ?? 0) / 1000) : null,
  ])

  return (
    <Panel
      title={ar ? 'الحسابات الإعلانية' : 'Ad accounts'}
      description={ar ? 'الأعلى إنفاقًا أولًا — ويمكن الترتيب بأي عمود' : 'Highest spend first — sortable by any column'}
      loading={a.isLoading}
      error={a.isError}
      empty={!a.isLoading && rows.length === 0}
    >
      <div data-testid="account-table">
        <MetricTable head={head} rows={cells} values={values} initialSort={{ column: 2, dir: 'desc' }} />
      </div>
    </Panel>
  )
}
