import { useEffect, useMemo, useState } from 'react'
import { metricSourceLabel } from './metricSource'
import { creativeKindLabel } from './CreativesPage'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Maximize2, Minus, Plus, RotateCcw } from 'lucide-react'
import { CreativeViewer } from './CreativeViewer'
import { CreativeVideoPlayer } from './CreativeVideoPlayer'
import { CreativeInsightCard } from './CreativeInsightCard'
import { CreativeCarousel } from './CreativeCarousel'
import { formatMetric, metricLabel, metricState } from './metrics'
import { formatBytes, imageLoading } from './format'
import { getCreativeInReach, type CreativeMetrics, type FunnelStage } from './api'
import { ConversionFunnelChart, MetricLineChart } from '@/features/analytics/charts'
import { DateField } from '@/components/ui/DateField'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { marketingPathLabel, objectiveLabel, providerLabel } from '@/features/campaigns/labels'
import { CANONICAL_CURRENCY } from '@/lib/money/contract'

/**
 * §15.6 — one creative, on its own page.
 *
 * ## Why this is a page and not a bigger modal
 *
 * The viewer answers «what does this look like». This answers «should we keep running it», and that
 * is a question people send to each other. A modal has no address: it cannot be linked to, it does
 * not survive a refresh, and Back closes it instead of returning to where the reader came from. So
 * the creative gets a real route, and the library's filters travel in the query string — the reader
 * goes back to the same shelf they took the creative off, not to an unfiltered library.
 *
 * ## No project id in the address
 *
 * The library spans projects and a card does not carry one, so a route that required a project id
 * could not be linked to from the page that lists them. The ceiling is the caller's membership,
 * applied to the LOOKUP rather than checked after it, and a creative outside it answers 404.
 *
 * ## The only control here is the period
 *
 * Platform, campaign, objective and marketing path are PROPERTIES of this creative, not filters over
 * it — a dropdown offering «platform» on a page about one creative would change nothing, which the
 * contract rightly calls a dead control. The period genuinely changes every figure on the page, and
 * it is in the address, so a shared link shows the reader the same window the sender was looking at.
 *
 * ## Nothing preloads, nothing plays by itself
 *
 * The video mounts with `preload="metadata"` and no autoplay, and the player is keyed by the
 * creative, so arriving at a different creative builds a new player rather than inheriting the old
 * one's armed state and playback position.
 */

