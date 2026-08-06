import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { GitCompare, LayoutGrid, Rows3, Search } from 'lucide-react'
import { CreativeViewer } from './CreativeViewer'
import { CreativeCompare } from './CreativeCompare'
import { formatMetric, metricLabel, metricState } from './metrics'
import { imageLoading } from './format'
import { libraryQueryString, listCreatives, type CreativeCard, type FatigueStatus, type LibraryQuery } from './api'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { ErrorState, Skeleton } from '@/components/ui/States'
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
    clearSelection: 'إلغاء التحديد',
    empty: 'لا توجد محتويات تطابق هذا التحديد.',
    emptyAll: 'لا توجد محتويات بعد — تظهر هنا بعد مزامنة الحملات.',
    error: 'تعذّر تحميل المكتبة.',
    demo: 'وضع تجريبي',
    grouped: 'مجمَّع عبر المنصات',
    noPreview: 'لا تتوفر معاينة',
    lastSync: 'آخر مزامنة',
    never: 'لم تتم بعد',
    showing: 'المعروض',
    of: 'من',
    prev: 'السابق',
    next: 'التالي',
    open: 'فتح المعاينة',
    details: 'تفاصيل المحتوى',
    source: 'المصدر: منصة الإعلان',
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
    clearSelection: 'Clear selection',
    empty: 'No creatives match this selection.',
    emptyAll: 'No creatives yet — they appear here after campaigns sync.',
    error: 'Could not load the library.',
    demo: 'Demo',
    grouped: 'Grouped across platforms',
    noPreview: 'No preview available',
    lastSync: 'Last sync',
    never: 'Not yet',
    showing: 'Showing',
    of: 'of',
    prev: 'Previous',
    next: 'Next',
    open: 'Open preview',
    details: 'Creative details',
    source: 'Source: ad platform',
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

