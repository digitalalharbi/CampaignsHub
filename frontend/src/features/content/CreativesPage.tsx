import { creativeKindLabel as creativeKindLabelImpl } from './creativeKind'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { GitCompare, Layers, LayoutGrid, Rows3 } from 'lucide-react'
import { DeliveryBadge } from './DeliveryBadge'
import { relevanceOf } from '@/features/campaigns/campaignRelevance'
import { PosterImage } from './PosterImage'
import { AdPreviewDialog } from './AdPreviewDialog'
import { creativeDialogFigures } from './creativeDialogFigures'
import { CreativeTrend } from './CreativeTrend'
import { CreativeCompare } from './CreativeCompare'
import { AboutThisData } from './AboutThisData'
import { MetricValue } from './MetricValue'
import { metricLabel } from './metrics'
import { canonicalFigureKeys } from './canonicalFigures'
import { creativeGrainMissing, emptyReason, noDisplayableMetrics, type EmptyReason, type MetricsAvailability } from './availability'
import { absenceLabel, assetAspect, mediaFitClass, posterSource, previewShape, readPreview } from './adPreview'
import { imageLoading } from './format'
import { creativeMoney } from './creativeMoney'
import { VideoPoster } from './VideoPoster'
import { anyDisplayablePreview } from './previewPresence'
import {
  groupCreatives,
  libraryQueryString,
  contentIntelligence,
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
import { PageIntro, DataFreshness, STALE_AFTER_HOURS } from '@/components/ui/PageIntro'
import { PeriodLabel } from '@/components/patterns/Status'
import { useFreshness } from '@/features/analytics/api'
import { listProjects } from '@/features/projects/api'
import { ContentSummary } from './ContentSummary'
import { metricsForKeys } from '@/features/analytics/metricCatalog'
import type { Summary } from '@/features/analytics/api'

/**
 * The figures a content library is read on — «provider-available» in the Owner's own list.
 *
 * Spend, reach and impressions, clicks and their rate, the cost of each, the result and what it
 * cost, revenue and its return, and the video figures this surface exists for. Every one of them is
 * a catalogue key: a metric named here that the platform never sent still renders «لم ترسله
 * المنصة», which is the requirement's «never turn unavailable data into zero».
 */
const CONTENT_SUMMARY_KEYS = ['spend', 'impressions', 'ctr', 'cpa'] as const
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useProject } from '@/stores/project'
import { campaignStatusLabel, marketingPathLabel, objectiveLabel, providerLabel } from '@/features/campaigns/labels'
import { canonicalObjectiveLabel, type CanonicalObjectiveKey } from '@/features/campaigns/canonicalObjectives'
import { Num } from '@/components/ui/Num'

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

/**
 * How many figures a card draws before the reader asks for the rest.
 *
 * A LAYOUT number, and only that: `canonicalFigureKeys` decides which figures exist, the card folds
 * the remainder behind «+N», and nothing is dropped on the way. This used to be a cap on the list
 * itself — three, in the page, and four again on the server — which is how figures the platform
 * reported disappeared between one surface and the next.
 */
const CARD_FIGURES = 3

