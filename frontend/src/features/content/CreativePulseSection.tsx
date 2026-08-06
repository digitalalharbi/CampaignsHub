import { useMemo, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ArrowDownRight, ArrowUpRight, ChevronLeft, ChevronRight, ImageIcon, PlayCircle, TriangleAlert } from 'lucide-react'
import { CreativeInsightCard } from './CreativeInsightCard'
import { getCreativePulse, type CreativeMove, type CreativeWinner, type PathComparison, type SpendByKind } from './pulse'
import { formatMetric, metricLabel, metricState } from './metrics'
import { imageLoading } from './format'
import {
  libraryQueryString,
  type CreativeCard,
  type CreativeMetrics,
  type LibraryFilterOptions,
  type LibraryQuery,
} from './api'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { marketingPathLabel, objectiveLabel, providerLabel } from '@/features/campaigns/labels'

/**
 * §15.11 — creative analysis inside the dashboard, in `/app` and `/agency`.
 *
 * ## It is the same query as the library
 *
 * The section is handed the dashboard's own filters and window and sends them to `/creatives/pulse`,
 * which runs the library's query and aggregates it. So changing the platform filter above changes
 * these cards, and every card links into the library carrying the filters it was computed under —
 * the figure the reader clicked and the page they land on are the same selection.
 *
 * ## Nothing here is «the best creative»
 *
 * Every ranking names the metric it used and the marketing path it was computed inside, because
 * those are the two things that make it checkable. An account running awareness and sales together
 * has two best videos and they are best at different things; collapsing them into one card would be
 * the blended-CPA defect §14 exists to prevent, wearing a thumbnail.
 *
 * ## No video element renders here
 *
 * A dashboard is the worst place to mount players: it is the page most often left open. Posters
 * only, and a creative opens in the library's viewer where a player is mounted on demand.
 */

const COPY = {
  ar: {
    title: 'تحليل المحتوى الإعلاني',
    subtitle: 'من الأرقام نفسها التي تقرأها المكتبة والتقارير — لا مصدر ثانٍ.',
    openLibrary: 'فتح المكتبة',
    empty: 'لا توجد محتويات ضمن هذا التحديد.',
    error: 'تعذّر تحميل تحليل المحتوى.',
    bestByObjective: 'الأفضل حسب هدف الحملة',
    bestImage: 'أفضل صورة',
    bestVideo: 'أفضل فيديو',
    growing: 'الأسرع نموًا',
    declining: 'المحتويات المتراجعة',
    fatigue: 'حالة إجهاد المحتوى',
    alerts: 'تنبيهات الإجهاد',
    spendAtRisk: 'إنفاق مستمر على محتوى مُجهَد',
    spendByKind: 'توزيع الإنفاق حسب نوع المحتوى',
    imageVsVideo: 'الصور مقابل الفيديو',
    bestPlatform: 'أفضل منصة لكل محتوى',
    lastSync: 'آخر مزامنة',
    never: 'لم تتم بعد',
    creatives: 'محتوى',
    of: 'من',
    showing: 'المعروض',
    noWinner: 'لا توجد بيانات كافية لترشيح محتوى.',
    lowEvidence: 'ترشيح مبدئي — لم يبلغ أي محتوى الحد الأدنى من الظهور.',
    evidenceNote: (n: string) => `مرشَّح من ${n} محتوى`,
    minImpressions: (n: string) => `الحد الأدنى للترشيح: ${n} ظهور`,
    path: 'المسار',
    metric: 'المؤشر',
    improved: 'تحسّن',
    worsened: 'تراجع',
    image: 'صورة',
    video: 'فيديو',
    carousel: 'دائري',
    other: 'أخرى',
    withoutMetrics: 'بلا أرقام في هذه الفترة',
    withheld: 'روابط محجوبة لحماية بيانات الاعتماد',
    drill: 'التفصيل',
    findings: 'ما تقوله الأرقام',
    findingsHint: 'ملاحظات مبنية على هذه الفترة ومقارنتها بالسابقة — المعروض من الإجمالي:',
    platform: 'المنصة',
    campaign: 'الحملة',
    adSet: 'المجموعة الإعلانية',
    ad: 'الإعلان',
    creative: 'المحتوى',
    all: 'الكل',
    period: 'الفترة',
    client: 'العميل',
    project: 'المشروع',
    objective: 'الهدف',
    kind: 'نوع المحتوى',
    lastDays: (n: string) => `آخر ${n} يومًا`,
    provisional: 'مبدئي',
    applied: 'مطبَّق',
    noPreview: 'لا تتوفر معاينة',
    tie: 'لا فارق',
    states: {
      improving: 'يتحسّن',
      stable: 'مستقر',
      watch: 'يحتاج مراقبة',
      fatigued: 'مُجهَد',
      insufficient_data: 'بيانات غير كافية',
    } as Record<string, string>,
  },
  en: {
    title: 'Creative analysis',
    subtitle: 'From the same figures the library and the reports read — never a second source.',
    openLibrary: 'Open the library',
    empty: 'No creatives match this selection.',
    error: 'The creative analysis could not be loaded.',
    bestByObjective: 'Best by campaign objective',
    bestImage: 'Best image',
    bestVideo: 'Best video',
    growing: 'Fastest growing',
    declining: 'Declining',
    fatigue: 'Creative fatigue',
    alerts: 'Fatigue alerts',
    spendAtRisk: 'Still spending on fatigued creatives',
    spendByKind: 'Spend by creative type',
    imageVsVideo: 'Images vs videos',
    bestPlatform: 'Best platform per creative',
    lastSync: 'Last sync',
    never: 'Not yet',
    creatives: 'creatives',
    of: 'of',
    showing: 'Showing',
    noWinner: 'Not enough data to name a creative.',
    lowEvidence: 'Provisional — no creative reached the minimum impressions.',
    evidenceNote: (n: string) => `Chosen from ${n} creatives`,
    minImpressions: (n: string) => `Minimum to qualify: ${n} impressions`,
    path: 'Path',
    metric: 'Metric',
    improved: 'improved',
    worsened: 'declined',
    image: 'Image',
    video: 'Video',
    carousel: 'Carousel',
    other: 'Other',
    withoutMetrics: 'No figures in this period',
    withheld: 'Links withheld to protect credentials',
    drill: 'Drill down',
    findings: 'What the figures say',
    findingsHint: 'Findings from this period against the previous one — showing:',
    platform: 'Platform',
    campaign: 'Campaign',
    adSet: 'Ad set',
    ad: 'Ad',
    creative: 'Creative',
    all: 'All',
    period: 'Period',
    client: 'Client',
    project: 'Project',
    objective: 'Objective',
    kind: 'Creative type',
    lastDays: (n: string) => `Last ${n} days`,
    provisional: 'provisional',
    applied: 'Filtered by',
    noPreview: 'No preview available',
    tie: 'No difference',
    states: {
      improving: 'Improving',
      stable: 'Stable',
      watch: 'Watch',
      fatigued: 'Fatigued',
      insufficient_data: 'Insufficient data',
    } as Record<string, string>,
  },
}

