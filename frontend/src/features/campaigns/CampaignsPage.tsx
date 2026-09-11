import { useEffect, useMemo, useState } from 'react'
import { StatCard as SharedStatCard } from '@/components/ui/StatCard'
import { portfolioBudget } from '@/lib/money/portfolioBudget'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { BarChart3, GitCompare, LayoutGrid, Plus, Rows, Search, TriangleAlert } from 'lucide-react'
import { listCampaigns } from './api'
import { CampaignFormModal } from './CampaignFormModal'
import { CampaignComparison } from './CampaignComparison'
import { attentionFlags, attentionRank, type AttentionMetrics } from './campaignInsights'
import { campaignStatusLabel, campaignStatusTone, objectiveLabel } from './labels'
import { CAMPAIGN_STATUSES, type UnifiedCampaign } from './types'
import { CANONICAL_OBJECTIVE_KEYS, canonicalObjectiveLabel, canonicalOfRaw, rawObjectivesFor, type CanonicalObjectiveKey } from './canonicalObjectives'
import { LIFECYCLE_KEYS, lifecycleView, type Lifecycle } from './campaignLifecycleView'
import { campaignEfficiency, campaignHeadline, type CampaignHeadline } from './campaignHeadline'
import { campaignRelevance, type CampaignRelevance } from './campaignRelevance'
import { bandCounts, byPriority, type CampaignBand } from './campaignPriority'
import { movers } from './campaignMovers'
import { campaignState } from './campaignState'
import { landingAnswer } from './campaignsLanding'

/** «النشطة» is what an operator still owns this month — serving and switched-on-but-dark alike. */
const LIFECYCLE_LABELS: Record<Lifecycle, { ar: string; en: string }> = {
  active: { ar: 'النشطة', en: 'Active' },
  inactive: { ar: 'غير النشطة', en: 'Inactive' },
  all: { ar: 'الكل', en: 'All' },
}
import { useUrlNumber, useUrlState } from '@/features/analytics/filterUrlState'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Select } from '@/components/ui/Select'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { ChartCard, PlatformDonutChart, ProgressRing, RankingBarChart, SpendRevenueAreaChart } from '@/features/analytics/charts'
import { useBudget, useCampaigns, usePlatforms, useSummary, useTimeseries, type BudgetRow } from '@/features/analytics/api'
import { useLastNDaysRange } from '@/features/analytics/hooks'
import { ProvenanceBadge, RangeTabs, TrendPill } from '@/features/analytics/components'
import { compact, money, num, rowCostPer, rowRoas } from '@/features/analytics/format'
import { campaigns as countedCampaigns } from '@/lib/counted'
import { rankableMoney, resolveMoneySeries, type MoneyTotals } from '@/lib/money/contract'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { useT } from '@/lib/i18n'
import { orderAttention } from './attentionOrdering'

const STATUS_COLORS: Record<string, string> = {
  active: 'var(--success)', paused: 'var(--warning)', completed: 'var(--info)',
  draft: 'var(--text-muted)', scheduled: 'var(--purple)', archived: 'var(--border-strong)',
}

/** The five ways to look at a project's campaigns (CAMPAIGN-010). */
type ViewMode = 'overview' | 'cards' | 'table' | 'compare' | 'attention'

/**
 * CAMPAIGNS-OVERVIEW-FIRST-001 — the order the owner asked for, and the reason it is an order at all.
 *
 * «Overview first, then Analytics / deeper analysis, then Table / list, optional Cards, optional
 * Comparison. Do NOT let cards/grid feel like the main default analytical surface.»
 *
 * The list already opened with Overview and the page still landed on CARDS, because the default was
 * hard-coded past it — so the first thing anybody saw was a grid of tiles, one campaign each, with no
 * portfolio answer anywhere. «Needs attention» keeps its own entry at the end: it is a filtered
 * TABLE rather than a mode of reading the portfolio, and the count beside it is what makes it
 * findable without competing with the first screen.
 */
/**
 * The five bands the portfolio is classified into — the owner's own words, in the owner's own order.
 *
 * Kept beside `VIEWS` rather than inside the component: these are the product's vocabulary, and a
 * label defined at a call site is a label the next surface will spell differently.
 */
const BANDS: Array<{ id: CampaignBand; ar: string; en: string }> = [
  { id: 'attention', ar: 'تحتاج تدخلًا', en: 'Needs attention' },
  { id: 'spending', ar: 'نشطة وتنفق', en: 'Active and spending' },
  { id: 'weak', ar: 'نشطة وضعيفة', en: 'Active but weak' },
  { id: 'paused', ar: 'متوقفة مؤخرًا', en: 'Paused recently' },
  { id: 'ended', ar: 'منتهية', en: 'Ended' },
]

const VIEWS: Array<{ id: ViewMode; ar: string; en: string; icon: typeof LayoutGrid }> = [
  { id: 'overview', ar: 'نظرة عامة', en: 'Overview', icon: BarChart3 },
  { id: 'table', ar: 'جدول', en: 'Table', icon: Rows },
  { id: 'cards', ar: 'بطاقات', en: 'Cards', icon: LayoutGrid },
  { id: 'compare', ar: 'مقارنة', en: 'Comparison', icon: GitCompare },
  { id: 'attention', ar: 'تحتاج تدخلًا', en: 'Needs attention', icon: TriangleAlert },
]

