import { useMemo, useState } from 'react'
import { canonicalPlatform } from '@/lib/platforms'
import { fmtDate, fmtDateTime } from '@/lib/datetime'
import { useSearchParams } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { AlertTriangle, Image as ImageIcon, LayoutGrid, Lightbulb, Rows3, TrendingDown, TrendingUp, Video } from 'lucide-react'
import {
  compareSharedCreatives,
  getSharedCreative,
  getSharedCreativeSummary,
  getSharedCreatives,
  type CreativeInsightItem,
  type CreativePermissions,
  type SharedCreativeMove,
  type SharedCreativeQuery,
  type SharedKindWinner,
  type SharedObjectiveWinner,
} from './sharedCreatives'
import { CreativeViewer } from '@/features/content/CreativeViewer'
import { CreativeVideoPlayer } from '@/features/content/CreativeVideoPlayer'
import { CreativeCarousel } from '@/features/content/CreativeCarousel'
import { imageLoading } from '@/features/content/format'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { formatMetric, metricLabel, metricState } from '@/features/content/metrics'
import { formatMoneyReading, readMoney } from '@/lib/money/contract'
import { marketingPathLabel, objectiveLabel, providerLabel } from '@/features/campaigns/labels'
import { DateField } from '@/components/ui/DateField'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import type { CreativeCard } from '@/features/content/api'
import type { CreativePulse, FatigueAlert, PulseList } from '@/features/content/pulse'

/** A winner card takes either shape; only `objective` distinguishes them, and only one of them has it. */
type SharedWinner = SharedObjectiveWinner | SharedKindWinner

/**
 * §15.12 — the content sections of a client's report, for a reader with no account.
 *
 * ## Two sections, and which one appears is the report's FORM
 *
 * The executive summary gets the answers: best by objective, best image, best video, what is growing,
 * what is declining, what is fatigued, what cannot yet be judged, and the findings behind them. The
 * detailed report gets those AND the library — every creative, filterable, openable, comparable.
 * That is the difference the two forms are supposed to have, expressed as different content rather
 * than as the same page with a shorter scrollbar.
 *
 * ## Every permission is read from the server, never assumed
 *
 * The payload states what this link may show, and the component renders that. It does not decide.
 * A control drawn from a client-side guess would eventually be drawn for something the server then
 * refuses, and the reader would meet a button that fails — which is the shape of «dead control» the
 * contract forbids, arriving through the back door of an optimistic UI.
 *
 * ## Nothing is a zero
 *
 * `metricState` distinguishes «the platform did not report this» from «this is zero» and the cards
 * print «غير مُرسَل» / «Not provided» accordingly. A withheld figure is not in the payload at all, so
 * it renders as «لا توجد بيانات» rather than as a labelled blank that names what is being kept back.
 */