/** A multi-select rendered as a plain `<select multiple>` — keyboard-navigable and screen-readable. */
function AxisSelect({
  label,
  value,
  options,
  onChange,
}: {
  label: string
  value: string[]
  options: Array<{ value: string; label: string }>
  onChange: (next: string[]) => void
}) {
  if (options.length === 0) return null

  return (
    <label className="flex min-w-40 flex-col gap-1 text-xs">
      <span className="font-medium text-text-secondary">{label}</span>
      <select
        multiple
        aria-label={label}
        value={value}
        onChange={(e) => onChange(Array.from(e.target.selectedOptions, (o) => o.value))}
        className="h-20 rounded-md border border-border bg-surface px-2 py-1 text-xs text-text-primary"
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  )
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

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-text-primary">{t.title}</h1>
          <p className="mt-1 text-sm text-text-secondary">{t.subtitle}</p>
        </div>

        <div className="flex items-center gap-2">
          <div className="flex rounded-md border border-border p-0.5" role="group" aria-label={t.grid}>
            <button
              type="button"
              aria-pressed={view === 'grid'}
              onClick={() => setView('grid')}
              className={`flex items-center gap-1 rounded px-2 py-1 text-xs ${view === 'grid' ? 'bg-surface-hover text-text-primary' : 'text-text-secondary'}`}
            >
              <LayoutGrid className="h-3.5 w-3.5" aria-hidden /> {t.grid}
            </button>
            <button
              type="button"
              aria-pressed={view === 'list'}
              onClick={() => setView('list')}
              className={`flex items-center gap-1 rounded px-2 py-1 text-xs ${view === 'list' ? 'bg-surface-hover text-text-primary' : 'text-text-secondary'}`}
            >
              <Rows3 className="h-3.5 w-3.5" aria-hidden /> {t.list}
            </button>
          </div>

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
        </div>
      </header>

      <section className="space-y-3 rounded-lg border border-border bg-surface p-3">
        <div className="flex flex-wrap items-end gap-3">
          <label className="flex min-w-52 flex-1 flex-col gap-1 text-xs">
            <span className="font-medium text-text-secondary">{t.search}</span>
            <span className="relative">
              <Search className="absolute top-2.5 h-4 w-4 text-text-secondary ltr:left-2 rtl:right-2" aria-hidden />
              <input
                type="search"
                value={search}
                aria-label={t.search}
                onChange={(e) => {
                  setSearch(e.target.value)
                  setPage(1)
                }}
                className="w-full rounded-md border border-border bg-surface py-1.5 text-sm text-text-primary ltr:pl-8 ltr:pr-2 rtl:pr-8 rtl:pl-2"
              />
            </span>
          </label>

          {/* `DateField`, never a native date input: the browser's own control renders in the OS
              locale, so a Saudi machine shows a Hijri calendar for an ISO value the API expects. */}
          <label className="flex flex-col gap-1 text-xs">
            <span className="font-medium text-text-secondary">{t.from}</span>
            <DateField aria-label={t.from} value={from} onChange={(v) => { setFrom(v); setPage(1) }} />
          </label>
          <label className="flex flex-col gap-1 text-xs">
            <span className="font-medium text-text-secondary">{t.to}</span>
            <DateField aria-label={t.to} value={to} onChange={(v) => { setTo(v); setPage(1) }} />
          </label>

          <label className="flex flex-col gap-1 text-xs">
            <span className="font-medium text-text-secondary">{t.sort}</span>
            <select
              value={sort}
              aria-label={t.sort}
              onChange={(e) => { setSort(e.target.value); setPage(1) }}
              className="rounded-md border border-border bg-surface px-2 py-1.5 text-sm text-text-primary"
            >
              <option value="recent">{t.sortRecent}</option>
              <option value="spend">{t.sortSpend}</option>
              <option value="impressions">{t.sortImpressions}</option>
              <option value="conversions">{t.sortConversions}</option>
              <option value="name">{t.sortName}</option>
            </select>
          </label>

          <label className="flex flex-col gap-1 text-xs">
            <span className="font-medium text-text-secondary">{t.health}</span>
            <select
              value={health}
              aria-label={t.health}
              onChange={(e) => { setHealth(e.target.value); setPage(1) }}
              className="rounded-md border border-border bg-surface px-2 py-1.5 text-sm text-text-primary"
            >
              <option value="">{t.all}</option>
              {(options?.health ?? []).map((status) => (
                <option key={status} value={status}>
                  {FATIGUE_LABEL[status] ? FATIGUE_LABEL[status][ar ? 'ar' : 'en'] : status}
                </option>
              ))}
            </select>
          </label>
        </div>

        {options && (
          <div className="flex flex-wrap gap-3">
            <AxisSelect
              label={t.client}
              value={axes.client_ids ?? []}
              options={options.clients.map((c) => ({ value: c.id, label: c.name }))}
              onChange={(v) => setAxis('client_ids', v)}
            />
            <AxisSelect
              label={t.project}
              value={axes.project_ids ?? []}
              options={options.projects.map((p) => ({ value: p.id, label: p.name }))}
              onChange={(v) => setAxis('project_ids', v)}
            />
            <AxisSelect
              label={t.platform}
              value={axes.providers ?? []}
              options={options.providers.map((p) => ({ value: p, label: providerLabel(p, locale) }))}
              onChange={(v) => setAxis('providers', v)}
            />
            <AxisSelect
              label={t.campaign}
              value={axes.campaign_ids ?? []}
              options={options.campaigns.map((c) => ({ value: c.id, label: c.name }))}
              onChange={(v) => setAxis('campaign_ids', v)}
            />
            <AxisSelect
              label={t.adSet}
              value={axes.ad_set_ids ?? []}
              options={options.ad_sets.map((id) => ({ value: id, label: id }))}
              onChange={(v) => setAxis('ad_set_ids', v)}
            />
            <AxisSelect
              label={t.ad}
              value={axes.ad_ids ?? []}
              options={options.ads.map((id) => ({ value: id, label: id }))}
              onChange={(v) => setAxis('ad_ids', v)}
            />
            <AxisSelect
              label={t.objective}
              value={axes.objectives ?? []}
              options={options.objectives.map((o) => ({ value: o, label: objectiveLabel(o, locale) }))}
              onChange={(v) => setAxis('objectives', v)}
            />
            <AxisSelect
              label={t.path}
              value={axes.paths ?? []}
              options={options.paths.map((p) => ({ value: p, label: marketingPathLabel(p, locale) }))}
              onChange={(v) => setAxis('paths', v)}
            />
            <AxisSelect
              label={t.kind}
              value={axes.kinds ?? []}
              options={options.kinds.map((k) => ({
                value: k,
                label: KIND_LABEL[k] ? KIND_LABEL[k][ar ? 'ar' : 'en'] : k,
              }))}
              onChange={(v) => setAxis('kinds', v)}
            />
            <AxisSelect
              label={t.status}
              value={axes.statuses ?? []}
              options={options.statuses.map((s) => ({ value: s, label: campaignStatusLabel(s, locale) }))}
              onChange={(v) => setAxis('statuses', v)}
            />
          </div>
        )}

        {selected.length > 0 && (
          <div className="flex items-center gap-2 text-xs text-text-secondary">
            <span dir="ltr">{selected.length}</span>
            <span>{t.selected}</span>
            <button type="button" onClick={() => setSelected([])} className="underline">
              {t.clearSelection}
            </button>
          </div>
        )}
      </section>

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

      {creatives.length > 0 && view === 'grid' && (
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {creatives.map((creative, index) => (
            <li key={creative.id}>
              <CreativeGridCard
                creative={creative}
                t={t}
                ar={ar}
                locale={locale}
                selected={selected.includes(creative.id)}
                onSelect={() => toggleSelected(creative.id)}
                onOpen={() => setViewerIndex(index)}
                detailsTo={`${creative.id}${libraryAddress}`}
              />
            </li>
          ))}
        </ul>
      )}

      {creatives.length > 0 && view === 'list' && (
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full min-w-[52rem] text-sm">
            <thead className="bg-surface-hover text-start text-xs text-text-secondary">
              <tr>
                <th className="p-2" />
                <th className="p-2 text-start">{t.title}</th>
                <th className="p-2 text-start">{t.platform}</th>
                <th className="p-2 text-start">{t.campaign}</th>
                <th className="p-2 text-start">{t.objective}</th>
                <th className="p-2 text-start">{metricLabel('spend', locale)}</th>
                <th className="p-2 text-start">{metricLabel('impressions', locale)}</th>
                <th className="p-2 text-start">{t.health}</th>
              </tr>
            </thead>
            <tbody>
              {creatives.map((creative, index) => (
                <tr key={creative.id} className="border-t border-border">
                  <td className="p-2">
                    <input
                      type="checkbox"
                      aria-label={`${t.compare}: ${creative.name}`}
                      checked={selected.includes(creative.id)}
                      onChange={() => toggleSelected(creative.id)}
                    />
                  </td>
                  <td className="p-2">
                    {/* The NAME opens the page; the preview button opens the viewer. «What does it
                        look like» and «should we keep running it» are two questions, and one click
                        cannot be both answers. */}
                    <div className="flex items-center gap-2">
                      <Link to={`${creative.id}${libraryAddress}`} className="text-start font-medium text-text-primary underline-offset-2 hover:underline">
                        {creative.name}
                      </Link>
                      <button
                        type="button"
                        onClick={() => setViewerIndex(index)}
                        aria-label={`${t.open}: ${creative.name}`}
                        className="shrink-0 rounded border border-border px-1.5 py-0.5 text-[11px] text-text-secondary hover:bg-surface-hover"
                      >
                        {t.open}
                      </button>
                    </div>
                  </td>
                  <td className="p-2 text-text-secondary">{providerLabel(creative.provider, locale)}</td>
                  <td className="p-2 text-text-secondary">{creative.campaign_name ?? '—'}</td>
                  <td className="p-2 text-text-secondary">
                    {creative.objective ? objectiveLabel(creative.objective, locale) : '—'}
                  </td>
                  <td className="p-2 tabular-nums" dir="ltr">
                    {formatMetric(metricState(creative.metrics, 'spend'), 'spend', locale)}
                  </td>
                  <td className="p-2 tabular-nums" dir="ltr">
                    {formatMetric(metricState(creative.metrics, 'impressions'), 'impressions', locale)}
                  </td>
                  <td className="p-2">
                    <span className={`rounded px-1.5 py-0.5 text-xs ${FATIGUE_TONE[creative.fatigue.status]}`}>
                      {FATIGUE_LABEL[creative.fatigue.status]?.[ar ? 'ar' : 'en'] ?? creative.fatigue.status}
                    </span>
                  </td>
                </tr>
              ))}
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
  t,
  ar,
  locale,
  selected,
  onSelect,
  onOpen,
  detailsTo,
}: {
  creative: CreativeCard
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
  const note = ar ? preview.note_ar : preview.note_en

  return (
    <article className={`flex h-full flex-col overflow-hidden rounded-lg border bg-surface ${selected ? 'border-primary' : 'border-border'}`}>
      <div className="relative">
        <button
          type="button"
          onClick={onOpen}
          aria-label={`${t.open}: ${creative.name}`}
          className="block aspect-video w-full bg-surface-hover"
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
          ) : (
            <span className="flex h-full flex-col items-center justify-center gap-1 p-3 text-center text-xs text-text-secondary">
              <span>{t.noPreview}</span>
              {note && <span className="text-[11px] opacity-80">{note}</span>}
            </span>
          )}
        </button>

        <label className="absolute top-2 flex items-center gap-1 rounded bg-surface/90 px-1.5 py-1 text-xs ltr:left-2 rtl:right-2">
          <input
            type="checkbox"
            checked={selected}
            onChange={onSelect}
            aria-label={`${t.compare}: ${creative.name}`}
          />
        </label>

        {creative.preview.kind === 'video' && (
          <span className="absolute bottom-2 rounded bg-black/70 px-1.5 py-0.5 text-[11px] text-white ltr:right-2 rtl:left-2" dir="ltr">
            {creative.duration_seconds === null ? 'video' : `${creative.duration_seconds}s`}
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

        {/* The creative's OWN headline metrics — chosen by its objective, so an awareness video is
            never asked for a cost per order it was not bought to produce. */}
        <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
          {creative.headline_metrics.slice(0, 4).map((key) => (
            <div key={key} className="flex flex-col">
              <dt className="text-text-secondary">{metricLabel(key, locale)}</dt>
              <dd className="tabular-nums text-text-primary" dir="ltr">
                {formatMetric(metricState(creative.metrics, key), key, locale)}
              </dd>
            </div>
          ))}
        </dl>

        <div className="mt-auto flex flex-wrap items-center gap-2 pt-2">
          <span className={`rounded px-1.5 py-0.5 text-[11px] ${FATIGUE_TONE[creative.fatigue.status]}`}>
            {FATIGUE_LABEL[creative.fatigue.status]?.[ar ? 'ar' : 'en'] ?? creative.fatigue.status}
          </span>
          {creative.grouped && (
            <span className="rounded bg-surface-hover px-1.5 py-0.5 text-[11px] text-text-secondary">{t.grouped}</span>
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