export function CampaignsPage() {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const navigate = useNavigate()
  const canCreate = useAuth((s) => s.hasPermission('campaigns.create'))
  const { currentProjectId: projectId } = useProject()

  const [days, setDays] = useUrlNumber('days', 30)
  // PERF-CAMPAIGNS-001: the page opens on the CARD LIST, not the chart-heavy overview. Four charts plus
  // five metric queries on first paint made the page slow to become interactive on Firefox under load —
  // and a page called "campaigns" should show campaigns first anyway. Overview is one click away.
  /*
    OVERVIEW, not cards — CAMPAIGNS-OVERVIEW-FIRST-001.

    «The first thing the user sees on entering Campaigns must be a strong campaign portfolio
    overview.» It was a grid of tiles: one campaign per card, no portfolio total, no pacing, nothing
    ranked. A reader arriving to answer «what needs me today» had to find the control that would tell
    them before they could start.
  */
  const [view, setView] = useState<ViewMode>('overview')
  const [compareIds, setCompareIds] = useState<string[]>([])
  /*
   * ANALYTICS-FILTER-TRUTH-001 — these live in the URL, so a refresh, Back and a shared link all
   * show the same list. `search` deliberately does not: it is typed a character at a time, and
   * writing every keystroke into the history would make Back unusable on this page.
   */
  const [lifecycle, setLifecycle] = useUrlState('lifecycle', 'active') as [Lifecycle, (v: string) => void]
  const [status, setStatus] = useUrlState('status', '')
  const [objective, setObjective] = useUrlState('objective', '')
  const [search, setSearch] = useState('')
  const [modalOpen, setModalOpen] = useState(false)
  const range = useLastNDaysRange(days)

  /*
   * The canonical key expanded into the raw objectives the API filters on — the same expansion the
   * dashboard and Analytics do, through the same mirror. Undefined rather than an empty string when
   * nothing is chosen, because an empty value is «no filter» and must not be sent as one.
   */
  const objectiveParam = useMemo(() => {
    const raw = rawObjectivesFor(objective === '' ? 'all' : (objective as CanonicalObjectiveKey))

    return raw.length === 0 ? undefined : raw.join(',')
  }, [objective])

  // Everything below is PROJECT-SCOPED — cache keys + endpoints carry projectId; disabled without one.
  /*
   * A change of scope puts the reader back on page one — page 3 of a narrower list is nowhere, and
   * the relevance ranking it was cut from no longer exists.
   */
  const [page, setPage] = useState(1)

  useEffect(() => setPage(1), [projectId, status, objectiveParam, search])

  const campaignsQuery = useQuery({
    /*
     * ANALYTICS-OBJECTIVE-SYSTEM-001 — the reader picks a canonical objective, the server gets the
     * raw ones it covers.
     *
     * `objectiveParam` is part of the key as well as the request: it is what actually narrows the
     * query, and keying on the canonical label instead would be one cache entry per label over
     * whatever the previous scope fetched.
     */
    queryKey: ['project', projectId, 'campaigns', { status, objective: objectiveParam, search }, page],
    queryFn: () => listCampaigns(projectId!, {
      status: status || undefined, objective: objectiveParam, search: search || undefined,
      page, from: range.from, to: range.to,
    }),
    enabled: Boolean(projectId),
    placeholderData: (prev) => prev,
  })
  const summary = useSummary(projectId, range)
  const timeseries = useTimeseries(view === 'overview' ? projectId : null, range)
  const platforms = usePlatforms(view === 'overview' ? projectId : null, range)
  const budget = useBudget(projectId, range)
  const metricCampaigns = useCampaigns(projectId, range)

  /*
   * CAMPAIGNS-LEDGER-001 — the rows are a page; the counts are the PROJECT's.
   *
   * The endpoint handed over every campaign and these counts were taken from the array it returned.
   * Campaigns are the one list here that grows without a ceiling, and a donut of the first
   * twenty-five looks exactly like a donut of the project — which is why the counts could not simply
   * follow the rows into a page.
   *
   * The ordering moved to the server with the page, for the same reason: re-ordering twenty-five rows
   * in the browser would make «what is running» mean «what is running among the newest twenty-five».
   */
  const campaigns = campaignsQuery.data?.campaigns ?? []
  const counts = useMemo(() => {
    const server = campaignsQuery.data?.counts ?? {}
    const c: Record<string, number> = { total: campaignsQuery.data?.total ?? 0 }
    for (const s of CAMPAIGN_STATUSES) c[s] = server[s] ?? 0

    return c
  }, [campaignsQuery.data])

  const statusDonut = useMemo(
    () => CAMPAIGN_STATUSES.map((s) => ({ name: campaignStatusLabel(s, locale), value: counts[s] ?? 0 })).filter((d) => d.value > 0),
    [counts, locale],
  )
  /*
   * CAMP-BUDGET-CURRENCY-001 — «الميزانية 80K · مصروف 3.7K». Eighty thousand of what?
   *
   * Both figures were rendered with `compact()`, which states a magnitude and no unit, on a card
   * beside KPI cards that do name their currency. And they were summed across campaigns without
   * asking whether those campaigns share one: adding a riyal budget to a dollar budget produces a
   * number that is not money.
   *
   * `budgetPacing` now returns `budget_currency` and `spent_currency` per row (BUDGET-WITHHELD-001),
   * so both questions can be answered instead of assumed. A mixed set is refused rather than summed
   * — the card says how many currencies are in play, which is the honest headline for that case.
   */
  const budgetTotals = useMemo(() => {
    const b = budget.data ?? []

    const currencies = new Set(b.map((r) => r.budget_currency).filter((c): c is string => typeof c === 'string' && c !== ''))
    const spentCurrencies = new Set(b.filter((r) => r.spent !== null).map((r) => r.spent_currency).filter((c): c is string => typeof c === 'string' && c !== ''))

    const total = b.reduce((a, r) => a + Number(r.budget ?? 0), 0)

    /*
     * PARTIAL-WITHHELD-001 — an aggregate spend exists only when EVERY campaign is a single spend
     * figure (a partial or mixed row carries `spent: null`) AND they all agree on one currency. A
     * partial campaign is not 0, and summing only the convertible subset states less than was spent
     * as though it were the whole. Any of those ⇒ unavailable, never `Number(r.spent ?? 0)`.
     *
     * A TOTAL fails closed. The two spend CHARTS below deliberately do not: see `rankableMoney`.
     */
    const spendComplete = b.length > 0 && b.every((r) => r.spent !== null) && spentCurrencies.size <= 1
    const spent = spendComplete ? b.reduce((a, r) => a + Number(r.spent ?? 0), 0) : null

    const budgetCurrency = currencies.size === 1 ? [...currencies][0] : null
    const spentCurrency = spentCurrencies.size === 1 ? [...spentCurrencies][0] : null
    // remaining/consumed compare spend to budget, so both must be one figure in the SAME currency.
    const comparable = spent !== null && total > 0
      && budgetCurrency !== null && spentCurrency !== null
      && budgetCurrency.toUpperCase() === spentCurrency.toUpperCase()

    return {
      total,
      spent,
      remaining: comparable ? total - (spent as number) : null,
      consumed: comparable ? (spent as number) / total : null,
      /** Null when the campaigns disagree — then no single figure can be stated. */
      currency: budgetCurrency,
      spentCurrency,
      currencyCount: currencies.size,
      /*
       * Whether there is a budget to speak about at all.
       *
       * Without this the card read «0 SAR» when no campaign has a budget — naming a currency for a
       * figure that does not exist, which is the same invention `money()`'s SAR default caused on
       * the CPA card. The first pass at this fix reintroduced it and an existing test caught it.
       */
      known: b.length > 0,
    }
  }, [budget.data])
  /*
   * PARTIAL-WITHHELD-001 — the spend CHARTS drop and disclose, where the totals above fail closed.
   *
   * A donut and a ranking are not a total. Refusing all six platforms because one is withheld hides
   * five that are perfectly known, so `rankableMoney` keeps every row that has a comparable
   * magnitude in one currency, leaves the rest off, and reports how many it left — which each card's
   * subtitle then states. A chart quietly showing fewer rows than the account has would be the same
   * lie in another shape, so the count is never dropped on the floor.
   */
  const topCampaigns = useMemo(() => {
    const rows = (metricCampaigns.data ?? []).slice(0, 6)
    const r = rankableMoney(rows as MoneyTotals[], 'spend', summary.data?.currency ?? null)
    if (r === null) return null
    return {
      data: rows.flatMap((c, i) => {
        const spend = r.values[i]
        return spend === null ? [] : [{ label: String(c.campaign_name ?? '—'), spend, platform: String(c.provider ?? '') }]
      }),
      dropped: r.dropped,
    }
  }, [metricCampaigns.data, summary.data?.currency])

  const platformSpend = useMemo(() => {
    const rows = platforms.data ?? []
    const r = rankableMoney(rows as MoneyTotals[], 'spend', summary.data?.currency ?? null)
    if (r === null) return null
    return {
      data: rows.flatMap((pl, i) => {
        const value = r.values[i]
        return value === null ? [] : [{ name: String(pl.provider), value }]
      }),
      dropped: r.dropped,
    }
  }, [platforms.data, summary.data?.currency])

  /*
   * PARTIAL-WITHHELD-001 (d/f) — the spend/revenue trend must plot EFFECTIVE money in one currency,
   * or nothing. Raw rows draw a withheld day as 0 and a partial day as a fabricated figure; a trend
   * cannot drop a point and stay honest, so it fails closed to «unavailable» (unlike the donut/
   * ranking above, which drop-and-disclose).
   */
  const moneySeries = useMemo(
    () => resolveMoneySeries((timeseries.data ?? []) as unknown as Array<Record<string, unknown>>, ['spend', 'revenue'], summary.data?.currency ?? null),
    [timeseries.data, summary.data?.currency],
  )

  // Per-campaign metric slice, keyed by campaign id — the needs-attention rules read from this and
  // report "no data" rather than assuming a campaign without metrics is healthy.
  const metricsByCampaign = useMemo(() => {
    const map = new Map<string, AttentionMetrics>()
    for (const r of metricCampaigns.data ?? []) {
      const id = String((r as unknown as Record<string, unknown>).campaign_id ?? '')
      if (id) map.set(id, r as unknown as AttentionMetrics)
    }
    return map
  }, [metricCampaigns.data])

  /*
   * CAMPAIGN-INTELLIGENCE-HUB — the workspace opens on what is RUNNING.
   *
   * It listed every campaign the project has ever had, newest first, so a project with two years of
   * history opened on whatever was created last. The lifecycle is read through the shared
   * `campaignRelevance` rule — status alone is the definition REPORT-SCOPE-SELECTION-001 warns
   * against — joined to the two facts the metrics window carries.
   *
   * `metricsKnown` is the honest half: relevance cannot be computed before those rows arrive, and
   * «active only» over unknown relevance would render an empty workspace as a statement about the
   * account rather than about a request that has not answered.
   */
  const metricsKnown = !metricCampaigns.isPending && !metricCampaigns.isError

  const lifecycleRows = useMemo(
    () => campaigns.map((c) => {
      const m = metricsByCampaign.get(c.id) as (AttentionMetrics & { last_active_on?: string | null }) | undefined

      return { ...c, last_active_on: m?.last_active_on ?? null, spend: m?.spend ?? null }
    }),
    [campaigns, metricsByCampaign],
  )

  const lifecycleShown = useMemo(
    () => lifecycleView(lifecycleRows, { lifecycle, windowEnd: range.to, metricsKnown }),
    [lifecycleRows, lifecycle, range.to, metricsKnown],
  )

  const visibleCampaigns = lifecycleShown.rows

  /*
   * Computed over the campaigns the reader is actually looking at, not the whole project: an answer
   * about «what needs attention» must describe the same set the list below it shows, or the two
   * disagree and the reader cannot tell which is wrong.
   */
  const landing = useMemo(
    () => landingAnswer(
      visibleCampaigns.map((c) => ({ id: c.id, objective: c.objective })),
      metricsByCampaign as Map<string, Record<string, unknown>>,
      budget.data,
    ),
    [visibleCampaigns, metricsByCampaign, budget.data],
  )

  const attention = useMemo(
    () => orderAttention(campaigns
      .map((c) => ({ c, flags: attentionFlags(c, metricsByCampaign.get(c.id), summary.data?.currency ?? null) }))
      .filter((x) => x.flags.length > 0)
      /*
        ENTITY-RELEVANCE-ORDERING-001 — rank first, then a key that cannot move.

        Severity is a small integer, so a dozen flagged campaigns are mostly ties, and `sort` left
        those in whatever order the campaigns API returned. An operator working down a half-finished
        list cannot tell whether it moved because something happened or because nothing did.
      */
      .map((x) => ({ ...x, id: x.c.id, rank: attentionRank(x.flags), name: x.c.name }))),
    // The reporting currency decides whether an over-budget comparison is possible at all, so the
    // flags must recompute when it arrives — otherwise the first render's «no verdict» would stick.
    [campaigns, metricsByCampaign, summary.data?.currency],
  )

  /*
   * CAMPAIGNS-OVERVIEW-FIRST-001 — the portfolio in the order the owner asked to read it in.
   *
   * «Needs attention, active and spending, active but weak, paused recently, ended.» The lifecycle
   * view already separates running from stopped; what it cannot express is whether the reader has
   * something to DO about a row. The attention set is computed just above from the same flags the
   * «تحتاج تدخلًا» list is built from, so the first campaign in this list and the first campaign in
   * that one are the same campaign — two rankings over one set of flags would drift the moment
   * either changed.
   */
  const attentionIds = useMemo(() => new Set(attention.map((a) => a.id)), [attention])

  const orderedCampaigns = useMemo(
    () => byPriority(
      visibleCampaigns.map((c) => ({
        ...c,
        campaign_id: c.id,
        needs_attention: attentionIds.has(c.id),
        /*
         * Efficiency is left UNJUDGED here rather than guessed.
         *
         * The server has no per-campaign efficiency verdict on this payload, and inventing one from
         * a ratio would be a score — which this product does not draw. An unjudged campaign bands as
         * «spending», not as «weak»: a verdict conjured from an absence is the coalesced zero again.
         */
        efficient: null,
      })),
      range.to,
    ),
    [visibleCampaigns, attentionIds, range.to],
  )

  /*
   * CAMPAIGNS-OVERVIEW-FIRST-001 — «which campaigns are deteriorating, which are improving».
   *
   * The bands say what state a campaign is IN; they cannot say what CHANGED, and a reader scanning
   * for a problem is usually looking for movement rather than for a level. `spend_change` is the
   * per-campaign comparison the server already computes — `byCampaign()` takes the immediately
   * preceding window, the same one the KPI strip's deltas are measured against, so the two cannot
   * disagree about what «previous» means.
   *
   * SPEND movement, and the block says so. There is no per-campaign result or efficiency comparison
   * on this payload, and deriving one from a single window would be a trend invented out of an
   * absence.
   */
  const movement = useMemo(
    () => movers(visibleCampaigns.map((c) => {
      const m = metricsByCampaign.get(c.id) as { spend?: number | null; previous_spend?: number | null; spend_change?: number | null } | undefined

      return {
        id: c.id,
        name: c.name,
        spend: m?.spend ?? null,
        previous_spend: m?.previous_spend ?? null,
        spend_change: m?.spend_change ?? null,
      }
    })),
    [visibleCampaigns, metricsByCampaign],
  )

  const bands = useMemo(
    () => bandCounts(orderedCampaigns, range.to),
    [orderedCampaigns, range.to],
  )

  const toggleCompare = (id: string) =>
    setCompareIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : prev.length >= 5 ? prev : [...prev, id]))

  const k = summary.data?.current
  const d = summary.data?.delta ?? {}

  /*
   * CAMP-MONEY-001 — this row read the aggregator's zero, in a currency it assumed.
   *
   * `money(k?.cpa)` took the coalesced figure and formatted it with the helper's SAR default, so on
   * an account reporting in USD with no rate available the card printed «0 SAR» over real spend —
   * the defect MONEY-TRUTH-001 fixed on the dashboard and the analytics board, still shipping here.
   * `ratio(k?.roas)` did the same one derivation down and printed «0.00x».
   *
   * Read through the canonical helpers, so this screen cannot disagree with the two that already
   * read the same totals correctly.
   */
  const cpaText = rowCostPer(k, 'cpa', 'conversions', summary.data?.currency ?? null)
  const roasText = rowRoas(k)

  /*
   * CAMP-COMPARE-001 — a delta is absent when there is nothing to compare against, not «unchanged».
   *
   * `undefined` removes the pill; `null` renders the «— —» that made a missing comparison window
   * look like a flat month. Same reading the board uses, from the same field.
   */
  const comparable = summary.data?.previous_rows_in_scope !== false
  const cmp = (v: number | null | undefined) => (comparable ? v ?? null : undefined)

  /*
   * No project chosen yet — a CHOICE, not a broken page (AGENCY-006).
   *
   * Reached differently in each portal, which is why the copy names the control rather than the
   * portal: an advertiser picks a project, an agency picks a client and then a project. What both
   * must never see is the previous selection's campaigns still on screen, so this renders instead of
   * the list rather than alongside an empty one.
   */
  if (!projectId) {
    return (
      <div className="space-y-6">
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'الحملات' : 'Campaigns'}
        </h1>
        <EmptyState
          title={ar ? 'اختر مشروعًا' : 'Select a project'}
          description={ar
            ? 'حملات كل مشروع مستقلة — اختر مشروعًا من المبدّل لعرض حملاته.'
            : 'Each project has its own campaigns — pick one from the switcher to see them.'}
        />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header — project context */}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{ar ? 'الحملات' : 'Campaigns'}</h1>
            <ProvenanceBadge provenance={summary.data?.provenance} />
          </div>
          <p className="mt-1 text-sm text-text-secondary">
            <span className="tnum font-semibold text-text-primary">{countedCampaigns(counts.total, ar ? 'ar' : 'en')}</span>
            {ar ? ' في المشروع الحالي — كل مشروع معزول عن غيره.' : ' in the current project — each project is isolated from the others.'}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <RangeTabs value={days} onChange={setDays} />
          {canCreate && <Button onClick={() => setModalOpen(true)}><Plus size={16} /> {t('new_campaign')}</Button>}
        </div>
      </div>

      {/* Summary cards — CURRENT PROJECT only */}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <StatCard label={ar ? 'نشطة' : 'Active'} value={String(counts.active ?? 0)} sub={ar ? `${counts.total} إجمالًا` : `${counts.total} in total`} tone="success" />
        {/*
          CAMP-COPY-001 — «تحتاج مراجعة» under a zero asserted that nothing needed reviewing and
          that it needed reviewing. The caption follows the count, and the warning tone with it.
        */}
        <StatCard
          label={ar ? 'متوقفة' : 'Paused'}
          value={String(counts.paused ?? 0)}
          sub={(counts.paused ?? 0) > 0 ? (ar ? 'تحتاج مراجعة' : 'Need a look') : (ar ? 'لا شيء متوقف' : 'None paused')}
          tone={(counts.paused ?? 0) > 0 ? 'warning' : undefined}
        />
        <StatCard
          testid="campaigns-budget-total"
          label={ar ? 'الميزانية' : 'Budget'}
          value={!budgetTotals.known
            ? '—'
            : budgetTotals.currencyCount > 1
              ? (ar ? `${budgetTotals.currencyCount} عملات` : `${budgetTotals.currencyCount} currencies`)
              : money(budgetTotals.total, budgetTotals.currency ?? undefined)}
          sub={!budgetTotals.known
            ? (ar ? 'لم تُحدَّد ميزانية لأي حملة' : 'No campaign has a budget set')
            : budgetTotals.currencyCount > 1
              ? (ar ? 'ميزانيات بعملات مختلفة — لا تُجمع' : 'Budgets in different currencies — not summed')
              : budgetTotals.spent === null
                ? (ar ? 'المصروف غير متاح — مبالغ جزئية أو بعملات متعددة' : 'Spend unavailable — partial or multi-currency')
                : ar
                  ? `مصروف ${money(budgetTotals.spent, budgetTotals.spentCurrency ?? budgetTotals.currency ?? undefined)}`
                  : `${money(budgetTotals.spent, budgetTotals.spentCurrency ?? budgetTotals.currency ?? undefined)} spent`}
        />
        <StatCard label={ar ? 'النتائج' : 'Results'} value={num(k?.conversions)} delta={cmp(d.conversions)} />
        <StatCard label="CPA" value={cpaText} delta={cmp(d.cpa)} invert />
        <StatCard label="ROAS" value={roasText} delta={cmp(d.roas)} />
      </div>

      {/* View switcher — the five modes of CAMPAIGN-010. */}
      <div className="flex flex-wrap items-center gap-1 rounded-xl border border-border bg-surface-secondary p-1">
        {VIEWS.map((v) => {
          const Icon = v.icon
          const on = view === v.id
          const count = v.id === 'attention' ? attention.length : v.id === 'compare' ? compareIds.length : 0
          return (
            <button
              key={v.id}
              data-testid={`view-${v.id}`}
              aria-pressed={on}
              onClick={() => setView(v.id)}
              className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors ${
                on ? 'bg-surface text-text-primary shadow-[var(--shadow-small)]' : 'text-text-secondary hover:text-text-primary'
              }`}
            >
              <Icon size={15} className={v.id === 'attention' && attention.length > 0 ? 'text-warning' : undefined} />
              {ar ? v.ar : v.en}
              {count > 0 && <span className="tnum rounded-full bg-surface-secondary px-1.5 text-[11px] text-text-secondary">{count}</span>}
            </button>
          )
        })}
      </div>

      {view === 'overview' ? (
        <>
          {/*
            CAMPAIGNS-OVERVIEW-FIRST-001 — the portfolio's SHAPE, before any one campaign.

            «Segment campaigns visually by active, needs attention, healthy, paused, ended.» Five
            counts, in the order the list below them is ranked, so the strip and the list are the
            same statement read twice rather than two answers a reader has to reconcile. Each band is
            a door: clicking one narrows the list to it.

            A band holding nothing is shown at zero rather than hidden — «no campaigns need
            attention» is the answer somebody came for, and a strip that silently loses its most
            important word when the news is good is a strip that cannot be trusted when it is bad.
          */}
          <div data-testid="campaigns-bands" className="flex flex-wrap gap-2">
            {BANDS.map((b) => (
              <button
                key={b.id}
                type="button"
                data-testid={`campaigns-band-${b.id}`}
                data-count={bands[b.id]}
                onClick={() => { setView('table'); setLifecycle('all') }}
                className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-sm ${
                  b.id === 'attention' && bands[b.id] > 0
                    ? 'border-warning/40 bg-warning/10 text-text-primary'
                    : 'border-border bg-surface text-text-secondary hover:border-brand-400'
                }`}
              >
                <span className="font-semibold">{ar ? b.ar : b.en}</span>
                <span className="tnum font-bold text-text-primary" dir="ltr">{bands[b.id]}</span>
              </button>
            ))}
          </div>

          {/*
            BUDGET-GOVERNANCE-001 — what is LEFT, where the period ENDS, and whether that is over.

            The page showed a budget and what had been spent and stopped there, while the server had
            already computed the remaining, the straight-line projection and the pace for every
            campaign. DATA → VISUAL → COMPARISON → SHORT INTERPRETATION: four figures, a bar against
            the elapsed budget, and one sentence that names the overrun in money rather than leaving
            a ratio to be interpreted.

            An incomparable scope reads «—» and says why. It never reads 0%, which an operator takes
            as «we have spent nothing» when the truth is that nothing here could be added up.
          */}
          <BudgetPacingRow rows={budget.data ?? []} ar={ar} />
          {/* Charts — all from the project-scoped metrics API. */}
          <div className="grid gap-4 lg:grid-cols-3">
            <ChartCard title={ar ? 'الإنفاق مقابل الإيرادات' : 'Spend vs revenue'} subtitle={ar ? 'اتجاه المشروع' : 'How the project is trending'} className="lg:col-span-2">
              {timeseries.isLoading
                ? <Skeleton className="h-[200px]" />
                : moneySeries === null
                  ? <div className="flex h-[200px] items-center justify-center text-center text-xs text-text-muted">{ar ? 'الإنفاق/الإيراد عبر الزمن غير متاح — مبالغ بانتظار سعر صرف أو بعملات متعددة' : 'Spend/revenue over time unavailable — amounts await a rate or span currencies'}</div>
                  : <SpendRevenueAreaChart data={moneySeries.rows} currency={moneySeries.currency ?? undefined} height={200} />}
            </ChartCard>
            <ChartCard
              title={ar ? 'توزيع الإنفاق' : 'Where the spend went'}
              subtitle={
                platformSpend !== null && platformSpend.dropped > 0
                  ? (ar ? `حسب المنصة — ${platformSpend.dropped} غير محتسَبة` : `By platform — ${platformSpend.dropped} withheld`)
                  : (ar ? 'حسب المنصة' : 'By platform')
              }
            >
              {platforms.isLoading
                ? <Skeleton className="h-[200px]" />
                : platformSpend === null
                  ? <div className="flex h-[200px] items-center justify-center text-center text-xs text-text-muted">{ar ? 'توزيع الإنفاق غير متاح — مبالغ جزئية أو بعملات متعددة لا تُجمع' : 'Spend share unavailable — partial or multi-currency amounts'}</div>
                  : <PlatformDonutChart data={platformSpend.data} centerLabel={ar ? 'الإجمالي' : 'Total'} centerValue={compact(platformSpend.data.reduce((a, b) => a + b.value, 0))} height={200} />}
            </ChartCard>
          </div>
          <div className="grid gap-4 lg:grid-cols-3">
            <ChartCard title={ar ? 'حالات الحملات' : 'Campaign statuses'} subtitle={ar ? 'توزيع الحالة' : 'How they break down'}>
              {statusDonut.length ? <PlatformDonutChart data={statusDonut} colorBy="series" centerLabel={ar ? 'الحملات' : 'Campaigns'} centerValue={String(counts.total)} height={190} /> : <EmptyState title={ar ? 'لا حملات' : 'No campaigns'} />}
            </ChartCard>
            <ChartCard
              title={ar ? 'أفضل الحملات' : 'Best campaigns'}
              subtitle={
                topCampaigns !== null && topCampaigns.dropped > 0
                  ? (ar ? `حسب الإنفاق — ${topCampaigns.dropped} غير محتسَبة` : `By spend — ${topCampaigns.dropped} withheld`)
                  : (ar ? 'حسب الإنفاق' : 'By spend')
              }
              className="lg:col-span-2"
            >
              {topCampaigns === null
                ? <div className="flex h-[190px] items-center justify-center text-center text-xs text-text-muted">{ar ? 'ترتيب الإنفاق غير متاح — مبالغ جزئية أو بعملات متعددة' : 'Spend ranking unavailable — partial or multi-currency amounts'}</div>
                : topCampaigns.data.length >= 2
                  ? <RankingBarChart data={topCampaigns.data} bars={[{ key: 'spend', name: ar ? 'الإنفاق' : 'Spend', kind: 'money' }]} horizontal height={190} colorByPlatform />
                  : budgetTotals.consumed !== null && budgetTotals.spent !== null
                    ? <div className="flex h-[190px] items-center justify-center"><ProgressRing value={budgetTotals.consumed} sublabel={`${compact(budgetTotals.spent)} / ${compact(budgetTotals.total)}`} size={140} tone={budgetTotals.consumed > 0.95 ? 'danger' : 'brand'} /></div>
                    : <div className="flex h-[190px] items-center justify-center text-center text-xs text-text-muted">{ar ? 'استهلاك الميزانية غير متاح — المصروف بمبالغ جزئية أو بعملة مختلفة عن الميزانية' : 'Budget consumption unavailable — spend is partial or in a different currency'}</div>}
            </ChartCard>
          </div>
          {(movement.up.length > 0 || movement.down.length > 0) && (
            <div data-testid="campaigns-movers" className="grid gap-3 lg:grid-cols-2">
              {([['up', movement.up], ['down', movement.down]] as const).map(([dir, rows]) => (
                <div key={dir} className="rounded-2xl border border-border bg-surface p-4">
                  <h3 className="mb-2 text-sm font-bold text-text-primary">
                    {dir === 'up'
                      ? (ar ? 'الأكثر ارتفاعًا في الإنفاق' : 'Biggest rises in spend')
                      : (ar ? 'الأكثر انخفاضًا في الإنفاق' : 'Biggest falls in spend')}
                  </h3>

                  {rows.length === 0 ? (
                    <p className="py-4 text-center text-xs text-text-muted">
                      {dir === 'up'
                        ? (ar ? 'لا حملة ارتفع إنفاقها في هذه الفترة.' : 'No campaign spent more this period.')
                        : (ar ? 'لا حملة انخفض إنفاقها في هذه الفترة.' : 'No campaign spent less this period.')}
                    </p>
                  ) : (
                    <ul className="space-y-1">
                      {rows.map((r) => (
                        <li key={r.id} data-testid={`campaigns-mover-${r.id}`} className="flex items-center justify-between gap-3 text-sm">
                          <Link to={`/app/campaigns/${r.id}`} className="truncate text-text-primary hover:text-brand-600">{r.name}</Link>
                          <TrendPill delta={r.spend_change} />
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              ))}
            </div>
          )}

          {/*
            And what could NOT be ranked, said rather than dropped.

            A campaign with no baseline did not «not move» — it did not exist in the comparison
            window, or nothing was reported for it. Leaving those out silently would make the two
            lists read as the whole portfolio.
          */}
          {movement.withoutBaseline > 0 && (
            <p data-testid="campaigns-movers-unranked" className="text-xs text-text-muted">
              {/* Counted through `lib/counted`: «1 حملة» and «3 حملات» are different words. */}
              {ar
                ? `${countedCampaigns(movement.withoutBaseline, 'ar')} بلا أساس للمقارنة — لم تكن تعمل في الفترة السابقة.`
                : `${countedCampaigns(movement.withoutBaseline, 'en')} have no baseline to compare against — they were not running in the previous period.`}
            </p>
          )}

          {attention.length > 0 && (
            <button onClick={() => setView('attention')} className="flex w-full items-center gap-2 rounded-xl border border-warning/40 bg-warning/10 p-3 text-start text-sm text-text-primary hover:bg-warning/15">
              <TriangleAlert size={16} className="shrink-0 text-warning" />
              <span><span className="tnum font-bold">{attention.length}</span>{ar ? ' حملة تحتاج تدخلًا — اعرض التفاصيل والأسباب.' : ' campaigns need attention — open them for the detail and the reason.'}</span>
            </button>
          )}
        </>
      ) : view === 'compare' ? (
        <CampaignComparison
          projectId={projectId}
          campaigns={campaigns}
          selected={compareIds}
          onToggle={toggleCompare}
          onClear={() => setCompareIds([])}
          range={range}
          locale={locale}
        />
      ) : (
        <>
          {/* Filters — search + taxonomy chips for status and objective. */}
          <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <div className="relative flex-1">
                <Search size={15} className="pointer-events-none absolute inset-y-0 start-3 my-auto text-text-muted" />
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={ar ? 'ابحث في حملات المشروع…' : 'Search this project’s campaigns…'} className="h-10 w-full rounded-xl border border-border bg-surface ps-9 pe-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
              </div>
              <Select value={status} onChange={(e) => setStatus(e.target.value)} options={[{ value: '', label: ar ? 'كل الحالات' : 'All statuses' }, ...CAMPAIGN_STATUSES.map((s) => ({ value: s, label: campaignStatusLabel(s, locale) }))]} />
              <Select value={objective} onChange={(e) => setObjective(e.target.value)} options={[{ value: '', label: ar ? 'كل الأهداف' : 'All objectives' }, ...CANONICAL_OBJECTIVE_KEYS.map((o) => ({ value: o, label: canonicalObjectiveLabel(o, locale) }))]} />
            </div>
            {/* Taxonomy chips — the same taxonomy the selects use, one tap away, with live counts. */}
            <div className="flex flex-wrap gap-1.5">
            {/*
              CAMPAIGN-INTELLIGENCE-HUB — what the workspace answers before the reader scrolls.

              Counts of rows that carry their own evidence, never a score. «Nothing needs attention»
              and «nothing could be examined» are separate answers, and a pacing question nobody
              could measure is not answered «no».
            */}
            {(movement.up.length > 0 || movement.down.length > 0) && (
              <div data-testid="campaigns-movers" className="grid gap-3 lg:grid-cols-2">
                {([['up', movement.up], ['down', movement.down]] as const).map(([dir, rows]) => (
                  <div key={dir} className="rounded-2xl border border-border bg-surface p-4">
                    <h3 className="mb-2 text-sm font-bold text-text-primary">
                      {dir === 'up'
                        ? (ar ? 'الأكثر ارتفاعًا في الإنفاق' : 'Biggest rises in spend')
                        : (ar ? 'الأكثر انخفاضًا في الإنفاق' : 'Biggest falls in spend')}
                    </h3>

                    {rows.length === 0 ? (
                      <p className="py-4 text-center text-xs text-text-muted">
                        {dir === 'up'
                          ? (ar ? 'لا حملة ارتفع إنفاقها في هذه الفترة.' : 'No campaign spent more this period.')
                          : (ar ? 'لا حملة انخفض إنفاقها في هذه الفترة.' : 'No campaign spent less this period.')}
                      </p>
                    ) : (
                      <ul className="space-y-1">
                        {rows.map((r) => (
                          <li key={r.id} data-testid={`campaigns-mover-${r.id}`} className="flex items-center justify-between gap-3 text-sm">
                            <Link to={`/app/campaigns/${r.id}`} className="truncate text-text-primary hover:text-brand-600">{r.name}</Link>
                            <TrendPill delta={r.spend_change} />
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                ))}
              </div>
            )}

            {/*
              And what could NOT be ranked, said rather than dropped.

              A campaign with no baseline did not «not move» — it did not exist in the comparison
              window, or nothing was reported for it. Leaving those out silently would make the two
              lists read as the whole portfolio.
            */}
            {movement.withoutBaseline > 0 && (
              <p data-testid="campaigns-movers-unranked" className="text-xs text-text-muted">
                {/* Counted through `lib/counted`: «1 حملة» and «3 حملات» are different words. */}
                {ar
                  ? `${countedCampaigns(movement.withoutBaseline, 'ar')} بلا أساس للمقارنة — لم تكن تعمل في الفترة السابقة.`
                  : `${countedCampaigns(movement.withoutBaseline, 'en')} have no baseline to compare against — they were not running in the previous period.`}
              </p>
            )}

            <LandingAnswer answer={landing} ar={ar} />
              {/*
                CAMPAIGN-INTELLIGENCE-HUB — what is RUNNING, first.
                Inactive is one click away with its count beside it: a campaign silently missing from a
                list is worse than one sorted low.
              */}
              {LIFECYCLE_KEYS.map((key) => (
                <Chip key={key} testid="lifecycle-chip" active={lifecycleShown.applied === key} onClick={() => setLifecycle(key)}>
                  {LIFECYCLE_LABELS[key][ar ? 'ar' : 'en']}{' '}
                  <span className="tnum" data-testid={`lifecycle-count-${key}`}>{lifecycleShown.counts[key]}</span>
                </Chip>
              ))}
              <Chip active={status === '' && objective === ''} onClick={() => { setStatus(''); setObjective('') }}>{ar ? 'الكل' : 'All'} <span className="tnum">{counts.total}</span></Chip>
              {CAMPAIGN_STATUSES.filter((s) => (counts[s] ?? 0) > 0).map((s) => (
                <Chip key={s} active={status === s} onClick={() => setStatus(status === s ? '' : s)}>
                  {campaignStatusLabel(s, locale)} <span className="tnum">{counts[s]}</span>
                </Chip>
              ))}
              {/*
                The chips offer the canonical objectives this project actually HAS campaigns for —
                `canonicalOfRaw` maps each campaign's raw objective up, so a project of video buys
                offers «الوعي والتفاعل» rather than a chip nobody can use.
              */}
              {CANONICAL_OBJECTIVE_KEYS.filter((o) => campaigns.some((c) => c.objective !== null && canonicalOfRaw(c.objective) === o)).map((o) => (
                <Chip key={o} active={objective === o} onClick={() => setObjective(objective === o ? '' : o)}>
                  {canonicalObjectiveLabel(o, locale)}
                </Chip>
              ))}
            </div>
            {/*
              A view that could not be computed is not «nothing is running».
            
              Relevance is read from the metrics window; before it arrives, or when it failed, every
              campaign looks dark. Showing «active only» then would render an empty workspace as a fact
              about the account rather than about a request that has not answered — so everything is
              shown, and the page says why.
            */}
            {lifecycleShown.degraded && (
              <p data-testid="lifecycle-degraded" className="text-xs text-text-secondary">
                {ar
                  ? 'يُعرض كل الحملات — تعذّر تحديد النشِط منها حتى تصل مؤشرات الفترة.'
                  : 'Showing every campaign — which of them are running cannot be told until this period’s metrics arrive.'}
              </p>
            )}
          </div>

          {/* Campaign list */}
          {campaignsQuery.isLoading ? (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-40" />)}</div>
          ) : campaigns.length === 0 ? (
            <EmptyState title={t('no_campaigns')} description={t('no_campaigns_hint')} />
          ) : view === 'attention' ? (
            attention.length === 0 ? (
              <EmptyState title={ar ? 'لا توجد حملات تحتاج تدخلًا' : 'Nothing needs attention'} description={ar ? 'كل حملات المشروع مرتبطة بمنصاتها وتنفق ضمن ميزانياتها وتحقق نتائج في الفترة المحددة.' : 'Every campaign in this project is linked to its platform, spending within budget and producing results in the selected period.'} />
            ) : (
              <div className="space-y-2">
                {attention.map(({ c, flags }) => (
                  <button
                    key={c.id}
                    data-testid="attention-row"
                    onClick={() => navigate(`/campaigns/${projectId}/${c.id}`)}
                    className="flex w-full flex-col gap-2 rounded-2xl border border-border bg-surface p-4 text-start shadow-[var(--shadow-small)] transition-colors hover:border-brand-300 hover:bg-surface-hover"
                  >
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-bold text-text-primary">{c.name}</span>
                      <Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge>
                      <Badge tone="neutral">{objectiveLabel(c.objective, locale)}</Badge>
                    </div>
                    <ul className="space-y-1">
                      {flags.map((f) => (
                        <li key={f.code} className={`flex items-start gap-1.5 text-sm ${f.severity === 'high' ? 'text-danger' : 'text-warning'}`}>
                          <TriangleAlert size={14} className="mt-0.5 shrink-0" />
                          <span className="text-text-secondary">{ar ? f.ar : f.en}</span>
                        </li>
                      ))}
                    </ul>
                  </button>
                ))}
              </div>
            )
          ) : visibleCampaigns.length === 0 ? (
            /*
              A real empty active view — every campaign in this project has finished. The reader is told
              that, with the count of what is there instead, rather than being shown the history as
              though it were live.
            */
            <EmptyState
              title={ar ? 'لا توجد حملات نشطة في هذه الفترة' : 'Nothing is running in this period'}
              description={
                ar
                  ? `${countedCampaigns(lifecycleShown.counts.inactive, 'ar')} متوقفة أو منتهية — اعرض «غير النشطة» للاطلاع عليها.`
                  : `${countedCampaigns(lifecycleShown.counts.inactive, 'en')} have stopped or finished — open «Inactive» to see them.`
              }
            />
          ) : view === 'cards' ? (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {orderedCampaigns.map((c) => (
                <CampaignCard
                  key={c.id}
                  c={c}
                  locale={locale}
                  headline={campaignHeadline(c.objective, metricsByCampaign.get(c.id) as Record<string, unknown> | undefined, ar)}
                  efficiency={campaignEfficiency(c.objective, metricsByCampaign.get(c.id) as Record<string, unknown> | undefined, ar)}
                  state={campaignState(c.objective, metricsByCampaign.get(c.id) as Record<string, unknown> | undefined)}
                  /*
                    Freshness from the SHARED relevance rule — the same one the lifecycle view and the
                    ordering already read. A second definition of «is this still running» is how the
                    list and the chip on its own rows end up disagreeing.
                  */
                  trend={{
                    change: (metricsByCampaign.get(c.id) as { spend_change?: number | null } | undefined)?.spend_change ?? null,
                    hasBaseline: ((metricsByCampaign.get(c.id) as { previous_spend?: number | null } | undefined)?.previous_spend ?? null) !== null,
                  }}
                  freshness={{
                    relevance: campaignRelevance(
                      {
                        campaign_id: c.id,
                        status: c.status,
                        last_active_on: (metricsByCampaign.get(c.id) as { last_active_on?: string | null } | undefined)?.last_active_on ?? null,
                        // Not read by the rule — spend decides ORDER, never relevance — but the row
                        // shape is shared with the ordering, so it is supplied rather than widened.
                        spend: (metricsByCampaign.get(c.id) as { spend?: number | null } | undefined)?.spend ?? null,
                      },
                      range.to,
                    ),
                    lastActiveOn: (metricsByCampaign.get(c.id) as { last_active_on?: string | null } | undefined)?.last_active_on ?? null,
                  }}
                  onOpen={() => navigate(`/campaigns/${projectId}/${c.id}`)}
                />
              ))}
            </div>
          ) : (
            <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
              <div className="overflow-x-auto"><table className="w-full min-w-[720px] text-sm">
                <thead><tr className="border-b border-border text-text-muted"><th className="p-3 text-start">{ar ? 'الحملة' : 'Campaign'}</th><th className="p-3 text-start">{ar ? 'الهدف' : 'Objective'}</th><th className="p-3 text-start">{ar ? 'الحالة' : 'Status'}</th><th className="p-3 text-end">{ar ? 'الميزانية' : 'Budget'}</th><th className="p-3 text-end">{ar ? 'مرتبطة' : 'Linked'}</th></tr></thead>
                <tbody>
                  {orderedCampaigns.map((c) => (
                    <tr key={c.id} data-testid="campaign-row" className="cursor-pointer border-b border-border last:border-0 hover:bg-surface-hover" onClick={() => navigate(`/campaigns/${projectId}/${c.id}`)}>
                      <td className="p-3 font-semibold text-text-primary">{c.name}</td>
                      <td className="p-3 text-text-secondary">{objectiveLabel(c.objective, locale)}</td>
                      <td className="p-3"><Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge></td>
                      <td className="p-3 text-end"><span className="tnum">{money(c.total_budget, c.budget_currency)}</span></td>
                      <td className="p-3 text-end"><span className="tnum">{c.external_campaigns_count ?? 0}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table></div>
            </div>
          )}
        </>
      )}

      {/*
        No silent caps. Twenty-five of two hundred with nothing saying so is indistinguishable from a
        project that has twenty-five campaigns — and the rows are the most RELEVANT twenty-five, not
        the newest, which is a promise worth stating where it is kept.
      */}
      {(campaignsQuery.data?.lastPage ?? 1) > 1 && (
        <nav className="flex items-center justify-between gap-3 text-sm" data-testid="campaigns-pager">
          <span className="text-text-secondary">
            {ar
              ? `${campaigns.length} من ${counts.total} — صفحة ${campaignsQuery.data?.page ?? 1} من ${campaignsQuery.data?.lastPage ?? 1}`
              : `${campaigns.length} of ${counts.total} — page ${campaignsQuery.data?.page ?? 1} of ${campaignsQuery.data?.lastPage ?? 1}`}
          </span>
          <span className="flex gap-2">
            <button
              type="button"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={(campaignsQuery.data?.page ?? 1) <= 1}
              className="rounded-lg border border-border px-3 py-1.5 font-medium text-text-primary disabled:opacity-40"
            >
              {ar ? 'السابق' : 'Previous'}
            </button>
            <button
              type="button"
              onClick={() => setPage((p) => p + 1)}
              disabled={(campaignsQuery.data?.page ?? 1) >= (campaignsQuery.data?.lastPage ?? 1)}
              className="rounded-lg border border-border px-3 py-1.5 font-medium text-text-primary disabled:opacity-40"
            >
              {ar ? 'التالي' : 'Next'}
            </button>
          </span>
        </nav>
      )}

      <CampaignFormModal open={modalOpen} onClose={() => setModalOpen(false)} projectId={projectId} />
    </div>
  )
}

/**
 * `testid` defaults to `taxonomy-chip` — the status and objective chips, which narrow the QUERY.
 *
 * The lifecycle chips pass their own, because they are a different kind of control: they group the
 * campaigns already fetched by whether they are running, and a test reaching for «the second
 * taxonomy chip» must not silently land on one of them.
 */
function Chip({ active, onClick, children, testid = 'taxonomy-chip' }: { active: boolean; onClick: () => void; children: React.ReactNode; testid?: string }) {
  return (
    <button
      data-testid={testid}
      aria-pressed={active}
      onClick={onClick}
      className={`inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold transition-colors ${
        active ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border text-text-secondary hover:border-brand-300 hover:bg-surface-hover'
      }`}
    >
      {children}
    </button>
  )
}

/**
 * UX-KPI-PRESENTATION-001 — the shared card.
 *
 * This one drew its value with no `dir`, which in an Arabic layout lets the bidi algorithm reorder
 * «1.2K SAR» into «SAR 1.2K» and move a minus sign to the wrong end of a delta.
 */
/**
 * This page's shorthand over the shared card — `testid` included, deliberately.
 *
 * It was dropped here, which made the PROJECT total unaddressable: a test asserting «the subset is
 * not shown as the whole» could only search the whole document, and the moment the overview drew a
 * truthful per-campaign figure on the same screen the two became indistinguishable. A wrapper that
 * silently discards a prop is a wrapper that decides what may be asserted.
 */
function StatCard({ label, value, sub, delta, invert, tone, testid }: { label: string; value: string; sub?: string; delta?: number | null; invert?: boolean; tone?: 'success' | 'warning'; testid?: string }) {
  return (
    <SharedStatCard
      label={label}
      value={value}
      hint={sub}
      tone={tone ?? 'neutral'}
      testid={testid}
      trailing={delta !== undefined ? <TrendPill delta={delta} invertGood={invert} /> : undefined}
    />
  )
}

/** One named figure, or the honest reason there is not one. Never a score. */
function ReadingCell({ reading, locale }: { reading: CampaignHeadline; locale: 'ar' | 'en' }) {
  return (
    <span
      className="rounded-lg bg-surface-secondary px-2 py-1.5"
      data-testid={`campaign-headline-${reading.key}`}
      data-state={reading.reading.kind}
    >
      {reading.label}
      <b className="tnum block text-text-primary">
        {reading.reading.kind === 'value'
          ? reading.reading.text
          : reading.reading.kind === 'not_provided'
            ? (locale === 'ar' ? 'لم ترسله المنصة' : 'Not provided')
            : '—'}
      </b>
    </span>
  )
}

/** Freshness, in the shared rule's own vocabulary — never a second definition of «running». */
const FRESHNESS_COPY: Record<CampaignRelevance, { ar: string; en: string }> = {
  serving: { ar: 'تعمل', en: 'Serving' },
  idle: { ar: 'خاملة', en: 'Idle' },
  stopped: { ar: 'متوقفة', en: 'Stopped' },
}

/** The row's own short phrasing — the panel explains, the row labels. */
const CAMPAIGN_STATE_COPY: Record<string, { ar: string; en: string }> = {
  not_delivering: { ar: 'لا تُعرض', en: 'Not delivering' },
  weak_attraction: { ar: 'ضعف الجذب', en: 'Weak attraction' },
  clicks_not_arriving: { ar: 'الضغطات لا تصل', en: 'Clicks not arriving' },
  visits_lost: { ar: 'فقد زيارات', en: 'Visits lost' },
  no_conversions: { ar: 'بلا تحويلات', en: 'No conversions' },
  conversions_without_value: { ar: 'تحويلات بلا قيمة', en: 'Conversions without value' },
}

/**
 * The three answers, and the silences between them.
 *
 * Each count is rows that carry their own evidence — the diagnostic engine's finding, the backend's
 * own pacing — never a derived score. A count of zero is shown; a count that could not be computed
 * is said in words, because «0 overspending» and «nothing could be paced» are different answers and
 * a zero is read as the first.
 */
function LandingAnswer({ answer, ar }: { answer: ReturnType<typeof landingAnswer>; ar: boolean }) {
  const nothing = answer.needsAttention === 0 && answer.healthy === 0 && answer.unexamined === 0

  if (nothing) return null

  return (
    <div className="flex flex-wrap items-center gap-2 text-sm" data-testid="campaigns-landing-answer">
      {answer.needsAttention > 0 && (
        <span className="inline-flex items-center gap-1 rounded-full border border-border px-2.5 py-1 text-text-primary" data-testid="landing-attention">
          <TriangleAlert size={13} className="text-warning" />
          {ar ? `${answer.needsAttention} تحتاج انتباهًا` : `${answer.needsAttention} need attention`}
        </span>
      )}
      {answer.healthy > 0 && (
        <span className="inline-flex items-center gap-1 rounded-full border border-border px-2.5 py-1 text-text-secondary" data-testid="landing-healthy">
          {ar ? `${answer.healthy} بلا ملاحظات` : `${answer.healthy} with nothing to flag`}
        </span>
      )}
      {/*
        Never folded into «healthy». A campaign whose connector reported nothing is not a healthy
        campaign, and counting it as one publishes an absence of evidence as evidence of health on the
        first figure a reader sees.
      */}
      {answer.unexamined > 0 && (
        <span className="inline-flex items-center gap-1 rounded-full border border-dashed border-border px-2.5 py-1 text-text-muted" data-testid="landing-unexamined">
          {ar ? `${answer.unexamined} بلا قياس` : `${answer.unexamined} not measured`}
        </span>
      )}
      {answer.overpacing === null ? (
        <span className="text-[11px] text-text-muted" data-testid="landing-pacing-unknown">
          {ar ? 'لا يمكن قياس وتيرة الصرف لأي حملة' : 'No campaign’s pacing could be measured'}
        </span>
      ) : answer.overpacing > 0 ? (
        <span className="inline-flex items-center gap-1 rounded-full border border-border px-2.5 py-1 text-text-primary" data-testid="landing-overpacing">
          {ar ? `${answer.overpacing} تصرف أسرع من الخطة` : `${answer.overpacing} spending ahead of plan`}
        </span>
      ) : null}
    </div>
  )
}

function CampaignCard({ c, locale, headline, efficiency, state, freshness, trend, onOpen }: { c: UnifiedCampaign; locale: 'ar' | 'en'; headline?: CampaignHeadline | null; efficiency?: CampaignHeadline | null; state?: ReturnType<typeof campaignState>; freshness?: { relevance: CampaignRelevance; lastActiveOn: string | null }; trend?: { change: number | null; hasBaseline: boolean }; onOpen: () => void }) {
  const unlinked = (c.external_campaigns_count ?? 0) === 0
  return (
    <button onClick={onOpen} data-testid="campaign-card" className="flex flex-col gap-2.5 rounded-2xl border border-border bg-surface p-4 text-start shadow-[var(--shadow-small)] transition-colors hover:border-brand-300 hover:bg-surface-hover">
      <div className="flex items-start justify-between gap-2">
        <span className="line-clamp-2 font-bold text-text-primary">{c.name}</span>
        <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: STATUS_COLORS[c.status] ?? 'var(--text-muted)' }} title={campaignStatusLabel(c.status, locale)} />
      </div>
      <div className="flex flex-wrap gap-1.5">
        <Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge>
        <Badge tone="neutral">{objectiveLabel(c.objective, locale)}</Badge>
      </div>
      <div className="grid grid-cols-3 gap-1.5 text-[11px]">
        <span className="rounded-lg bg-surface-secondary px-2 py-1.5">{locale === 'ar' ? 'الميزانية' : 'Budget'} <b className="tnum block text-text-primary">{money(c.total_budget, c.budget_currency)}</b></span>
        {/*
          CAMPAIGN-INTELLIGENCE-HUB — the result this campaign was BOUGHT for, beside what it cost.
          A budget and a count of linked platform campaigns say nothing about whether the money is
          working; `campaignHeadline` names the metric the objective is judged on and states it, or
          says the platform never sent it. Never a score.
        */}
        {headline ? (
          <ReadingCell reading={headline} locale={locale} />
        ) : (
          <span className="rounded-lg bg-surface-secondary px-2 py-1.5">{locale === 'ar' ? 'مرتبطة' : 'Linked'} <b className="tnum block text-text-primary">{c.external_campaigns_count ?? 0}</b></span>
        )}
        {/*
          What that result COST — a result on its own decides nothing. Forty orders is good or bad
          depending on what was paid for them, and `readMetric` refuses a cost with nothing to divide
          rather than printing a zero that was never measured.
        */}
        {efficiency && <ReadingCell reading={efficiency} locale={locale} />}
        {/*
          CAMPAIGN-INTELLIGENCE-HUB — the concise state, from the shared engine and NOT a score.
          Rendered only when the row was actually judged: a campaign whose connector reported
          nothing is unjudged, and showing it the same «fine» as an examined healthy campaign is the
          opaque score this requirement forbids, wearing a word instead of a number.
        */}
        {/*
          «Not measured» is its own state, and the row says it.

          Without this the unexamined campaign and the examined-healthy one look identical — a blank
          — which is the opaque score this requirement forbids, expressed as an absence instead of a
          number. `judged` is false when nothing was spent or nothing was reported, and that is a
          fact about the connector, not a verdict on the campaign.
        */}
        {/*
          CAMPAIGN-INTELLIGENCE-HUB — freshness, said as a fact rather than a verdict.

          A campaign that reported no active day in this window is NOT «stopped»: the platform did
          not say it ended, and the window is the only evidence there is. So the chip names the
          shared relevance rule's own answer and, where there is one, the day itself.
        */}
        {/*
          CAMPAIGN-INTELLIGENCE-HUB — spend against the previous window, and only where there IS one.

          A campaign with no row in that window did not exist yet or reported nothing, and a pill
          reading «-100%» there is a collapse that never happened. The row says «لا أساس للمقارنة»
          instead, because a missing trend and a flat trend are different facts and a muted pill
          reading «—» is easily taken for the second.
        */}
        {trend !== undefined && (
          trend.change !== null ? (
            <span className="self-start" data-testid="campaign-trend">
              <TrendPill delta={trend.change} />
            </span>
          ) : (
            <span className="self-start text-[11px] text-text-muted" data-testid="campaign-trend-no-baseline">
              {trend.hasBaseline
                ? (locale === 'ar' ? 'لا تغيّر يمكن قياسه' : 'No measurable change')
                : (locale === 'ar' ? 'لا أساس للمقارنة' : 'No baseline to compare')}
            </span>
          )
        )}
        {freshness !== undefined && (
          <span
            className="inline-flex items-center gap-1 self-start text-[11px] text-text-muted"
            data-testid={`campaign-freshness-${freshness.relevance}`}
          >
            {locale === 'ar' ? FRESHNESS_COPY[freshness.relevance].ar : FRESHNESS_COPY[freshness.relevance].en}
            {freshness.lastActiveOn !== null
              ? ` · ${freshness.lastActiveOn}`
              : ` · ${locale === 'ar' ? 'لا يوم نشط في هذه الفترة' : 'no active day in this window'}`}
          </span>
        )}
        {state?.judged === false && (
          <span
            className="inline-flex items-center gap-1 self-start rounded-full border border-dashed border-border px-2 py-0.5 text-[11px] text-text-muted"
            data-testid="campaign-state-unmeasured"
          >
            {locale === 'ar' ? 'لا قياس بعد' : 'Not measured yet'}
          </span>
        )}
        {state?.judged === true && state.finding !== null && (
          <span
            className="inline-flex items-center gap-1 self-start rounded-full border border-border px-2 py-0.5 text-[11px] text-text-secondary"
            data-testid={`campaign-state-${state.finding.code}`}
          >
            <TriangleAlert size={11} className="text-warning" />
            {CAMPAIGN_STATE_COPY[state.finding.code]
              ? (locale === 'ar' ? CAMPAIGN_STATE_COPY[state.finding.code].ar : CAMPAIGN_STATE_COPY[state.finding.code].en)
              : state.finding.code}
            {state.finding.confidence === 'probable' && (
              <span className="text-text-muted">{locale === 'ar' ? '· مرجَّح' : '· inferred'}</span>
            )}
          </span>
        )}
      </div>
      {unlinked && <div className="inline-flex items-center gap-1 text-[11px] text-warning"><TriangleAlert size={12} /> {locale === 'ar' ? 'بلا حملات خارجية مرتبطة' : 'No linked platform campaign'}</div>}
    </button>
  )
}


/**
 * The portfolio's budget as a decision, not a total.
 *
 * `portfolioBudget` owns the arithmetic and the refusals; this owns the reading. The sentence is
 * deliberately one line and names the overrun in MONEY: «سيتجاوز ٤٬٤٠٠ ر.س» is actionable, «1.1×»
 * is a ratio somebody has to convert before they can act on it.
 */
export function BudgetPacingRow({ rows, ar }: { rows: BudgetRow[]; ar: boolean }) {
  const b = useMemo(() => portfolioBudget(rows), [rows])

  if (b.budget === null) {
    // Nothing to pace against is a fact worth stating once, not a row of dashes.
    if (b.excluded === 0 && b.currencies === 0) return null

    return (
      <p className="text-xs text-text-muted" data-testid="budget-pacing-unavailable">
        {b.currencies > 1
          ? (ar ? `تعذّر جمع الميزانية — ${b.currencies} عملات مختلفة` : `Budget not totalled — ${b.currencies} different currencies`)
          : (ar ? `لا ميزانية قابلة للمقارنة — ${countedCampaigns(b.excluded, 'ar')} خارج الحساب` : `No comparable budget — ${countedCampaigns(b.excluded, 'en')} excluded`)}
      </p>
    )
  }

  const over = b.pace !== null && b.pace > 1
  const overBy = b.projected !== null && b.budget !== null ? b.projected - b.budget : null

  return (
    <div className="flex flex-col gap-2 rounded-2xl border border-border bg-surface p-4" data-testid="budget-pacing">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Figure label={ar ? 'الميزانية' : 'Budget'} value={money(b.budget, b.currency ?? undefined)} />
        <Figure label={ar ? 'المصروف' : 'Spent'} value={money(b.spent, b.currency ?? undefined)} />
        <Figure label={ar ? 'المتبقي' : 'Remaining'} value={money(b.remaining, b.currency ?? undefined)} testid="budget-remaining" />
        <Figure
          label={ar ? 'المتوقع' : 'Forecast'}
          value={b.projected === null ? '—' : money(b.projected, b.currency ?? undefined)}
          testid="budget-forecast"
        />
      </div>

      {b.pace !== null && (
        <div className="flex flex-col gap-1">
          {/* The bar is against the BUDGET, so «past the end» is visible rather than clamped away. */}
          <div className="h-2 overflow-hidden rounded-full bg-surface-secondary">
            <div
              className={`h-full rounded-full ${over ? 'bg-danger' : 'bg-brand-600'}`}
              style={{ width: `${Math.min(100, (b.pace ?? 0) * 100)}%` }}
            />
          </div>
          <span className={`text-xs font-semibold ${over ? 'text-danger' : 'text-text-secondary'}`} data-testid="budget-pacing-reading">
            {over
              ? (ar
                  ? `بهذا المعدل سيتجاوز الإنفاق الميزانية بـ ${money(overBy, b.currency ?? undefined)}`
                  : `At this rate spend will exceed the budget by ${money(overBy, b.currency ?? undefined)}`)
              : (ar
                  ? `ضمن الميزانية — متوقع ${money(b.projected, b.currency ?? undefined)} من ${money(b.budget, b.currency ?? undefined)}`
                  : `Within budget — forecast ${money(b.projected, b.currency ?? undefined)} of ${money(b.budget, b.currency ?? undefined)}`)}
          </span>
        </div>
      )}

      {/* No silent caps: a campaign left out of the total is counted where the total is read. */}
      {b.excluded > 0 && (
        <span className="text-[11px] text-text-muted" data-testid="budget-pacing-excluded">
          {ar ? `${countedCampaigns(b.excluded, 'ar')} خارج الحساب — بلا ميزانية أو بعملة مختلفة` : `${countedCampaigns(b.excluded, 'en')} excluded — no budget, or a different currency`}
        </span>
      )}
    </div>
  )
}

function Figure({ label, value, testid }: { label: string; value: string; testid?: string }) {
  return (
    <div className="flex flex-col">
      <span className="text-xs text-text-muted">{label}</span>
      <span className="tnum text-lg font-bold text-text-primary" data-testid={testid}>{value}</span>
    </div>
  )
}