const COPY = {
  ar: {
    heading: 'تحليل الإعلان',
    library: 'مكتبة الإعلانات',
    grid: 'شبكة',
    list: 'قائمة',
    bestByObjective: 'أفضل إعلان لكل هدف',
    bestImage: 'أفضل صورة',
    bestVideo: 'أفضل فيديو',
    growing: 'الأسرع تحسنًا',
    declining: 'المتراجع',
    fatigued: 'إعلان مُجهَد',
    watch: 'يحتاج مراقبة',
    insufficient: 'بيانات غير كافية',
    alerts: 'تنبيهات إجهاد الإعلان',
    insights: 'أبرز التحليلات',
    recommendation: 'الإجراء المقترح',
    freshness: 'آخر مزامنة',
    quality: 'جودة البيانات',
    noSync: 'لم تتم مزامنة بعد',
    period: 'الفترة',
    previous: 'الفترة السابقة',
    objective: 'الهدف',
    path: 'المسار',
    platform: 'المنصة',
    campaign: 'الحملة',
    metric: 'المؤشر المستخدم',
    value: 'القيمة',
    change: 'التغيّر عن الفترة السابقة',
    hidden: 'غير معروض في هذا الرابط',
    thin: 'أدلة محدودة — الترتيب مبدئي',
    tie: 'لا فارق',
    empty: 'لا يوجد إعلان ضمن هذا التحديد.',
    error: 'تعذّر تحميل قسم الإعلان.',
    compare: 'قارن المحدد',
    clear: 'إلغاء التحديد',
    selected: 'محدد',
    notComparable: 'لا يمكن إعلان فائز عام بين مسارين مختلفين.',
    from: 'من',
    to: 'إلى',
    all: 'الكل',
    of: 'من',
    attribution: 'المصدر: ما أبلغت عنه المنصة الإعلانية',
    confidence: { high: 'ثقة عالية', medium: 'ثقة متوسطة', insufficient_data: 'بيانات غير كافية' },
    severity: { warning: 'يحتاج انتباهًا', opportunity: 'فرصة', positive: 'تحسّن' },
    evidence: 'الأدلة',
    showing: 'المعروض',
    details: 'تفاصيل الإعلان',
    backToLibrary: 'العودة إلى مكتبة الإعلانات',
    funnel: 'الفانل',
    funnelNone: 'لم ترسل المنصة أي مرحلة يمكن بناء فانل منها.',
    funnelMissing: 'مراحل لا ترسلها هذه المنصة',
    stage: 'المرحلة',
    count: 'العدد',
    rate: 'التحول من السابقة',
    costPer: 'التكلفة لكل',
    notShown: 'غير معروضة في هذا الرابط',
    byPlatform: 'الأداء حسب المنصة',
    onePlatform: 'هذا الإعلان يعمل على منصة واحدة، فلا توجد مقارنة بين المنصات.',
    lastSync: 'آخر مزامنة',
    sourceUpdated: 'آخر تحديث من المصدر',
    previousPeriod: 'الفترة السابقة',
    copy: 'نص الإعلان',
    headlineText: 'العنوان',
    body: 'النص',
    cta: 'زر الإجراء',
    destination: 'الرابط الوجهة',
    detailError: 'هذا الإعلان غير متاح في هذا الرابط.',
    notProvided: 'غير مُرسَل',
  },
  en: {
    heading: 'Ad analysis',
    library: 'Ad library',
    grid: 'Grid',
    list: 'List',
    bestByObjective: 'Best ad per objective',
    bestImage: 'Best image',
    bestVideo: 'Best video',
    growing: 'Fastest improving',
    declining: 'Declining',
    fatigued: 'Fatigued',
    watch: 'Needs watching',
    insufficient: 'Insufficient data',
    alerts: 'Ad fatigue alerts',
    insights: 'Key insights',
    recommendation: 'Recommended action',
    freshness: 'Last sync',
    quality: 'Data quality',
    noSync: 'Awaiting sync',
    period: 'Period',
    previous: 'Previous period',
    objective: 'Objective',
    path: 'Marketing path',
    platform: 'Platform',
    campaign: 'Campaign',
    metric: 'Metric used',
    value: 'Value',
    change: 'Change vs previous period',
    hidden: 'Not shown on this link',
    thin: 'Thin evidence — provisional ranking',
    tie: 'No difference',
    empty: 'No ads in this selection.',
    error: 'Could not load the ad section.',
    compare: 'Compare selected',
    clear: 'Clear selection',
    selected: 'selected',
    notComparable: 'No overall winner can be declared across two different marketing paths.',
    from: 'From',
    to: 'To',
    all: 'All',
    of: 'of',
    attribution: 'Source: as the ad platform reported it',
    confidence: { high: 'High confidence', medium: 'Medium confidence', insufficient_data: 'Insufficient data' },
    severity: { warning: 'Needs attention', opportunity: 'Opportunity', positive: 'Improved' },
    evidence: 'Evidence',
    showing: 'Showing',
    details: 'Ad details',
    backToLibrary: 'Back to the ad library',
    funnel: 'Funnel',
    funnelNone: 'The platform reported no stage a funnel could be built from.',
    funnelMissing: 'Stages this platform does not report',
    stage: 'Stage',
    count: 'Count',
    rate: 'From previous stage',
    costPer: 'Cost per',
    notShown: 'Not shown on this link',
    byPlatform: 'By platform',
    onePlatform: 'This ad runs on one platform, so there is no cross-platform comparison.',
    lastSync: 'Last sync',
    sourceUpdated: 'Source updated at',
    previousPeriod: 'Previous period',
    copy: 'Ad copy',
    headlineText: 'Headline',
    body: 'Body',
    cta: 'Call to action',
    destination: 'Destination URL',
    detailError: 'This ad is not available on this link.',
    notProvided: 'Not provided',
  },
}

const pct = (value: number | null | undefined) =>
  value === null || value === undefined ? '—' : `${(value * 100).toFixed(1)}%`

