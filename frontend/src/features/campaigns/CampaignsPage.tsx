import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
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
import { useBudget, useCampaigns, usePlatforms, useSummary, useTimeseries } from '@/features/analytics/api'
import { useLastNDaysRange } from '@/features/analytics/hooks'
import { ProvenanceBadge, RangeTabs, TrendPill } from '@/features/analytics/components'
import { compact, money, num, rowCostPer, rowRoas } from '@/features/analytics/format'
import { rankableMoney, resolveMoneySeries, type MoneyTotals } from '@/lib/money/contract'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { useT } from '@/lib/i18n'

const STATUS_COLORS: Record<string, string> = {
  active: 'var(--success)', paused: 'var(--warning)', completed: 'var(--info)',
  draft: 'var(--text-muted)', scheduled: 'var(--purple)', archived: 'var(--border-strong)',
}

/** The five ways to look at a project's campaigns (CAMPAIGN-010). */
type ViewMode = 'overview' | 'cards' | 'table' | 'compare' | 'attention'

const VIEWS: Array<{ id: ViewMode; ar: string; en: string; icon: typeof LayoutGrid }> = [
  { id: 'overview', ar: 'نظرة عامة', en: 'Overview', icon: BarChart3 },
  { id: 'cards', ar: 'بطاقات', en: 'Cards', icon: LayoutGrid },
  { id: 'table', ar: 'جدول', en: 'Table', icon: Rows },
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
  const [view, setView] = useState<ViewMode>('cards')
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
  const campaignsQuery = useQuery({
    /*
     * ANALYTICS-OBJECTIVE-SYSTEM-001 — the reader picks a canonical objective, the server gets the
     * raw ones it covers.
     *
     * `objectiveParam` is part of the key as well as the request: it is what actually narrows the
     * query, and keying on the canonical label instead would be one cache entry per label over
     * whatever the previous scope fetched.
     */
    queryKey: ['project', projectId, 'campaigns', { status, objective: objectiveParam, search }],
    queryFn: () => listCampaigns(projectId!, { status: status || undefined, objective: objectiveParam, search: search || undefined }),
    enabled: Boolean(projectId),
  })
  const summary = useSummary(projectId, range)
  const timeseries = useTimeseries(view === 'overview' ? projectId : null, range)
  const platforms = usePlatforms(view === 'overview' ? projectId : null, range)
  const budget = useBudget(projectId, range)
  const metricCampaigns = useCampaigns(projectId, range)

  const campaigns = campaignsQuery.data ?? []
  const counts = useMemo(() => {
    const c: Record<string, number> = { total: campaigns.length }
    for (const s of CAMPAIGN_STATUSES) c[s] = campaigns.filter((x) => x.status === s).length
    return c
  }, [campaigns])

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

  const attention = useMemo(
    () => campaigns
      .map((c) => ({ c, flags: attentionFlags(c, metricsByCampaign.get(c.id), summary.data?.currency ?? null) }))
      .filter((x) => x.flags.length > 0)
      .sort((a, b) => attentionRank(b.flags) - attentionRank(a.flags)),
    // The reporting currency decides whether an over-budget comparison is possible at all, so the
    // flags must recompute when it arrives — otherwise the first render's «no verdict» would stick.
    [campaigns, metricsByCampaign, summary.data?.currency],
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
            <span className="tnum font-semibold text-text-primary">{counts.total}</span>{ar ? ' حملة في المشروع الحالي — كل مشروع معزول عن غيره.' : ' campaigns in the current project — each project is isolated from the others.'}
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
                  ? `${lifecycleShown.counts.inactive} حملة متوقفة أو منتهية — اعرض «غير النشطة» للاطلاع عليها.`
                  : `${lifecycleShown.counts.inactive} campaigns have stopped or finished — open «Inactive» to see them.`
              }
            />
          ) : view === 'cards' ? (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {visibleCampaigns.map((c) => <CampaignCard key={c.id} c={c} locale={locale} onOpen={() => navigate(`/campaigns/${projectId}/${c.id}`)} />)}
            </div>
          ) : (
            <div className="overflow-hidden rounded-2xl border border-border bg-surface shadow-[var(--shadow-small)]">
              <div className="overflow-x-auto"><table className="w-full min-w-[720px] text-sm">
                <thead><tr className="border-b border-border text-text-muted"><th className="p-3 text-start">{ar ? 'الحملة' : 'Campaign'}</th><th className="p-3 text-start">{ar ? 'الهدف' : 'Objective'}</th><th className="p-3 text-start">{ar ? 'الحالة' : 'Status'}</th><th className="p-3 text-end">{ar ? 'الميزانية' : 'Budget'}</th><th className="p-3 text-end">{ar ? 'مرتبطة' : 'Linked'}</th></tr></thead>
                <tbody>
                  {visibleCampaigns.map((c) => (
                    <tr key={c.id} data-testid="campaign-row" className="cursor-pointer border-b border-border last:border-0 hover:bg-surface-hover" onClick={() => navigate(`/campaigns/${projectId}/${c.id}`)}>
                      <td className="p-3 font-semibold text-text-primary">{c.name}</td>
                      <td className="p-3 text-text-secondary">{objectiveLabel(c.objective, locale)}</td>
                      <td className="p-3"><Badge tone={campaignStatusTone(c.status)}>{campaignStatusLabel(c.status, locale)}</Badge></td>
                      <td className="tnum p-3 text-end">{money(c.total_budget, c.budget_currency)}</td>
                      <td className="tnum p-3 text-end">{c.external_campaigns_count ?? 0}</td>
                    </tr>
                  ))}
                </tbody>
              </table></div>
            </div>
          )}
        </>
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

function StatCard({ label, value, sub, delta, invert, tone }: { label: string; value: string; sub?: string; delta?: number | null; invert?: boolean; tone?: 'success' | 'warning' }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-3.5 shadow-[var(--shadow-small)]">
      <div className="flex items-center justify-between">
        <span className="text-sm text-text-secondary">{label}</span>
        {delta !== undefined && <TrendPill delta={delta} invertGood={invert} />}
      </div>
      <div className={`tnum mt-1 text-2xl font-extrabold ${tone === 'success' ? 'text-success' : tone === 'warning' ? 'text-warning' : 'text-text-primary'}`}>{value}</div>
      {sub && <div className="mt-0.5 text-xs text-text-muted">{sub}</div>}
    </div>
  )
}

function CampaignCard({ c, locale, onOpen }: { c: UnifiedCampaign; locale: 'ar' | 'en'; onOpen: () => void }) {
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
      <div className="grid grid-cols-2 gap-1.5 text-xs">
        <span className="rounded-lg bg-surface-secondary px-2 py-1.5">{locale === 'ar' ? 'الميزانية' : 'Budget'} <b className="tnum block text-text-primary">{money(c.total_budget, c.budget_currency)}</b></span>
        <span className="rounded-lg bg-surface-secondary px-2 py-1.5">{locale === 'ar' ? 'مرتبطة' : 'Linked'} <b className="tnum block text-text-primary">{c.external_campaigns_count ?? 0}</b></span>
      </div>
      {unlinked && <div className="inline-flex items-center gap-1 text-[11px] text-warning"><TriangleAlert size={12} /> {locale === 'ar' ? 'بلا حملات خارجية مرتبطة' : 'No linked platform campaign'}</div>}
    </button>
  )
}