const COPY = {
  ar: {
    back: 'العودة إلى المكتبة',
    loading: 'جارٍ التحميل…',
    error: 'تعذّر فتح هذا المحتوى.',
    notFound: 'هذا المحتوى غير متاح لك، أو لم يعد موجودًا.',
    from: 'من',
    to: 'إلى',
    period: 'الفترة',
    previousPeriod: 'الفترة السابقة',
    fullscreen: 'ملء الشاشة',
    zoomIn: 'تكبير',
    zoomOut: 'تصغير',
    reset: 'الحجم الأصلي',
    noPreview: 'لا تتوفر معاينة',
    identity: 'التعريف والمصدر',
    platform: 'المنصة',
    campaign: 'الحملة',
    adSet: 'المجموعة الإعلانية',
    ad: 'الإعلان',
    objective: 'الهدف',
    path: 'المسار التسويقي',
    kind: 'نوع المحتوى',
    firstSeen: 'أول ظهور',
    lastActive: 'آخر نشاط',
    lastSync: 'آخر مزامنة',
    sourceUpdated: 'آخر تحديث من المصدر',
    freshness: 'حداثة البيانات',
    currency: 'العملة',
    timezone: 'المنطقة الزمنية',
    attribution: 'الإسناد',
    demo: 'وضع تجريبي',
    live: 'بيانات مزامنة',
    dimensions: 'الأبعاد',
    ratio: 'النسبة',
    size: 'حجم الملف',
    duration: 'المدة',
    copy: 'نص الإعلان',
    headline: 'العنوان',
    body: 'النص',
    cta: 'زر الإجراء',
    destination: 'الرابط الوجهة',
    figures: 'المؤشرات حسب الهدف',
    figuresHint: 'المؤشرات التي اشتُري هذا المحتوى لتحقيقها — لا قائمة موحدة لكل الأهداف.',
    change: 'التغير عن الفترة السابقة',
    funnel: 'الفانل',
    funnelHint: 'المراحل التي أرسلتها المنصة فقط.',
    funnelMissing: 'مراحل لا ترسلها هذه المنصة',
    funnelNone: 'لم ترسل المنصة أي مرحلة يمكن بناء فانل منها.',
    trend: 'الاتجاه الزمني',
    daily: 'يومي',
    weekly: 'أسبوعي',
    noTrend: 'لا توجد أيام نشاط داخل هذه الفترة.',
    byPlatform: 'الأداء حسب المنصة',
    byCampaign: 'الأداء حسب الحملة',
    onePlatform: 'هذا المحتوى يعمل على منصة واحدة، فلا توجد مقارنة بين المنصات.',
    peers: 'المقارنة بمحتويات المسار نفسه',
    peersNone: 'لا توجد محتويات أخرى على المسار نفسه للمقارنة بها.',
    peersCount: 'محتوى في المقارنة',
    mine: 'هذا المحتوى',
    average: 'متوسط المسار',
    fatigue: 'حالة الإجهاد',
    evidence: 'الأدلة',
    insights: 'التحليلات والتوصيات',
    insightsNone: 'لا توجد تحليلات لهذا المحتوى في هذه الفترة — لم يتجاوز أي مؤشر عتبة التغير المعتبر.',
    action: 'الإجراء المقترح',
    comparedAgainst: 'المقارنة أُجريت مقابل',
    capped: 'أعلى المحتويات إنفاقًا فقط',
    confidence: 'مستوى الثقة',
    aiReview: 'يحتاج مراجعة بشرية',
    of: 'من',
    week: 'الأسبوع',
    stage: 'المرحلة',
    count: 'العدد',
    rate: 'التحول من السابقة',
    costPer: 'التكلفة لكل',
    costHidden: 'غير معروضة في هذا الرابط',
    source: 'المصدر',
    notProvided: 'غير مُرسَل',
  },
  en: {
    back: 'Back to the library',
    loading: 'Loading…',
    error: 'Could not open this creative.',
    notFound: 'This creative is not available to you, or no longer exists.',
    from: 'From',
    to: 'To',
    period: 'Period',
    previousPeriod: 'Previous period',
    fullscreen: 'Fullscreen',
    zoomIn: 'Zoom in',
    zoomOut: 'Zoom out',
    reset: 'Actual size',
    noPreview: 'No preview available',
    identity: 'Identity and source',
    platform: 'Platform',
    campaign: 'Campaign',
    adSet: 'Ad set',
    ad: 'Ad',
    objective: 'Objective',
    path: 'Marketing path',
    kind: 'Creative type',
    firstSeen: 'First seen',
    lastActive: 'Last active',
    lastSync: 'Last sync',
    sourceUpdated: 'Source updated at',
    freshness: 'Data freshness',
    currency: 'Currency',
    timezone: 'Timezone',
    attribution: 'Attribution',
    demo: 'Demo',
    live: 'Synced data',
    dimensions: 'Dimensions',
    ratio: 'Ratio',
    size: 'File size',
    duration: 'Duration',
    copy: 'Ad copy',
    headline: 'Headline',
    body: 'Body',
    cta: 'Call to action',
    destination: 'Destination URL',
    figures: 'Metrics for this objective',
    figuresHint: 'The metrics this creative was bought to move — never one list for every objective.',
    change: 'Change vs previous period',
    funnel: 'Funnel',
    funnelHint: 'Only the stages the platform reported.',
    funnelMissing: 'Stages this platform does not report',
    funnelNone: 'The platform reported no stage a funnel could be built from.',
    trend: 'Trend over time',
    daily: 'Daily',
    weekly: 'Weekly',
    noTrend: 'No active days inside this period.',
    byPlatform: 'By platform',
    byCampaign: 'By campaign',
    onePlatform: 'This creative runs on one platform, so there is no cross-platform comparison.',
    peers: 'Against creatives on the same path',
    peersNone: 'No other creatives on the same path to compare against.',
    peersCount: 'creatives compared',
    mine: 'This creative',
    average: 'Path average',
    fatigue: 'Fatigue',
    evidence: 'Evidence',
    insights: 'Insights and recommendations',
    insightsNone: 'No findings for this creative in this period — nothing crossed a material threshold.',
    action: 'Suggested action',
    comparedAgainst: 'Compared against',
    capped: 'highest-spending creatives only',
    confidence: 'Confidence',
    aiReview: 'Needs human review',
    of: 'of',
    week: 'Week',
    stage: 'Stage',
    count: 'Count',
    rate: 'From previous stage',
    costPer: 'Cost per',
    costHidden: 'Not shown on this link',
    source: 'Source',
    notProvided: 'Not provided',
  },
}

const FATIGUE_TONE: Record<string, string> = {
  improving: 'bg-success/15 text-success',
  stable: 'bg-surface-hover text-text-secondary',
  watch: 'bg-warning/15 text-warning',
  fatigued: 'bg-danger/15 text-danger',
  insufficient_data: 'bg-surface-hover text-text-secondary',
}