export function SharedCreativeSection({
  token,
  password,
  currency,
  form,
}: {
  token: string
  password?: string
  currency: string
  form: 'executive_summary' | 'detailed'
}) {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']

  /*
   * One filter state, shared by both sections.
   *
   * The summary and the library answer the same question at two depths, so a reader who narrows to
   * TikTok in one and finds the other still showing everything has been shown two accounts. Held
   * here rather than in each child for that reason alone.
   */
  const [filters, setFilters] = useState<SharedCreativeQuery>({})
  const [view, setView] = useState<'grid' | 'list'>('grid')

  /*
   * §15.6 — the open creative lives in the ADDRESS, so the client's detail view survives a refresh.
   *
   * A query parameter rather than a nested route on purpose: a password-gated link holds the
   * accepted password in this tree's state, and a route change that remounted the gate would ask the
   * reader for it again every time they opened a creative. `?creative=<id>` is refresh-safe,
   * deep-linkable, and Back returns to the library with the filters still in place — which is what
   * the requirement is actually asking for.
   */
  const [params, setParams] = useSearchParams()
  const openId = params.get('creative')

  const openDetail = (id: string | null) => {
    const next = new URLSearchParams(params)
    if (id === null) next.delete('creative')
    else next.set('creative', id)
    setParams(next, { replace: false })
  }
  const [viewerIndex, setViewerIndex] = useState<number | null>(null)
  const [selected, setSelected] = useState<string[]>([])
  const [comparing, setComparing] = useState(false)

  const summary = useQuery({
    queryKey: ['shared-creative-summary', token, filters],
    queryFn: () => getSharedCreativeSummary(token, filters, password),
    placeholderData: keepPreviousData,
  })

  const library = useQuery({
    queryKey: ['shared-creatives', token, filters],
    queryFn: () => getSharedCreatives(token, { ...filters, per_page: 24 }, password),
    placeholderData: keepPreviousData,
    // The summary is the whole ceiling read as answers; the library is only asked for by the
    // detailed report, where the reader is actually going to page through cards.
    enabled: form === 'detailed',
  })

  const comparison = useQuery({
    queryKey: ['shared-creative-comparison', token, selected, filters],
    queryFn: () => compareSharedCreatives(token, selected, filters, password),
    enabled: comparing && selected.length >= 2,
  })

  const permissions: CreativePermissions | undefined = summary.data?.permissions ?? library.data?.permissions
  const rows = useMemo(() => library.data?.creatives ?? [], [library.data])
  const available = summary.data?.available ?? library.data?.available

  if (summary.isLoading) return <Skeleton className="mt-6 h-64 w-full" />
  if (summary.isError) return <ErrorState title={t.error} error={summary.error} />
  if (!summary.data || !permissions?.creatives) return null

  const data = summary.data
  const narrow = (patch: SharedCreativeQuery) => setFilters((f) => ({ ...f, ...patch }))

  return (
    <section className="mt-8 grid gap-4" data-testid="shared-creative-section" aria-label={t.heading}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-heading text-lg font-extrabold tracking-tight">{t.heading}</h2>
        <p className="tnum text-xs text-text-secondary" dir="ltr">
          {data.period.from} → {data.period.to}
        </p>
      </div>

      {/* The reader's own filters, offering only what this link can honour. */}
      {available && (
        <div className="flex flex-wrap items-end gap-2 rounded-xl border border-border bg-surface-secondary p-3">
          <label className="grid gap-1 text-xs">
            <span className="font-semibold text-text-muted">{t.from}</span>
            <DateField value={filters.from ?? available.earliest} onChange={(v) => narrow({ from: v })} />
          </label>
          <label className="grid gap-1 text-xs">
            <span className="font-semibold text-text-muted">{t.to}</span>
            <DateField value={filters.to ?? available.latest} onChange={(v) => narrow({ to: v })} />
          </label>

          <Picker
            label={t.platform}
            value={filters.providers?.[0] ?? ''}
            onChange={(v) => narrow({ providers: v ? [v] : [] })}
            options={available.providers.map((p) => ({ value: p, label: providerLabel(p, locale) }))}
            all={t.all}
          />
          <Picker
            label={t.campaign}
            value={filters.campaign_ids?.[0] ?? ''}
            onChange={(v) => narrow({ campaign_ids: v ? [v] : [] })}
            options={available.campaigns.map((c) => ({ value: c.id, label: c.name }))}
            all={t.all}
          />
          <Picker
            label={t.objective}
            value={filters.objectives?.[0] ?? ''}
            onChange={(v) => narrow({ objectives: v ? [v] : [] })}
            options={available.objectives.map((o) => ({ value: o, label: o }))}
            all={t.all}
          />
          <Picker
            label={t.path}
            value={filters.paths?.[0] ?? ''}
            onChange={(v) => narrow({ paths: v ? [v] : [] })}
            options={available.paths.map((p) => ({ value: p, label: p }))}
            all={t.all}
          />
        </div>
      )}

      {data.totals.creatives === 0 ? (
        <p className="rounded-xl border border-border bg-surface p-6 text-center text-sm text-text-secondary">{t.empty}</p>
      ) : (
        <>
          <div className="grid gap-3 md:grid-cols-2">
            <WinnerGroup title={t.bestByObjective} entries={data.best_by_objective} t={t} currency={currency} permissions={permissions} />
            <div className="grid gap-3">
              <WinnerGroup title={t.bestImage} entries={data.best_image} t={t} currency={currency} permissions={permissions} icon={<ImageIcon size={14} />} />
              <WinnerGroup title={t.bestVideo} entries={data.best_video} t={t} currency={currency} permissions={permissions} icon={<Video size={14} />} />
            </div>
          </div>

          <div className="grid gap-3 md:grid-cols-2">
            <MoveList title={t.growing} icon={<TrendingUp size={14} className="text-success" />} list={data.fastest_growing} t={t} />
            <MoveList title={t.declining} icon={<TrendingDown size={14} className="text-danger" />} list={data.declining} t={t} />
          </div>

          <FatigueBlock data={data} t={t} currency={currency} permissions={permissions} />

          {permissions.insights && data.insights && data.insights.items.length > 0 && (
            <InsightList insights={data.insights} t={t} ar={ar} permissions={permissions} />
          )}

          <Freshness data={data} t={t} ar={ar} />
        </>
      )}

      {form === 'detailed' && (
        <div className="grid gap-3" data-testid="shared-creative-library">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-sm font-bold">{t.library}</h3>
            <div className="flex items-center gap-2">
              {permissions.comparison && selected.length >= 2 && (
                <>
                  <button
                    type="button"
                    onClick={() => setComparing(true)}
                    className="rounded-lg border border-brand-500 bg-[var(--brand-background)] px-2 py-1 text-xs font-semibold text-brand-700"
                  >
                    {t.compare} ({selected.length})
                  </button>
                  <button type="button" onClick={() => { setSelected([]); setComparing(false) }} className="text-xs text-text-secondary hover:underline">
                    {t.clear}
                  </button>
                </>
              )}
              <div className="flex rounded-lg border border-border">
                <button type="button" aria-label={t.grid} onClick={() => setView('grid')} className={`rounded-s-lg p-1.5 ${view === 'grid' ? 'bg-surface-hover' : ''}`}>
                  <LayoutGrid size={14} />
                </button>
                <button type="button" aria-label={t.list} onClick={() => setView('list')} className={`rounded-e-lg p-1.5 ${view === 'list' ? 'bg-surface-hover' : ''}`}>
                  <Rows3 size={14} />
                </button>
              </div>
            </div>
          </div>

          {comparing && comparison.data && (
            <div className="rounded-xl border border-border bg-surface p-3">
              {!comparison.data.comparable && (
                <p className="mb-2 text-xs font-semibold text-warning">
                  {(ar ? comparison.data.reason_ar : comparison.data.reason) ?? t.notComparable}
                </p>
              )}
              <div className="overflow-x-auto">
                <CreativeComparisonTable creatives={comparison.data.creatives} currency={currency} locale={locale} />
              </div>
            </div>
          )}

          {openId !== null ? (
            <SharedCreativeDetail
              token={token}
              password={password}
              creativeId={openId}
              filters={filters}
              currency={currency}
              permissions={permissions}
              locale={locale}
              t={t}
              onBack={() => openDetail(null)}
            />
          ) : library.isLoading ? (
            <Skeleton className="h-48 w-full" />
          ) : rows.length === 0 ? (
            <p className="rounded-xl border border-border bg-surface p-6 text-center text-sm text-text-secondary">{t.empty}</p>
          ) : view === 'grid' ? (
            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
              {rows.map((creative, index) => (
                <li key={creative.id}>
                  <CreativeTile
                    creative={creative}
                    currency={currency}
                    locale={locale}
                    t={t}
                    selectable={permissions.comparison}
                    selected={selected.includes(creative.id)}
                    onSelect={() =>
                      setSelected((s) => (s.includes(creative.id) ? s.filter((v) => v !== creative.id) : [...s, creative.id]))
                    }
                    onOpen={() => setViewerIndex(index)}
                    onDetails={() => openDetail(creative.id)}
                  />
                </li>
              ))}
            </ul>
          ) : (
            <div className="overflow-x-auto rounded-xl border border-border">
              <CreativeComparisonTable creatives={rows} currency={currency} locale={locale} onOpen={(i) => setViewerIndex(i)} />
            </div>
          )}

          {openId === null && (
            <p className="tnum text-xs text-text-muted" dir="ltr">
              {t.showing} {rows.length} {t.of} {library.data?.total ?? rows.length}
            </p>
          )}
        </div>
      )}

      {viewerIndex !== null && rows.length > 0 && (
        <CreativeViewer
          creatives={rows}
          index={viewerIndex}
          onIndexChange={setViewerIndex}
          onClose={() => setViewerIndex(null)}
          canZoom={permissions.image_zoom}
        />
      )}

      <p className="text-xs text-text-muted">{t.attribution}</p>
    </section>
  )
}

