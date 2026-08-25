import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { GitCompare, Layers, LayoutGrid, Rows3 } from 'lucide-react'
import { CreativeViewer } from './CreativeViewer'
import { CreativeCompare } from './CreativeCompare'
import { formatMetric, metricLabel, metricState } from './metrics'
import { creativeGrainMissing, emptyReason, noDisplayableMetrics, type EmptyReason, type MetricsAvailability } from './availability'
import { imageLoading } from './format'
import { creativeMoney } from './creativeMoney'
import { VideoPoster } from './VideoPoster'
import { anyDisplayablePreview } from './previewPresence'
import {
  groupCreatives,
  libraryQueryString,
  listCreatives,
  type CreativeCard,
  type FatigueStatus,
  type LibraryQuery,
} from './api'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { FilterBar, FilterMulti, FilterSearch, FilterSelect, type AppliedFilter } from '@/components/ui/FilterBar'
import { FilterPlatforms } from '@/components/ui/FilterPlatforms'
import { PageIntro } from '@/components/ui/PageIntro'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useProject } from '@/stores/project'
import { campaignStatusLabel, marketingPathLabel, objectiveLabel, providerLabel } from '@/features/campaigns/labels'

/**
 * §15.2 — the Creative Library, in `/app` and `/agency`.
 *
 * ## One pipeline, and why this page was rewritten rather than extended
 *
 * It used to read `GET /creatives`, a controller that summed `creative_daily_metrics` with its own
 * SQL and coalesced every missing value to `0`. Two consequences, both visible to a customer: this
 * page and Creative Analysis could report different numbers for the same creative, and a metric the
 * platform never sends rendered as a measured zero. §15.17 calls an independent source an
 * architectural defect rather than a discrepancy, so the fix is not a reconciliation — it is that
 * the second source no longer exists. Everything here comes from `CreativeAnalysisController`, the
 * same controller the detail view, the dashboard cards and the client report read.
 *
 * ## The filters narrow; they never widen
 *
 * Every axis is sent only when it has a value (`libraryQueryString` omits the empty ones), and the
 * server intersects them against the caller's membership ceiling. Asking for another client's id
 * returns nothing rather than that client's creatives — the URL is not a permission.
 *
 * ## The filters somebody uses daily are on the page — UX-CONTENT-001
 *
 * All ten axes used to fold into `ViewCustomiser` (SIMPLIFY-001). That was right about the symptom
 * — ten rows of chips above a library is a settings screen — and wrong about which controls are
 * configuration. Narrowing to one platform, one campaign, videos only, or the fatigued ones IS how
 * a library is used, and folding those made a rich product look like a plain grid.
 *
 * So the division is by frequency rather than by count. Period, client, project, platform,
 * campaign, objective, path, creative type and fatigue are inline. **Status, ad set and ad** fold
 * into `More filters` — they are the rare ones. Search and the grid/table switcher were always
 * outside: one is how a person finds a row, the other is how the page is READ.
 *
 * The applied axes render as chips that each remove their own value, which is what makes a narrowed
 * library legible at a glance instead of looking like a short one.
 *
 * Closed sets and open-ended id lists both use the same multi-select, because a chip row of four
 * hundred campaign names is not a control and two different shapes of the same idea is not a design.
 *
 * ## Nothing autoplays and nothing preloads
 *
 * Cards render a poster image, never a `<video>`; a real player is mounted only inside the viewer,
 * once somebody opens a creative and presses play. A grid of twenty videos that preloaded their
 * streams would cost a phone tens of megabytes to open a page.
 */

