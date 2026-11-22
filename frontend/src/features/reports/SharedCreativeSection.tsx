import { canonicalObjectiveLabel, type CanonicalObjectiveKey } from '@/features/campaigns/canonicalObjectives'
import { AboutThisData } from '@/features/content/AboutThisData'
import { useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { keepPreviousData, useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { AlertTriangle, Image as ImageIcon, Lightbulb, TrendingDown, TrendingUp, Video } from 'lucide-react'
import {
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
import { Modal } from '@/components/ui/Modal'
import { ContentTile } from './live/LiveContent'
import type { ReportAd } from './ReportAdsSection'
import { ReportAdDetail } from './ReportAdDetail'
import { AdPoster } from '@/features/content/AdPoster'
import { DataMetricTable, MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { formatMetric, metricLabel, metricState } from '@/features/content/metrics'
import { creativeMoney } from '@/features/content/creativeMoney'
import { formatMoneyReading, readMoney } from '@/lib/money/contract'
import { marketingPathLabel, objectiveLabel, providerLabel } from '@/features/campaigns/labels'
import { DateField } from '@/components/ui/DateField'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import type { CreativeCard, CreativeMetrics } from '@/features/content/api'
import type { CreativePulse, FatigueAlert, PulseList } from '@/features/content/pulse'
import { Num } from '@/components/ui/Num'
import { PeriodText } from '@/components/ui/PeriodText'
import { formatPeriodRange } from '@/lib/period/formatPeriodLabel'

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
    quality: 'جودة البيانات',
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
    severity: { warning: 'تنبيه', opportunity: 'فرصة', positive: 'تحسّن' },
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
    previousPeriod: 'الفترة السابقة',
    copy: 'نص الإعلان',
    headlineText: 'العنوان',
    body: 'النص',
    cta: 'زر الإجراء',
    destination: 'الرابط الوجهة',
    detailError: 'هذا الإعلان غير متاح في هذا الرابط.',
    notProvided: 'غير مُرسَل',
    winners: 'الأفضل أداءً',
    allContent: 'كل المحتوى',
    inPeriod: 'محتوى في هذه الفترة',
    sortBy: 'ترتيب',
    more: 'عرض المزيد',
    remaining: 'بقي',
    close: 'إغلاق',
    sorts: { spend: 'الإنفاق', conversions: 'النتائج', clicks: 'النقرات', impressions: 'الظهور' },
    bestFor: 'الأفضل',
    health: 'حالة المحتوى',
    spendNoResults: 'إنفاق بدون نتائج',
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
    quality: 'Data quality',
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
    previousPeriod: 'Previous period',
    copy: 'Ad copy',
    headlineText: 'Headline',
    body: 'Body',
    cta: 'Call to action',
    destination: 'Destination URL',
    detailError: 'This ad is not available on this link.',
    notProvided: 'Not provided',
    winners: 'Top performers',
    allContent: 'All content',
    inPeriod: 'creatives in this period',
    sortBy: 'Sort',
    more: 'Show more',
    remaining: 'left',
    close: 'Close',
    sorts: { spend: 'Spend', conversions: 'Results', clicks: 'Clicks', impressions: 'Impressions' },
    bestFor: 'Best',
    health: 'Content health',
    spendNoResults: 'Spend without results',
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
  const [sort, setSort] = useState<string>('')

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

  const summary = useQuery({
    queryKey: ['shared-creative-summary', token, filters],
    queryFn: () => getSharedCreativeSummary(token, filters, password),
    placeholderData: keepPreviousData,
  })

  /*
   * AD-ANALYSIS-VISUAL-001 — «كل المحتوى», the live link's grid: the server's order (spend by default, or the reader's choice
   * among the figures this link shows), 24 at a time with «عرض المزيد». A sort on a figure the link hides is ignored by the server.
   */
  const library = useInfiniteQuery({
    queryKey: ['shared-creatives', token, filters, sort],
    queryFn: ({ pageParam }) => getSharedCreatives(token, { ...filters, per_page: 24, page: pageParam, ...(sort ? { sort } : {}) }, password),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.page * last.per_page < last.total ? last.page + 1 : undefined),
    placeholderData: keepPreviousData,
    // The summary is the whole ceiling read as answers; the library is only asked for by the
    // detailed report, where the reader is actually going to page through cards.
    enabled: form === 'detailed',
  })
  const firstPage = library.data?.pages[0]

  const permissions: CreativePermissions | undefined = summary.data?.permissions ?? firstPage?.permissions
  const rows = useMemo(() => library.data?.pages.flatMap((p) => p.creatives) ?? [], [library.data])
  const total = firstPage?.total ?? rows.length
  /** Every card on the page by id, so the popup opens on the card the reader clicked without waiting for its detail. */
  const knownCards = useMemo(() => {
    const map = new Map<string, CreativeCard>()
    const d = summary.data
    const add = (c: CreativeCard | null | undefined) => { if (c && !map.has(c.id)) map.set(c.id, c) }
    rows.forEach(add)
    if (d) {
      ;[...d.best_by_objective, ...d.best_image, ...d.best_video].forEach((w) => add(w.creative))
      ;[...d.fastest_growing.items, ...d.declining.items].forEach((m) => add(m.creative))
      ;[...d.fatigue.fatigued.items, ...d.fatigue.watch.items, ...d.fatigue.insufficient_data.items].forEach(add)
      d.fatigue.alerts.items.forEach((a) => add(a.creative))
    }
    return map
  }, [rows, summary.data])
  const available = summary.data?.available ?? firstPage?.available

  if (summary.isLoading) return <Skeleton className="mt-6 h-64 w-full" />
  if (summary.isError) return <ErrorState title={t.error} error={summary.error} />
  if (!summary.data || !permissions?.creatives) return null

  const data = summary.data
  const narrow = (patch: SharedCreativeQuery) => setFilters((f) => ({ ...f, ...patch }))

  return (
    <section className="mt-8 grid gap-4" data-testid="shared-creative-section" aria-label={t.heading}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-heading text-lg font-extrabold tracking-tight">{t.heading}</h2>
        <p className="tnum flex flex-wrap items-center gap-2 text-xs text-text-secondary">
          <Num><PeriodText from={data.period.from} to={data.period.to} /></Num>
          {/*
            §17 — the client is the reader most likely to mistake a provider's silence for a fault.

            They did not configure the account, they cannot check the platform, and a «—» beside
            three figures that arrived is exactly the shape of something broken. One sentence, in
            the same words the operator's library uses, so neither surface explains it differently.
          */}
          <AboutThisData locale={locale} testid="shared-creative-about-data" />
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
          {/*
            CONTENT-FILTER-TRUTH-001 — the canonical five, LABELLED, and no path control beside them.

            Two defects on a client-facing surface. The objective picker offered raw provider values
            as their own labels, so a client read «sales», «conversions» and «add_to_cart» in English
            regardless of their language — and the «المسار التسويقي» picker under it asked the same
            question a second time, which is the duplication the owner reported internally.
          */}
          <Picker
            label={t.objective}
            value={filters.objectives?.[0] ?? ''}
            onChange={(v) => narrow({ objectives: v ? [v] : [] })}
            options={available.objectives.map((o) => ({
              value: o.key,
              label: canonicalObjectiveLabel(o.key as CanonicalObjectiveKey, ar ? 'ar' : 'en'),
            }))}
            all={t.all}
          />
        </div>
      )}

      {data.totals.creatives === 0 ? (
        <p className="rounded-xl border border-border bg-surface p-6 text-center text-sm text-text-secondary">{t.empty}</p>
      ) : (
        <>
          {/*
            AD-ANALYSIS-VISUAL-001 (Owner 2026-11-22) — the same reading as the live link's «المحتوى» tab: the ads
            themselves, not lists of names. Each winner is its poster with what it won, the figure it won on and
            the path it won inside; movers are posters with their change; fatigue is a bar of counts and faces.
          */}
          <WinnerCards
            t={t}
            currency={currency}
            onOpen={openDetail}
            groups={[
              { key: 'objective', title: t.bestByObjective, entries: data.best_by_objective },
              { key: 'image', title: t.bestImage, entries: data.best_image, icon: <ImageIcon size={12} /> },
              { key: 'video', title: t.bestVideo, entries: data.best_video, icon: <Video size={12} /> },
            ]}
          />

          <div className="grid gap-4">
            <MoveList title={t.growing} icon={<TrendingUp size={14} className="text-success" />} list={data.fastest_growing} t={t} tone="up" currency={currency} onOpen={openDetail} />
            <MoveList title={t.declining} icon={<TrendingDown size={14} className="text-danger" />} list={data.declining} t={t} tone="down" currency={currency} onOpen={openDetail} />
          </div>

          <FatigueBlock data={data} t={t} currency={currency} permissions={permissions} onOpen={openDetail} />

          {permissions.insights && data.insights && data.insights.items.length > 0 && (
            <InsightList insights={data.insights} t={t} ar={ar} permissions={permissions} />
          )}

          {/*
            CLIENT-DIAGNOSTIC-SEPARATION-001 — the sync strip left the client's link.

            It printed our last-sync clock, a sync date per platform, and the raw data-quality map
            («missing_previews: 3») on the page a client opens to look at their ads. None of the
            three is a fact about their campaign, none of them can be acted on, and the last was raw
            database keys. They live in the operator's Data Quality surface, where they are the
            whole point.
          */}
        </>
      )}

      {form === 'detailed' && (
        <div className="grid gap-3" data-testid="shared-creative-library">
          <div className="flex flex-wrap items-end justify-between gap-2">
            <div>
              <h3 className="text-sm font-bold">{t.allContent}</h3>
              <p className="tnum text-xs text-text-muted"><Num>{`${total} ${t.inPeriod}`}</Num></p>
            </div>
            <label className="flex items-center gap-1.5 text-xs">
              <span className="text-text-muted">{t.sortBy}</span>
              <select
                value={sort}
                onChange={(e) => setSort(e.target.value)}
                aria-label={t.sortBy}
                data-testid="shared-creative-sort"
                className="rounded-lg border border-border bg-surface px-2 py-1 text-xs"
              >
                {(permissions.spend ? ['', 'conversions', 'clicks', 'impressions'] : ['conversions', 'clicks', 'impressions']).map((key) => (
                  <option key={key} value={key}>{t.sorts[(key || 'spend') as keyof Copy['sorts']]}</option>
                ))}
              </select>
            </label>
          </div>

          {library.isLoading ? (
            <Skeleton className="h-48 w-full" />
          ) : rows.length === 0 ? (
            <p className="rounded-xl border border-border bg-surface p-6 text-center text-sm text-text-secondary">{t.empty}</p>
          ) : (
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
              {rows.map((creative) => (
                <Tile key={creative.id} creative={creative} currency={currency} onOpen={openDetail} />
              ))}
            </div>
          )}

          {library.hasNextPage && (
            <button
              type="button"
              onClick={() => library.fetchNextPage()}
              disabled={library.isFetchingNextPage}
              data-testid="shared-creative-more"
              className="tnum justify-self-start rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-primary hover:bg-surface-hover disabled:opacity-50"
            >
              <Num>{`${t.more} — ${t.remaining} ${Math.max(total - rows.length, 0)}`}</Num>
            </button>
          )}
        </div>
      )}

      {/*
        AD-ANALYSIS-VISUAL-001 — one popup for every card in this section, as on the live link: the ad itself, its figures, its copy,
        its funnel and its platforms. Still addressed by `?creative=`, so a refresh or a forwarded link reopens it.
      */}
      {openId !== null && (
        <SharedCreativeDetail
          token={token}
          password={password}
          creativeId={openId}
          filters={filters}
          currency={currency}
          permissions={permissions}
          locale={locale}
          t={t}
          known={knownCards.get(openId) ?? null}
          onBack={() => openDetail(null)}
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

/** A ranking figure: the change of a mover, signed, as a whole percent. */
const signedPct = (value: number) => `${value > 0 ? '+' : value < 0 ? '−' : ''}${Math.round(Math.abs(value) * 100)}%`

/** The marketing path, in the card family the live link's tiles choose their figures by. */
const FAMILY_OF_PATH: Record<string, string> = { conversion: 'sales', sales: 'sales', traffic: 'traffic', leads: 'leads', awareness: 'awareness', engagement: 'engagement', app: 'app', video: 'video' }

/**
 * AD-ANALYSIS-VISUAL-001 — a client creative, read as the live link's content row.
 *
 * The figures are the server's (already redacted for this link): a hidden metric is absent from `metrics`, so the tile simply
 * does not build it. `content_key` is the creative's id, which is what makes the tile open this section's popup.
 */
function toReportAd(c: CreativeCard): ReportAd {
  const m = (c.metrics ?? {}) as unknown as Record<string, unknown>

  return {
    ...m,
    id: c.id,
    content_key: c.id,
    name: c.name,
    provider: c.provider,
    objective: c.objective,
    preview: c.preview,
    status: c.status,
    card_family: FAMILY_OF_PATH[c.path] ?? null,
    metrics: c.metrics,
  } as ReportAd
}

/** The live link's content card, with what this list says about it laid over the cover. */
function Tile({
  creative,
  currency,
  onOpen,
  rank,
  chips = [],
  note,
}: {
  creative: CreativeCard | null | undefined
  currency: string
  onOpen: (id: string) => void
  rank?: number
  chips?: Array<{ text: React.ReactNode; tone: 'success' | 'danger' | 'brand'; icon?: React.ReactNode }>
  note?: string | null
}) {
  const { locale } = useUi()
  const content = useMemo(() => (creative ? toReportAd(creative) : null), [creative])
  if (!creative || !content) return null
  const tone = { success: 'bg-success', danger: 'bg-danger', brand: 'bg-brand-600' }

  return (
    <div className="relative grid min-w-0 content-start gap-1" data-testid={`shared-tile-${creative.id}`}>
      <ContentTile content={content} locale={locale} currency={currency || null} onOpen={() => onOpen(creative.id)} rank={rank} />
      {chips.length > 0 && (
        <div className="pointer-events-none absolute end-5 top-5 flex max-w-[70%] flex-col items-end gap-1">
          {chips.map((chip, i) => (
            <span key={i} className={`tnum inline-flex max-w-full items-center gap-1 truncate rounded-full ${tone[chip.tone]} px-2 py-0.5 text-[10px] font-bold text-white shadow`}>
              {chip.icon}{chip.text}
            </span>
          ))}
        </div>
      )}
      {note && <p className="rounded-md bg-warning/10 px-2 py-1 text-[11px] font-semibold text-warning">{note}</p>}
    </div>
  )
}

/**
 * «الأفضل أداءً» — each winner once, as its live-link card, with every answer it won laid on its cover (an ad that is the best
 * image AND the best for sales is one card with two badges, not two cards). Opens the popup like every card here.
 */
function WinnerCards({
  groups,
  t,
  currency,
  onOpen,
}: {
  groups: Array<{ key: string; title: string; entries: SharedWinner[]; icon?: React.ReactNode }>
  t: Copy
  currency: string
  onOpen: (id: string) => void
}) {
  const { locale } = useUi()
  const merged = new Map<string, { creative: CreativeCard; badges: Array<{ text: string; icon?: React.ReactNode }>; thin: boolean; hidden: boolean }>()
  for (const group of groups) {
    for (const entry of group.entries) {
      const creative = entry.creative
      if (!creative) continue
      const text = 'objective' in entry && entry.objective ? `${t.bestFor} · ${objectiveLabel(entry.objective, locale)}` : group.title
      const row = merged.get(creative.id) ?? { creative, badges: [], thin: false, hidden: false }
      row.badges.push({ text, icon: group.icon })
      row.thin = row.thin || entry.low_evidence === true
      row.hidden = row.hidden || entry.value_hidden === true
      merged.set(creative.id, row)
    }
  }
  if (merged.size === 0) return null

  return (
    <div className="grid gap-2" data-testid="shared-winners">
      <h3 className="flex items-center gap-1.5 text-sm font-bold">{t.winners}</h3>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
        {[...merged.values()].map((row, i) => (
          <div key={row.creative.id} data-testid="winner-card" className="min-w-0">
            <Tile
              creative={row.creative}
              currency={currency}
              onOpen={onOpen}
              rank={i + 1}
              chips={row.badges.map((b) => ({ text: b.text, icon: b.icon, tone: 'success' as const }))}
              note={row.thin ? t.thin : null}
            />
            {row.hidden && <p className="mt-1 text-[11px] text-text-muted">{t.hidden}</p>}
          </div>
        ))}
      </div>
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

/** Movers as their live-link cards, the change on the cover — the arrow always, the number only where the link shows it. */
function MoveList({
  title,
  icon,
  list,
  t,
  tone,
  currency,
  onOpen,
}: {
  title: string
  icon: React.ReactNode
  list: PulseList<SharedCreativeMove>
  t: Copy
  tone: 'up' | 'down'
  currency: string
  onOpen: (id: string) => void
}) {
  const { locale } = useUi()
  if (list.items.length === 0) return null
  const Arrow = tone === 'up' ? TrendingUp : TrendingDown

  return (
    <div className="grid gap-2" data-testid={`shared-moves-${tone}`}>
      <h3 className="flex items-center gap-1.5 text-sm font-bold">
        {icon}{title}
        {list.total > list.shown && <span className="tnum ms-auto text-[11px] font-normal text-text-muted"><Num>{`${list.shown} ${t.of} ${list.total}`}</Num></span>}
      </h3>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
        {list.items.map((move, i) => {
          const hidden = move.value_hidden === true || move.change === null || move.change === undefined
          const label = metricLabel(move.metric, locale)

          return (
            <Tile
              key={`${move.creative?.id ?? i}`}
              creative={move.creative}
              currency={currency}
              onOpen={onOpen}
              chips={[{
                tone: tone === 'up' ? 'success' : 'danger',
                icon: <Arrow size={10} aria-hidden />,
                text: hidden ? label : <><span>{label}</span> <span dir="ltr"><Num>{signedPct(move.change as number)}</Num></span></>,
              }]}
            />
          )
        })}
      </div>
    </div>
  )
}

/** A fatigue signal's own words: the metric it moved on, never the engine's key. */
function signalLabel(key: string, t: Copy, locale: 'ar' | 'en'): string {
  if (key === 'spend_without_results') return t.spendNoResults
  return metricLabel(key.replace(/_(decay|down|up)$/, ''), locale)
}

type Signal = { key: string; direction: string; change: number }
const isSignal = (s: unknown): s is Signal =>
  typeof s === 'object' && s !== null && typeof (s as Signal).key === 'string' && typeof (s as Signal).change === 'number'

function FatigueBlock({
  data,
  t,
  currency,
  permissions,
  onOpen,
}: {
  data: { fatigue: CreativePulse['fatigue'] }
  t: Copy
  currency: string
  permissions: CreativePermissions
  onOpen: (id: string) => void
}) {
  const { locale } = useUi()
  const fatigue = data.fatigue
  const ar = locale === 'ar'
  // One reader for the money, so this figure cannot disagree with the operator's copy of it.
  const spendAtRisk = readMoney(fatigue.spend_at_risk, 'spend', currency ?? null, ar)

  const buckets = [
    { key: 'fatigued', title: t.fatigued, list: fatigue.fatigued, bar: 'bg-danger', text: 'text-danger', border: 'border-danger/40' },
    { key: 'watch', title: t.watch, list: fatigue.watch, bar: 'bg-warning', text: 'text-warning', border: 'border-warning/40' },
    { key: 'insufficient', title: t.insufficient, list: fatigue.insufficient_data, bar: 'bg-text-muted', text: 'text-text-secondary', border: 'border-border' },
  ]
  const total = buckets.reduce((sum, b) => sum + b.list.total, 0)

  return (
    <div className="grid gap-3">
      {total > 0 && (
        <div className="rounded-2xl border border-border bg-surface p-3" data-testid="fatigue-overview">
          <h3 className="mb-2 text-sm font-bold">{t.health}</h3>
          <div className="flex h-3 w-full overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
            {buckets.filter((b) => b.list.total > 0).map((b) => (
              <span key={b.key} className={`${b.bar} h-full`} style={{ width: `${(b.list.total / total) * 100}%` }} />
            ))}
          </div>
          <div className="mt-3 grid gap-3 md:grid-cols-3">
            {buckets.map((b) => (
              <div key={b.key} className={`rounded-xl border ${b.border} p-2.5`}>
                <h4 className="mb-2 flex items-baseline justify-between text-xs font-bold">
                  <span className="flex items-center gap-1.5"><span className={`h-2 w-2 rounded-full ${b.bar}`} aria-hidden />{b.title}</span>
                  <span className={`tnum text-lg font-extrabold ${b.text}`} dir="ltr"><Num>{String(b.list.total)}</Num></span>
                </h4>
                {b.list.items.length > 0 && (
                  <ul className="grid grid-cols-4 gap-1.5">
                    {b.list.items.slice(0, 4).map((c) => (
                      <li key={c.id} className="min-w-0">
                        <button type="button" onClick={() => onOpen(c.id)} title={c.name} className="block w-full min-w-0 text-start" data-testid={`fatigue-thumb-${c.id}`}>
                          <AdPoster preview={c.preview} name={c.name} className="aspect-square w-full rounded-md" fit="cover" forClient provider={c.provider} />
                          <p dir="auto" className="mt-0.5 truncate text-[10px] text-text-secondary">{c.name}</p>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {fatigue.alerts.items.length > 0 && (
        <div className="rounded-2xl border border-danger/40 bg-danger/5 p-3" data-testid="fatigue-alerts">
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
          <ul className="grid gap-2 md:grid-cols-2">
            {fatigue.alerts.items.map((alert: FatigueAlert, i: number) => {
              const signals = (alert.signals as unknown[]).filter(isSignal)
              const note = ar ? alert.note_ar : alert.note_en

              return (
                <li key={i}>
                  <button
                    type="button"
                    onClick={() => alert.creative && onOpen(alert.creative.id)}
                    className="flex w-full min-w-0 items-start gap-2.5 rounded-xl border border-danger/20 bg-surface p-2 text-start transition-colors hover:border-danger/50"
                  >
                    <AdPoster preview={alert.creative?.preview} name={alert.creative?.name ?? '—'} className="h-16 w-16 shrink-0 rounded-lg" fit="cover" forClient provider={alert.creative?.provider ?? null} />
                    <span className="grid min-w-0 flex-1 gap-1.5">
                      <b dir="auto" className="truncate text-xs font-semibold">{alert.creative?.name ?? '—'}</b>
                      {/* The EVIDENCE, beside the verdict — «fatigued» with nothing behind it is a label. */}
                      {signals.length > 0 ? (
                        <span className="flex flex-wrap gap-1" data-testid="fatigue-signals">
                          {signals.map((sg) => (
                            <span key={sg.key} className={`tnum inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ${sg.direction === 'worse' ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success'}`}>
                              {signalLabel(sg.key, t, locale)}
                              <span dir="ltr"><Num>{signedPct(sg.change)}</Num></span>
                            </span>
                          ))}
                        </span>
                      ) : note ? (
                        <span className="text-[11px] text-text-secondary">{note}</span>
                      ) : null}
                    </span>
                  </button>
                </li>
              )
            })}
          </ul>
        </div>
      )}
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
              {item.provider && <Pair k={t.platform} v={providerLabel(item.provider, ar ? 'ar' : 'en')} />}
              {item.campaign_name && <Pair k={t.campaign} v={item.campaign_name} />}
              <Pair k={t.period} v={formatPeriodRange(item.period.from, item.period.to, ar ? 'ar' : 'en')} />
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
  known,
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
  /** The card the reader clicked, so the popup opens on it at once; absent when the address alone named the ad. */
  known?: CreativeCard | null
  onBack: () => void
}) {
  const ar = locale === 'ar'
  void permissions

  const detail = useQuery({
    queryKey: ['shared-creative-detail', token, creativeId, filters],
    queryFn: () => getSharedCreative(token, creativeId, filters, password),
  })

  const card: CreativeCard | null = (detail.data?.creative as unknown as CreativeCard | undefined) ?? known ?? null
  const ad = useMemo(() => (card ? toReportAd(card) : null), [card])

  /*
   * AD-ANALYSIS-VISUAL-001 — the live link's popup, `ReportAdDetail`: the ad on its own stage (film, carousel, zoom and download
   * as the link allows), its figures, then this link's analysis below — copy, figures, funnel and platforms. Before a card is
   * known (an address opened cold) a small sheet says the ad is loading or is not on this link.
   */
  if (ad === null) {
    return (
      <Modal open onClose={onBack} size="md" title={t.details}>
        <div data-testid="shared-creative-detail">
          {detail.isError ? <ErrorState title={t.detailError} error={detail.error} /> : <Skeleton className="h-64 w-full" />}
        </div>
      </Modal>
    )
  }

  const data = detail.data
  const creative = data?.creative
  const funnel = data?.funnel

  return (
    <ReportAdDetail ad={ad} currency={currency || null} locale={locale} onClose={onBack}>
      <div className="grid gap-3" data-testid="shared-creative-detail">
        {detail.isLoading ? (
          <Skeleton className="h-40 w-full" />
        ) : detail.isError || !data || !creative ? (
          <ErrorState title={t.detailError} error={detail.error} />
        ) : (
          <>
            <div className="rounded-xl border border-border bg-surface p-3">
              <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs sm:grid-cols-3">
                <Pair k={t.platform} v={providerLabel(creative.provider, locale)} />
                <Pair k={t.campaign} v={creative.campaign_name ?? t.notProvided} />
                <Pair k={t.objective} v={creative.objective ? objectiveLabel(creative.objective, locale) : t.notProvided} />
                <Pair k={t.path} v={marketingPathLabel(creative.path, locale)} />
                <Pair k={t.period} v={formatPeriodRange(data.period.from, data.period.to, ar ? 'ar' : 'en')} />
                <Pair k={t.previousPeriod} v={formatPeriodRange(data.previous_period.from, data.previous_period.to, ar ? 'ar' : 'en')} />
                {/*
                  Our clock was here too, twice, among the facts of one ad — «آخر مزامنة» and «آخر تحديث
                  من المصدر». Removed for the same reason, and because sitting in a list of campaign facts
                  implied they were campaign facts.
                */}
              </dl>
            </div>

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
              {/*
                Through the primitive — TABLE-NUMERIC-ALIGNMENT-001.

                Every column was `text-start`, so under RTL the figures hugged the right edge while the
                report deck's platform table pushed its figures to the left. One client, two documents,
                two answers to where a number belongs.

                The cost column stays a NODE rather than a money kind, because it has three readings and
                only one of them is a number: withheld by this link, never reported, or a real figure.
                Collapsing the first two into the primitive's dash would tell the wrong story — «your
                agency hid this» and «the platform never sent it» are not the same sentence.
              */}
              {!funnel || funnel.stages.length === 0 ? (
                <p className="text-xs text-text-secondary">{t.funnelNone}</p>
              ) : (
                <DataMetricTable
                  columns={[
                    { key: 'stage', label: t.stage, kind: 'text' },
                    { key: 'count', label: t.count, kind: 'number' },
                    { key: 'rate', label: t.rate, kind: 'text' },
                    { key: 'cost', label: t.costPer, kind: 'text' },
                  ]}
                  rows={funnel.stages.map((stage) => ({
                    stage: ar ? stage.label_ar : stage.label_en,
                    count: stage.count,
                    rate: <span dir="ltr">{pct(stage.rate_from_previous)}</span>,
                    cost: (
                      <span dir="ltr">
                        {stage.cost_hidden
                          ? t.notShown
                          : stage.cost_per === null
                            ? t.notProvided
                            : formatMetric({ kind: 'value', value: stage.cost_per }, 'cpa', locale, currency)}
                      </span>
                    ),
                  }))}
                />
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
          </>
        )}
      </div>
    </ReportAdDetail>
  )
}

/**
 * CLIENT-REPORT-MONEY-REDACTION-001 — is this reader allowed to see this money figure?
 *
 * Not «did the operator tick a box» — the PAYLOAD's own answer. `SharedCreativeView::redactRow`
 * removes a hidden metric from `metrics` and filters it out of `headline_metrics`, for a stated
 * reason: «a key present and empty tells a reader that a value exists and is being kept from them».
 * So a page that renders only what it was sent cannot bypass the permission, and asking the payload
 * rather than a second flag is what makes that structural instead of merely careful.
 *
 * A withheld figure is permitted and PRESENT — `spend` is null by FX-001's design with the real
 * amount in `spend_original` — so the original is part of the question, not a fallback to it.
 */
const moneyPermitted = (metrics: CreativeMetrics | null, key: 'spend' | 'revenue'): boolean =>
  metrics !== null && (key in metrics || `${key}_original` in metrics)

/**
 * One cell's worth of a creative's figure, money read the way every other surface reads it.
 *
 * Money goes through `creativeMoney` → `readMoney`, which distinguishes converted, original-only,
 * a measured zero, and genuinely unavailable. `metricState` sees only the CONVERTED column, so on
 * production — every Snapchat account USD with no USD→SAR rate — it called a real 412.50 USD «No
 * data» on the one document a client keeps. Counts and ratios stay on `metricState`, which is right
 * for them: it already separates a measured zero from «the platform does not send this».
 */
const figureText = (
  metrics: CreativeMetrics | null,
  key: string,
  locale: 'ar' | 'en',
  currency: string | null,
): string =>
  key === 'spend' || key === 'revenue'
    ? creativeMoney(metrics, key, currency, locale).text
    : formatMetric(metricState(metrics, key), key, locale, currency)


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
    () => {
      const chosen = [...new Set(creatives.flatMap((c) => c.headline_metrics))].filter((key) => key !== 'spend')

      /*
       * Spend leads the comparison for the same reason it leads the tile, and is present on the same
       * terms: ANY creative in the set that is permitted its spend earns the column, and the rows
       * that were not sent theirs render an absence in it rather than a figure.
       */
      return creatives.some((c) => moneyPermitted(c.metrics, 'spend'))
        ? ['spend', ...chosen.slice(0, 6)]
        : chosen.slice(0, 7)
    },
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
      <span key={key} dir="ltr">{figureText(creative.metrics, key, locale, currency)}</span>
    )),
  ])

  /*
   * The raw figures the cells were rendered from, positionally matched — a formatted cell cannot be
   * compared, and «Not provided» must sort as an absence rather than as the string it prints.
   */
  const values: SortValues[] = creatives.map((creative) => [
    creative.name,
    ...columns.map((key) => {
      /*
       * A withheld figure sorts by its ORIGINAL amount, not as an absence.
       *
       * The cells and the sort keys have to answer the same question or the column orders by
       * something the reader cannot see. `metricState` returned null for every withheld row, so a
       * table of Snapchat creatives — real money, no rate — sorted as though none of them had spent
       * anything. Cross-currency ordering is the honest limit here: a column mixing converted and
       * original amounts is ordered within each kind, which is what the rows themselves say.
       */
      if (key === 'spend' || key === 'revenue') {
        const reading = readMoney(creative.metrics ?? undefined, key, currency, locale === 'ar')

        return reading.kind === 'converted' || reading.kind === 'withheld' || reading.kind === 'zero'
          ? reading.amount
          : null
      }

      const state = metricState(creative.metrics, key)

      return state.kind === 'value' ? state.value : null
    }),
  ])

  return <MetricTable head={head} rows={rows} values={values} />
}