// ---- pieces ---------------------------------------------------------------------------------

function Picker({
  label,
  value,
  onChange,
  options,
  all,
}: {
  label: string
  value: string
  onChange: (v: string) => void
  options: Array<{ value: string; label: string }>
  all: string
}) {
  if (options.length === 0) return null

  return (
    <label className="grid gap-1 text-xs">
      <span className="font-semibold text-text-muted">{label}</span>
      <select
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-label={label}
        className="rounded-lg border border-border bg-surface px-2 py-1.5 text-xs"
      >
        <option value="">{all}</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
    </label>
  )
}

type Copy = (typeof COPY)['en']

function WinnerGroup({
  title,
  entries,
  t,
  currency,
  permissions,
  icon,
}: {
  title: string
  entries: SharedWinner[]
  t: Copy
  currency: string
  permissions: CreativePermissions
  icon?: React.ReactNode
}) {
  const { locale } = useUi()

  if (entries.length === 0) return null

  return (
    <div className="rounded-xl border border-border bg-surface p-3">
      <h3 className="mb-2 flex items-center gap-1.5 text-sm font-bold">{icon}{title}</h3>
      <ul className="grid gap-2">
        {entries.map((entry, i) => {
          const creative = entry.creative
          const metric = entry.metric
          const hidden = entry.value_hidden === true
          return (
            <li key={i} className="rounded-lg border border-border p-2 text-xs">
              <p className="truncate font-semibold">{creative?.name ?? '—'}</p>
              <dl className="mt-1 grid grid-cols-2 gap-x-3 gap-y-0.5 text-[11px] text-text-secondary">
                {'objective' in entry && entry.objective && <Pair k={t.objective} v={entry.objective} />}
                {entry.path && <Pair k={t.path} v={entry.path} />}
                <Pair k={t.metric} v={metricLabel(metric, locale)} />
                <Pair
                  k={t.value}
                  v={
                    hidden
                      ? t.hidden
                      : formatMetric(
                          metricState(creative?.metrics ?? null, metric),
                          metric,
                          locale,
                          currency,
                        )
                  }
                />
                {creative?.provider && <Pair k={t.platform} v={providerLabel(creative.provider, locale)} />}
                {creative?.campaign_name && <Pair k={t.campaign} v={creative.campaign_name} />}
                {!permissions.spend && <Pair k="—" v="" />}
              </dl>
              {entry.low_evidence === true && (
                <p className="mt-1 text-[11px] font-semibold text-warning">{t.thin}</p>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

/**
 * A labelled value — and an UNLABELLED one when the value speaks for itself.
 *
 * The empty key is used for the creative's own name on an insight, where a label would be noise.
 * Rendering `{k}:` unconditionally printed a bare colon in front of it, which reads as a missing
 * word rather than as a deliberate absence. Found by opening the Arabic page.
 */
function Pair({ k, v }: { k: string; v: string }) {
  if (!v) return null
  return (
    <div className="flex min-w-0 gap-1">
      {k && <dt className="shrink-0 text-text-muted">{k}:</dt>}
      <dd className="truncate font-semibold text-text-primary">{v}</dd>
    </div>
  )
}

function MoveList({
  title,
  icon,
  list,
  t,
}: {
  title: string
  icon: React.ReactNode
  list: PulseList<SharedCreativeMove>
  t: Copy
}) {
  const { locale } = useUi()

  return (
    <div className="rounded-xl border border-border bg-surface p-3">
      <h3 className="mb-2 flex items-center gap-1.5 text-sm font-bold">{icon}{title}</h3>
      {list.items.length === 0 ? (
        <p className="text-xs text-text-muted">—</p>
      ) : (
        <ul className="grid gap-1.5">
          {list.items.map((move, i) => {
            const creative = move.creative
            return (
              <li key={i} className="flex items-baseline justify-between gap-2 text-xs">
                <span className="min-w-0 flex-1 truncate">{creative?.name ?? '—'}</span>
                <span className="tnum shrink-0 text-text-secondary" dir="ltr">
                  {metricLabel(move.metric, locale)}{' '}
                  {move.value_hidden === true ? t.hidden : pct(move.change)}
                </span>
              </li>
            )
          })}
        </ul>
      )}
      {list.total > list.shown && (
        <p className="mt-1 text-[11px] text-text-muted" dir="ltr">{list.shown} {t.of} {list.total}</p>
      )}
    </div>
  )
}

function FatigueBlock({
  data,
  t,
  currency,
  permissions,
}: {
  data: { fatigue: CreativePulse['fatigue'] }
  t: Copy
  currency: string
  permissions: CreativePermissions
}) {
  const { locale } = useUi()
  const fatigue = data.fatigue
  const ar = locale === 'ar'
  // One reader for the money, so this figure cannot disagree with the operator's copy of it.
  const spendAtRisk = readMoney(fatigue.spend_at_risk, 'spend', currency ?? null, ar)

  return (
    <div className="grid gap-3 md:grid-cols-3">
      <Bucket title={t.fatigued} list={fatigue.fatigued} tone="danger" />
      <Bucket title={t.watch} list={fatigue.watch} tone="warning" />
      <Bucket title={t.insufficient} list={fatigue.insufficient_data} tone="muted" />

      {fatigue.alerts.items.length > 0 && (
        <div className="rounded-xl border border-danger/40 bg-danger/5 p-3 md:col-span-3" data-testid="fatigue-alerts">
          <h3 className="mb-2 flex items-center gap-1.5 text-sm font-bold text-danger">
            <AlertTriangle size={14} /> {t.alerts}
            {/*
              * CREATIVE-MONEY-TRUTH-001 — the client's own copy of this figure obeys the same
              * contract as the operator's. A shared report is the version somebody outside the
              * agency reads, so a wrongly-labelled currency here is the one that reaches a client.
              */}
            {permissions.spend && spendAtRisk.amount !== null && (
              <span className="tnum ms-auto text-xs font-semibold" dir="ltr" title={spendAtRisk.note ?? undefined}>
                {formatMoneyReading(spendAtRisk, (n, c) =>
                  formatMetric({ kind: 'value', value: n ?? 0 }, 'spend', locale, c ?? currency))}
              </span>
            )}
          </h3>
          <ul className="grid gap-1.5">
            {fatigue.alerts.items.map((alert: FatigueAlert, i: number) => (
              <li key={i} className="text-xs">
                <b className="font-semibold">{alert.creative?.name ?? '—'}</b>
                {/* The EVIDENCE, beside the verdict — «fatigued» with nothing behind it is a label. */}
                {(ar ? alert.note_ar : alert.note_en) && (
                  <span className="text-text-secondary"> — {ar ? alert.note_ar : alert.note_en}</span>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

function Bucket({ title, list, tone }: { title: string; list: { items: CreativeCard[]; total: number }; tone: string }) {
  const border = tone === 'danger' ? 'border-danger/40' : tone === 'warning' ? 'border-warning/40' : 'border-border'

  return (
    <div className={`rounded-xl border ${border} bg-surface p-3`}>
      <h3 className="mb-1.5 flex items-baseline justify-between text-sm font-bold">
        {title}
        <span className="tnum text-xs text-text-secondary" dir="ltr">{list.total}</span>
      </h3>
      <ul className="grid gap-1 text-xs text-text-secondary">
        {list.items.slice(0, 4).map((c) => (
          <li key={c.id} className="truncate">{c.name}</li>
        ))}
        {list.items.length === 0 && <li className="text-text-muted">—</li>}
      </ul>
    </div>
  )
}

function InsightList({
  insights,
  t,
  ar,
  permissions,
}: {
  insights: { items: CreativeInsightItem[]; total: number; shown: number }
  t: Copy
  ar: boolean
  permissions: CreativePermissions
}) {
  return (
    <div className="rounded-xl border border-border bg-surface p-3" data-testid="shared-creative-insights">
      <h3 className="mb-2 flex items-center gap-1.5 text-sm font-bold"><Lightbulb size={14} /> {t.insights}</h3>
      <ul className="grid gap-2">
        {insights.items.map((item, i) => (
          <li key={`${item.key}-${i}`} className="rounded-lg border border-border p-2.5 text-xs">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <b className="font-semibold">{ar ? item.title_ar : item.title_en}</b>
              <span className="flex gap-2 text-[11px]">
                <span className={item.severity === 'warning' ? 'text-danger' : item.severity === 'opportunity' ? 'text-brand-700' : 'text-success'}>
                  {t.severity[item.severity]}
                </span>
                {/* Confidence is printed, always. «Insufficient data» is a verdict, not a silence. */}
                <span className="text-text-muted">{t.confidence[item.confidence]}</span>
              </span>
            </div>
            <p className="mt-1 text-text-secondary">{ar ? item.detail_ar : item.detail_en}</p>
            <dl className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-text-muted">
              {item.creative_name && <Pair k="" v={item.creative_name} />}
              {item.objective && <Pair k={t.objective} v={item.objective} />}
              {item.path && <Pair k={t.path} v={item.path} />}
              {item.provider && <Pair k={t.platform} v={item.provider} />}
              {item.campaign_name && <Pair k={t.campaign} v={item.campaign_name} />}
              <Pair k={t.period} v={`${item.period.from} → ${item.period.to}`} />
            </dl>
            {permissions.recommendations && (ar ? item.action_ar : item.action_en) && (
              <p className="mt-1.5 rounded bg-surface-secondary px-2 py-1 text-text-primary">
                <b className="font-semibold">{t.recommendation}:</b> {ar ? item.action_ar : item.action_en}
              </p>
            )}
          </li>
        ))}
      </ul>
      {insights.total > insights.shown && (
        <p className="mt-1.5 text-[11px] text-text-muted" dir="ltr">{insights.shown} {t.of} {insights.total}</p>
      )}
    </div>
  )
}

function Freshness({ data, t, ar }: { data: { freshness: CreativePulse['freshness'] }; t: Copy; ar: boolean }) {
  const f = data.freshness

  return (
    <div className="flex flex-wrap gap-x-5 gap-y-1.5 rounded-xl border border-border bg-surface-secondary px-4 py-3 text-xs text-text-secondary">
      <span>
        <span className="text-text-muted">{t.freshness}:</span>{' '}
        <b className="tnum font-semibold text-text-primary" dir="ltr">
          {f.last_synced_at ? fmtDateTime(f.last_synced_at) : t.noSync}
        </b>
      </span>
      {f.providers.map((p) => (
        <span key={p.provider}>
          <span className="text-text-muted">{providerLabel(canonicalPlatform(p.provider), ar ? 'ar' : 'en')}:</span>{' '}
          <b className="tnum font-semibold text-text-primary" dir="ltr">
            {p.last_synced_at ? fmtDate(p.last_synced_at) : t.noSync}
          </b>
        </span>
      ))}
      <span>
        <span className="text-text-muted">{t.quality}:</span>{' '}
        <b className="tnum font-semibold text-text-primary" dir="ltr">
          {Object.entries(f.quality)
            .filter(([, v]) => Number(v) > 0)
            .map(([k, v]) => `${k}: ${v}`)
            .join(' · ') || '—'}
        </b>
      </span>
    </div>
  )
}

/**
 * §15.6 on the client's side — one creative, in depth, inside the link's ceiling.
 *
 * The endpoint is the same bounded lookup the list runs, so a creative the link excludes is not
 * found here either: this panel cannot be used as a side door to an id the library refused. What it
 * shows is whatever the server sent — a withheld metric is ABSENT from the payload rather than
 * blanked here, so there is nothing for the browser to reveal.
 */
function SharedCreativeDetail({
  token,
  password,
  creativeId,
  filters,
  currency,
  permissions,
  locale,
  t,
  onBack,
}: {
  token: string
  password?: string
  creativeId: string
  filters: SharedCreativeQuery
  currency: string
  permissions: CreativePermissions
  locale: 'ar' | 'en'
  t: Copy
  onBack: () => void
}) {
  const ar = locale === 'ar'
  const [zoom, setZoom] = useState(1)

  const detail = useQuery({
    queryKey: ['shared-creative-detail', token, creativeId, filters],
    queryFn: () => getSharedCreative(token, creativeId, filters, password),
  })

  const back = (
    <button type="button" onClick={onBack} className="text-xs font-semibold text-brand-700 underline-offset-2 hover:underline">
      ← {t.backToLibrary}
    </button>
  )

  if (detail.isLoading) {
    return (
      <div className="grid gap-3">
        {back}
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  if (detail.isError || !detail.data) {
    return (
      <div className="grid gap-3">
        {back}
        <ErrorState title={t.detailError} error={detail.error} />
      </div>
    )
  }

  const data = detail.data
  const creative = data.creative
  const preview = creative.preview
  const showing: 'video' | 'image' | 'none' =
    preview.state !== 'available' ? 'none' : preview.video_url ? 'video' : preview.image_url ? 'image' : 'none'
  const funnel = data.funnel

  return (
    <div className="grid gap-4" data-testid="shared-creative-detail">
      {back}

      <div className="grid gap-3 rounded-xl border border-border bg-surface p-3">
        <div className="flex min-h-48 items-center justify-center overflow-auto rounded-lg bg-surface-secondary p-2">
          {showing === 'video' && preview.video_url ? (
            <CreativeVideoPlayer
              // Keyed by the creative, so opening another one builds a new player rather than
              // inheriting the last one's armed state and playback position.
              key={creative.id}
              src={preview.video_url}
              poster={preview.thumbnail_url}
              durationHint={creative.duration_seconds}
              className="w-full max-w-2xl"
            />
          ) : showing === 'image' && preview.image_url ? (
            <img
              src={preview.image_url}
              alt={creative.name}
              loading={imageLoading(preview.image_url)}
              style={{ transform: `scale(${zoom})`, transformOrigin: 'center' }}
              className="max-h-[55vh] max-w-full object-contain transition-transform"
            />
          ) : (
            <p className="p-6 text-center text-xs text-text-muted">
              {(ar ? preview.note_ar : preview.note_en) ?? '—'}
            </p>
          )}
        </div>

        {/* Zoom is drawn only when the link allows it — a hidden control whose shortcut still works
            is a control that is merely invisible. */}
        {showing === 'image' && permissions.image_zoom && (
          <div className="flex items-center gap-1 text-xs">
            <button type="button" onClick={() => setZoom((z) => Math.max(z - 0.25, 0.5))} className="rounded border border-border px-2 py-1">−</button>
            <span className="tnum w-12 text-center" dir="ltr">{Math.round(zoom * 100)}%</span>
            <button type="button" onClick={() => setZoom((z) => Math.min(z + 0.25, 4))} className="rounded border border-border px-2 py-1">+</button>
            <button type="button" onClick={() => setZoom(1)} className="rounded border border-border px-2 py-1">100%</button>
          </div>
        )}

        <h3 className="font-heading text-base font-extrabold">{creative.name}</h3>
        <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs sm:grid-cols-3">
          <Pair k={t.platform} v={providerLabel(creative.provider, locale)} />
          <Pair k={t.campaign} v={creative.campaign_name ?? t.notProvided} />
          <Pair k={t.objective} v={creative.objective ? objectiveLabel(creative.objective, locale) : t.notProvided} />
          <Pair k={t.path} v={marketingPathLabel(creative.path, locale)} />
          <Pair k={t.period} v={`${data.period.from} → ${data.period.to}`} />
          <Pair k={t.previousPeriod} v={`${data.previous_period.from} → ${data.previous_period.to}`} />
          <Pair k={t.lastSync} v={creative.freshness.last_synced_at?.slice(0, 10) ?? t.notProvided} />
          <Pair k={t.sourceUpdated} v={creative.freshness.source_updated_at?.slice(0, 10) ?? t.notProvided} />
        </dl>
      </div>

      {/*
        A carousel's cards, on the client's link too.
        The server has already removed whatever this link withholds from each card — the copy is gone
        from the payload, not merely undrawn, which is what §15.12 asks for.
      */}
      <CreativeCarousel preview={creative.preview} locale={locale} />

      {/* The words on the creative — each field already removed by the server when withheld. */}
      {(creative.copy?.headline || creative.copy?.body || creative.copy?.cta || creative.destination_url) && (
        <div className="grid gap-1.5 rounded-xl border border-border bg-surface p-3 text-xs">
          <h4 className="text-sm font-bold">{t.copy}</h4>
          {creative.copy?.headline && <Pair k={t.headlineText} v={creative.copy.headline} />}
          {creative.copy?.body && <Pair k={t.body} v={creative.copy.body} />}
          {creative.copy?.cta && <Pair k={t.cta} v={creative.copy.cta} />}
          {/* Text, never a link: it is an address chosen by whoever wrote the ad, and a report that
              made it clickable would be offering to follow it on the reader's behalf. */}
          {creative.destination_url && <Pair k={t.destination} v={creative.destination_url} />}
        </div>
      )}

      <div className="overflow-x-auto rounded-xl border border-border">
        <CreativeComparisonTable creatives={[creative as unknown as CreativeCard]} currency={currency} locale={locale} />
      </div>

      <div className="grid gap-2 rounded-xl border border-border bg-surface p-3">
        <h4 className="text-sm font-bold">{t.funnel}</h4>
        {!funnel || funnel.stages.length === 0 ? (
          <p className="text-xs text-text-secondary">{t.funnelNone}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[28rem] text-xs">
              <thead className="bg-surface-secondary text-text-muted">
                <tr>
                  <th className="p-2 text-start">{t.stage}</th>
                  <th className="p-2 text-start">{t.count}</th>
                  <th className="p-2 text-start">{t.rate}</th>
                  <th className="p-2 text-start">{t.costPer}</th>
                </tr>
              </thead>
              <tbody>
                {funnel.stages.map((stage) => (
                  <tr key={stage.key} className="border-t border-border">
                    <td className="p-2">{ar ? stage.label_ar : stage.label_en}</td>
                    <td className="tnum p-2" dir="ltr">{stage.count === null ? t.notProvided : stage.count.toLocaleString('en-US')}</td>
                    <td className="tnum p-2" dir="ltr">{pct(stage.rate_from_previous)}</td>
                    <td className="tnum p-2" dir="ltr">
                      {/* Three sentences kept apart: withheld by this link, never reported, and a
                          real figure. Collapsing the first two into a dash tells the wrong story. */}
                      {stage.cost_hidden
                        ? t.notShown
                        : stage.cost_per === null
                          ? t.notProvided
                          : formatMetric({ kind: 'value', value: stage.cost_per }, 'cpa', locale, currency)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {funnel && funnel.missing.length > 0 && (
          <p className="text-[11px] text-text-muted">
            {t.funnelMissing}: {funnel.missing.map((m) => (ar ? m.label_ar : m.label_en)).join('، ')}
          </p>
        )}
      </div>

      <div className="grid gap-2 rounded-xl border border-border bg-surface p-3">
        <h4 className="text-sm font-bold">{t.byPlatform}</h4>
        {data.by_platform.length <= 1 ? (
          <p className="text-xs text-text-secondary">{t.onePlatform}</p>
        ) : (
          <div className="overflow-x-auto">
            <CreativeComparisonTable
              creatives={data.by_platform.map((row, i) => ({
                ...(creative as unknown as CreativeCard),
                id: `${row.creative_id}-${i}`,
                name: providerLabel(row.provider, locale),
                provider: row.provider,
                metrics: row.metrics,
              }))}
              currency={currency}
              locale={locale}
            />
          </div>
        )}
      </div>

      <p className="text-xs text-text-muted">
        {ar ? data.attribution.note_ar : data.attribution.note_en}
      </p>
    </div>
  )
}

function CreativeTile({
  creative,
  currency,
  locale,
  t,
  selectable,
  selected,
  onSelect,
  onOpen,
  onDetails,
}: {
  creative: CreativeCard
  currency: string
  locale: 'ar' | 'en'
  t: Copy
  selectable: boolean
  selected: boolean
  onSelect: () => void
  onOpen: () => void
  onDetails: () => void
}) {
  const preview = creative.preview
  const image = preview.state === 'available' ? (preview.thumbnail_url ?? preview.image_url) : null

  return (
    <div className={`grid gap-1.5 rounded-xl border p-2 ${selected ? 'border-brand-500' : 'border-border'} bg-surface`}>
      <button type="button" onClick={onOpen} className="block overflow-hidden rounded-lg bg-surface-secondary">
        {image ? (
          <img
            src={image}
            alt={creative.name}
            // `loading="lazy"` on a data: URI never loads at all — see imageLoading().
            loading={imageLoading(image)}
            className="aspect-square w-full object-cover"
          />
        ) : (
          <span className="flex aspect-square w-full items-center justify-center px-2 text-center text-[11px] text-text-muted">
            {(locale === 'ar' ? preview.note_ar : preview.note_en) ?? '—'}
          </span>
        )}
      </button>
      <p className="truncate text-xs font-semibold">{creative.name}</p>
      <p className="truncate text-[11px] text-text-secondary">
        {providerLabel(creative.provider, locale)}
        {creative.campaign_name ? ` · ${creative.campaign_name}` : ''}
      </p>
      <dl className="grid gap-0.5 text-[11px]">
        {creative.headline_metrics.slice(0, 3).map((key) => (
          <div key={key} className="flex justify-between gap-1">
            <dt className="truncate text-text-muted">{metricLabel(key, locale)}</dt>
            <dd className="tnum shrink-0 font-semibold" dir="ltr">
              {formatMetric(metricState(creative.metrics, key), key, locale, currency)}
            </dd>
          </div>
        ))}
      </dl>
      {/* The picture opens the viewer; this opens the analysis. Two questions, two affordances. */}
      <button type="button" onClick={onDetails} className="text-start text-[11px] font-semibold text-brand-700 underline-offset-2 hover:underline">
        {t.details}
      </button>
      {selectable && (
        <label className="flex items-center gap-1.5 text-[11px] text-text-secondary">
          <input type="checkbox" checked={selected} onChange={onSelect} className="h-3.5 w-3.5 accent-brand-600" />
          {t.selected}
        </label>
      )}
    </div>
  )
}

/**
 * The list view, and the comparison, are the same table.
 *
 * Two renderers would have been two opinions about which columns matter, and the reader would meet a
 * metric in one that is missing from the other for no reason they can see.
 */
/**
 * TABLE-PRESENTATION-CONTRACT-001 — the client's comparison table, on the product's own table.
 *
 * This was a second table implementation living in the one document a client actually keeps. It
 * left-aligned every figure, offered no sort, and had its own idea of what a header looks like — so
 * the most scrutinised surface in the product was the one furthest from its own conventions.
 *
 * It is `MetricTable` now. Two things follow for free and both were missing: a client can order the
 * comparison by any column, and an unreported figure sorts LAST rather than winning an ascending
 * sort — «this platform does not send CPM» is not the cheapest CPM.
 *
 * The name column keeps its own cell, because it carries two lines and a button rather than a value.
 */
function CreativeComparisonTable({
  creatives,
  currency,
  locale,
  onOpen,
}: {
  creatives: CreativeCard[]
  currency: string
  locale: 'ar' | 'en'
  onOpen?: (index: number) => void
}) {
  // The union of what each row's own path calls headline, so an awareness row is not given a column
  // for a metric its objective never produces — it simply has no value in that column.
  const columns = useMemo(
    () => [...new Set(creatives.flatMap((c) => c.headline_metrics))].slice(0, 7),
    [creatives],
  )

  const head = [locale === 'ar' ? 'المحتوى' : 'Content', ...columns.map((key) => metricLabel(key, locale))]

  const rows = creatives.map((creative, index) => [
    <div key="name" className="max-w-[220px]">
      {onOpen ? (
        <button type="button" onClick={() => onOpen(index)} className="truncate text-start font-semibold hover:underline">
          {creative.name}
        </button>
      ) : (
        <span className="truncate font-semibold">{creative.name}</span>
      )}
      <span className="block truncate text-[11px] text-text-secondary">
        {providerLabel(creative.provider, locale)}
        {creative.objective ? ` · ${creative.objective}` : ''}
      </span>
    </div>,
    ...columns.map((key) => (
      <span key={key} dir="ltr">{formatMetric(metricState(creative.metrics, key), key, locale, currency)}</span>
    )),
  ])

  /*
   * The raw figures the cells were rendered from, positionally matched — a formatted cell cannot be
   * compared, and «Not provided» must sort as an absence rather than as the string it prints.
   */
  const values: SortValues[] = creatives.map((creative) => [
    creative.name,
    ...columns.map((key) => {
      const state = metricState(creative.metrics, key)

      return state.kind === 'value' ? state.value : null
    }),
  ])

  return <MetricTable head={head} rows={rows} values={values} />
}