const COPY = {
  ar: {
    title: 'مكتبة المحتويات',
    subtitle: 'كل محتوى إعلاني مزامَن — بأرقامه الحقيقية، ومقارنته بمسار هدفه.',
    search: 'ابحث بالاسم أو نص الإعلان…',
    grid: 'شبكة',
    list: 'قائمة',
    all: 'الكل',
    client: 'العميل',
    project: 'المشروع',
    platform: 'المنصة',
    campaign: 'الحملة',
    adSet: 'المجموعة الإعلانية',
    ad: 'الإعلان',
    objective: 'الهدف',
    path: 'المسار التسويقي',
    kind: 'نوع المحتوى',
    status: 'الحالة',
    health: 'حالة الإجهاد',
    from: 'من',
    to: 'إلى',
    sort: 'الترتيب',
    sortRecent: 'الأحدث نشاطًا',
    sortSpend: 'الأعلى إنفاقًا',
    sortImpressions: 'الأعلى ظهورًا',
    sortConversions: 'الأعلى نتائج',
    sortName: 'الاسم',
    compare: 'مقارنة',
    compareHint: 'اختر محتويين أو أكثر للمقارنة.',
    selected: 'محدَّد',
    merge: 'دمج كأصل واحد',
    merging: 'جارٍ الدمج…',
    merged: 'تم دمج {n} محتويات كأصل واحد.',
    mergeFailed: 'تعذّر الدمج. تأكد أن المحتويات المختارة تتبع المشروع نفسه.',
    openGroup: 'فتح المجموعة',
    groups: 'المجموعات',
    clearSelection: 'إلغاء التحديد',
    empty: 'لا توجد محتويات تطابق هذا التحديد.',
    emptyAll: 'لا توجد محتويات بعد — تظهر هنا بعد مزامنة الحملات.',
    error: 'تعذّر تحميل المكتبة.',
    demo: 'وضع تجريبي',
    grouped: 'مجمَّع عبر المنصات',
    cards: (n: number) => `${n} بطاقات`,
    noPreview: 'لا تتوفر معاينة',
    noPreviewAll: 'لم تُرجع المنصة ملف أي محتوى في هذه النتيجة؛ الأرقام أدناه كاملة.',
    lastSync: 'آخر مزامنة',
    never: 'لم تتم بعد',
    showing: 'المعروض',
    of: 'من',
    prev: 'السابق',
    next: 'التالي',
    open: 'فتح المعاينة',
    preview: 'المعاينة',
    name: 'الاسم',
    result: 'النتيجة',
    efficiency: 'الكفاءة',
    details: 'تفاصيل المحتوى',
    source: 'المصدر: منصة الإعلان',
    allContent: 'كل المحتوى',
    /*
     * Plurals for the applied-state line, one word per axis.
     *
     * Used only above one — a single choice is named («ميتا»), and only a selection of several
     * collapses to a count, because «3 منصات» is readable where three platform names in a row is a
     * list nobody finishes. Latin digits, per the product's standing rule for Arabic copy.
     */
    manyClients: 'عملاء',
    manyProjects: 'مشاريع',
    manyPlatforms: 'منصات',
    manyCampaigns: 'حملات',
    manyAdSets: 'مجموعات إعلانية',
    manyAds: 'إعلانات',
    manyObjectives: 'أهداف',
    manyPaths: 'مسارات',
    manyKinds: 'أنواع',
    manyStatuses: 'حالات',
  },
  en: {
    title: 'Creative library',
    subtitle: 'Every synced creative — with its real figures, judged against its own objective.',
    search: 'Search by name or ad copy…',
    grid: 'Grid',
    list: 'List',
    all: 'All',
    client: 'Client',
    project: 'Project',
    platform: 'Platform',
    campaign: 'Campaign',
    adSet: 'Ad set',
    ad: 'Ad',
    objective: 'Objective',
    path: 'Marketing path',
    kind: 'Creative type',
    status: 'Status',
    health: 'Fatigue',
    from: 'From',
    to: 'To',
    sort: 'Sort',
    sortRecent: 'Most recently active',
    sortSpend: 'Highest spend',
    sortImpressions: 'Most impressions',
    sortConversions: 'Most results',
    sortName: 'Name',
    compare: 'Compare',
    compareHint: 'Select two or more creatives to compare.',
    selected: 'selected',
    merge: 'Merge as one asset',
    merging: 'Merging…',
    merged: '{n} creatives were merged as one asset.',
    mergeFailed: 'The merge failed. Check that the selected creatives belong to the same project.',
    openGroup: 'Open group',
    groups: 'Groups',
    clearSelection: 'Clear selection',
    empty: 'No creatives match this selection.',
    emptyAll: 'No creatives yet — they appear here after campaigns sync.',
    error: 'Could not load the library.',
    demo: 'Demo',
    grouped: 'Grouped across platforms',
    cards: (n: number) => `${n} cards`,
    noPreview: 'No preview available',
    noPreviewAll: 'The platform returned no creative file for anything in this result; the figures below are complete.',
    lastSync: 'Last sync',
    never: 'Not yet',
    showing: 'Showing',
    of: 'of',
    prev: 'Previous',
    next: 'Next',
    open: 'Open preview',
    preview: 'Preview',
    name: 'Name',
    result: 'Result',
    efficiency: 'Efficiency',
    details: 'Creative details',
    source: 'Source: ad platform',
    allContent: 'All content',
    manyClients: 'clients',
    manyProjects: 'projects',
    manyPlatforms: 'platforms',
    manyCampaigns: 'campaigns',
    manyAdSets: 'ad sets',
    manyAds: 'ads',
    manyObjectives: 'objectives',
    manyPaths: 'paths',
    manyKinds: 'types',
    manyStatuses: 'statuses',
  },
}

const FATIGUE_TONE: Record<FatigueStatus, string> = {
  improving: 'bg-success/15 text-success',
  stable: 'bg-surface-hover text-text-secondary',
  watch: 'bg-warning/15 text-warning',
  fatigued: 'bg-danger/15 text-danger',
  insufficient_data: 'bg-surface-hover text-text-secondary',
}

const FATIGUE_LABEL: Record<FatigueStatus, { ar: string; en: string }> = {
  improving: { ar: 'يتحسّن', en: 'Improving' },
  stable: { ar: 'مستقر', en: 'Stable' },
  watch: { ar: 'يحتاج متابعة', en: 'Watch' },
  fatigued: { ar: 'مُجهَد', en: 'Fatigued' },
  // Never «stable» — «we have not looked» and «we looked and it is fine» are different claims.
  insufficient_data: { ar: 'بيانات غير كافية', en: 'Insufficient data' },
}

const KIND_LABEL: Record<string, { ar: string; en: string }> = {
  image: { ar: 'صورة', en: 'Image' },
  video: { ar: 'فيديو', en: 'Video' },
  carousel: { ar: 'دوّار', en: 'Carousel' },
}

/**
 * Which of a creative's headline metrics is its RESULT, and which is its efficiency.
 *
 * `headline_metrics` is the server's answer to «what is this creative judged on», ordered by that
 * objective's own priority — but it mixes the two kinds of answer a reviewer needs side by side.
 * The result is what the money BOUGHT (purchases, leads, clicks, views); the efficiency is what
 * each one COST or returned (CPA, CPL, CPC, CTR, ROAS). One column each, so a lead ad and a brand
 * video can sit in the same table and neither is asked the other's question.
 *
 * `spend` is deliberately neither: it has its own column, because it is the one figure that means
 * the same thing whatever the campaign was for.
 */
const EFFICIENCY_KEYS = new Set([
  'ctr', 'cpc', 'cpm', 'cpa', 'roas', 'conversion_rate', 'aov',
  'cost_per_view', 'cost_per_lpv', 'view_rate', 'completion_rate', 'hook_rate',
])

function primaryResultKey(headline: string[]): string | null {
  return headline.find((key) => key !== 'spend' && !EFFICIENCY_KEYS.has(key)) ?? null
}

function primaryEfficiencyKey(headline: string[]): string | null {
  return headline.find((key) => EFFICIENCY_KEYS.has(key)) ?? null
}

/**
 * The filter axes the address may carry, and the library may be opened on.
 *
 * Named in one place because both ends read it: the drill-down writes these keys and this page
 * seeds its state from them. A key on one side only is a filter that silently fails to travel.
 */
const AXIS_KEYS = [
  'client_ids', 'project_ids', 'providers', 'campaign_ids', 'ad_set_ids',
  'ad_ids', 'objectives', 'paths', 'kinds', 'statuses',
] as const

const isoDaysAgo = (days: number) => {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return d.toISOString().slice(0, 10)
}

