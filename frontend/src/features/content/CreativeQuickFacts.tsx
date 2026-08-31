import { Link } from 'react-router-dom'
import { Area, AreaChart, ResponsiveContainer, Tooltip, XAxis } from 'recharts'
import { useQuery } from '@tanstack/react-query'
import { ArrowDownRight, ArrowUpRight, ExternalLink, Minus } from 'lucide-react'
import { getCreativeInReach, type CreativeInsight, type CreativeMetrics } from './api'
import { formatMetric, metricLabel, metricState } from './metrics'
import { Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { marketingPathLabel, objectiveLabel, providerLabel } from '@/features/campaigns/labels'
import { useUi } from '@/stores/ui'
import { CANONICAL_CURRENCY } from '@/lib/money/contract'

/**
 * Everything needed to judge one creative, beside the creative — UX-CONTENT-001.
 *
 * ## Why this is not a link to the detail page
 *
 * Reviewing a library means asking the same four questions of forty assets: what is it, what did it
 * cost, is it working, and is it tiring. Answering them through a full page per creative is four
 * navigations and a lost scroll position each time, so in practice nobody does it — they judge by
 * the picture, which is the one thing the picture cannot tell them. This pane puts the answers next
 * to the asset, and the full page stays one click away for the questions it alone answers.
 *
 * ## One pipeline, and one request
 *
 * It reads `getCreativeInReach` — the same controller the detail page, the dashboard cards and the
 * client report read (§15.17). Not a lighter endpoint of its own: a summary that computed its own
 * figures could disagree with the page it summarises, and the reader would have no way to know
 * which of the two was lying.
 *
 * ## What it refuses to draw
 *
 * A metric the platform never sent renders «غير مُرسَل», never a zero — `metricState` is the only
 * way a figure gets out of here. A change against an absent previous value is not a change, so no
 * arrow is drawn for it. And a funnel stage nobody reported is NAMED as missing rather than
 * padded with a zero (§15.6), because the stages arrive as a list of what was reported.
 */

const COPY = {
  ar: {
    loading: 'جارٍ التحميل…',
    failed: 'تعذّر تحميل تفاصيل هذا الإعلان.',
    identity: 'التعريف',
    platform: 'المنصة',
    campaign: 'الحملة',
    ads: 'الإعلانات التي تعرضه',
    objective: 'الهدف',
    path: 'المسار',
    copy: 'النص الإعلاني',
    headline: 'العنوان',
    body: 'النص',
    cta: 'زر الإجراء',
    destination: 'الوجهة',
    metrics: 'أهم المؤشرات',
    vsPrevious: 'مقارنة بالفترة السابقة',
    trend: 'الاتجاه اليومي',
    trendNote: 'الإنفاق يومًا بيوم خلال الفترة.',
    funnel: 'مراحل القمع',
    notReported: 'لم ترسلها المنصة',
    fatigue: 'حالة الإجهاد',
    insights: 'ملاحظات وتوصيات',
    noInsights: 'لا توجد ملاحظات لهذه الفترة.',
    full: 'التفاصيل الكاملة',
    none: 'غير متوفر',
  },
  en: {
    loading: 'Loading…',
    failed: 'Could not load this ad’s details.',
    identity: 'Identity',
    platform: 'Platform',
    campaign: 'Campaign',
    ads: 'Ads running it',
    objective: 'Objective',
    path: 'Path',
    copy: 'Ad copy',
    headline: 'Headline',
    body: 'Body',
    cta: 'Call to action',
    destination: 'Destination',
    metrics: 'Key metrics',
    vsPrevious: 'vs the previous period',
    trend: 'Daily trend',
    trendNote: 'Spend, day by day over the period.',
    funnel: 'Funnel stages',
    notReported: 'Not reported by the platform',
    fatigue: 'Fatigue',
    insights: 'Findings and recommendations',
    noInsights: 'No findings for this period.',
    full: 'Full details',
    none: 'Not available',
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

const SEVERITY_TONE: Record<CreativeInsight['severity'], string> = {
  warning: 'bg-danger',
  opportunity: 'bg-warning',
  positive: 'bg-success',
}

/** Costs improve by going DOWN, so an arrow alone would call every saving a loss. */
const LOWER_IS_BETTER = new Set(['cpc', 'cpm', 'cpa', 'cost_per_view', 'cost_per_lpv'])

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="border-t border-white/10 px-4 py-3 first:border-t-0">
      <h3 className="mb-2 text-[11px] font-bold uppercase tracking-wide text-white/45">{title}</h3>
      {children}
    </section>
  )
}

/**
 * The change against the previous period, or nothing at all.
 *
 * Nothing, deliberately, when either side is missing: «+100%» against an absence is a sentence
 * about a number that was never there, and it is indistinguishable on screen from a real doubling.
 */
function Change({ current, previous, metric }: { current: number | null; previous: number | null; metric: string }) {
  if (current === null || previous === null || previous === 0) return null

  const delta = (current - previous) / Math.abs(previous)
  const flat = Math.abs(delta) < 0.005
  const good = flat ? null : LOWER_IS_BETTER.has(metric) ? delta < 0 : delta > 0
  const Icon = flat ? Minus : delta > 0 ? ArrowUpRight : ArrowDownRight

  return (
    <span
      dir="ltr"
      className={`inline-flex items-center gap-0.5 text-[11px] font-semibold ${
        good === null ? 'text-white/45' : good ? 'text-success' : 'text-danger'
      }`}
    >
      <Icon size={11} aria-hidden />
      {`${Math.abs(delta * 100).toFixed(0)}%`}
    </span>
  )
}

export function CreativeQuickFacts({
  creativeId,
  objective,
  window,
  detailsTo,
}: {
  creativeId: string
  /**
   * The objective, from the CARD that opened this panel.
   *
   * `CreativePresenter::card()` does not carry it — the library list adds it in `CreativeRows`, and
   * the detail endpoint publishes the marketing PATH instead. So the panel showed «—» beside a row
   * that had just said «Sales». Taken from the card rather than added to the payload, because that
   * is the same value the row displayed rather than a second source that could disagree with it,
   * and because `card()` is also what a client link renders — widening it is a disclosure decision,
   * not a display one.
   */
  objective: string | null
  /** The same period the library was measured over — so the pane cannot quote a different window. */
  window: { from: string; to: string }
  /** Where «full details» goes, carrying the library's own address so Back rebuilds the shelf. */
  detailsTo: string
}) {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']

  const detail = useQuery({
    queryKey: ['creative-quick', creativeId, window.from, window.to],
    queryFn: () => getCreativeInReach(creativeId, window),
    // The library already fetched a page of cards; refetching this pane on every focus change would
    // re-request it once per creative the reader arrows past.
    staleTime: 60_000,
  })

  if (detail.isPending) {
    return (
      <div className="space-y-3 p-4" aria-busy>
        <Skeleton className="h-4 w-32" />
        <Skeleton className="h-20 w-full" />
        <Skeleton className="h-32 w-full" />
      </div>
    )
  }

  if (detail.isError || !detail.data) {
    return (
      <div className="p-4">
        <QueryFailure error={detail.error} ar={ar} fallbackTitle={t.failed} onRetry={() => void detail.refetch()} />
      </div>
    )
  }

  const data = detail.data
  const creative = data.creative
  const metrics: CreativeMetrics | null = data.metrics
  const previous: CreativeMetrics | null = data.previous
  const currency = data.currency ?? CANONICAL_CURRENCY
  const copy = creative.copy
  const hasCopy = Boolean(copy.headline || copy.body || copy.cta || creative.destination_url)
  /*
   * One point is a dot, not a trend — and a creative that ran for a single day should say so with
   * its figures rather than with a chart that draws a straight line through nothing.
   */
  const trendRows = data.trend as Array<Record<string, number | string | null>>

  return (
    <div className="divide-y divide-white/10 text-sm text-white/80">
      <Section title={t.identity}>
        <dl className="grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
          <div>
            <dt className="text-white/45">{t.platform}</dt>
            <dd className="text-white">{providerLabel(creative.provider, locale)}</dd>
          </div>
          <div className="min-w-0">
            <dt className="text-white/45">{t.campaign}</dt>
            <dd className="truncate text-white">{creative.campaign_name ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-white/45">{t.objective}</dt>
            <dd className="text-white">{objective ? objectiveLabel(objective, locale) : '—'}</dd>
          </div>
          <div>
            <dt className="text-white/45">{t.path}</dt>
            <dd className="text-white">{marketingPathLabel(data.path, locale)}</dd>
          </div>
          {/*
            * CREATIVE-FRONTEND-ADS-001 — one asset is routinely placed by SEVERAL ads.
            *
            * `ad_id` is one ad picked from many by row order, and showing it alone implied each
            * creative belonged to exactly one. The canonical relation is `external_ads.creative_id`,
            * the backend has sent the whole list since the presenter was fixed, and nothing read it.
            * «Which ads are running this?» is the question somebody asks before pausing anything.
            */}
          {creative.ads.length > 0 && (
            <div className="col-span-2 min-w-0">
              <dt className="text-white/45">
                {t.ads}
                {creative.ads.length > 1 && <span className="ms-1 tabular-nums">({creative.ads.length})</span>}
              </dt>
              <dd className="truncate text-white">
                {creative.ads.map((ad) => ad.name ?? ad.external_id).join(' · ')}
              </dd>
            </div>
          )}
        </dl>
      </Section>

      {/* The words that ran, not only the picture — half of why a creative worked is what it said. */}
      {hasCopy && (
        <Section title={t.copy}>
          <dl className="space-y-1.5 text-xs">
            {copy.headline && (
              <div>
                <dt className="text-white/45">{t.headline}</dt>
                <dd className="text-white">{copy.headline}</dd>
              </div>
            )}
            {copy.body && (
              <div>
                <dt className="text-white/45">{t.body}</dt>
                <dd className="whitespace-pre-line text-white/85">{copy.body}</dd>
              </div>
            )}
            {copy.cta && (
              <div>
                <dt className="text-white/45">{t.cta}</dt>
                <dd className="text-white">{copy.cta}</dd>
              </div>
            )}
            {creative.destination_url && (
              <div className="min-w-0">
                <dt className="text-white/45">{t.destination}</dt>
                <dd className="truncate">
                  {/* `noreferrer` as well as `noopener`: this URL is an advertiser's own landing page,
                      and the report it is read from should not be in its referrer log. */}
                  <a
                    href={creative.destination_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    dir="ltr"
                    className="inline-flex items-center gap-1 text-brand-300 underline-offset-2 hover:underline"
                  >
                    {creative.destination_url}
                    <ExternalLink size={11} aria-hidden />
                  </a>
                </dd>
              </div>
            )}
          </dl>
        </Section>
      )}

      <Section title={`${t.metrics} · ${t.vsPrevious}`}>
        <dl className="grid grid-cols-2 gap-x-3 gap-y-2">
          {data.headline_metrics.map((key) => (
            <div key={key} data-testid={`quick-metric-${key}`}>
              <dt className="text-[11px] text-white/45">{metricLabel(key, locale)}</dt>
              <dd className="flex items-baseline gap-1.5">
                <span className="tabular-nums text-white" dir="ltr">
                  {formatMetric(metricState(metrics, key), key, locale, currency)}
                </span>
                <Change
                  current={typeof metrics?.[key] === 'number' ? (metrics[key] as number) : null}
                  previous={typeof previous?.[key] === 'number' ? (previous[key] as number) : null}
                  metric={key}
                />
              </dd>
            </div>
          ))}
        </dl>
      </Section>

      {/*
        The shape of the period, not only its total.
        A creative that spent evenly and one that spent everything in three days produce the same
        «3,962 SAR», and only one of them is a creative anybody should draw a conclusion from.
      */}
      {trendRows.length > 1 && (
        <Section title={t.trend}>
          <div className="h-20 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={trendRows} margin={{ top: 4, right: 2, bottom: 0, left: 2 }}>
                <defs>
                  <linearGradient id={`qf-${creativeId}`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="var(--brand-500)" stopOpacity={0.4} />
                    <stop offset="100%" stopColor="var(--brand-500)" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <XAxis dataKey="date" hide />
                <Tooltip
                  contentStyle={{
                    background: 'var(--surface)',
                    border: '1px solid var(--border-strong)',
                    borderRadius: 10,
                    fontSize: 12,
                    color: 'var(--text-primary)',
                  }}
                  labelStyle={{ color: 'var(--text-secondary)' }}
                />
                <Area
                  name={metricLabel('spend', locale)}
                  type="monotone"
                  dataKey="spend"
                  stroke="var(--brand-500)"
                  strokeWidth={2}
                  fill={`url(#qf-${creativeId})`}
                  isAnimationActive={false}
                />
              </AreaChart>
            </ResponsiveContainer>
          </div>
          <p className="mt-1 text-[11px] text-white/45">{t.trendNote}</p>
        </Section>
      )}

      {/*
        §15.6 — the funnel is a list of what WAS reported, and a named list of what was not.
        It is never padded, so four stages means four stages arrived rather than two having failed.
      */}
      {(data.funnel.stages.length > 0 || data.funnel.missing.length > 0) && (
        <Section title={t.funnel}>
          <ul className="space-y-1 text-xs">
            {data.funnel.stages.map((stage) => (
              <li key={stage.key} className="flex items-center justify-between gap-2">
                <span className="text-white/70">{ar ? stage.label_ar : stage.label_en}</span>
                <span className="tabular-nums text-white" dir="ltr">
                  {stage.count === null ? '—' : stage.count.toLocaleString('en-US')}
                  {stage.rate_from_previous !== null && (
                    <span className="ms-1.5 text-white/45">{(stage.rate_from_previous * 100).toFixed(0)}%</span>
                  )}
                </span>
              </li>
            ))}
          </ul>
          {data.funnel.missing.length > 0 && (
            <p className="mt-2 text-[11px] text-white/45">
              {t.notReported}: {data.funnel.missing.map((m) => (ar ? m.label_ar : m.label_en)).join(ar ? '، ' : ', ')}
            </p>
          )}
        </Section>
      )}

      <Section title={t.fatigue}>
        <span className={`inline-block rounded px-1.5 py-0.5 text-xs ${FATIGUE_TONE[data.fatigue.status] ?? ''}`}>
          {FATIGUE_LABEL[data.fatigue.status]?.[ar ? 'ar' : 'en'] ?? data.fatigue.status}
        </span>
        <p className="mt-1.5 text-xs text-white/60">{ar ? data.fatigue.reason_ar : data.fatigue.reason_en}</p>
      </Section>

      <Section title={t.insights}>
        {data.insights.items.length === 0 ? (
          <p className="text-xs text-white/50">{t.noInsights}</p>
        ) : (
          <ul className="space-y-2">
            {/* Three, not all of them — the pane is a judgement aid, and the detail page holds the rest. */}
            {data.insights.items.slice(0, 3).map((insight) => (
              <li key={insight.id} className="flex gap-2">
                <span className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${SEVERITY_TONE[insight.severity]}`} aria-hidden />
                <div className="min-w-0">
                  <p className="text-xs font-semibold text-white">{ar ? insight.title_ar : insight.title_en}</p>
                  <p className="text-[11px] text-white/60">{ar ? insight.detail_ar : insight.detail_en}</p>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Section>

      <div className="px-4 py-3">
        <Link
          to={detailsTo}
          className="inline-flex items-center gap-1 text-xs font-semibold text-brand-300 underline-offset-2 hover:underline"
        >
          {t.full} <ExternalLink size={12} aria-hidden />
        </Link>
      </div>
    </div>
  )
}