const KIND_LABEL = (kind: string, t: (typeof COPY)['ar']) =>
  kind === 'image' ? t.image : kind === 'video' ? t.video : kind === 'carousel' ? t.carousel : t.other

/** Latin digits in both languages, per the product's standing rule. */
const num = (n: number | null | undefined) =>
  typeof n === 'number' ? n.toLocaleString('en-US', { maximumFractionDigits: 0 }) : '—'

const money = (n: number | null | undefined, ar: boolean) =>
  typeof n === 'number' ? `${n.toLocaleString('en-US', { maximumFractionDigits: 0 })} ${ar ? 'ر.س' : 'SAR'}` : '—'

const percent = (n: number) => `${(n * 100).toFixed(0)}%`

function value(metrics: CreativeMetrics | null, key: string, locale: 'ar' | 'en'): string {
  return formatMetric(metricState(metrics, key), key, locale)
}

/** The axes this section may render controls for. The host owns the rest. */
export type PulseAxis = 'period' | 'clients' | 'projects' | 'providers' | 'objectives' | 'paths' | 'kinds'

export interface CreativePulseSectionProps {
  /** The host dashboard's own filters and window — sent verbatim, so both surfaces agree. */
  filters: LibraryQuery
  projectId?: string | null
  /** `/app/content` or `/agency/content` — resolved by the caller's portal, never hard-coded here. */
  libraryPath: string
  /**
   * Which filters the SECTION renders for itself.
   *
   * `/app`'s dashboard already has a period, a platform filter and an objective above it, so it
   * hands those down and asks only for the axes it does not own. `/agency`'s dashboard has none at
   * all, so the section carries them — otherwise «changing a filter changes the results» would be
   * true on one portal and not the other.
   */
  axes?: PulseAxis[]
}

const PERIODS = [7, 30, 90] as const

function isoDaysAgo(days: number): string {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return d.toISOString().slice(0, 10)
}