export function CreativesPage() {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']
  const { currentProjectId } = useProject()
  /*
   * The address of THIS library, carried into every detail link.
   *
   * Relative — `content/<id>` resolves under whichever portal mounted this page, so one component
   * serves `/app` and `/agency` without being told which it is. The search string travels so the
   * detail page's Back link rebuilds the shelf rather than dropping the reader in an unfiltered
   * library, which is the exact way a drill-down stops being trusted.
   */
  const { search: libraryAddress } = useLocation()

  /*
   * The address is the opening state (§15.11's drill-down).
   *
   * The dashboard's creative cards link here carrying the filters and period they were computed
   * under, so «best video» has to land on a library narrowed the same way — otherwise the reader
   * arrives at a different set of creatives than the card they clicked and the two surfaces look
   * like they disagree. Read once, as the initial value: after that the controls own the state, and
   * the effect below writes it back so a refresh or a shared link reopens the same page.
   */
  const [params, setParams] = useSearchParams()
  const initial = useRef(params)
  const opened = useRef(false)

  const [view, setView] = useState<'grid' | 'list'>('grid')
  const [search, setSearch] = useState(() => initial.current.get('search') ?? '')
  const [from, setFrom] = useState(() => initial.current.get('from') ?? isoDaysAgo(29))
  const [to, setTo] = useState(() => initial.current.get('to') ?? isoDaysAgo(0))
  const [sort, setSort] = useState(() => initial.current.get('sort') ?? 'recent')
  const [page, setPage] = useState(1)
  const [axes, setAxes] = useState<Record<string, string[]>>(() => {
    const seeded: Record<string, string[]> = {}
    for (const key of AXIS_KEYS) {
      const values = initial.current.getAll(`${key}[]`)
      // Absent, not empty: an axis sent as `[]` is a bound of «nothing» on a fail-closed server.
      if (values.length > 0) seeded[key] = values
    }
    return seeded
  })
  const [health, setHealth] = useState(() => initial.current.get('health') ?? '')
  const [selected, setSelected] = useState<string[]>([])
  const [viewerIndex, setViewerIndex] = useState<number | null>(null)
  const [comparing, setComparing] = useState(false)
  const [mergeNotice, setMergeNotice] = useState<{ message: string; groupId: string | null } | null>(null)

  /*
   * §15.8 — merging is `campaigns.link`, the permission that already means «these two platform
   * records are one thing». The button is absent rather than disabled without it: a control that
   * always refuses teaches the reader nothing about what they may do.
   */
  const canLink = useAuth((s) => s.hasPermission('campaigns.link'))
  const queryClient = useQueryClient()

  const merge = useMutation({
    mutationFn: (ids: string[]) => groupCreatives(ids),
    onSuccess: (group) => {
      setMergeNotice({ message: t.merged.replace('{n}', String(group.creative_ids.length)), groupId: group.id })
      setSelected([])
      void queryClient.invalidateQueries({ queryKey: ['creative-library'] })
      void queryClient.invalidateQueries({ queryKey: ['creative-groups'] })
    },
  })

  const query: LibraryQuery = useMemo(
    () => ({
      from,
      to,
      page,
      per_page: 24,
      sort,
      search: search.trim() || undefined,
      health: health || undefined,
      client_ids: axes.client_ids,
      project_ids: axes.project_ids,
      providers: axes.providers,
      campaign_ids: axes.campaign_ids,
      ad_set_ids: axes.ad_set_ids,
      ad_ids: axes.ad_ids,
      objectives: axes.objectives,
      paths: axes.paths,
      kinds: axes.kinds,
      statuses: axes.statuses,
    }),
    [from, to, page, sort, search, health, axes],
  )

  const libraryQuery = useQuery({
    // The whole query is the key, so changing any filter refetches and the two never disagree
    // about what is on screen.
    queryKey: ['creative-library', currentProjectId, query],
    queryFn: () => listCreatives(query, null),
    // The previous page stays visible while the next one loads, instead of the list collapsing to a
    // skeleton on every keystroke.
    placeholderData: keepPreviousData,
  })

  /*
   * The address follows the controls — so a refresh, a Back, or a shared link reopens this view.
   *
   * `replace` deliberately: typing in the search box would otherwise push a history entry per
   * keystroke, and Back would walk the reader backwards through their own typing.
   */
  useEffect(() => {
    const next = libraryQueryString(query).replace(/^\?/, '')
    const creative = params.get('creative')
    setParams(creative ? `${next}${next ? '&' : ''}creative=${creative}` : next, { replace: true })
    // `params` is deliberately absent: including it would re-run this on the write it just made.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, setParams])

  const setAxis = (key: string, values: string[]) => {
    setPage(1)
    setAxes((prev) => {
      const next = { ...prev }
      // Deleted, not emptied: an axis sent as `[]` is a bound of «nothing» on a fail-closed server,
      // while an absent axis is «unbounded». They are not the same request.
      if (values.length === 0) delete next[key]
      else next[key] = values
      return next
    })
  }

  const data = libraryQuery.data
  const creatives = data?.creatives ?? []

  /**
   * CONTENT-NO-PREVIEW-001 — does ANY creative in this result carry a displayable asset?
   *
   * Computed over the whole result rather than per card, because the question the layout is asking
   * is «is the preview column worth reserving at all», and one card cannot answer it.
   */
  const anyPreview = useMemo(() => anyDisplayablePreview(creatives), [creatives])
  const options = data?.filters
  const total = data?.total ?? 0
  const perPage = data?.per_page ?? 24
  const lastPage = Math.max(1, Math.ceil(total / perPage))

  /*
   * `?creative=<id>` opens that creative once, when the page it is on has arrived.
   *
   * Once, and only when it is actually in the list: reopening on every fetch would fight the reader
   * closing it, and an id that is filtered out has to leave the library open rather than opening
   * something else that happens to be at the same index.
   */
  useEffect(() => {
    if (opened.current) return
    const wanted = initial.current.get('creative')
    const rows = data?.creatives ?? []
    if (!wanted || rows.length === 0) return

    opened.current = true
    const index = rows.findIndex((c) => c.id === wanted)
    if (index >= 0) setViewerIndex(index)
    // `data`, not `creatives`: the latter is a fresh array on every render, so the effect would run
    // on every render and lean on the guard above instead of on its dependencies.
  }, [data])


  const toggleSelected = (id: string) =>
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))

  const filtersTouched = Object.keys(axes).length > 0 || search.trim() !== '' || health !== ''

  /**
   * What is narrowing the library, one chip per value.
   *
   * The applied row replaces the sentence the folded dialog needed. A sentence had to compress
   * «three campaigns» into a phrase because it could not offer a way to undo any single one; chips
   * can, and undoing one filter without touching the other eight is most of what a reviewer does.
   */
  const applied: AppliedFilter[] = useMemo(() => {
    const nameOf = (rows: Array<{ id: string; name: string }> | undefined, id: string) =>
      rows?.find((r) => r.id === id)?.name ?? id

    const forAxis = (key: string, axis: string, label: (value: string) => string): AppliedFilter[] =>
      (axes[key] ?? []).map((value) => ({
        key: `${key}:${value}`,
        axis,
        label: label(value),
        onRemove: () => setAxis(key, (axes[key] ?? []).filter((v) => v !== value)),
      }))

    const out: AppliedFilter[] = [
      ...forAxis('providers', t.platform, (p) => providerLabel(p, locale)),
      ...forAxis('kinds', t.kind, (k) => KIND_LABEL[k]?.[ar ? 'ar' : 'en'] ?? k),
      ...forAxis('objectives', t.objective, (o) => objectiveLabel(o, locale)),
      ...forAxis('paths', t.path, (p) => marketingPathLabel(p, locale)),
      ...forAxis('client_ids', t.client, (id) => nameOf(options?.clients, id)),
      ...forAxis('project_ids', t.project, (id) => nameOf(options?.projects, id)),
      ...forAxis('campaign_ids', t.campaign, (id) => nameOf(options?.campaigns, id)),
      ...forAxis('statuses', t.status, (s) => campaignStatusLabel(s, locale)),
      ...forAxis('ad_set_ids', t.adSet, (id) => id),
      ...forAxis('ad_ids', t.ad, (id) => id),
    ]

    if (health !== '') {
      out.push({
        key: `health:${health}`,
        axis: t.health,
        label: FATIGUE_LABEL[health as FatigueStatus]?.[ar ? 'ar' : 'en'] ?? health,
        onRemove: () => { setHealth(''); setPage(1) },
      })
    }

    if (search.trim() !== '') {
      out.push({ key: 'search', axis: t.search, label: search.trim(), onRemove: () => { setSearch(''); setPage(1) } })
    }

    return out
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [axes, health, search, options, locale, ar, t])

  /** Only the folded axes drive the marker on «More filters» — the visible ones speak for themselves. */
  const advancedActive = ['statuses', 'ad_set_ids', 'ad_ids'].some((key) => (axes[key] ?? []).length > 0)

  const resetFilters = () => {
    setAxes({})
    setHealth('')
    setSearch('')
    setPage(1)
  }

  const multi = (key: string, label: string, values: Array<{ value: string; label: string }>) => (
    <FilterMulti
      label={label}
      ar={ar}
      testid={`content-${key}`}
      values={axes[key] ?? []}
      options={values}
      onChange={(next) => setAxis(key, next)}
    />
  )

  return (
    <div className="space-y-5">
      <PageIntro
        testid="content-intro"
        title={t.title}
        purpose={t.subtitle}
        actions={
          <>
            <Button
              variant="secondary"
              disabled={selected.length < 2}
              onClick={() => setComparing(true)}
              title={selected.length < 2 ? t.compareHint : undefined}
            >
              <GitCompare className="h-4 w-4" aria-hidden />
              {t.compare}
              {selected.length > 0 && <span dir="ltr"> ({selected.length})</span>}
            </Button>

            {/* Relative, so one component serves both portals without being told which mounted it. */}
            <Link
              to="groups"
              className="flex items-center gap-1 rounded-xl border border-border-strong px-3 py-2 text-sm font-semibold text-text-primary hover:bg-surface-hover"
            >
              <Layers className="h-4 w-4" aria-hidden />
              {t.groups}
            </Link>
          </>
        }
      />

      <FilterBar
        id="content"
        ar={ar}
        applied={applied}
        onReset={resetFilters}
        advancedActive={advancedActive}
        advanced={
          options && (
            <div className="flex flex-wrap items-end gap-3">
              {multi('statuses', t.status, options.statuses.map((s) => ({ value: s, label: campaignStatusLabel(s, locale) })))}
              {multi('ad_set_ids', t.adSet, options.ad_sets.map((id) => ({ value: id, label: id })))}
              {/* Already labelled by the server — the id is the value, the ad's name is what is read. */}
              {multi('ad_ids', t.ad, options.ads)}
            </div>
          )
        }
        trailing={
          <>
            {/* How the page is READ, not how it is configured — so it sits with the controls and
                never inside a dialog. */}
            <div className="flex rounded-xl border border-border p-0.5" role="group" aria-label={t.grid}>
              <button
                type="button"
                aria-pressed={view === 'grid'}
                onClick={() => setView('grid')}
                className={`flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold ${view === 'grid' ? 'bg-surface-hover text-text-primary' : 'text-text-secondary'}`}
              >
                <LayoutGrid className="h-3.5 w-3.5" aria-hidden /> {t.grid}
              </button>
              <button
                type="button"
                aria-pressed={view === 'list'}
                onClick={() => setView('list')}
                className={`flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold ${view === 'list' ? 'bg-surface-hover text-text-primary' : 'text-text-secondary'}`}
              >
                <Rows3 className="h-3.5 w-3.5" aria-hidden /> {t.list}
              </button>
            </div>

            {/* Reordering changes which creative is first, never which exist — so it is beside the
                filters rather than among them, and never counts as a narrowing. */}
            <FilterSelect
              label={t.sort}
              value={sort}
              testid="content-sort"
              options={[
                { value: 'recent', label: t.sortRecent },
                { value: 'spend', label: t.sortSpend },
                { value: 'impressions', label: t.sortImpressions },
                { value: 'conversions', label: t.sortConversions },
                { value: 'name', label: t.sortName },
              ]}
              onChange={(v) => { setSort(v); setPage(1) }}
            />
          </>
        }
      >
        <FilterSearch
          value={search}
          placeholder={t.search}
          testid="content-search"
          onChange={(v) => { setSearch(v); setPage(1) }}
        />

        {/* `DateField`, never a native date input: the browser's own control renders in the OS
            locale, so a Saudi machine shows a Hijri calendar for an ISO value the API expects. */}
        <div className="flex flex-col gap-1">
          <span className="text-xs font-semibold text-text-secondary">{t.from}</span>
          <DateField aria-label={t.from} value={from} onChange={(v) => { setFrom(v); setPage(1) }} />
        </div>
        <div className="flex flex-col gap-1">
          <span className="text-xs font-semibold text-text-secondary">{t.to}</span>
          <DateField aria-label={t.to} value={to} onChange={(v) => { setTo(v); setPage(1) }} />
        </div>

        {options && (
          <>
            {multi('client_ids', t.client, options.clients.map((c) => ({ value: c.id, label: c.name })))}
            {multi('project_ids', t.project, options.projects.map((p) => ({ value: p.id, label: p.name })))}
            {/* UX-FILTERS-001 — platforms as visible chips here too, so the library filters the
                same way the dashboard and analytics do. */}
            <FilterPlatforms
              label={t.platform}
              allLabel={ar ? 'الكل' : 'All'}
              values={axes.providers ?? []}
              testid="content-providers"
              options={options.providers.map((p) => ({ value: p, label: providerLabel(p, locale) }))}
              onChange={(next) => setAxis('providers', next)}
            />
            {multi('campaign_ids', t.campaign, options.campaigns.map((c) => ({ value: c.id, label: c.name })))}
            {multi('objectives', t.objective, options.objectives.map((o) => ({ value: o, label: objectiveLabel(o, locale) })))}
            {multi('paths', t.path, options.paths.map((p) => ({ value: p, label: marketingPathLabel(p, locale) })))}
            {multi('kinds', t.kind, options.kinds.map((k) => ({ value: k, label: KIND_LABEL[k]?.[ar ? 'ar' : 'en'] ?? k })))}

            {/* Single-valued: a creative is in exactly one fatigue state, so «watch AND fatigued» is
                not a question the server can be asked. */}
            <FilterSelect
              label={t.health}
              value={health}
              testid="content-health"
              options={[
                { value: '', label: t.all },
                ...options.health.map((status) => ({
                  value: status,
                  label: FATIGUE_LABEL[status]?.[ar ? 'ar' : 'en'] ?? status,
                })),
              ]}
              onChange={(v) => { setHealth(v); setPage(1) }}
            />
          </>
        )}
      </FilterBar>

      {selected.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-surface px-3 py-2 text-xs text-text-secondary">
          <span dir="ltr">{selected.length}</span>
          <span>{t.selected}</span>
          <button type="button" onClick={() => setSelected([])} className="underline">
            {t.clearSelection}
          </button>
          {canLink && (
            <button
              type="button"
              disabled={selected.length < 2 || merge.isPending}
              onClick={() => merge.mutate(selected)}
              className="flex items-center gap-1 rounded-md border border-border px-2 py-1 text-text-primary hover:bg-surface-hover disabled:opacity-50"
            >
              <Layers className="h-3.5 w-3.5" aria-hidden />
              {merge.isPending ? t.merging : t.merge}
            </button>
          )}
        </div>
      )}

      {mergeNotice && (
        <p className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-surface-hover p-2 text-xs" role="status">
          <span className="text-text-primary">{mergeNotice.message}</span>
          {mergeNotice.groupId && (
            <Link to={`groups?group=${mergeNotice.groupId}`} className="text-primary underline">
              {t.openGroup}
            </Link>
          )}
        </p>
      )}

      {merge.isError && (
        <p className="rounded-md border border-danger/40 bg-danger/10 p-2 text-xs text-text-primary" role="alert">
          {t.mergeFailed}
        </p>
      )}

      {libraryQuery.isError && (
        <ErrorState title={t.error} error={libraryQuery.error} ar={ar} onRetry={() => void libraryQuery.refetch()} />
      )}

      {libraryQuery.isPending && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-64" />
          ))}
        </div>
      )}

      {!libraryQuery.isPending && !libraryQuery.isError && creatives.length === 0 && (
        <div className="rounded-lg border border-border bg-surface p-8 text-center text-sm text-text-secondary">
          {filtersTouched ? t.empty : t.emptyAll}
        </div>
      )}

      {/*
        CONTENT-NO-PREVIEW-001 — the same sentence, four times, in four large empty boxes.

        Every card reserves a 16:9 panel for the asset. When the platform returns no asset for ANY
        creative in the result — which is every Meta result today, because the ad API does not hand
        back the creative file — the grid becomes rows of identical grey rectangles each repeating
        «لا تتوفر معاينة», and the numbers people came for are pushed below the fold.
    
        The fact is not hidden: it is stated ONCE, above the grid, and the cards drop the reserved
        panel so the metrics move up. A grid where SOME creatives have assets keeps every panel, so
        the ones that are missing stay visibly missing rather than being quietly levelled.
      */}
      {creatives.length > 0 && view === 'grid' && !anyPreview && (
        <p data-testid="creatives-no-previews" className="rounded-lg border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary">
          {t.noPreview} — {t.noPreviewAll}
        </p>
      )}

      {creatives.length > 0 && view === 'grid' && (
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {creatives.map((creative, index) => (
            <li key={creative.id}>
              <CreativeGridCard
                creative={creative}
                currency={data?.currency ?? null}
                availability={data?.metrics_availability?.[creative.provider]}
                t={t}
                ar={ar}
                locale={locale}
                selected={selected.includes(creative.id)}
                onSelect={() => toggleSelected(creative.id)}
                onOpen={() => setViewerIndex(index)}
                showPreviewPanel={anyPreview}
                detailsTo={`${creative.id}${libraryAddress}`}
              />
            </li>
          ))}
        </ul>
      )}

      {creatives.length > 0 && view === 'list' && (
        /*
         * The table is for DECIDING, not for auditing — UX-CONTENT-001.
         *
         * Its predecessor carried whatever was easy to reach: spend and impressions, the same two
         * for every row whatever the campaign was for. Impressions do not help anybody decide
         * whether to keep running a lead ad. So each row now shows its own objective's headline
         * result and its own efficiency figure, chosen by `resultKey`/`efficiencyKey` from the
         * creative's `headline_metrics` — which is the server's answer to «what is this judged on».
         *
         * Ten columns, and no more. Everything else is one click away in the panel, which is
         * reachable from any cell in the row.
         */
        <div className="overflow-x-auto rounded-2xl border border-border">
          <table className="w-full min-w-[60rem] text-sm">
            <thead className="bg-surface-hover text-start text-xs text-text-secondary">
              <tr>
                <th className="p-2" />
                <th className="p-2 text-start">{t.preview}</th>
                <th className="p-2 text-start">{t.name}</th>
                <th className="p-2 text-start">{t.platform}</th>
                <th className="p-2 text-start">{t.campaign}</th>
                <th className="p-2 text-start">{t.objective}</th>
                <th className="p-2 text-start">{metricLabel('spend', locale)}</th>
                <th className="p-2 text-start">{t.result}</th>
                <th className="p-2 text-start">{t.efficiency}</th>
                <th className="p-2 text-start">{t.health}</th>
                <th className="p-2 text-start">{t.lastSync}</th>
              </tr>
            </thead>
            <tbody>
              {creatives.map((creative, index) => {
                const resultKey = primaryResultKey(creative.headline_metrics)
                const efficiencyKey = primaryEfficiencyKey(creative.headline_metrics)
                const poster = creative.preview.thumbnail_url ?? creative.preview.image_url
                /*
                 * CONTENT-PREVIEW-VIDEO-001 — a video with no poster is not «no preview».
                 *
                 * Snapchat returns a video creative's file as `video_url` and frequently supplies no
                 * separate thumbnail. This row derived its poster from `thumbnail_url ?? image_url`
                 * only, so a creative with a perfectly good video asset rendered «لا توجد معاينة» —
                 * the product claiming to have nothing while holding the thing itself.
                 */
                const video = poster === null ? creative.preview.video_url : null

                return (
                  <tr
                    key={creative.id}
                    data-testid={`content-row-${creative.id}`}
                    /*
                     * The whole row opens the panel. A single small «Open» button in one column is
                     * a target somebody has to aim at forty times; the row is the thing they are
                     * already looking at. The name stays a real link inside it — middle-clickable,
                     * copyable — and the checkbox stops the click from reaching the row so
                     * selecting for comparison does not also open a dialog.
                     */
                    onClick={() => setViewerIndex(index)}
                    className="cursor-pointer border-t border-border hover:bg-surface-hover"
                  >
                    <td className="p-2" onClick={(e) => e.stopPropagation()}>
                      <input
                        type="checkbox"
                        aria-label={`${t.compare}: ${creative.name}`}
                        checked={selected.includes(creative.id)}
                        onChange={() => toggleSelected(creative.id)}
                      />
                    </td>
                    <td className="p-2">
                      {poster ? (
                        <img
                          src={poster}
                          alt=""
                          loading={imageLoading(poster)}
                          decoding="async"
                          className="h-10 w-16 rounded object-cover"
                        />
                      ) : video ? (
                        /*
                         * `preload="metadata"` and no `autoPlay`: the browser fetches enough to draw
                         * the first frame and stops. A grid of twenty `preload="auto"` videos would
                         * cost a phone tens of megabytes to open a list page, which is why cards
                         * never mounted a player before — the answer is a cheap one, not none.
                         *
                         * `muted` and `playsInline` so the frame renders on iOS without asking to
                         * play; `#t=0.1` because some browsers draw nothing at exactly zero.
                         */
                        <VideoPoster
                          src={video}
                          className="h-10 w-16 rounded object-cover"
                          onUnavailable={() => undefined}
                        />
                      ) : (
                        <span className="flex h-10 w-16 items-center justify-center rounded bg-surface-hover text-[10px] text-text-muted">
                          {t.noPreview}
                        </span>
                      )}
                    </td>
                    <td className="p-2" onClick={(e) => e.stopPropagation()}>
                      <Link to={`${creative.id}${libraryAddress}`} className="text-start font-medium text-text-primary underline-offset-2 hover:underline">
                        {creative.name}
                      </Link>
                    </td>
                    <td className="p-2 text-text-secondary">{providerLabel(creative.provider, locale)}</td>
                    <td className="max-w-48 truncate p-2 text-text-secondary">{creative.campaign_name ?? '—'}</td>
                    <td className="p-2 text-text-secondary">
                      {creative.objective ? objectiveLabel(creative.objective, locale) : marketingPathLabel(creative.path, locale)}
                    </td>
                    <td className="p-2 tabular-nums" dir="ltr">
                      {/*
                        * CONTENT-MONEY-VISIBLE-001 — through the canonical reader, not `metricState`.
                        *
                        * `metricState` sees only the CONVERTED column, so a withheld figure — which
                        * is every Snapchat row on production, a USD account with no USD→SAR rate —
                        * rendered as «No data». Real, measured spend reported as never having run.
                        */}
                      {creativeMoney(creative.metrics, 'spend', data?.currency ?? null, locale).text}
                    </td>
                    <td className="p-2" dir="ltr">
                      {resultKey === null ? (
                        <span className="text-text-muted">—</span>
                      ) : (
                        <span className="tabular-nums">
                          {formatMetric(metricState(creative.metrics, resultKey), resultKey, locale, data?.currency ?? null)}
                          <span className="ms-1 text-[11px] text-text-muted">{metricLabel(resultKey, locale)}</span>
                        </span>
                      )}
                    </td>
                    <td className="p-2" dir="ltr">
                      {efficiencyKey === null ? (
                        <span className="text-text-muted">—</span>
                      ) : (
                        <span className="tabular-nums">
                          {formatMetric(metricState(creative.metrics, efficiencyKey), efficiencyKey, locale, data?.currency ?? null)}
                          <span className="ms-1 text-[11px] text-text-muted">{metricLabel(efficiencyKey, locale)}</span>
                        </span>
                      )}
                    </td>
                    <td className="p-2">
                      <span className={`rounded px-1.5 py-0.5 text-xs ${FATIGUE_TONE[creative.fatigue.status]}`}>
                        {FATIGUE_LABEL[creative.fatigue.status]?.[ar ? 'ar' : 'en'] ?? creative.fatigue.status}
                      </span>
                    </td>
                    <td className="p-2 text-xs text-text-secondary" dir="ltr">
                      {creative.freshness.last_synced_at?.slice(0, 10) ?? t.never}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {total > perPage && (
        <nav className="flex items-center justify-between gap-3 text-sm" aria-label={t.showing}>
          <span className="text-text-secondary" dir="ltr">
            {(page - 1) * perPage + 1}–{Math.min(page * perPage, total)} {t.of} {total}
          </span>
          <div className="flex gap-2">
            <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              {t.prev}
            </Button>
            <Button variant="secondary" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}>
              {t.next}
            </Button>
          </div>
        </nav>
      )}

      {viewerIndex !== null && creatives[viewerIndex] && (
        <CreativeViewer
          creatives={creatives}
          index={viewerIndex}
          onIndexChange={setViewerIndex}
          onClose={() => setViewerIndex(null)}
          /*
           * The panel, with the figures beside the asset — so «should we keep running this» is
           * answerable here rather than four navigations away. It carries THIS library's window, so
           * the pane can never quote a different period from the row that opened it, and the
           * details link carries the library's address so Back rebuilds the shelf.
           */
          analysis={{ window: { from, to }, detailsTo: (c) => `${c.id}${libraryAddress}` }}
        />
      )}

      {comparing && (
        <CreativeCompare
          creativeIds={selected}
          creatives={creatives.filter((c) => selected.includes(c.id))}
          window={{ from, to }}
          onClose={() => setComparing(false)}
        />
      )}
    </div>
  )
}

function CreativeGridCard({
  creative,
  currency,
  availability,
  t,
  ar,
  locale,
  selected,
  onSelect,
  onOpen,
  showPreviewPanel = true,
  detailsTo,
}: {
  creative: CreativeCard
  /** False when nothing in the result has an asset — the reserved 16:9 panel is then dead space. */
  showPreviewPanel?: boolean
  /** CREATIVE-MONEY-TRUTH-001 — stated by the payload, never assumed by the card. */
  currency: string | null
  /** CONTENT-STATE-SEMANTICS-001 — what the sync recorded for THIS creative's provider. */
  availability: MetricsAvailability | undefined
  t: (typeof COPY)['ar']
  ar: boolean
  locale: 'ar' | 'en'
  selected: boolean
  onSelect: () => void
  onOpen: () => void
  detailsTo: string
}) {
  const preview = creative.preview
  const poster = preview.thumbnail_url ?? preview.image_url
  /*
   * CONTENT-PREVIEW-VIDEO-001 — a video with no poster is not «no preview».
   *
   * Snapchat returns a video creative's file as `video_url` and often supplies no separate
   * thumbnail, so this card said «لا توجد معاينة» while holding the asset itself.
   */
  /*
   * CONTENT-VIDEO-POSTER-001 — a video that will not decode is «no preview», not a black box.
   *
   * An expired signed link, a CDN that refuses the range request, a codec the browser declines:
   * each leaves a `<video>` that paints nothing and keeps its space. The card falls back to the
   * sentence it already has for an absent asset, so one fact gets one statement.
   */
  const [brokenVideo, setBrokenVideo] = useState(false)
  const video = poster === null && !brokenVideo ? preview.video_url : null
  const note = ar ? preview.note_ar : preview.note_en

  return (
    <article className={`flex h-full flex-col overflow-hidden rounded-lg border bg-surface ${selected ? 'border-primary' : 'border-border'}`}>
      <div className="relative">
        <button
          type="button"
          onClick={onOpen}
          aria-label={`${t.open}: ${creative.name}`}
          className={showPreviewPanel ? 'block aspect-video w-full bg-surface-hover' : 'block w-full bg-surface-hover'}
        >
          {poster ? (
            <img
              src={poster}
              alt={creative.name}
              // Off-screen cards cost nothing until they scroll into view — the difference between
              // a page and a download on a phone with twenty creatives on it. An INLINE asset is
              // exempt: see `imageLoading`, where lazy-loading a `data:` URI stopped it loading at all.
              loading={imageLoading(poster)}
              decoding="async"
              className="h-full w-full object-cover"
            />
          ) : video ? (
            /*
             * The cheapest thing that shows the asset: `preload="metadata"` fetches enough for a
             * first frame and stops, and nothing autoplays. Twenty `preload="auto"` videos on one
             * grid would cost a phone tens of megabytes to open the page — which is the reason
             * cards never mounted a player, and the reason this one is deliberately inert.
             *
             * CONTENT-VIDEO-POSTER-001 — the seek is PERFORMED, not requested. See `VideoPoster`.
             */
            <VideoPoster
              src={video}
              className="h-full w-full object-cover"
              onUnavailable={() => setBrokenVideo(true)}
            />
          ) : showPreviewPanel ? (
            <span className="flex h-full flex-col items-center justify-center gap-1 p-3 text-center text-xs text-text-secondary">
              <span>{t.noPreview}</span>
              {note && <span className="text-[11px] opacity-80">{note}</span>}
            </span>
          ) : (
            /*
             * CONTENT-NO-PREVIEW-001 — the PANEL goes, the reason stays.
             *
             * A first pass dropped this branch entirely and took the note with it. The absences are
             * not interchangeable: «the platform does not hand back the file» and «the link carries
             * a credential, so we will not show it» are different facts about this creative, and the
             * second is one the reader needs in order to stop looking for the asset. One line, no
             * reserved box.
             */
            note && (
              <span className="block px-3 py-1.5 text-start text-[11px] text-text-secondary">{note}</span>
            )
          )}
        </button>

        <label className="absolute top-2 flex items-center gap-1 rounded bg-surface/90 px-1.5 py-1 text-xs start-2">
          <input
            type="checkbox"
            checked={selected}
            onChange={onSelect}
            aria-label={`${t.compare}: ${creative.name}`}
          />
        </label>

        {/*
          The badge says «فيديو» in Arabic, not the literal `video`.

          It fell back to the raw English word whenever the platform sent no duration, which on an
          Arabic page is an untranslated term sitting on top of the picture. `dir="ltr"` is applied
          only to the DURATION, because «30s» is a Latin figure and «فيديو» is not — and because an
          element carrying `dir="ltr"` inside an RTL page matches BOTH the `ltr:` and `rtl:`
          variants, which is what was stretching this badge across the whole card.
        */}
        {creative.preview.kind === 'video' && (
          <span className="absolute bottom-2 end-2 rounded bg-black/70 px-1.5 py-0.5 text-[11px] text-white">
            {creative.duration_seconds === null ? (
              KIND_LABEL.video[ar ? 'ar' : 'en']
            ) : (
              <span dir="ltr">{creative.duration_seconds}s</span>
            )}
          </span>
        )}
      </div>

      <div className="flex flex-1 flex-col gap-2 p-3">
        <div className="flex items-start justify-between gap-2">
          {/* The NAME is a real link — it can be middle-clicked, copied and sent. The poster above
              stays a button, because a quick look is not a navigation. */}
          <Link to={detailsTo} className="text-start text-sm font-medium text-text-primary underline-offset-2 hover:underline">
            {creative.name}
          </Link>
          {creative.is_demo && (
            <span className="shrink-0 rounded bg-warning/15 px-1.5 py-0.5 text-[11px] text-warning">{t.demo}</span>
          )}
        </div>

        <p className="text-xs text-text-secondary">
          {providerLabel(creative.provider, locale)}
          {creative.campaign_name ? ` · ${creative.campaign_name}` : ''}
          {creative.objective ? ` · ${objectiveLabel(creative.objective, locale)}` : ''}
        </p>

        {/*
          * CONTENT-STATE-SEMANTICS-001 — a creative with NO figures says why, once.
          *
          * Repeating «لا توجد بيانات» four times down a metric grid told the operator nothing and
          * hid the only fact that mattered: whether the platform was asked, answered, or refused.
          * A creative that has figures keeps the grid; one that has none gets the reason instead.
          */}
        {creative.metrics === null ? (
          /*
             CONTENT-AD-DELIVERED-001 — three absences, three sentences.

             `metrics_availability` answers what happened to the REQUEST, and when the request
             succeeded it says «لم يعمل خلال هذه الفترة». That is true of a creative that did not
             deliver and FALSE of one whose ad ran while the platform declined to break the result
             down per creative — 35 creatives on this account. The ad-level fact decides which.
          */
          creative.ad_delivered ? (
            <EmptyReasonPanel reason={creativeGrainMissing(locale)} />
          ) : (
            <CardEmptyReason availability={availability} locale={locale} />
          )
        ) : creative.headline_metrics.length === 0 ? (
          /*
             CONTENT-KPI-EMPTY-STATE-001 — «it ran and we cannot headline it» is its OWN sentence.
             
             This branch used to share the one above, and that was a false statement. A creative with
             a metrics object HAS figures — the platform answered for it — so printing
             «لم يعمل خلال هذه الفترة» over it tells the operator to leave alone a creative that is
             actually running. `metrics_availability` cannot answer this either: it records what
             happened to the REQUEST, and the request succeeded.
             
             Still not an empty grid: mapping over an empty list would render a `<dl>` with nothing
             in it, which reads as a broken card rather than as a true statement.
          */
          <EmptyReasonPanel reason={noDisplayableMetrics(locale)} />
        ) : (
          /* The creative's OWN headline metrics — chosen by its objective, so an awareness video is
             never asked for a cost per order it was not bought to produce. */
          <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
            {creative.headline_metrics.slice(0, 4).map((key) => (
              <div key={key} className="flex flex-col">
                <dt className="text-text-secondary">{metricLabel(key, locale)}</dt>
                <dd className="tabular-nums text-text-primary" dir="ltr">
                  {/*
                    * CONTENT-MONEY-VISIBLE-001 — money through the canonical reader, everything
                    * else through `metricState`.
                    *
                    * `metricState` reads the CONVERTED column only, so a withheld spend rendered as
                    * «No data» — on production that is every Snapchat creative, because the account
                    * spends USD and no USD→SAR rate exists. Counts and ratios keep the old path,
                    * which is correct for them: it already tells a measured zero from «not sent».
                    */}
                  {key === 'spend' || key === 'revenue'
                    ? creativeMoney(creative.metrics, key, currency, locale).text
                    : formatMetric(metricState(creative.metrics, key), key, locale, currency)}
                </dd>
              </div>
            ))}
          </dl>
        )}

        <div className="mt-auto flex flex-wrap items-center gap-2 pt-2">
          <span className={`rounded px-1.5 py-0.5 text-[11px] ${FATIGUE_TONE[creative.fatigue.status]}`}>
            {FATIGUE_LABEL[creative.fatigue.status]?.[ar ? 'ar' : 'en'] ?? creative.fatigue.status}
          </span>
          {creative.grouped && (
            <span className="rounded bg-surface-hover px-1.5 py-0.5 text-[11px] text-text-secondary">{t.grouped}</span>
          )}
          {/* A card shows ONE picture; saying how many there are is what stops that reading as all
              of them. Only when the platform actually sent the breakdown. */}
          {creative.preview.cards_reported && (creative.preview.cards?.length ?? 0) > 0 && (
            <span className="rounded bg-surface-hover px-1.5 py-0.5 text-[11px] text-text-secondary" dir="ltr">
              {t.cards(creative.preview.cards?.length ?? 0)}
            </span>
          )}
          <Link to={detailsTo} className="ms-auto text-[11px] text-brand-700 underline-offset-2 hover:underline">
            {t.details}
          </Link>
        </div>

        {/* §15.15 — where the number came from and how old it is, beside the number itself. */}
        <p className="text-[11px] text-text-secondary">
          {t.source} · {t.lastSync}:{' '}
          <span dir="ltr">{creative.freshness.last_synced_at?.slice(0, 10) ?? t.never}</span>
        </p>
      </div>
    </article>
  )
}

/**
 * CONTENT-STATE-SEMANTICS-001 — the reason, rendered once, with the right weight.
 *
 * `failed` is the only state drawn as a warning: numbers exist at the platform and we do not have
 * them, which is a pipeline to go and fix. A creative that simply did not run is not a problem and
 * must not be dressed as one — that is how real alerts stop being read.
 */
function CardEmptyReason({
  availability,
  locale,
}: {
  availability: MetricsAvailability | undefined
  locale: 'ar' | 'en'
}) {
  return <EmptyReasonPanel reason={emptyReason(availability, locale)} />
}

/**
 * One panel, several sentences — the sentence is the whole difference.
 *
 * Extracted so «this creative did not run» and «this creative ran and none of its figures can be
 * headlined» look identical and READ differently. They were briefly the same branch, and a shared
 * branch is how the second came to print the first's words over a creative that was delivering.
 *
 * `data-testid` carries the kind, so a test can assert WHICH sentence rendered rather than that
 * something grey appeared.
 */
function EmptyReasonPanel({ reason }: { reason: EmptyReason }) {
  return (
    <div
      className={`rounded-md px-2 py-1.5 text-xs ${
        reason.tone === 'warning'
          ? 'bg-warning/10 text-warning'
          : 'bg-surface-muted text-text-secondary'
      }`}
      data-testid={`creative-empty-${reason.kind}`}
    >
      {reason.text}
      {/* The provider's own words — «rate limited» is actionable in a way «no data» never was. */}
      {reason.kind === 'failed' && reason.detail !== null && (
        <span className="mt-0.5 block text-[11px] opacity-80">{reason.detail}</span>
      )}
    </div>
  )
}