const COPY = {
  ar: {
    title: 'مكتبة المحتويات',
    subtitle: 'كل إعلان مزامَن — بأرقامه الحقيقية، ومقارنته بمسار هدفه.',
    search: 'ابحث بالاسم أو نص الإعلان…',
    grid: 'شبكة',
    list: 'قائمة',
    all: 'الكل',
    client: 'العميل',
    project: 'المشروع',
    platform: 'المنصة',
    adAccount: 'الحساب الإعلاني',
    delivery: 'حالة العرض',
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
    sortAuto: 'تلقائي — حسب هدف الحملة',
    sortedBy: 'مرتَّب حسب',
    sortedByObjective: 'لأن هدف الحملات هنا',
    sortedBySpendFallback: 'ما يعمل أولًا، ثم حسب الإنفاق — لم تُحدَّد وجهة واحدة لترتيبها عليها.',
    /*
     * CONTENT-BROWSER-PARITY-001 — the running-first clause is SAID, because it now decides.
     *
     * It used to sit below the metric and below spend, where it almost never fired, so leaving it
     * out of the sentence cost nothing. It leads the measured now, and an order a reader cannot
     * account for is indistinguishable from a bug — which is the defect this whole statement exists
     * to prevent.
     */
    sortedRunningFirst: 'ما يعمل أولًا، ثم',
    sortRelevance: 'ما يعمل الآن',
    sortRecent: 'الأحدث نشاطًا',
    sortSpend: 'الأعلى إنفاقًا',
    sortImpressions: 'الأعلى ظهورًا',
    sortConversions: 'الأعلى طلبات',
    sortClicks: 'الأعلى نقرًا',
    sortEngagements: 'الأعلى تفاعلًا',
    sortVideoViews: 'الأعلى مشاهدة',
    sortReach: 'الأوسع وصولًا',
    sortEngagementRate: 'الأعلى معدل تفاعل',
    sortName: 'الاسم',
    compare: 'مقارنة',
    compareHint: 'اختر إعلانين أو أكثر للمقارنة.',
    selected: 'محدَّد',
    merge: 'دمج كأصل واحد',
    merging: 'جارٍ الدمج…',
    merged: 'تم دمج {n} إعلانات كأصل واحد.',
    mergeFailed: 'تعذّر الدمج. تأكد أن الإعلانات المختارة تتبع المشروع نفسه.',
    openGroup: 'فتح المجموعة',
    groups: 'المجموعات',
    clearSelection: 'إلغاء التحديد',
    empty: 'لا توجد إعلانات تطابق هذا التحديد.',
    emptyAll: 'لا توجد إعلانات بعد — تظهر هنا بعد مزامنة الحملات.',
    error: 'تعذّر تحميل المكتبة.',
    demo: 'وضع تجريبي',
    grouped: 'مجمَّع عبر المنصات',
    cards: (n: number) => `${n} بطاقات`,
    noPreview: 'لا تتوفر معاينة',
    noPreviewAll: 'لم تُرجع المنصة ملف أي إعلان في هذه النتيجة؛ الأرقام أدناه كاملة.',
    lastSync: 'آخر مزامنة',
    never: 'لم تتم بعد',
    showing: 'المعروض',
    of: 'من',
    prev: 'السابق',
    next: 'التالي',
    open: 'فتح المعاينة',
    fewerMetrics: 'عرض أقل',
    preview: 'المعاينة',
    name: 'الاسم',
    result: 'النتيجة',
    efficiency: 'الكفاءة',
    details: 'تفاصيل المحتوى',
    source: 'المصدر: منصة الإعلان',
    allContent: 'كل الإعلانات',
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
    title: 'Content library',
    subtitle: 'Every synced piece of content — with its real figures, judged against its own objective.',
    search: 'Search by name or ad copy…',
    grid: 'Grid',
    list: 'List',
    all: 'All',
    client: 'Client',
    project: 'Project',
    platform: 'Platform',
    adAccount: 'Ad account',
    delivery: 'Delivery',
    campaign: 'Campaign',
    adSet: 'Ad set',
    ad: 'Ad',
    objective: 'Objective',
    path: 'Marketing path',
    kind: 'Content type',
    status: 'Status',
    health: 'Fatigue',
    from: 'From',
    to: 'To',
    sort: 'Sort',
    sortAuto: 'Automatic — by campaign objective',
    sortedBy: 'ordered by',
    sortedByObjective: 'because the objective here is',
    sortedBySpendFallback: 'What is running first, then by spend — no single objective was named to rank on.',
    sortedRunningFirst: 'What is running first, then',
    sortRelevance: 'What is running',
    sortRecent: 'Most recently active',
    sortSpend: 'Highest spend',
    sortImpressions: 'Most impressions',
    sortConversions: 'Most orders',
    sortClicks: 'Most clicks',
    sortEngagements: 'Most engagements',
    sortVideoViews: 'Most views',
    sortReach: 'Widest reach',
    sortEngagementRate: 'Highest engagement rate',
    sortName: 'Name',
    compare: 'Compare',
    compareHint: 'Select two or more ads to compare.',
    selected: 'selected',
    merge: 'Merge as one asset',
    merging: 'Merging…',
    merged: '{n} ads were merged as one asset.',
    mergeFailed: 'The merge failed. Check that the selected ads belong to the same project.',
    openGroup: 'Open group',
    groups: 'Groups',
    clearSelection: 'Clear selection',
    empty: 'No ads match this selection.',
    emptyAll: 'No ads yet — they appear here after campaigns sync.',
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
    fewerMetrics: 'Show fewer',
    preview: 'Preview',
    name: 'Name',
    result: 'Result',
    efficiency: 'Efficiency',
    details: 'Content details',
    source: 'Source: ad platform',
    allContent: 'All ads',
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

/**
 * CONTENT-KIND-LABEL-001 — the creative's type, in words.
 *
 * The library badge has always said «فيديو»; the creative DETAIL page rendered `preview.kind`
 * straight, so opening a creative to read about it showed «نوع المحتوى video» — the one screen
 * dedicated to describing an asset naming its type in the database's words.
 *
 * Exported so the detail page reads this map rather than growing a second one three lines long,
 * which is how the label maps in this codebase have drifted every previous time.
 */
/*
 * CONTENT-KIND-VOCABULARY-001 — the map moved to `creativeKind.ts`, and grew the kinds it was missing.
 *
 * The docblock above warned that these maps drift «every previous time», and it was already true of
 * this one in two ways:
 *
 *  - it held THREE kinds. `CreativeKind::ALL` has five, and the filter offers all five — so
 *    filtering by «كولكشن» produced a badge reading the bare English «collection», and a catalog
 *    read «catalog». Two of the five shapes had no word in this product at all.
 *  - two other tables named the same shapes differently («المجموعة»/«تشكيلة», «الدوارة»/«دوّار»).
 *
 * «An unknown kind shows as itself» was the other half, and it is why a provider token reached a
 * reader as the ad's type: `external_creatives.format` carries whatever the platform called it, so
 * «story_ad» was shown under «النوع». The intent — do not HIDE a shape we failed to classify — is
 * kept and served better: the type says «غير مصنّف», and the platform's own token is shown beside
 * it, named as the platform's.
 */
export { creativeKindLabel } from './creativeKind'

/** This module re-exports the labeller, so it reads it under its own name. */
const kindWord = (kind: string, ar: boolean): string => creativeKindLabelImpl(kind, ar)

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
  'ad_ids', 'objectives', 'kinds', 'statuses',
] as const

const isoDaysAgo = (days: number) => {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return d.toISOString().slice(0, 10)
}

/**
 * The address this library should be at, for a given set of controls — CONTENT-VIEW-PERSISTS-001.
 *
 * One builder, because there are now two writers: the effect that follows the controls, and the view
 * toggle that records its own decision the moment it is made. Two hand-rolled strings would drift,
 * and the drift would show up as a click that appears to do nothing — which is the bug this exists
 * to end rather than to reproduce somewhere new.
 *
 * The default is NOT written. An address should carry a decision, not a restatement of what the page
 * would have done anyway, or every link anybody shares grows a `view=grid` that means nothing.
 */
function addressFor(query: LibraryQuery, view: 'grid' | 'list', creative: string | null): string {
  return [
    libraryQueryString(query).replace(/^\?/, ''),
    view === 'list' ? 'view=list' : '',
    creative ? `creative=${creative}` : '',
  ]
    .filter((part) => part !== '')
    .join('&')
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

  /*
   * CONTENT-VIEW-PERSISTS-001 — the view is a control, so it lives where the other controls live.
   *
   * `search`, `from`, `to` and `sort` all seed from the address and are written back, under the
   * comment above that says «so a refresh, a Back, or a shared link reopens this view». `view` was
   * the exception: `useState('grid')` with no seed and no write-back, so a reader who chose the list
   * got the grid again on every refresh, every shared link, and every remount of this page.
   *
   * A gate found it before a person reported it. The alignment sweep clicked «قائمة» and the page
   * came back reporting «شبكة=true, قائمة=false» with four grid cards still on screen — the click had
   * landed and a re-render had thrown the answer away. That is the same defect a user meets as «it
   * keeps forgetting which view I chose», and it was invisible in isolation because nothing remounts
   * the page when you are looking at it.
   */
  /*
   * ...and seeding from the address was not enough, because the seed can still be read too early.
   *
   * The first fix gave `view` a `useState` initialiser reading the address. It closed the refresh and
   * the shared link, and left a race the sweep kept finding: click «قائمة» and the answer vanishes,
   * roughly two runs in four at 1440, in EITHER writing direction — the locale had nothing to do with
   * it, which is what ruled out the layout explanation.
   *
   * The sequence. This page opens on a bare address and the effect below writes the controls into it,
   * computed while `view` is still `grid`. A click arriving before that navigation settles sets the
   * state, and then the write lands, the page re-reads its opening state from an address that says
   * nothing about the view, and the answer is gone. Measured: one `evaluate` round trip inserted
   * before the click — enough for the first write to land — and the failure stopped.
   *
   * So the state stops being a copy. The ADDRESS is the view, read on every render: there is now no
   * second place holding the answer, and therefore nowhere to lose it. A user meets this as «it
   * forgot which view I chose» after clicking a little too quickly on a slow connection, which is
   * exactly when a reader is most likely to click before a page has settled.
   */
  const view: 'grid' | 'list' = params.get('view') === 'list' ? 'list' : 'grid'
  const [search, setSearch] = useState(() => initial.current.get('search') ?? '')
  const [from, setFrom] = useState(() => initial.current.get('from') ?? isoDaysAgo(29))
  const [to, setTo] = useState(() => initial.current.get('to') ?? isoDaysAgo(0))
  /*
   * CONTENT-OBJECTIVE-SORT-001 — the default is the OBJECTIVE's metric, not a fixed one.
   *
   * Spend is the right order for «where is the money» and the wrong one for «what worked»: an
   * awareness campaign that spent most is not the creative that was seen most, and ranking a sales
   * library by spend puts the expensive creative above the one that actually sold. The server
   * resolves which metric from the scope's objective and says so in `sort.metric`, which the strip
   * below prints — an order a reader cannot account for is indistinguishable from a bug.
   */
  const [sort, setSort] = useState(() => initial.current.get('sort') ?? 'auto')
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

  /* The project this library belongs to, and how current its figures are — see the head below. */
  const projectsQuery = useQuery({ queryKey: ['projects'], queryFn: () => listProjects(), retry: false })
  const projectName = projectsQuery.data?.find((p) => p.id === currentProjectId)?.name ?? null

  const freshness = useFreshness(currentProjectId, { from, to })
  const freshestAt = useMemo(
    () => (freshness.data?.rows ?? [])
      .map((row) => row.data_freshness_at)
      .filter((at): at is string => at !== null)
      .sort()
      .at(-1) ?? null,
    [freshness.data],
  )

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
   * CONTENT-SUMMARY-COMPACT-001 — the split of spend across content shapes.
   *
   * The one comparison this page owes that a dashboard cannot give: «what KIND of content earns its
   * money here» is the only content question whose answer transfers to the next brief. Computed
   * server-side already, over the same filtered scope as the library itself, so the bar and the grid
   * under it can never describe different sets.
   *
   * A failure is silent — the bar simply does not draw. A content library that fails to load because
   * a comparison did would be a worse outcome than the missing bar.
   */
  const intelligence = useQuery({
    queryKey: ['content-intelligence', currentProjectId, query],
    queryFn: () => contentIntelligence(query, currentProjectId ?? ''),
    enabled: Boolean(currentProjectId),
    placeholderData: keepPreviousData,
  })

  /*
   * The address follows the controls — so a refresh, a Back, or a shared link reopens this view.
   *
   * `replace` deliberately: typing in the search box would otherwise push a history entry per
   * keystroke, and Back would walk the reader backwards through their own typing.
   */
  useEffect(() => {
    const wanted = addressFor(query, view, params.get('creative'))

    /*
     * Written only when it would actually change something.
     *
     * An unconditional `setParams` navigates on every render that reaches here, and a navigation
     * this page does not need is a re-render it does not need — which is the very thing that used to
     * throw a click away. Comparing first makes the common case a no-op.
     */
    if (wanted !== params.toString()) {
      setParams(wanted, { replace: true })
    }
    // `params` is deliberately absent from the deps: including it would re-run this on the write it
    // just made. It is READ here only to compare, which cannot loop.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, view, setParams])

  /*
   * Choosing a view IS writing the address — one step, not two.
   *
   * Setting a state and letting an effect mirror it into the URL a render later is what left the
   * window a click could fall into. There is no window now: the decision and the record of it are
   * the same operation.
   */
  const chooseView = (next: 'grid' | 'list') =>
    setParams(addressFor(query, next, params.get('creative')), { replace: true })

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

  /*
   * The library's headline figures, as catalogue items.
   *
   * `metricsForKeys` is the same builder the dashboard's KPI cards use, and it wants the summary
   * envelope: `current` for the figures and `reported` for which of their zeros are measurements.
   * The server's totals already carry both — this only names them in the shape the catalogue reads,
   * rather than re-deriving a single number.
   *
   * `previous` is empty and `delta` is absent on purpose: the library has no comparison window, and
   * a card drawn with a delta of zero would say a figure held steady when nothing was compared.
   */
  /*
   * CONTENT-SUMMARY-COMPACT-001 — four figures, not thirteen.
   *
   * The strip this replaces named every metric the catalogue holds, and on the owner's own account
   * nine of the thirteen read «لا توجد بيانات» or «لم ترسله المنصة». Truthful and unreadable: a wall
   * of empty boxes above the thing the page is for.
   *
   * These four are what a content reader is deciding on — what it cost, how far it reached, whether
   * anybody clicked, and what a result cost. The other nine are not deleted; they are on the
   * creative's own page and in the popup, where somebody has asked about ONE thing rather than
   * scanned a shelf.
   *
   * Read through `metricsForKeys` exactly as before, so «لم ترسله المنصة» and the money contract's
   * refusals survive the change — the strip's size is what was wrong, not its honesty.
   */
  const summaryFigures = useMemo(() => {
    const totals = data?.totals

    if (!totals) {
      return []
    }

    const summary = {
      current: totals as unknown as Summary['current'],
      previous: {} as Summary['previous'],
      delta: {},
      reported: (totals as unknown as { reported?: Record<string, boolean> }).reported ?? {},
      rows_in_scope: data?.total ?? 0,
      currency: data?.currency ?? null,
    } as unknown as Summary

    return metricsForKeys(CONTENT_SUMMARY_KEYS, 'all', summary, ar).map((m) => ({
      key: m.key,
      label: typeof m.label === 'string' ? m.label : m.key,
      value: m.reading.kind === 'value' ? m.reading.text : m.reading.kind === 'withheld' ? m.reading.original : '—',
    }))
  }, [data, ar])

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
      ...forAxis('kinds', t.kind, (k) => kindWord(k, ar)),
      ...forAxis('objectives', t.objective, (o) => canonicalObjectiveLabel(o as CanonicalObjectiveKey, ar ? 'ar' : 'en')),
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
    <div className="space-y-4">
      {/*
        PRODUCT-VISUAL-001 §4 §15 — WHICH project, WHAT window, HOW fresh.

        The library's cards are figures from one project over one window, and the head named
        neither: the project lived in the rail's switcher and the dates in two inputs below the
        fold. The same three answers every other surface now gives, in the same place.

        Freshness is the project's own, from the canonical endpoint the analytics surfaces read —
        and it is the one a media-first page most needs, because a creative card with yesterday's
        cost per result looks exactly like one with last week's.
      */}
      <PageIntro
        testid="content-intro"
        eyebrow={projectName ?? ''}
        title={t.title}
        purpose={t.subtitle}
        meta={
          <>
            <PeriodLabel from={from} to={to} testId="content-period" />
            <DataFreshness
              lastSyncAt={freshestAt}
              ar={ar}
              staleAfterHours={STALE_AFTER_HOURS}
              testid="content-freshness"
            />
            {/*
              §17 — said once, where a reader meets it before they start wondering.
              Four absences explained one tooltip at a time still leave the reader assembling the
              pattern; this is the pattern, in a sentence.
            */}
            <AboutThisData locale={locale} testid="content-about-data" />
          </>
        }
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

      {/*
        CONTENT-KPI-TOTALS-001 — the figures for the library the reader is looking at.

        «The Content area does not visibly expose the required KPI figures consistently … never turn
        unavailable data into zero.» There was no totals row at all: a card per creative, and no way
        to ask what the current filter cost.

        Drawn through `MetricStrip` over the canonical catalogue, so these cards are the same cards
        the dashboard and the analysis draw — same formatting, same «لم ترسله المنصة» for a metric
        the platform never sent, same refusal when the money cannot be added. The server totals over
        the FILTERED set, never the page, so the strip and the cards beneath it describe one scope.
      */}
      <ContentSummary
        figures={summaryFigures}
        formats={intelligence.data?.by_format.formats}
        creativesRead={intelligence.data?.creatives_read ?? null}
        creativesInScope={(data?.totals as { creatives?: number } | null | undefined)?.creatives ?? null}
        scopeName={projectName}
        loading={libraryQuery.isPending}
        formatsPending={intelligence.isPending}
        currency={data?.currency ?? null}
        locale={locale}
      />

      <FilterBar
        id="content"
        ar={ar}
        applied={applied}
        onReset={resetFilters}
        advancedActive={advancedActive}
        /*
          CONTENT-TOOLBAR-STABLE-001 — and the «More filters» button with them.
          *
          * `FilterBar` renders that button only when it is GIVEN an advanced slot, and this slot was
          * gated on `options` like the controls above. So even with the controls fixed, one more
          * child appeared in the same wrapping row a second later and pushed the view toggle down a
          * whole line. The same defect, one level up, and it needed the same answer.
        */
        advanced={
          <div className="flex flex-wrap items-end gap-3">
            {multi('statuses', t.status, (options?.statuses ?? []).map((s) => ({ value: s, label: campaignStatusLabel(s, locale) })))}
            {multi('ad_set_ids', t.adSet, (options?.ad_sets ?? []).map((id) => ({ value: id, label: id })))}
            {/* Already labelled by the server — the id is the value, the ad's name is what is read. */}
            {multi('ad_ids', t.ad, options?.ads ?? [])}
          </div>
        }
        trailing={
          <>
            {/* How the page is READ, not how it is configured — so it sits with the controls and
                never inside a dialog. */}
            <div className="flex rounded-xl border border-border p-0.5" role="group" aria-label={t.grid}>
              <button
                type="button"
                aria-pressed={view === 'grid'}
                onClick={() => chooseView('grid')}
                className={`flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold ${view === 'grid' ? 'bg-surface-hover text-text-primary' : 'text-text-secondary'}`}
              >
                <LayoutGrid className="h-3.5 w-3.5" aria-hidden /> {t.grid}
              </button>
              <button
                type="button"
                aria-pressed={view === 'list'}
                onClick={() => chooseView('list')}
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
                /*
                 * ENTITY-RELEVANCE-ORDERING-001 — «what is running» leads, and it is the default.
                 *
                 * Recency is not relevance: a paused campaign's creative that delivered yesterday
                 * sorted above a serving creative whose last figure was three days old, so the first
                 * thing an operator saw was work they could do nothing about. «Most recently active»
                 * stays on the list, because it is a real question — it is just not the first one.
                 */
                /*
                  CONTENT-OBJECTIVE-SORT-001 — the automatic order leads, because it is the default.
                  «Most orders if the objective is sales, most clicks if it is engagement, most
                  impressions if it is awareness, and so on for every objective» — the metric is
                  resolved on the server from `ObjectiveFamily`, so this list never names one.
                */
                { value: 'auto', label: t.sortAuto },
                { value: 'relevance', label: t.sortRelevance },
                { value: 'recent', label: t.sortRecent },
                { value: 'spend', label: t.sortSpend },
                { value: 'conversions', label: t.sortConversions },
                { value: 'clicks', label: t.sortClicks },
                { value: 'impressions', label: t.sortImpressions },
                { value: 'engagements', label: t.sortEngagements },
                /* A RATE, computed over the window's totals — see `applySort`'s note on why not an
                   average of daily rates. */
                { value: 'engagement_rate', label: t.sortEngagementRate },
                { value: 'video_views', label: t.sortVideoViews },
                { value: 'reach', label: t.sortReach },
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

        {/*
          CONTENT-TOOLBAR-STABLE-001 — the controls exist from the first paint, empty until answered.
          *
          * These were gated on `options`, which arrives with the data. Seven controls therefore
          * appeared a second or two after the page did, in the SAME wrapping row as the view toggle
          * and before it — so the toggle dropped 68 pixels, measured, in every browser. Two whole
          * rows, under a reader who is already reaching for it.
          *
          * A gate found it while blaming something else: the alignment sweep clicked «قائمة» and hit
          * `DIV|ابحث بالاسم`, the search box, because the search box is what moved into that space.
          * A person meets the same thing as a mis-click on a page that has just finished loading.
          *
          * An empty control is honest — nothing has been narrowed yet, and «الكل» is exactly what it
          * would say anyway. A control that is not there and then IS there is the thing that lies,
          * because it moves everything a reader has already aimed at.
        */}
        <>
          {multi('client_ids', t.client, (options?.clients ?? []).map((c) => ({ value: c.id, label: c.name })))}
          {multi('project_ids', t.project, (options?.projects ?? []).map((p) => ({ value: p.id, label: p.name })))}
          {/* UX-FILTERS-001 — platforms as visible chips here too, so the library filters the
              same way the dashboard and analytics do. */}
          {/*
            CONTENT-TOOLBAR-STABLE-001 — and the space its chips will need is reserved for them.
            *
            * This control shows one chip per platform present in the data, so it is «الكل» alone on
            * first paint and «الكل جوجل ميتا سناب شات تيك توك» a second later. The chips are short
            * but there are several, and the extra width rewraps the row — which moved the view
            * toggle a whole line even after every control had stopped popping in.
            *
            * Reserved HERE rather than inside `FilterPlatforms`, which analytics also renders and
            * which has no such problem: a shared component should not carry one page's layout.
            *
            * `sm:` ONLY. A 384px floor on a 390px phone is wider than the screen once padding is
            * taken, and it pushed `/agency/content` into a sideways scroll — caught by the
            * appearance gate at «phone · rtl · light». The reservation exists to stop a row from
            * REWRAPPING, and at 390 there is no row to rewrap: the controls are stacked already.
          */}
          <div className="min-w-0 sm:min-w-96">
            <FilterPlatforms
              label={t.platform}
              allLabel={ar ? 'الكل' : 'All'}
              values={axes.providers ?? []}
              testid="content-providers"
              options={(options?.providers ?? []).map((p) => ({ value: p, label: providerLabel(p, locale) }))}
              onChange={(next) => setAxis('providers', next)}
            />
          </div>
            {multi('campaign_ids', t.campaign, (options?.campaigns ?? []).map((c) => ({ value: c.id, label: c.name })))}
            {/*
              CONTENT-FILTER-TRUTH-001 — the five PRODUCT objectives, with what each can reach.

              This rendered the RAW list the server sent, so «التحويلات» sat beside «المبيعات» as a
              competing choice and «المبيعات» narrowed to one raw value out of four. The «المسار
              التسويقي» control that used to follow it is GONE: it was the same axis asked twice, and
              Analytics removed it under ANALYTICS-OBJECTIVE-SYSTEM-001 while Content kept it.
            */}
            {multi('objectives', t.objective, (options?.objectives ?? []).map((o) => ({
              value: o.key,
              label: canonicalObjectiveLabel(o.key as CanonicalObjectiveKey, ar ? 'ar' : 'en'),
              count: o.count,
            })))}
            {multi('kinds', t.kind, (options?.kinds ?? []).map((k) => ({
              value: k.key,
              label: kindWord(k.key, ar),
              count: k.count,
            })))}

            {/* Single-valued: a creative is in exactly one fatigue state, so «watch AND fatigued» is
                not a question the server can be asked. */}
            <FilterSelect
              label={t.health}
              value={health}
              testid="content-health"
              options={[
                { value: '', label: t.all },
                ...(options?.health ?? []).map((status) => ({
                  value: status,
                  label: FATIGUE_LABEL[status]?.[ar ? 'ar' : 'en'] ?? status,
                })),
              ]}
              onChange={(v) => { setHealth(v); setPage(1) }}
            />
        </>
      </FilterBar>

      {/*
        BELOW the toolbar, not above it — CONTENT-TOOLBAR-STABLE-001.
        *
        * This block arrives with its own query, and anything that appears LATE above the toolbar
        * pushes the view toggle down: measured at 144px, which is a reader aiming at a control and
        * hitting whatever took its place. Beneath the toolbar a late arrival moves only itself.
      */}
      {/*
        CONTENT-FORMAT-ROAS-REMOVED-001 — the «which format wins» comparison is gone.

        The owner, with a screenshot: «يوجد خطأ فادح — كيف حققت الحملة أداء عائد إلى 5x بالمقابل
        المحتويات العائد لها ضعيف جداً، غير منطقي».

        He is right, and the contradiction was structural rather than a bug in the arithmetic. The
        block divided creative-grain REVENUE by creative-grain SPEND. Spend is attributed to every
        creative in full; revenue at that grain is reported by the platform only sometimes. So the
        numerator was a fraction of the truth over a denominator that was all of it, and the result —
        «الصور 0.02», «الكولكشن 0.10» — is not a return on anything. The campaign reading 5x beside
        it was computed at campaign grain, where both halves are reported, and nothing on the page
        reconciled the two.

        A verdict («الكولكشن أفضل في هذه الفترة») on top of that is worse than the number: it tells
        an operator to move budget on the strength of a figure nobody can stand behind.

        The spend SHARE by format stays, above — it divides nothing and is simply where the money
        went.
      */}

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

      {/*
        CONTENT-OBJECTIVE-SORT-001 — the automatic order, accounted for.

        An order a reader cannot explain is indistinguishable from a bug, and this unit exists
        because an order nobody could explain made the owner doubt the figures beside it. So the
        automatic sort says which metric it ranked by, and which objective it read that from — and
        where the filter named none or several, it says it fell back to spend rather than implying a
        goal the library does not have.

        Only for `auto`: every other sort names its own metric in the control the reader just used.

        Gated on what the SERVER applied, not on the local control. The two agree in normal use and
        the server is the authority when they do not — it is the thing that actually ordered the rows.

        ## Below the toolbar, not above it

        It first sat between the figures and the filter bar, where it appears only once the query
        resolves — so the toolbar dropped 40px the moment the options arrived and a reader aiming at
        the view toggle hit whatever took its place. `creative-analysis.spec.ts`'s «the library
        toolbar holds still while it loads» caught it, which is exactly what that guard is for.

        Here it costs the toolbar nothing, and it reads better: it describes the order of the ROWS,
        so it belongs immediately above them rather than above the controls that produced them.
      */}
      {data?.sort?.applied === 'auto' && (
        <p data-testid="content-sort-note" className="text-sm text-text-secondary">
          {data.sort.objective !== null
            ? `${t.sortedRunningFirst} ${t.sortedBy} ${metricName(data.sort.metric, ar)} — ${t.sortedByObjective} ${objectiveLabel(data.sort.objective, locale)}.`
            : t.sortedBySpendFallback}
        </p>
      )}

      {!libraryQuery.isPending && !libraryQuery.isError && creatives.length === 0 && (
        <div data-testid="content-no-results" className="rounded-lg border border-border bg-surface p-8 text-center text-sm text-text-secondary">
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
      {creatives.length > 0 && !anyPreview && (
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
                windowEnd={to}
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
                {/*
                  CONTENT-NO-PREVIEW-001, in the table.
                  
                  The grid stopped reserving space for an asset nobody returned; this column did
                  not, so a Meta-only result was a strip of «لا تتوفر معاينة» repeated down the
                  page, pushing spend and results to the right on a narrow screen. Dropped only
                  when NOTHING in the result has an asset, so a mixed set keeps the column and the
                  missing ones stay visibly missing.
                */}
                {anyPreview && <th className="p-2 text-start">{t.preview}</th>}
                <th className="p-2 text-start">{t.name}</th>
                <th className="p-2 text-start">{t.platform}</th>
                {/*
                  CONTENT-BROWSER-PARITY-001 — which ad account ran it.

                  A project reads more than one, so two creatives with the same name under two
                  accounts were indistinguishable here. It is also the axis
                  ACCOUNT-SCOPE-ISOLATION-001 is about: a reader who cannot see the account cannot
                  check the isolation they are being promised.
                */}
                <th className="p-2 text-start">{t.adAccount}</th>
                <th className="p-2 text-start">{t.campaign}</th>
                <th className="p-2 text-start">{t.objective}</th>
                {/*
                  The delivery state, which the CARD carries and this table did not.

                  «Fatigue» further right is a different question — whether a creative that IS
                  running has worn out — and reading it as «is this on?» is the confusion this
                  column removes. The default order now puts running content first, so the table
                  has to show the fact it is ordered by.
                */}
                <th className="p-2 text-start">{t.delivery}</th>
                {/*
                  TABLE-NUMERIC-ALIGNMENT-001 §58 — these three are the NUMERIC columns, so they
                  take the primitive's convention rather than the page's. `MetricTable` centres
                  every column after the first and says why: under `dir="rtl"` an end-aligned figure
                  sits against the left edge of its own column, which reads as the wrong column.
                  Start-aligning them agreed with their headers and was not wrong — it was a third
                  answer in a product that now has one.
                */}
                <th className="p-2 text-center">{metricLabel('spend', locale)}</th>
                <th className="p-2 text-center">{t.result}</th>
                <th className="p-2 text-center">{t.efficiency}</th>
                <th className="p-2 text-start">{t.health}</th>
                <th className="p-2 text-start">{t.lastSync}</th>
              </tr>
            </thead>
            <tbody>
              {creatives.map((creative, index) => {
                const resultKey = primaryResultKey(creative.headline_metrics)
                const efficiencyKey = primaryEfficiencyKey(creative.headline_metrics)
                /*
                 * OWNER CONTENT P0 — the row asks the CANONICAL reader, like every other surface.
                 *
                 * It read `thumbnail_url ?? image_url` straight off the envelope, which skips the two
                 * decisions `readPreview` exists to make: the STATE (an expired link still carries the
                 * old thumbnail, and drawing it presents a dead asset as the live ad) and the SHAPE (a
                 * collection's hero is `image_url`, and taking the thumbnail first is a second opinion
                 * about which frame the ad is). The owner met the result on production: a picture on
                 * the list and «no cover» on the creative one click away, about one creative.
                 */
                const reading = readPreview(creative.preview, ar)
                const poster = posterSource(reading)
                /*
                 * CONTENT-PREVIEW-VIDEO-001 — a video with no poster is not «no preview».
                 *
                 * Snapchat returns a video creative's file as `video_url` and frequently supplies no
                 * separate thumbnail, so a creative with a perfectly good video asset rendered «لا
                 * توجد معاينة» — the product claiming to have nothing while holding the thing itself.
                 */
                const video = poster === null && reading.kind === 'video' ? reading.src : null
                /*
                 * CONTENT-PREVIEW-FIT-001 — this tile is 64x40, a LANDSCAPE box.
                 *
                 * Everything in it was covered, so a 9:16 story was judged on its middle sixth and a
                 * square creative lost a third of its height. Computed once for the row so the still
                 * and the film cannot be fitted differently — which is how one creative came to be
                 * cropped in the grid and whole in the table.
                 */
                const rowFit = mediaFitClass(
                  creative.preview?.aspect ?? assetAspect(creative.width, creative.height, creative.aspect_ratio),
                  'horizontal',
                )

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
                    {anyPreview && <td className="p-2">
                      {poster ? (
                        <img
                          src={poster}
                          alt=""
                          loading={imageLoading(poster)}
                          decoding="async"
                          /* CONTENT-PREVIEW-FIT-001 — a 64x40 tile is landscape; only a landscape asset fills it. */
                          className={`h-10 w-16 rounded ${rowFit}`}
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
                          /* Same tile, same rule — the still and the film must not be fitted differently. */
                          className={`h-10 w-16 rounded ${rowFit}`}
                          onUnavailable={() => undefined}
                        />
                      ) : (
                        <span className="flex h-10 w-16 items-center justify-center rounded bg-surface-hover text-[10px] text-text-muted">
                          {t.noPreview}
                        </span>
                      )}
                    </td>}
                    <td className="p-2" onClick={(e) => e.stopPropagation()}>
                      <Link to={`${creative.id}${libraryAddress}`} className="text-start font-medium text-text-primary underline-offset-2 hover:underline">
                        {creative.name}
                      </Link>
                    </td>
                    <td className="p-2 text-text-secondary">{providerLabel(creative.provider, locale)}</td>
                    <td className="max-w-40 truncate p-2 text-text-secondary" data-testid={`content-row-account-${creative.id}`}>
                      {/*
                        An em dash, not «unknown account». A creative imported before its campaign is
                        linked genuinely has none yet, and naming that state would be inventing one.
                      */}
                      {creative.ad_account?.name ?? '—'}
                    </td>
                    <td className="max-w-48 truncate p-2 text-text-secondary">{creative.campaign_name ?? '—'}</td>
                    <td className="p-2 text-text-secondary">
                      {creative.objective ? objectiveLabel(creative.objective, locale) : marketingPathLabel(creative.path, locale)}
                    </td>
                    <td className="p-2">
                      {/*
                        The same badge the card carries, from the same `relevanceOf`.
                        
                        A second reading of `status` for the table is how one surface comes to call a
                        creative running while the other calls it stopped — about the same row, on the
                        same page, behind one toggle.
                      */}
                      <DeliveryBadge
                        state={relevanceOf(
                          { status: creative.status, last_active_on: creative.freshness.last_active_at },
                          to,
                        )}
                        ar={ar}
                      />
                    </td>
                    {/*
                      * TABLE-NUMERIC-ALIGNMENT-001 — `dir` belongs to the NUMERAL, not to the cell.
                      *
                      * On the `<td>` it also flipped the cell's own `start` edge. The header inherits
                      * the Arabic page and resolves `text-start` to the RIGHT; these figures, inside
                      * `dir="ltr"`, resolved the same `start` to the LEFT — «الإنفاق» at one edge of
                      * the column and its money at the other, which is the defect the owner has
                      * reported five times. The centre-to-centre sweep read zero throughout, because
                      * a `th` and its cells share one column BOX however the text inside them sits.
                      *
                      * The numerals still need LTR bidi, so it moves inwards to the text it is about.
                      */}
                    <td className="p-2 text-center tabular-nums">
                      {/*
                        * CONTENT-MONEY-VISIBLE-001 — through the canonical reader, not `metricState`.
                        *
                        * `metricState` sees only the CONVERTED column, so a withheld figure — which
                        * is every Snapchat row on production, a USD account with no USD→SAR rate —
                        * rendered as «No data». Real, measured spend reported as never having run.
                        */}
                      <span dir="ltr">{creativeMoney(creative.metrics, 'spend', data?.currency ?? null, locale).text}</span>
                    </td>
                    <td className="p-2 text-center">
                      {resultKey === null ? (
                        <span className="text-text-muted">—</span>
                      ) : (
                        <span className="tabular-nums" dir="ltr">
                          {/* Same reading as the card — a table row and a card must not disagree. */}
                          <MetricValue metrics={creative.metrics} metricKey={resultKey} currency={data?.currency ?? null} locale={locale} />
                          <span className="ms-1 text-[11px] text-text-muted">{metricLabel(resultKey, locale)}</span>
                        </span>
                      )}
                    </td>
                    <td className="p-2 text-center">
                      {efficiencyKey === null ? (
                        <span className="text-text-muted">—</span>
                      ) : (
                        <span className="tabular-nums" dir="ltr">
                          {/* Same reading as the card — a table row and a card must not disagree. */}
                          <MetricValue metrics={creative.metrics} metricKey={efficiencyKey} currency={data?.currency ?? null} locale={locale} />
                          <span className="ms-1 text-[11px] text-text-muted">{metricLabel(efficiencyKey, locale)}</span>
                        </span>
                      )}
                    </td>
                    <td className="p-2">
                      {/*
                        INSUFFICIENT-DATA-EXPLAINED-001 — the verdict carries the reason it was
                        reached, and «insufficient data» carries the reason it was NOT.

                        `CreativeFatigue` has always computed exactly what was missing — fewer than
                        seven active days, no previous window, too few impressions to read movement —
                        and returned it as `reason_ar`/`reason_en`. The library dropped it and printed
                        the bare chip, so the one status that exists to say «we cannot tell you yet»
                        did not say why, and reads as a data-quality problem the reader should go and
                        fix. It is usually just a creative that started on Thursday.
                      */}
                      <span
                        className={`rounded px-1.5 py-0.5 text-xs ${FATIGUE_TONE[creative.fatigue.status]}`}
                        title={(ar ? creative.fatigue.reason_ar : creative.fatigue.reason_en) || undefined}
                        data-reason={(ar ? creative.fatigue.reason_ar : creative.fatigue.reason_en) || undefined}
                      >
                        {FATIGUE_LABEL[creative.fatigue.status]?.[ar ? 'ar' : 'en'] ?? creative.fatigue.status}
                      </span>
                    </td>
                    <td className="p-2 text-xs text-text-secondary">
                      {/* The same flip as the figures above: `dir` belongs to the date, not the cell. */}
                      <span dir="ltr">{creative.freshness.last_synced_at?.slice(0, 10) ?? t.never}</span>
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

      {/*
        AD-PREVIEW-DEFAULT-001 — a thumbnail opens the quick popup, and the popup opens the page.

        «Cancel this page for viewing content and adopt the direct, simple popup — and from it go to
        the content's own analytics page.» The full-screen viewer carried a rail of figures that the
        creative's own page already draws better — identity, copy, figures, funnel, trend, by
        platform, peers, fatigue, evidence, insights against that rail's four blocks — so the reader
        met a shallower copy of the page and had no route from it to the real one.

        The popup answers «which ad is this, and did it work»; the page answers everything else, and
        `detailsTo` is the way there. Paging between creatives goes with the viewer: the grid behind
        the popup is the way to the next one, and it keeps the reader's filters and scroll position.
      */}
      {viewerIndex !== null && creatives[viewerIndex] && (
        <AdPreviewDialog
          creative={creatives[viewerIndex]}
          locale={ar ? 'ar' : 'en'}
          /* This library's own window and currency — the popup never decides what «this period» is. */
          figures={creativeDialogFigures(creatives[viewerIndex].metrics ?? undefined, data?.currency ?? null, ar, creatives[viewerIndex].headline_metrics ?? [], creatives[viewerIndex].results_not_attributable === true)}
          trend={currentProjectId
            ? (
              <CreativeTrend
                projectId={currentProjectId}
                creativeId={creatives[viewerIndex].id}
                window={{ from, to }}
                locale={ar ? 'ar' : 'en'}
                currency={data?.currency ?? 'SAR'}
                height={180}
              />
            )
            : undefined}
          /* The library's address travels with the link, so Back rebuilds the shelf as it was. */
          detailsTo={`${creatives[viewerIndex].id}${libraryAddress}`}
          onClose={() => setViewerIndex(null)}
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
  windowEnd,
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
  /**
   * The END of the window the page is reading — what «still running» is measured against.
   *
   * Not `today`: a reader looking at last quarter is asking whether these ran THEN, and judging a
   * September creative against today's date would mark the whole quarter stopped.
   */
  windowEnd: string
}) {
  const preview = creative.preview
  /*
   * OWNER CONTENT P0 — ONE preview decision, and the card no longer has its own.
   *
   * `thumbnail_url ?? image_url` read the envelope's columns directly, so the card could draw a frame
   * the canonical reader refuses — an expired link keeps its thumbnail by design — and could prefer a
   * different frame from the popup for the same collection. `readPreview` + `posterSource` is what
   * every other surface in the product already asks, including the popup this card opens.
   */
  const reading = readPreview(preview, ar)
  const poster = posterSource(reading)
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
  /*
   * AD-PREVIEW-001 — the still gets the same treatment the film already had.
   *
   * `brokenVideo` has existed for a while: a `<video>` that will not decode falls back to the card's
   * own sentence rather than keeping a black box. The `<img>` beside it had no equivalent, so a
   * refused or expired asset painted nothing at all and said nothing about it — which is the blank
   * card the owner reported, and the one state no server-side probe can see.
   *
   * When the poster fails the card asks the same question it asks when there was never a poster: is
   * there a film here instead? So a video creative whose cover has expired still shows the film.
   */
  /*
   * OWNER CONTENT P0 — the figures this creative can state, decided in ONE place for every surface.
   *
   * `canonicalFigureKeys` is what the popup's own list is built from, so the card cannot show fewer
   * figures than the panel it opens. Spend is not among them here: the fixed cell above already
   * carries it, through the money contract, which is the only reader that can state a withheld amount.
   */
  const [moreShown, setMoreShown] = useState(false)
  const figureKeys = canonicalFigureKeys(
    creative.headline_metrics ?? [],
    creative.metrics ?? undefined,
    currency,
    ar,
    /* CREATIVE-GRAIN-TRUTH-001 — without this the appending loop puts the withheld zeros back. */
    creative.results_not_attributable === true,
  )
    .filter((key) => key !== 'spend')
  const shownKeys = moreShown ? figureKeys : figureKeys.slice(0, CARD_FIGURES)
  const hiddenKeys = figureKeys.slice(CARD_FIGURES)
  const [brokenPoster, setBrokenPoster] = useState(false)
  useEffect(() => setBrokenPoster(false), [poster])
  const usablePoster = brokenPoster ? null : poster
  const video = usablePoster === null && !brokenVideo && reading.kind === 'video' ? reading.src : null
  const note = ar ? preview.note_ar : preview.note_en
  /*
   * What the panel says. Resolved once, because the note below is only worth printing when it is
   * NOT this — `absenceLabel` already prefers the server's note over its own wording.
   */
  const said = absenceLabel(readPreview(preview, ar), ar)

  return (
    /*
     * Addressable by the creative's own id.
     *
     * The grid card had no handle, so a browser test could only reach it through `.first()` — which
     * works exactly as long as the seed holds one row of the shape under test, and silently judges
     * the wrong card the moment it holds two. `content-grid-video.spec.ts` says so in its own
     * docblock, and the table view beside this one has carried `content-row-{id}` all along.
     */
    <article
      data-testid={`content-card-${creative.id}`}
      className={`flex h-full flex-col overflow-hidden rounded-lg border bg-surface ${selected ? 'border-primary' : 'border-border'}`}
    >
      <div className="relative">
        <button
          type="button"
          onClick={onOpen}
          /*
           * Named for what it IS, so a selector can ask for the poster rather than for «a button in a
           * card». The card grew a second control — the «+N» that reveals the rest of the figures —
           * and any index-based reading of `article button` silently re-pointed at it.
           */
          data-testid="creative-card-open"
          aria-label={`${t.open}: ${creative.name}`}
          /*
           * CONTENT-PREVIEW-SHAPES-001 — the frame is the shape the ad actually is.
           *
           * Every card was `aspect-video`, so a story or a reel — 9:16, which is most of what runs on
           * Snapchat and TikTok — was letterboxed into a third of its own frame, and a reader
           * comparing two ads was comparing two crops. The provider's dimensions have always been
           * synced; the preview payload carries them now. Where it says nothing the frame keeps the
           * shape it has always had, because guessing tall is a claim too.
           */
          /*
            CONTENT-COVER-FILL-001 — one frame for every cover, and the picture fills it.

            The frame took each asset's OWN aspect, so a 9:16 story produced a card twice the height
            of the 16:9 beside it and a mixed library became a wall of strips of different heights.
            The owner named exactly that: «the cover must be the full cover, not a tall shape … so it
            holds an image that fills the whole cover, not only a portrait strip.»

            Square, because the wall holds both: a 16:9 frame would take a story down to its middle
            third, and a 9:16 frame is the tall card being removed. A square crops both shapes by a
            similar amount and tiles evenly at every column count.

            The crop is real, and is why the whole asset stays one click away — the viewer contains,
            its own guard is untouched, and the owner has confirmed that surface reads correctly.
          */
          className={showPreviewPanel ? 'block w-full bg-surface-hover aspect-square' : 'block w-full bg-surface-hover'}
        >
          {usablePoster ? (
            <PosterImage
              src={usablePoster}
              alt={creative.name}
              // Off-screen cards cost nothing until they scroll into view — the difference between
              // a page and a download on a phone with twenty creatives on it. An INLINE asset is
              // exempt: see `imageLoading`, where lazy-loading a `data:` URI stopped it loading at all.
              loading={imageLoading(usablePoster)}
              /*
               * CONTENT-PREVIEW-SHAPES-001 — a story is contained, never covered.
               *
               * `object-cover` on a 9:16 asset in a 16:9 card keeps the middle third and throws away
               * the top and the bottom — on a story that is the logo and the call to action. The card
               * then shows a picture the ad never was, and two creatives compared side by side are
               * two crops this product invented.
               */
              shape={previewShape(creative.width, creative.height, creative.aspect_ratio) ?? undefined}
              /*
               * Nothing is drawn here on failure: the card re-renders with `usablePoster` null and
               * falls through to the film, or to the sentence, which is what it already does for a
               * creative that never had a still.
               */
              fallback={null}
              onFailed={() => setBrokenPoster(true)}
              /*
                CONTENT-PREVIEW-FIT-001 — one rule, read from the same source as the FRAME.
                The stage above takes `preview.aspect`; so does the fit, so the two cannot disagree
                about one creative. Where the platform stated no shape the frame is a guessed 16:9
                and the asset is contained rather than cropped into it.
              */
              className={`h-full w-full ${mediaFitClass(preview.aspect ?? assetAspect(creative.width, creative.height, creative.aspect_ratio), preview.aspect ?? null, 'cover')}`}
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
              /*
               * CONTENT-PREVIEW-SHAPES-001 applies to the FILM as well as the still.
               *
               * The image branch above has honoured «a story is contained, never covered» since the
               * rule was written; this one has always been `object-cover` with no shape in it. So a
               * 9:16 film whose frame does not match its declared shape — which is every film whose
               * platform returned a landscape cover, and every one in the demo — is cropped to the
               * middle third of the box, and on a story that is the logo and the call to action.
               * Measured on the library: a 480×270 frame drawn `cover` inside a 225×399 box.
               *
               * The same expression rather than a second rule, so the two branches cannot drift into
               * disagreeing about what a portrait creative is.
               */
              /* The same rule as the still above, from the same source — see CONTENT-PREVIEW-FIT-001. */
              className={`h-full w-full ${mediaFitClass(preview.aspect ?? assetAspect(creative.width, creative.height, creative.aspect_ratio), preview.aspect ?? null, 'cover')}`}
              onUnavailable={() => setBrokenVideo(true)}
            />
          ) : showPreviewPanel ? (
            /*
             * AD-PREVIEW-001 — the canonical sentence, not a generic one.
             *
             * This said «لا تتوفر معاينة» for every shape, and `absenceLabel` has a written sentence
             * for each of them: a film whose platform sent no cover, a collection with no hero, a
             * catalog ad that has no fixed asset by design. The grid is the surface the owner opens,
             * and it was the one surface not asking the module whose whole job is to answer this —
             * so a catalog ad, which is missing nothing, read as an ad with no preview.
             *
             * The platform's own note still wins where it sent one: it is more specific than
             * anything written here, and it is what the presenter composed for this exact moment.
             */
            <span
              data-testid="creative-absence-reason"
              data-absence={preview.state}
              className="flex h-full flex-col items-center justify-center gap-1 p-3 text-center text-xs text-text-secondary"
            >
              <span>{said || t.noPreview}</span>
              {/*
                The note, ONLY when it is not what the line above already said.

                `absenceLabel` prefers the server's note over its own wording — deliberately, it is
                the more specific truth — so once a note arrived, this second line printed the very
                same sentence directly beneath the first. The grid drew every derived row twice.
                This line was written when the line above was the generic «لا تتوفر معاينة», where a
                note genuinely added something; it still does, whenever the two differ.
              */}
              {note && note !== said && <span className="text-[11px] opacity-80">{note}</span>}
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
              /*
                The line has to clear the two controls floating over this corner.

                The compare checkbox is pinned to the START corner and the «فيديو» badge to the END
                one, and both are absolutely positioned over this strip: with plain padding the note
                rendered UNDER the checkbox, so an Arabic reader's card opened «…ج هذه المنصة أصل
                المحتوى» — a sentence with its first three words hidden, which reads as a rendering
                fault rather than as the reason it is.
              */
              <span
                data-testid="creative-absence-note"
                className="block py-2 pe-14 ps-12 text-start text-[11px] leading-snug text-text-secondary"
              >
                {note}
              </span>
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
              kindWord('video', ar)
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
          CONTENT-BROWSER-PARITY-001 — «is this still running on the platform?», asked for by name.
          
          The owner: «إذا كان هناك علامة لتوضيح هل المحتوى فعال أو واقف في المنصة جدا ممتاز». It is
          not decoration: the list now puts what is running first, and a reader who cannot see WHY a
          card is above another is back to an order they cannot account for.
          
          `relevanceOf` is the product's one answer to this question, already read by the campaigns
          workspace and the analytics rows — not a fourth reading of `status`. Three states, because
          there are three: serving, switched on and producing nothing, and stopped.
          
          Historical spend never implies «active»: the state is read from the status and the last
          ACTIVE date, never from whether figures exist.
        */}
        <DeliveryBadge
          state={relevanceOf(
            { status: creative.status, last_active_on: creative.freshness.last_active_at },
            windowEnd,
          )}
          ar={ar}
        />

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
        ) : (
          /*
             CONTENT-SPEND-ALWAYS-001 — Spend is an operational fact, not an objective's opinion.
             
             This grid used to be `headline_metrics.slice(0, 4)` and nothing else. `headline_metrics`
             is the server's answer to «what is this creative JUDGED on», which is an objective
             question — an awareness video is headlined on reach and view rate, a traffic ad on
             clicks and CPC — so Spend appeared for some objectives and simply vanished for others,
             on a card sitting beside a table that renders Spend explicitly. The money reader handled
             it correctly the whole time; nobody asked it.
             
             So Spend is FIXED and first, and the objective's own metrics follow it. The card stays
             compact: one fixed figure and three chosen ones, not seven.
             
             «It ran and we cannot headline it» keeps its sentence — CONTENT-KPI-EMPTY-STATE-001 —
             but it is no longer a reason to withhold the price. A creative the platform priced and
             could not headline is still a creative somebody spent money on.
          */
          <div data-testid="creative-card-metrics" className="flex flex-col gap-1">
            <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
              {/* Each cell carries its metric KEY, so «the card and the popup state the same figure»
                  is answerable without comparing words in two languages. */}
              <div data-metric="spend" className="flex flex-col">
                <dt className="text-text-secondary">{metricLabel('spend', locale)}</dt>
                <dd className="tabular-nums text-text-primary">
                  {/*
                    Through `creativeMoney` — the canonical reader the table, the popup, Content
                    Analytics and the report all use, so the six surfaces reconcile on this figure by
                    construction rather than by four of them agreeing.
                    
                    It already knows the four states this contract names: a converted figure in the
                    reporting currency; the ORIGINAL amount and currency when no rate exists (every
                    Snapchat creative on this account); a reported zero as zero; and an unreported
                    spend as «—» rather than as a zero somebody invented.
                  */}
                  <Num>{creativeMoney(creative.metrics, 'spend', currency, locale).text}</Num>
                </dd>
              </div>

              {shownKeys
                .map((key) => (
                  <div key={key} data-metric={key} className="flex flex-col">
                    <dt className="text-text-secondary">{metricLabel(key, locale)}</dt>
                    <dd className="tabular-nums text-text-primary">
                      <Num>{/*
                        * CONTENT-MONEY-VISIBLE-001 — money through the canonical reader, everything
                        * else through `metricState`. Counts and ratios keep that path, which is
                        * right for them: it already tells a measured zero from «not sent».
                        *
                        * Owner defect 95 — asked of ONE reader rather than by a ternary here. This
                        * named `revenue` and not `spend`, which was safe only because the fixed cell
                        * above already carries spend; a key list that changes with the objective is
                        * the wrong thing to guard by remembering.
                        */}
                      {/*
                        CONTENT-RESULT-AVAILABILITY-001 §4 — «0» and «—» are different claims.

                        A result the provider cannot measure here renders as the dash with its reason
                        one hover away, rather than as a measured zero or as four stacked «غير مُرسَل»
                        that read like a list of faults. Everything answerable renders exactly as
                        before, through the same reader.
                      */}
                      <MetricValue metrics={creative.metrics} metricKey={key} currency={currency} locale={locale} /></Num>
                    </dd>
                  </div>
                ))}
            </dl>

            {/*
                Owner defect 95 — the gap beside the price is EXPLAINED, or the card reads as broken.

                This was gated on `headline_metrics.length === 0`, and that length is 1 in the one
                state the sentence was written for: `supportable()` falls back to `['spend']` when
                nothing else about the creative can be headlined — its stated last resort, «the one
                question asked of every campaign» — and the grid above filters `spend` out because
                the fixed cell already carries it. So the reader got a price, three empty columns and
                no reason, which is the owner's «Spend appears and the other KPIs disappear» rendered
                literally. Measured after the list the grid actually draws, which is the only number
                that can answer «is there anything beside the price».
            */}
            {/*
                OWNER CONTENT P0 — folded, never dropped.

                The card used to draw three figures and stop, so a creative whose objective filled
                those three lost every universal figure the platform reported for it — while the popup
                one click away showed them. The whole set is here; the card opens with the objective's
                own verdict and reveals the rest in place, which is a LAYOUT decision and can be, so
                long as nothing is lost by making it.
            */}
            {hiddenKeys.length > 0 && (
              <button
                type="button"
                data-testid="creative-card-more-metrics"
                aria-expanded={moreShown}
                onClick={(e) => {
                  e.stopPropagation()
                  setMoreShown((v) => !v)
                }}
                className="self-start rounded px-1 text-[11px] text-text-secondary underline-offset-2 hover:underline"
              >
                {moreShown ? t.fewerMetrics : `+${hiddenKeys.length}`}
              </button>
            )}

            {figureKeys.length === 0 && (
              <EmptyReasonPanel reason={noDisplayableMetrics(locale)} />
            )}

            {/*
              CREATIVE-GRAIN-TRUTH-001 — «the platform did not break this result down», said once.

              The campaign above this creative reported sales and no creative under it reported a
              single one, so the platform never attributed the result at this grain. The figures are
              dropped server-side rather than printed as zeros — a zero here is what put «الطلبات 0»
              under a campaign reading 5x — and this is the sentence that stops their absence being
              read as «nobody bought».

              Stated once per card, under the figures, rather than on each missing metric: the
              reader needs the reason, not four copies of it.
            */}
            {creative.results_not_attributable === true && (
              <p data-testid="creative-results-not-attributable" className="mt-2 text-[11px] text-text-muted">
                {ar
                  ? 'لم تُسنِد المنصة نتائج هذه الحملة إلى المحتوى — النتائج متاحة على مستوى الحملة فقط.'
                  : 'The platform did not attribute this campaign’s results to content — they are reported at campaign level only.'}
              </p>
            )}
          </div>
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
          : 'bg-surface-secondary text-text-secondary'
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

/**
 * The sort metric's name, for the sentence that accounts for the automatic order.
 *
 * Local and small on purpose: these are the few keys {@see CreativeRows::SORTABLE} can order by, and
 * the metric catalogue's own labels are written for a figure in a card rather than for the middle of
 * a sentence. A key this list has not met prints as itself, which is visible and fixable, rather than
 * as «—», which is not.
 */
function metricName(metric: string, ar: boolean): string {
  const names: Record<string, { ar: string; en: string }> = {
    spend: { ar: 'الإنفاق', en: 'spend' },
    conversions: { ar: 'الطلبات', en: 'orders' },
    impressions: { ar: 'الظهور', en: 'impressions' },
    clicks: { ar: 'النقرات', en: 'clicks' },
    engagements: { ar: 'التفاعلات', en: 'engagements' },
    engagement_rate: { ar: 'معدل التفاعل', en: 'engagement rate' },
    video_views: { ar: 'المشاهدات', en: 'views' },
    reach: { ar: 'الوصول', en: 'reach' },
    revenue: { ar: 'الإيراد', en: 'revenue' },
  }

  return names[metric] ? (ar ? names[metric]!.ar : names[metric]!.en) : metric
}