export function CreativePulseSection({ filters, projectId, libraryPath, axes = [] }: CreativePulseSectionProps) {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']

  /*
   * The section's OWN narrowing, applied over the host's.
   *
   * Kept separate rather than merged into one state so the two cannot fight: the host's filters are
   * the ceiling of what this section shows, and a control here can only narrow inside it. An axis
   * with no selection is deleted rather than sent empty — `providers[]=` is a bound of «nothing» to
   * a fail-closed server, while an absent axis is «unbounded», and they are not the same request.
   */
  const [own, setOwn] = useState<LibraryQuery>({})
  const [days, setDays] = useState(30)

  const query = useMemo<LibraryQuery>(
    () => ({
      ...filters,
      ...own,
      ...(axes.includes('period') ? { from: isoDaysAgo(days - 1), to: isoDaysAgo(0) } : {}),
    }),
    [filters, own, axes, days],
  )

  const narrow = (key: keyof LibraryQuery, value: string) =>
    setOwn((prev) => {
      const next = { ...prev }
      if (value === '') delete next[key]
      else (next as Record<string, string[]>)[key] = [value]
      return next
    })

  const pulse = useQuery({
    queryKey: ['creative-pulse', projectId ?? null, query],
    queryFn: () => getCreativePulse(query, projectId),
    placeholderData: keepPreviousData,
  })

  /**
   * A link into the library that carries THIS section's filters plus one more narrowing.
   *
   * Built from `filters` rather than from scratch: a drill-down that dropped the dashboard's period
   * would land on a different set of creatives than the card the reader clicked, which is the exact
   * way a drill-down stops being trusted.
   */
  const drill = useMemo(
    () => (extra: LibraryQuery & { creative?: string }) => {
      const { creative, ...rest } = extra
      const qs = libraryQueryString({ ...query, ...rest })

      /*
       * §15.6 — a card naming ONE creative opens that creative's page, not a filtered library.
       *
       * It used to land on the library with `?creative=<id>`, which opened the quick viewer: the
       * reader asked «why is my best video my best video» and got a picture. The page answers that
       * question. The filters still travel, because the page's Back link rebuilds the shelf the
       * reader took it off.
       */
      return creative ? `${libraryPath}/${creative}${qs}` : `${libraryPath}${qs}`
    },
    [query, libraryPath],
  )

  if (pulse.isPending) {
    return (
      <section className="flex flex-col gap-4 rounded-2xl border border-border bg-surface p-5">
        <Skeleton className="h-6 w-56" />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Skeleton className="h-40" />
          <Skeleton className="h-40" />
          <Skeleton className="h-40" />
        </div>
      </section>
    )
  }

  if (pulse.isError || !pulse.data) {
    return (
      <section className="rounded-2xl border border-border bg-surface p-5">
        <ErrorState title={t.error} error={pulse.error} onRetry={() => void pulse.refetch()} />
      </section>
    )
  }

  const data = pulse.data

  return (
    <section className="flex flex-col gap-5" data-testid="creative-pulse">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-text">{t.title}</h2>
          <p className="text-sm text-text-muted">{t.subtitle}</p>
        </div>
        <Link
          to={drill({})}
          className="inline-flex items-center gap-1 rounded-xl border border-border px-3 py-2 text-sm font-medium text-brand-700 transition-colors hover:border-brand-400"
        >
          {t.openLibrary}
          {ar ? <ChevronLeft className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
        </Link>
      </header>

      {axes.length > 0 && (
        <div className="flex flex-wrap items-end gap-3 rounded-2xl border border-border bg-surface p-4">
          {axes.includes('period') && (
            <Field label={t.period}>
              <select
                aria-label={t.period}
                value={days}
                onChange={(e) => setDays(Number(e.target.value))}
                className="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text"
              >
                {PERIODS.map((p) => (
                  <option key={p} value={p}>
                    {t.lastDays(String(p))}
                  </option>
                ))}
              </select>
            </Field>
          )}

          {axes.includes('clients') && (
            <Select
              label={t.client}
              all={t.all}
              value={own.client_ids?.[0] ?? ''}
              onChange={(v) => narrow('client_ids', v)}
              options={data.filters.clients.map((c) => ({ value: c.id, label: c.name }))}
            />
          )}

          {axes.includes('projects') && (
            <Select
              label={t.project}
              all={t.all}
              value={own.project_ids?.[0] ?? ''}
              onChange={(v) => narrow('project_ids', v)}
              options={data.filters.projects.map((p) => ({ value: p.id, label: p.name }))}
            />
          )}

          {axes.includes('providers') && (
            <Select
              label={t.platform}
              all={t.all}
              value={own.providers?.[0] ?? ''}
              onChange={(v) => narrow('providers', v)}
              options={data.filters.providers.map((p) => ({ value: p, label: providerLabel(p, locale) }))}
            />
          )}

          {axes.includes('objectives') && (
            <Select
              label={t.objective}
              all={t.all}
              value={own.objectives?.[0] ?? ''}
              onChange={(v) => narrow('objectives', v)}
              options={data.filters.objectives.map((o) => ({ value: o, label: objectiveLabel(o, locale) }))}
            />
          )}

          {axes.includes('paths') && (
            <Select
              label={t.path}
              all={t.all}
              value={own.paths?.[0] ?? ''}
              onChange={(v) => narrow('paths', v)}
              options={data.filters.paths.map((p) => ({ value: p, label: marketingPathLabel(p, locale) }))}
            />
          )}

          {axes.includes('kinds') && (
            <Select
              label={t.kind}
              all={t.all}
              value={own.kinds?.[0] ?? ''}
              onChange={(v) => narrow('kinds', v)}
              options={data.filters.kinds.map((k) => ({ value: k, label: KIND_LABEL(k, t) }))}
            />
          )}
        </div>
      )}

      {/*
        What is narrowing this section, in words.

        Found on `/app`, where the dashboard's filters live behind a «customise» dialog: the section
        read «no creatives match this selection» on a workspace with four creatives, because the page
        defaults to the awareness objective and that workspace runs only sales. Both statements were
        true and the reader could see neither the cause nor the cure. The same rule the folded filter
        bars already follow (SIMPLIFY-001) — a filter you cannot see must be stated beside its result.
      */}
      <AppliedLine query={query} options={data.filters} t={t} locale={ar ? 'ar' : 'en'} />

      {data.totals.creatives === 0 ? (
        <p className="rounded-2xl border border-border bg-surface p-6 text-center text-sm text-text-muted">{t.empty}</p>
      ) : (
        <>
          <FreshnessLine data={data} t={t} ar={ar} />

          <div className="grid gap-4 lg:grid-cols-3">
            {data.best_by_objective.slice(0, 1).map((best) => (
              <WinnerCard
                key={`obj-${best.objective ?? 'none'}`}
                heading={`${t.bestByObjective} — ${best.objective ? objectiveLabel(best.objective, locale) : t.other}`}
                winner={best}
                path={best.path}
                t={t}
                locale={ar ? 'ar' : 'en'}
                drill={drill}
              />
            ))}
            {data.best_image.slice(0, 1).map((best) => (
              <WinnerCard
                key={`img-${best.path}`}
                heading={t.bestImage}
                icon={<ImageIcon className="h-4 w-4" />}
                winner={best}
                path={best.path}
                t={t}
                locale={ar ? 'ar' : 'en'}
                drill={drill}
              />
            ))}
            {data.best_video.slice(0, 1).map((best) => (
              <WinnerCard
                key={`vid-${best.path}`}
                heading={t.bestVideo}
                icon={<PlayCircle className="h-4 w-4" />}
                winner={best}
                path={best.path}
                t={t}
                locale={ar ? 'ar' : 'en'}
                drill={drill}
              />
            ))}
          </div>

          {data.best_by_objective.length > 1 && (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {data.best_by_objective.slice(1).map((best) => (
                <Link
                  key={`obj-more-${best.objective ?? 'none'}`}
                  to={drill({ objectives: best.objective ? [best.objective] : undefined, creative: best.creative.id })}
                  className="flex items-center justify-between gap-3 rounded-2xl border border-border bg-surface p-4 text-sm transition-colors hover:border-brand-400"
                >
                  <span className="min-w-0">
                    <span className="block text-xs text-text-muted">
                      {best.objective ? objectiveLabel(best.objective, locale) : t.other}
                    </span>
                    <span className="block truncate font-medium text-text">{best.creative.name}</span>
                  </span>
                  <span className="shrink-0 text-end">
                    <span className="block text-xs text-text-muted">
                      {metricLabel(best.metric, locale)}
                      {/* The provisional marker belongs on the compact rows too: a thin ranking is
                          MORE likely to appear here, because these are the objectives with the
                          least spend behind them. */}
                      {best.low_evidence && ` · ${t.provisional}`}
                    </span>
                    <span className="block font-semibold text-text">{value(best.creative.metrics, best.metric, ar ? 'ar' : 'en')}</span>
                  </span>
                </Link>
              ))}
            </div>
          )}

          {data.fatigue.alerts.total > 0 && (
            <div className="rounded-2xl border border-warning/40 bg-warning/5 p-5">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="flex items-center gap-2 text-sm font-semibold text-text">
                  <TriangleAlert className="h-4 w-4 text-warning" />
                  {t.alerts}
                </h3>
                <p className="text-sm text-text-muted">
                  {t.spendAtRisk}: <span className="font-semibold text-text">{money(data.fatigue.spend_at_risk, ar)}</span>
                </p>
              </div>
              <ul className="mt-3 flex flex-col gap-2">
                {data.fatigue.alerts.items.map((alert) => (
                  <li key={alert.creative.id}>
                    <Link
                      to={drill({ creative: alert.creative.id })}
                      className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-surface p-3 text-sm transition-colors hover:ring-1 hover:ring-brand-400"
                    >
                      <span className="min-w-0">
                        <span className="block truncate font-medium text-text">{alert.creative.name}</span>
                        <span className="block text-xs text-text-muted">{ar ? alert.note_ar : alert.note_en}</span>
                      </span>
                      <span className="shrink-0 font-semibold text-text">{money(alert.spend, ar)}</span>
                    </Link>
                  </li>
                ))}
              </ul>
              <Counted list={data.fatigue.alerts} t={t} />
            </div>
          )}

          {/*
           * §15.10 on the dashboard — the findings the endpoint has always returned.
           *
           * `GET /creatives/pulse` carried `insights` from the day the engine landed and this
           * section drew none of them: an API without a UI, which is the same defect as a page
           * without data. Rendered through the SAME card the creative detail page uses, so a finding
           * cannot be worded one way here and another way on the page it links into.
           *
           * Empty is a legitimate answer and gets no box: an account where nothing moved materially
           * has nothing to be told, and an empty «Findings» panel reads as a broken one.
           */}
          {data.insights.items.length > 0 && (
            <div className="rounded-2xl border border-border bg-surface p-5">
              <h3 className="text-sm font-semibold text-text">{t.findings}</h3>
              <p className="mt-1 text-xs text-text-muted">
                {t.findingsHint}{' '}
                <span dir="ltr">
                  {data.insights.shown}/{data.insights.total}
                </span>
              </p>
              <ul className="mt-3 flex flex-col gap-2">
                {data.insights.items.map((item) => (
                  <CreativeInsightCard
                    key={item.id}
                    item={item}
                    locale={ar ? 'ar' : 'en'}
                    creativeHref={item.creative_id ? drill({ creative: item.creative_id }) : null}
                  />
                ))}
              </ul>
            </div>
          )}

          <div className="grid gap-4 lg:grid-cols-2">
            <MoveList
              title={t.growing}
              tone="success"
              list={data.fastest_growing}
              t={t}
              locale={ar ? 'ar' : 'en'}
              drill={drill}
            />
            <MoveList title={t.declining} tone="danger" list={data.declining} t={t} locale={ar ? 'ar' : 'en'} drill={drill} />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <div className="rounded-2xl border border-border bg-surface p-5">
              <h3 className="text-sm font-semibold text-text">{t.fatigue}</h3>
              <div className="mt-3 flex flex-wrap gap-2">
                {Object.entries(data.fatigue.counts).map(([status, count]) => (
                  <Link
                    key={status}
                    to={drill({ health: status })}
                    className="rounded-xl border border-border px-3 py-2 text-sm transition-colors hover:border-brand-400"
                  >
                    <span className="text-text-muted">{t.states[status] ?? status}</span>{' '}
                    <span className="font-semibold text-text">{num(count)}</span>
                  </Link>
                ))}
              </div>
            </div>

            <SpendSplit rows={data.spend_by_kind} t={t} ar={ar} drill={drill} />
          </div>

          {data.image_vs_video.length > 0 && (
            <div className="rounded-2xl border border-border bg-surface p-5">
              <h3 className="text-sm font-semibold text-text">{t.imageVsVideo}</h3>
              <div className="mt-3 flex flex-col gap-5">
                {data.image_vs_video.map((row) => (
                  <PathTable key={row.path} row={row} t={t} locale={ar ? 'ar' : 'en'} />
                ))}
              </div>
            </div>
          )}

          {data.best_platform.total > 0 && (
            <div className="rounded-2xl border border-border bg-surface p-5">
              <h3 className="text-sm font-semibold text-text">{t.bestPlatform}</h3>
              <ul className="mt-3 flex flex-col gap-3">
                {data.best_platform.items.map((group) => (
                  <li key={group.group_id} className="rounded-xl border border-border p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="truncate text-sm font-medium text-text">{group.name}</span>
                      <span className="text-xs text-text-muted">
                        {marketingPathLabel(group.path, locale)} · {metricLabel(group.metric, locale)}
                        {group.tied && ` · ${t.tie}`}
                      </span>
                    </div>
                    <div className="mt-2 flex flex-wrap gap-2">
                      {group.platforms.map((p) => (
                        <Link
                          key={p.creative_id}
                          to={drill({ providers: [p.provider], creative: p.creative_id })}
                          className={`rounded-lg px-3 py-1.5 text-sm transition-colors ${
                            group.winner !== null && p.provider === group.winner
                              ? 'bg-success/15 font-semibold text-success'
                              : 'border border-border text-text-muted hover:border-brand-400'
                          }`}
                        >
                          {providerLabel(p.provider, locale)}{' '}
                          {p.value === null ? '—' : formatMetric({ kind: 'value', value: p.value }, group.metric, ar ? 'ar' : 'en')}
                        </Link>
                      ))}
                    </div>
                  </li>
                ))}
              </ul>
              <Counted list={data.best_platform} t={t} />
            </div>
          )}
        </>
      )}
    </section>
  )
}

// ---- pieces ---------------------------------------------------------------------------------

type Copy = (typeof COPY)['ar']
type Drill = (extra: LibraryQuery & { creative?: string }) => string

/** The poster — never a `<video>`, and never a fabricated image when the platform withheld one. */
function Poster({ creative, label }: { creative: CreativeCard; label: string }) {
  const src = creative.preview.thumbnail_url ?? creative.preview.image_url

  if (creative.preview.state !== 'available' || !src) {
    return (
      <span className="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-surface-muted text-center text-[10px] leading-tight text-text-muted">
        {label}
      </span>
    )
  }

  return (
    <img
      src={src}
      alt={creative.name}
      // `data:` URIs must load eagerly — a lazy one never enters the viewport observer and never
      // decodes, which is ten cards and ten blank frames with no error anywhere.
      loading={imageLoading(src)}
      className="h-16 w-16 shrink-0 rounded-xl object-cover"
    />
  )
}

/**
 * A drill-down path: Platform › Campaign › Ad set › Ad › Creative, each step a narrower library.
 *
 * Each link ADDS to the filters this section was computed under rather than replacing them, so the
 * period and the platform chosen on the dashboard survive every step of the descent.
 */
function DrillPath({ creative, drill, t, locale }: { creative: CreativeCard; drill: Drill; t: Copy; locale: 'ar' | 'en' }) {
  const steps: Array<{ label: string; to: string }> = [
    { label: providerLabel(creative.provider, locale), to: drill({ providers: [creative.provider] }) },
  ]

  if (creative.campaign_id) {
    steps.push({
      label: creative.campaign_name ?? t.campaign,
      to: drill({ providers: [creative.provider], campaign_ids: [creative.campaign_id] }),
    })
  }
  if (creative.ad_set_id) {
    steps.push({
      label: t.adSet,
      to: drill({ providers: [creative.provider], campaign_ids: creative.campaign_id ? [creative.campaign_id] : undefined, ad_set_ids: [creative.ad_set_id] }),
    })
  }
  if (creative.ad_id) {
    steps.push({
      label: t.ad,
      to: drill({
        providers: [creative.provider],
        campaign_ids: creative.campaign_id ? [creative.campaign_id] : undefined,
        ad_set_ids: creative.ad_set_id ? [creative.ad_set_id] : undefined,
        ad_ids: [creative.ad_id],
      }),
    })
  }
  steps.push({ label: t.creative, to: drill({ creative: creative.id }) })

  return (
    <nav aria-label={t.drill} className="flex flex-wrap items-center gap-1 text-xs text-text-muted">
      {steps.map((step, i) => (
        <span key={step.label + i} className="flex items-center gap-1">
          {i > 0 && <span aria-hidden="true">›</span>}
          <Link to={step.to} className="rounded px-1 py-0.5 underline-offset-2 hover:text-brand-700 hover:underline">
            {step.label}
          </Link>
        </span>
      ))}
    </nav>
  )
}

function WinnerCard({
  heading,
  icon,
  winner,
  path,
  t,
  locale,
  drill,
}: {
  heading: string
  icon?: ReactNode
  winner: CreativeWinner
  path: string
  t: Copy
  locale: 'ar' | 'en'
  drill: Drill
}) {
  return (
    <article className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5">
      <h3 className="flex items-center gap-2 text-sm font-semibold text-text">
        {icon}
        {heading}
      </h3>

      <div className="flex items-start gap-3">
        <Poster creative={winner.creative} label={t.noPreview} />
        <div className="min-w-0">
          <Link
            to={drill({ creative: winner.creative.id })}
            className="block truncate font-medium text-text underline-offset-2 hover:text-brand-700 hover:underline"
          >
            {winner.creative.name}
          </Link>
          <p className="text-xs text-text-muted">{marketingPathLabel(path, locale)}</p>
          <p className="mt-1 text-lg font-semibold text-text">
            {value(winner.creative.metrics, winner.metric, locale)}
          </p>
          {/* The metric is named beside the number: a winner with no stated axis is a verdict nobody
              can check, and the axis differs by path. */}
          <p className="text-xs text-text-muted">{metricLabel(winner.metric, locale)}</p>
        </div>
      </div>

      <p className="text-xs text-text-muted">
        {winner.low_evidence ? t.lowEvidence : t.evidenceNote(num(winner.candidates))}
      </p>

      <DrillPath creative={winner.creative} drill={drill} t={t} locale={locale} />
    </article>
  )
}

function MoveList({
  title,
  tone,
  list,
  t,
  locale,
  drill,
}: {
  title: string
  tone: 'success' | 'danger'
  list: { items: CreativeMove[]; total: number; shown: number }
  t: Copy
  locale: 'ar' | 'en'
  drill: Drill
}) {
  const Icon = tone === 'success' ? ArrowUpRight : ArrowDownRight

  return (
    <div className="rounded-2xl border border-border bg-surface p-5">
      <h3 className="text-sm font-semibold text-text">{title}</h3>

      {list.items.length === 0 ? (
        <p className="mt-3 text-sm text-text-muted">{t.noWinner}</p>
      ) : (
        <ul className="mt-3 flex flex-col gap-2">
          {list.items.map((move) => (
            <li key={move.creative.id}>
              <Link
                to={drill({ creative: move.creative.id })}
                className="flex items-center justify-between gap-3 rounded-xl border border-border p-3 text-sm transition-colors hover:border-brand-400"
              >
                <span className="min-w-0">
                  <span className="block truncate font-medium text-text">{move.creative.name}</span>
                  <span className="block text-xs text-text-muted">
                    {metricLabel(move.metric, locale)} · {formatMetric({ kind: 'value', value: move.previous }, move.metric, locale)}
                    {' → '}
                    {formatMetric({ kind: 'value', value: move.current }, move.metric, locale)}
                  </span>
                </span>
                <span className={`flex shrink-0 items-center gap-1 font-semibold ${tone === 'success' ? 'text-success' : 'text-danger'}`}>
                  <Icon className="h-4 w-4" aria-hidden="true" />
                  {percent(Math.abs(move.improvement))}
                  <span className="sr-only">{tone === 'success' ? t.improved : t.worsened}</span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      <Counted list={list} t={t} />
    </div>
  )
}

function SpendSplit({ rows, t, ar, drill }: { rows: SpendByKind[]; t: Copy; ar: boolean; drill: Drill }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-5">
      <h3 className="text-sm font-semibold text-text">{t.spendByKind}</h3>
      <ul className="mt-3 flex flex-col gap-3">
        {rows.map((row) => (
          <li key={row.kind}>
            <Link to={drill({ kinds: [row.kind] })} className="block rounded-xl p-1 transition-colors hover:bg-surface-muted">
              <div className="flex items-center justify-between gap-2 text-sm">
                <span className="text-text">{KIND_LABEL(row.kind, t)}</span>
                <span className="text-text-muted">
                  {money(row.spend, ar)} · {row.share === null ? '—' : percent(row.share)}
                </span>
              </div>
              <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-surface-muted">
                <div
                  className="h-full rounded-full bg-brand-500"
                  style={{ width: `${Math.round((row.share ?? 0) * 100)}%` }}
                />
              </div>
              {row.spend_not_reported > 0 && (
                <p className="mt-1 text-xs text-text-muted">
                  {num(row.spend_not_reported)} {t.withoutMetrics}
                </p>
              )}
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}

/**
 * Images against videos on ONE path, metric by metric.
 *
 * The winner is marked per metric and never overall — the same rule the two-creative comparison
 * follows, for the same reason: «videos are better» is not a sentence the data supports, while
 * «videos cost less per thousand impressions here» is.
 */
function PathTable({ row, t, locale }: { row: PathComparison; t: Copy; locale: 'ar' | 'en' }) {
  const lowerWins = new Set(['cpm', 'cpc', 'cpa', 'cost_per_view', 'cost_per_lpv'])

  return (
    <div>
      <h4 className="text-xs font-semibold uppercase tracking-wide text-text-muted">
        {marketingPathLabel(row.path, locale)}
      </h4>
      <div className="mt-2 overflow-x-auto">
        <table className="w-full min-w-[22rem] text-sm">
          <thead>
            <tr className="text-start text-xs text-text-muted">
              <th scope="col" className="py-1 text-start font-medium">{t.metric}</th>
              <th scope="col" className="py-1 text-start font-medium">{t.image}</th>
              <th scope="col" className="py-1 text-start font-medium">{t.video}</th>
            </tr>
          </thead>
          <tbody>
            {row.headline_metrics.map((metric) => {
              const image = metricState(row.image, metric)
              const video = metricState(row.video, metric)
              const comparable = image.kind === 'value' && video.kind === 'value'
              const imageWins =
                comparable && (lowerWins.has(metric) ? image.value < video.value : image.value > video.value)
              const videoWins =
                comparable && (lowerWins.has(metric) ? video.value < image.value : video.value > image.value)

              return (
                <tr key={metric} className="border-t border-border">
                  <th scope="row" className="py-1.5 text-start font-normal text-text-muted">
                    {metricLabel(metric, locale)}
                  </th>
                  <td className={`py-1.5 ${imageWins ? 'font-semibold text-success' : 'text-text'}`}>
                    {formatMetric(image, metric, locale)}
                  </td>
                  <td className={`py-1.5 ${videoWins ? 'font-semibold text-success' : 'text-text'}`}>
                    {formatMetric(video, metric, locale)}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function FreshnessLine({ data, t, ar }: { data: { freshness: { last_synced_at: string | null; quality: Record<string, number> }; totals: { creatives: number; with_metrics: number }; evidence: { min_impressions: number } }; t: Copy; ar: boolean }) {
  const q = data.freshness.quality

  return (
    <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-muted">
      <span>
        {t.lastSync}:{' '}
        {data.freshness.last_synced_at
          ? new Date(data.freshness.last_synced_at).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB')
          : t.never}
      </span>
      <span>·</span>
      <span>
        {num(data.totals.with_metrics)} {t.of} {num(data.totals.creatives)} {t.creatives}
      </span>
      {q.without_metrics > 0 && (
        <>
          <span>·</span>
          <span>{num(q.without_metrics)} {t.withoutMetrics}</span>
        </>
      )}
      {q.previews_withheld > 0 && (
        <>
          <span>·</span>
          <span>{num(q.previews_withheld)} {t.withheld}</span>
        </>
      )}
      <span>·</span>
      <span>{t.minImpressions(num(data.evidence.min_impressions))}</span>
    </p>
  )
}

/** «Six of nineteen» — a section that shows part of a list and does not say so reads as the whole. */
function Counted({ list, t }: { list: { total: number; shown: number }; t: Copy }) {
  if (list.total <= list.shown) return null

  return (
    <p className="mt-3 text-xs text-text-muted">
      {t.showing} {num(list.shown)} {t.of} {num(list.total)}
    </p>
  )
}

/** A labelled control. The label is a real `<label>`, so the select is reachable by name. */
function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-xs text-text-muted">
      <span>{label}</span>
      {children}
    </label>
  )
}

/**
 * One axis of the section's own filter bar.
 *
 * Options come from the response, which is derived from the rows in reach — never from an enum. A
 * control listing platforms this account has never run is a control that returns nothing, and an
 * empty result reads as «no data» rather than as «nothing was ever going to match».
 */
function Select({
  label,
  all,
  value,
  onChange,
  options,
}: {
  label: string
  all: string
  value: string
  onChange: (value: string) => void
  options: Array<{ value: string; label: string }>
}) {
  if (options.length === 0) return null

  return (
    <Field label={label}>
      <select
        aria-label={label}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text"
      >
        <option value="">{all}</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </Field>
  )
}

/**
 * The narrowing behind this section, written out.
 *
 * Only the axes that are actually set, and named the way the reader named them — a client's own
 * name rather than its id. Renders nothing when nothing is applied, so an unfiltered dashboard does
 * not carry a line saying so.
 */
function AppliedLine({
  query,
  options,
  t,
  locale,
}: {
  query: LibraryQuery
  options: LibraryFilterOptions
  t: Copy
  locale: 'ar' | 'en'
}) {
  const named = (ids: string[] | undefined, rows: Array<{ id: string; name: string }>): string[] =>
    (ids ?? []).map((id) => rows.find((r) => r.id === id)?.name ?? id)

  const parts = [
    ...named(query.client_ids, options.clients).map((n) => `${t.client}: ${n}`),
    ...named(query.project_ids, options.projects).map((n) => `${t.project}: ${n}`),
    ...(query.providers ?? []).map((p) => `${t.platform}: ${providerLabel(p, locale)}`),
    ...(query.objectives ?? []).map((o) => `${t.objective}: ${objectiveLabel(o, locale)}`),
    ...(query.paths ?? []).map((p) => `${t.path}: ${marketingPathLabel(p, locale)}`),
    ...(query.kinds ?? []).map((k) => `${t.kind}: ${KIND_LABEL(k, t)}`),
    ...named(query.campaign_ids, options.campaigns).map((n) => `${t.campaign}: ${n}`),
  ]

  if (parts.length === 0) return null

  return (
    <p className="text-xs text-text-muted">
      {t.applied}: {parts.join(' · ')}
    </p>
  )
}