const FATIGUE_LABEL: Record<string, { ar: string; en: string }> = {
  improving: { ar: 'يتحسّن', en: 'Improving' },
  stable: { ar: 'مستقر', en: 'Stable' },
  watch: { ar: 'يحتاج متابعة', en: 'Watch' },
  fatigued: { ar: 'مُجهَد', en: 'Fatigued' },
  insufficient_data: { ar: 'بيانات غير كافية', en: 'Insufficient data' },
}



const ZOOM_MIN = 0.5
const ZOOM_MAX = 4
const ZOOM_STEP = 0.25

/**
 * The library's own filters, carried through so Back returns to the same shelf.
 *
 * `creative` is dropped: it is the address of this page, and leaving it in the back link would send
 * the reader to a library that immediately reopened the viewer on the creative they just left.
 */
function backQuery(params: URLSearchParams): string {
  const next = new URLSearchParams(params)
  next.delete('creative')
  const qs = next.toString()

  return qs === '' ? '' : `?${qs}`
}

export function CreativeDetailPage({ portal }: { portal: 'app' | 'agency' }) {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']
  const { creativeId = '' } = useParams()
  const [params, setParams] = useSearchParams()

  const from = params.get('from') ?? ''
  const to = params.get('to') ?? ''
  const [grain, setGrain] = useState<'daily' | 'weekly'>('daily')
  const [zoom, setZoom] = useState(1)
  const [fullscreen, setFullscreen] = useState(false)

  // A new creative opens at its own size: inheriting 3× shows a corner of an image and reads as a
  // rendering fault.
  useEffect(() => setZoom(1), [creativeId])

  const detail = useQuery({
    queryKey: ['creative-detail', creativeId, from, to],
    queryFn: () => getCreativeInReach(creativeId, { from: from || undefined, to: to || undefined }),
    enabled: creativeId !== '',
  })

  const setWindow = (key: 'from' | 'to', value: string) => {
    const next = new URLSearchParams(params)
    if (value === '') next.delete(key)
    else next.set(key, value)
    // `replace`, so a reader dragging a date range does not have to press Back once per keystroke.
    setParams(next, { replace: true })
  }

  const data = detail.data
  const creative = data?.creative
  const metrics: CreativeMetrics | null = data?.metrics ?? null
  const currency = data?.currency ?? CANONICAL_CURRENCY

  const trendRows = useMemo(() => {
    if (!data) return []
    return grain === 'daily'
      ? data.trend
      : data.weekly.map((w) => ({ ...w, date: `${t.week} ${w.week}` }))
  }, [data, grain, t.week])

  if (detail.isPending) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-80" />
        <Skeleton className="h-48" />
      </div>
    )
  }

  if (detail.isError || !data || !creative) {
    return (
      <div className="space-y-4">
        <Link to={`/${portal}/content${backQuery(params)}`} className="inline-flex items-center gap-1 text-sm text-brand-700 underline-offset-2 hover:underline">
          {ar ? <ArrowRight className="h-4 w-4" aria-hidden /> : <ArrowLeft className="h-4 w-4" aria-hidden />}
          {t.back}
        </Link>
        <ErrorState title={t.error} description={t.notFound} error={detail.error} ar={ar} onRetry={() => void detail.refetch()} />
      </div>
    )
  }

  const preview = creative.preview
  const note = ar ? preview.note_ar : preview.note_en
  const showing: 'video' | 'image' | 'none' =
    preview.state !== 'available' ? 'none' : preview.video_url ? 'video' : preview.image_url ? 'image' : 'none'

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Link
          to={`/${portal}/content${backQuery(params)}`}
          className="inline-flex items-center gap-1 text-sm text-brand-700 underline-offset-2 hover:underline"
        >
          {ar ? <ArrowRight className="h-4 w-4" aria-hidden /> : <ArrowLeft className="h-4 w-4" aria-hidden />}
          {t.back}
        </Link>

        <div className="flex flex-wrap items-end gap-3">
          {/* `DateField`, never a native date input: the browser's own control renders in the OS
              locale, so a Saudi machine shows a Hijri calendar for the ISO value the API expects. */}
          <label className="flex flex-col gap-1 text-xs">
            <span className="font-medium text-text-secondary">{t.from}</span>
            <DateField aria-label={t.from} value={from} onChange={(v) => setWindow('from', v)} />
          </label>
          <label className="flex flex-col gap-1 text-xs">
            <span className="font-medium text-text-secondary">{t.to}</span>
            <DateField aria-label={t.to} value={to} onChange={(v) => setWindow('to', v)} />
          </label>
        </div>
      </div>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-xl font-semibold text-text-primary">{creative.name}</h1>
          <p className="mt-1 text-sm text-text-secondary">
            {providerLabel(creative.provider, locale)}
            {creative.campaign_name ? ` · ${creative.campaign_name}` : ''}
            {creative.objective ? ` · ${objectiveLabel(creative.objective, locale)}` : ''}
            {` · ${marketingPathLabel(data.path, locale)}`}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <span className={`rounded px-2 py-0.5 text-xs ${FATIGUE_TONE[data.fatigue.status] ?? ''}`}>
            {FATIGUE_LABEL[data.fatigue.status]?.[ar ? 'ar' : 'en'] ?? data.fatigue.status}
          </span>
          <span className={`rounded px-2 py-0.5 text-xs ${creative.is_demo ? 'bg-warning/15 text-warning' : 'bg-surface-hover text-text-secondary'}`}>
            {creative.is_demo ? t.demo : t.live}
          </span>
        </div>
      </header>

      {/* ---- the asset itself ---------------------------------------------------------------- */}
      <section className="rounded-lg border border-border bg-surface p-4">
        <div className="flex min-h-64 items-center justify-center overflow-auto rounded-md bg-surface-secondary p-3">
          {showing === 'video' && preview.video_url ? (
            <CreativeVideoPlayer
              /* Keyed by the CREATIVE, not the file: two creatives sharing one asset produce an
                 identical url key, and React would reuse the player across a navigation — landing
                 the reader on a new creative already mid-play. */
              key={creative.id}
              src={preview.video_url}
              poster={preview.thumbnail_url}
              durationHint={creative.duration_seconds}
              className="w-full max-w-3xl"
            />
          ) : showing === 'image' && preview.image_url ? (
            <img
              src={preview.image_url}
              alt={creative.name}
              loading={imageLoading(preview.image_url)}
              decoding="async"
              style={{ transform: `scale(${zoom})`, transformOrigin: 'center' }}
              className="max-h-[60vh] max-w-full object-contain transition-transform"
            />
          ) : (
            <div className="max-w-md p-6 text-center text-sm text-text-secondary">
              <p className="font-medium">{t.noPreview}</p>
              {/* The REASON, not a shrug: «expired» and «the platform does not expose this» call for
                  completely different actions, and one grey box asks for neither. */}
              {note && <p className="mt-2 text-xs">{note}</p>}
            </div>
          )}
        </div>

        <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
          <dl className="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-text-secondary">
            <Fact k={t.dimensions} v={creative.width !== null && creative.height !== null ? `${creative.width}×${creative.height}` : t.notProvided} ltr />
            <Fact k={t.ratio} v={creative.aspect_ratio ?? t.notProvided} ltr />
            <Fact k={t.size} v={formatBytes(creative.file_size) ?? t.notProvided} ltr />
            {/* «Not provided» rather than «0s»: a still image has no duration, and a video whose
                platform omitted it has none either — neither of them lasts zero seconds. */}
            <Fact k={t.duration} v={creative.duration_seconds === null ? t.notProvided : `${creative.duration_seconds}s`} ltr />
          </dl>

          <div className="flex items-center gap-1">
            {showing === 'image' && (
              <>
                <button type="button" aria-label={t.zoomOut} onClick={() => setZoom((z) => Math.max(z - ZOOM_STEP, ZOOM_MIN))} className="rounded border border-border p-1.5 hover:bg-surface-hover">
                  <Minus className="h-4 w-4" aria-hidden />
                </button>
                <span className="w-12 text-center text-xs tabular-nums" dir="ltr">{Math.round(zoom * 100)}%</span>
                <button type="button" aria-label={t.zoomIn} onClick={() => setZoom((z) => Math.min(z + ZOOM_STEP, ZOOM_MAX))} className="rounded border border-border p-1.5 hover:bg-surface-hover">
                  <Plus className="h-4 w-4" aria-hidden />
                </button>
                <button type="button" aria-label={t.reset} onClick={() => setZoom(1)} className="rounded border border-border p-1.5 hover:bg-surface-hover">
                  <RotateCcw className="h-4 w-4" aria-hidden />
                </button>
              </>
            )}
            {showing !== 'none' && (
              <button
                type="button"
                onClick={() => setFullscreen(true)}
                className="ms-1 inline-flex items-center gap-1 rounded border border-border px-2 py-1.5 text-xs hover:bg-surface-hover"
              >
                <Maximize2 className="h-3.5 w-3.5" aria-hidden /> {t.fullscreen}
              </button>
            )}
          </div>
        </div>
      </section>

      {/* ---- a carousel is more than one picture (§15) ---------------------------------------- */}
      <CreativeCarousel preview={creative.preview} locale={locale} />

      {/* ---- what it is, and where the figures came from ------------------------------------- */}
      <section className="rounded-lg border border-border bg-surface p-4">
        <h2 className="text-sm font-semibold text-text-primary">{t.identity}</h2>
        <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-xs sm:grid-cols-3 lg:grid-cols-4">
          <Fact k={t.platform} v={providerLabel(creative.provider, locale)} block />
          <Fact k={t.campaign} v={creative.campaign_name ?? t.notProvided} block />
          <Fact k={t.adSet} v={creative.external_ids.ad_set ?? t.notProvided} block ltr />
          <Fact k={t.ad} v={creative.external_ids.ad ?? t.notProvided} block ltr />
          <Fact k={t.objective} v={creative.objective ? objectiveLabel(creative.objective, locale) : t.notProvided} block />
          <Fact k={t.path} v={marketingPathLabel(data.path, locale)} block />
          <Fact k={t.kind} v={creativeKindLabel(preview.kind, ar)} block />
          <Fact k={t.firstSeen} v={creative.freshness.first_seen_at?.slice(0, 10) ?? t.notProvided} block ltr />
          <Fact k={t.lastActive} v={creative.freshness.last_active_at?.slice(0, 10) ?? t.notProvided} block ltr />
          <Fact k={t.lastSync} v={creative.freshness.last_synced_at?.slice(0, 16).replace('T', ' ') ?? t.notProvided} block ltr />
          <Fact k={t.sourceUpdated} v={creative.freshness.source_updated_at?.slice(0, 16).replace('T', ' ') ?? t.notProvided} block ltr />
          <Fact k={t.currency} v={data.currency ?? t.notProvided} block ltr />
          <Fact k={t.timezone} v={data.timezone ?? t.notProvided} block ltr />
          <Fact k={t.period} v={`${data.period.from} → ${data.period.to}`} block ltr />
          <Fact k={t.previousPeriod} v={`${data.previous_period.from} → ${data.previous_period.to}`} block ltr />
        </dl>
        <p className="mt-3 text-xs text-text-secondary">
          {t.attribution}: {ar ? data.attribution.note_ar : data.attribution.note_en}
        </p>
      </section>

      {/* ---- the words on it, shown as text and never followed -------------------------------- */}
      {(creative.copy.headline || creative.copy.body || creative.copy.cta || creative.destination_url) && (
        <section className="rounded-lg border border-border bg-surface p-4">
          <h2 className="text-sm font-semibold text-text-primary">{t.copy}</h2>
          <dl className="mt-3 space-y-2 text-sm">
            {creative.copy.headline && <Fact k={t.headline} v={creative.copy.headline} block />}
            {creative.copy.body && <Fact k={t.body} v={creative.copy.body} block />}
            {creative.copy.cta && <Fact k={t.cta} v={creative.copy.cta} block />}
            {creative.destination_url && (
              <div>
                <dt className="text-xs text-text-secondary">{t.destination}</dt>
                {/*
                  Text, not a link.
                  It is the advertiser's own URL rather than the platform's, so it carries no
                  credential of ours — but it is still an address chosen by whoever wrote the ad, and
                  a page that made it clickable would be offering to follow it on the reader's behalf.
                */}
                <dd className="break-all font-mono text-xs text-text-primary" dir="ltr">{creative.destination_url}</dd>
              </div>
            )}
          </dl>
        </section>
      )}

      {/* ---- the figures this creative was bought to move -------------------------------------- */}
      <section className="rounded-lg border border-border bg-surface p-4">
        <h2 className="text-sm font-semibold text-text-primary">{t.figures}</h2>
        <p className="mt-1 text-xs text-text-secondary">{t.figuresHint}</p>
        <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {data.headline_metrics.map((key) => (
            <MetricBlock
              key={key}
              metricKey={key}
              metrics={metrics}
              previous={data.previous}
              locale={locale}
              currency={currency}
              changeLabel={t.change}
            />
          ))}
        </div>
      </section>

      {/* ---- the funnel, with only the steps the platform sent --------------------------------- */}
      <section className="rounded-lg border border-border bg-surface p-4">
        <h2 className="text-sm font-semibold text-text-primary">{t.funnel}</h2>
        <p className="mt-1 text-xs text-text-secondary">{t.funnelHint}</p>
        {data.funnel.stages.length === 0 ? (
          <p className="mt-3 text-sm text-text-secondary">{t.funnelNone}</p>
        ) : (
          <>
            <div className="mt-4">
              <ConversionFunnelChart
                stages={data.funnel.stages.map((s) => ({
                  label: ar ? s.label_ar : s.label_en,
                  // Was `s.count ?? 0` — the coercion FUNNEL-NULL-001 is about, on the one chart whose
                  // whole premise is that an unreported step is not a step. The chart takes the null.
                  count: s.count,
                  step_rate: s.rate_from_previous,
                  /*
                   * The bars carry no cost. Found live: the chart rounds money to whole units, so a
                   * cost of 0.026 per impression printed «0 SAR» beside a bar — «this step is free»
                   * — while the table below it correctly said 0.03. One of the two had to go, and
                   * the one that cannot show the precision is the one that should not show the
                   * figure. The table is where the money lives.
                   */
                  cost_per: null,
                }))}
                currency={currency}
              />
            </div>
            <FunnelTable stages={data.funnel.stages} ar={ar} t={t} currency={currency} locale={locale} />
          </>
        )}
        {data.funnel.missing.length > 0 && (
          <p className="mt-3 text-xs text-text-secondary">
            {/* Named, not dropped: a reader who cannot see «add to cart» needs to know the platform
                never sent it, or they read its absence as a creative that sold nothing. */}
            {t.funnelMissing}: {data.funnel.missing.map((m) => (ar ? m.label_ar : m.label_en)).join('، ')}
          </p>
        )}
      </section>

      {/* ---- how it moved ---------------------------------------------------------------------- */}
      <section className="rounded-lg border border-border bg-surface p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold text-text-primary">{t.trend}</h2>
          <div className="flex rounded-md border border-border p-0.5" role="group" aria-label={t.trend}>
            {(['daily', 'weekly'] as const).map((option) => (
              <button
                key={option}
                type="button"
                aria-pressed={grain === option}
                onClick={() => setGrain(option)}
                className={`rounded px-2 py-1 text-xs ${grain === option ? 'bg-surface-hover text-text-primary' : 'text-text-secondary'}`}
              >
                {t[option]}
              </button>
            ))}
          </div>
        </div>
        {trendRows.length === 0 ? (
          <p className="mt-3 text-sm text-text-secondary">{t.noTrend}</p>
        ) : (
          <div className="mt-3">
            <MetricLineChart
              data={trendRows as Array<Record<string, unknown>>}
              currency={currency}
              series={[
                { key: 'spend', name: metricLabel('spend', locale), kind: 'money' },
                { key: 'impressions', name: metricLabel('impressions', locale), kind: 'compact' },
                { key: 'clicks', name: metricLabel('clicks', locale), kind: 'compact' },
                { key: 'conversions', name: metricLabel('conversions', locale), kind: 'num' },
              ]}
            />
          </div>
        )}
      </section>

      {/* ---- the same asset elsewhere ---------------------------------------------------------- */}
      <section className="rounded-lg border border-border bg-surface p-4">
        <h2 className="text-sm font-semibold text-text-primary">{t.byPlatform}</h2>
        {data.by_platform.length <= 1 ? (
          <p className="mt-2 text-sm text-text-secondary">{t.onePlatform}</p>
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full min-w-[36rem] text-sm">
              <thead className="bg-surface-hover text-xs text-text-secondary">
                <tr>
                  <th className="p-2 text-start">{t.platform}</th>
                  {['spend', 'impressions', 'clicks', 'conversions'].map((k) => (
                    <th key={k} className="p-2 text-start">{metricLabel(k, locale)}</th>
                  ))}
                  <th className="p-2 text-start">{t.source}</th>
                </tr>
              </thead>
              <tbody>
                {data.by_platform.map((row) => (
                  <tr key={row.creative_id} className="border-t border-border">
                    <td className="p-2">{providerLabel(row.provider, locale)}</td>
                    {['spend', 'impressions', 'clicks', 'conversions'].map((k) => (
                      <td key={k} className="p-2 tabular-nums" dir="ltr">
                        {formatMetric(metricState(row.metrics, k), k, locale, currency)}
                      </td>
                    ))}
                    {/*
                      CONTENT-SOURCE-LABEL-001 — the «المصدر» column printed `platform_reported`.
                      
                      That column answers «where did this row come from», which is the question a
                      reader checking a figure asks first — and it answered in the database's words.
                    */}
                    <td className="p-2 text-xs text-text-secondary">{metricSourceLabel(row.source, locale === 'ar')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {/* ---- against content doing the same job ------------------------------------------------ */}
      <section className="rounded-lg border border-border bg-surface p-4">
        <h2 className="text-sm font-semibold text-text-primary">{t.peers}</h2>
        {!data.peers ? (
          <p className="mt-2 text-sm text-text-secondary">{t.peersNone}</p>
        ) : (
          <>
            <p className="mt-1 text-xs text-text-secondary">
              <span dir="ltr">{data.peers.count}</span> {t.peersCount} · {marketingPathLabel(String(data.peers.path ?? data.path), locale)}
            </p>
            <div className="mt-3 overflow-x-auto">
              <table className="w-full min-w-[32rem] text-sm">
                <thead className="bg-surface-hover text-xs text-text-secondary">
                  <tr>
                    <th className="p-2 text-start" />
                    {['ctr', 'cpc', 'cpm', 'roas'].map((k) => (
                      <th key={k} className="p-2 text-start">{metricLabel(k, locale)}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  <tr className="border-t border-border">
                    <td className="p-2 font-medium">{t.mine}</td>
                    {['ctr', 'cpc', 'cpm', 'roas'].map((k) => (
                      <td key={k} className="p-2 tabular-nums" dir="ltr">
                        {formatMetric(metricState(metrics, k), k, locale, currency)}
                      </td>
                    ))}
                  </tr>
                  <tr className="border-t border-border text-text-secondary">
                    <td className="p-2">{t.average}</td>
                    {['ctr', 'cpc', 'cpm', 'roas'].map((k) => (
                      <td key={k} className="p-2 tabular-nums" dir="ltr">
                        {typeof data.peers?.[k] === 'number'
                          ? formatMetric({ kind: 'value', value: data.peers[k] as number }, k, locale, currency)
                          : t.notProvided}
                      </td>
                    ))}
                  </tr>
                </tbody>
              </table>
            </div>
          </>
        )}
      </section>

      {/* ---- the verdict, with what produced it ------------------------------------------------ */}
      <section className="rounded-lg border border-border bg-surface p-4">
        <h2 className="text-sm font-semibold text-text-primary">{t.fatigue}</h2>
        <p className="mt-2 flex flex-wrap items-center gap-2 text-sm">
          <span className={`rounded px-2 py-0.5 text-xs ${FATIGUE_TONE[data.fatigue.status] ?? ''}`}>
            {FATIGUE_LABEL[data.fatigue.status]?.[ar ? 'ar' : 'en'] ?? data.fatigue.status}
          </span>
          <span className="text-text-secondary">{ar ? data.fatigue.reason_ar : data.fatigue.reason_en}</span>
        </p>
        {data.fatigue.signals.length > 0 && (
          <>
            <h3 className="mt-3 text-xs font-semibold text-text-secondary">{t.evidence}</h3>
            <ul className="mt-1 space-y-1 text-xs text-text-secondary">
              {data.fatigue.signals.map((signal) => (
                <li key={`${signal.metric}-${signal.direction}`} className="flex flex-wrap gap-2">
                  <span className="font-medium text-text-primary">{metricLabel(signal.metric, locale)}</span>
                  <span>{signal.direction}</span>
                  {signal.change !== null && <span dir="ltr" className="tabular-nums">{(signal.change * 100).toFixed(1)}%</span>}
                </li>
              ))}
            </ul>
          </>
        )}
      </section>

      {/* ---- what the figures say, and what to do about it ------------------------------------- */}
      <section className="rounded-lg border border-border bg-surface p-4">
        <h2 className="text-sm font-semibold text-text-primary">{t.insights}</h2>
        <p className="mt-1 text-xs text-text-secondary">
          {t.comparedAgainst}: <span dir="ltr">{data.insights.compared_against.creatives}</span>{' '}
          {marketingPathLabel(data.insights.compared_against.path, locale)}
          {data.insights.compared_against.capped && ` — ${t.capped}`}
        </p>
        {data.insights.items.length === 0 ? (
          <p className="mt-3 text-sm text-text-secondary">{t.insightsNone}</p>
        ) : (
          <ul className="mt-3 space-y-3">
            {data.insights.items.map((item) => (
              <CreativeInsightCard key={item.id} item={item} locale={locale} />
            ))}
          </ul>
        )}
      </section>

      {/* Reused rather than rebuilt: the viewer already owns zoom, the arrow keys and Escape, and
          it unmounts the player on close — which is what stops a video that was playing. */}
      {fullscreen && (
        <CreativeViewer
          creatives={[creative]}
          index={0}
          onIndexChange={() => undefined}
          onClose={() => setFullscreen(false)}
        />
      )}
    </div>
  )
}

/** A labelled value. An empty value renders «not provided» upstream — never a bare colon. */
function Fact({ k, v, ltr = false, block = false }: { k: string; v: string; ltr?: boolean; block?: boolean }) {
  if (block) {
    return (
      <div className="min-w-0">
        <dt className="text-text-secondary">{k}</dt>
        <dd className="truncate font-medium text-text-primary" dir={ltr ? 'ltr' : undefined}>{v}</dd>
      </div>
    )
  }

  return (
    <div className="flex gap-1">
      <dt className="text-text-muted">{k}</dt>
      <dd className="font-medium text-text-primary" dir={ltr ? 'ltr' : undefined}>{v}</dd>
    </div>
  )
}

/**
 * One headline metric, with what it did against the previous window.
 *
 * The change is shown only when BOTH sides are real numbers. A «+100%» computed against a previous
 * period the platform did not report is not a rise; it is an artefact of treating silence as zero.
 */
/** Ratios that are normally a share of a whole, so exceeding it needs saying rather than printing. */
const RATE_KEYS = new Set(['conversion_rate', 'view_rate', 'completion_rate', 'video_completion_rate', 'engagement_rate', 'ctr'])

function MetricBlock({
  metricKey,
  metrics,
  previous,
  locale,
  currency,
  changeLabel,
}: {
  metricKey: string
  metrics: CreativeMetrics | null
  previous: CreativeMetrics | null
  locale: 'ar' | 'en'
  currency: string
  changeLabel: string
}) {
  const now = metricState(metrics, metricKey)
  const then = metricState(previous, metricKey)
  const change =
    now.kind === 'value' && then.kind === 'value' && then.value !== 0
      ? (now.value - then.value) / Math.abs(then.value)
      : null

  /*
   * RATE-OVER-WHOLE-001 — «معدل التحويل 136.51%», printed flat.
   *
   * 172 orders against 126 clicks. Both figures are real and the ratio is arithmetically right: a
   * view-through conversion needs no click, and the two are counted on different attribution
   * windows, so conversions genuinely can exceed clicks. Printed without a word, «136.51%» reads as
   * a bug in the product and teaches the reader to distrust the whole panel.
   *
   * This is the treatment FUNNEL-NOT-NESTED-001 established for «166%» one screen over: the figure
   * is not hidden, corrected or clamped — nothing here knows which side is wrong — it is marked, and
   * the marker explains itself on hover.
   */
  const overWhole = RATE_KEYS.has(metricKey) && now.kind === 'value' && now.value > 1

  return (
    <div className="rounded-md border border-border p-3">
      <p className="text-xs text-text-secondary">{metricLabel(metricKey, locale)}</p>
      <p className="mt-1 text-lg font-semibold tabular-nums text-text-primary" dir="ltr">
        {formatMetric(now, metricKey, locale, currency)}
        {overWhole && (
          <span
            className="ms-1 cursor-help text-xs font-normal text-text-muted"
            title={locale === 'ar'
              ? 'أكبر من 100% لأن التحويلات لا تتطلب نقرة — تُحتسب مشاهدات الإعلان أيضًا، وبنافذة إسناد مختلفة عن النقرات.'
              : 'Above 100% because a conversion does not require a click — view-throughs count too, on a different attribution window from clicks.'}
          >
            ⓘ
          </span>
        )}
      </p>
      {change !== null && (
        <p
          className={`mt-1 text-xs tabular-nums ${change >= 0 ? 'text-success' : 'text-danger'}`}
          dir="ltr"
          title={changeLabel}
        >
          {change >= 0 ? '+' : ''}{(change * 100).toFixed(1)}%
        </p>
      )}
    </div>
  )
}

/** The funnel as numbers, beside the bars — a bar chart alone cannot say «not shown on this link». */
function FunnelTable({
  stages,
  ar,
  t,
  currency,
  locale,
}: {
  stages: FunnelStage[]
  ar: boolean
  t: (typeof COPY)['ar']
  currency: string
  locale: 'ar' | 'en'
}) {
  return (
    <div className="mt-4 overflow-x-auto">
      <table className="w-full min-w-[32rem] text-sm">
        <thead className="bg-surface-hover text-xs text-text-secondary">
          <tr>
            <th className="p-2 text-start">{t.stage}</th>
            <th className="p-2 text-start">{t.count}</th>
            <th className="p-2 text-start">{t.rate}</th>
            <th className="p-2 text-start">{t.costPer}</th>
            <th className="p-2 text-start">{t.source}</th>
          </tr>
        </thead>
        <tbody>
          {stages.map((stage) => (
            <tr key={stage.key} className="border-t border-border">
              <td className="p-2">{ar ? stage.label_ar : stage.label_en}</td>
              <td className="p-2 tabular-nums" dir="ltr">
                {stage.count === null ? t.notProvided : stage.count.toLocaleString('en-US')}
              </td>
              <td className="p-2 tabular-nums" dir="ltr">
                {stage.rate_from_previous === null ? '—' : `${(stage.rate_from_previous * 100).toFixed(1)}%`}
              </td>
              <td className="p-2 tabular-nums" dir="ltr">
                {/* Three different sentences: withheld by the link, no spend reported, and a real
                    figure. Collapsing the first two into «—» tells the reader the wrong story. */}
                {stage.cost_hidden
                  ? t.costHidden
                  : stage.cost_per === null
                    ? t.notProvided
                    : formatMetric({ kind: 'value', value: stage.cost_per }, 'cpa', locale, currency)}
              </td>
              <td className="p-2 text-xs text-text-secondary">{stage.source}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** One finding — with the creative, the window, the figures behind it, and what to do. */
