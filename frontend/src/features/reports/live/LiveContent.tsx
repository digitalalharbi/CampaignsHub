import { useEffect, useState } from 'react'
import { AdPoster } from '@/features/content/AdPoster'
import { providerLabel } from '@/features/campaigns/labels'
import { platformColor } from '@/features/analytics/components'
import { MetricLineChart } from '@/features/analytics/charts'
import { canonicalPlatform } from '@/lib/platforms'
import { Num } from '@/components/ui/Num'
import type { Locale } from '@/stores/ui'
import { figuresFor, type ReportAd } from '../ReportAdsSection'
import type { RosterRow } from '../ReportCreativeRoster'
import { ReportAdDetail } from '../ReportAdDetail'
import { fetchLiveContent, type LiveContentPayload } from '../api'

/**
 * One shape for a piece of content, whichever list it came from.
 *
 * The ranked lists carry their figures at the top level and the roster nests them under `metrics`.
 * Both are the same `CreativeMetrics` reading; flattening here means a tile, a strip and the detail
 * dialog all state a creative's figures through ONE function (`figuresFor`) rather than each
 * remembering which list it is holding.
 */
export function asContent(row: RosterRow | ReportAd): ReportAd {
  if (!('metrics' in row) || row.metrics === null || row.metrics === undefined) return row as ReportAd

  const { metrics, ...rest } = row as RosterRow

  return { ...(metrics as Record<string, unknown>), ...rest } as ReportAd
}

export function ContentTile({
  content,
  locale,
  currency,
  onOpen,
  rank,
}: {
  content: ReportAd
  locale: Locale
  currency: string | null
  onOpen?: (content: ReportAd) => void
  rank?: number
}) {
  const ar = locale === 'ar'
  const provider = content.provider ? canonicalPlatform(content.provider) : null
  const figures = figuresFor(content, ar, currency)
  const openable = onOpen !== undefined && !!content.content_key

  const body = (
    <>
      <div className="relative">
        <AdPoster preview={content.preview ?? null} name={content.name ?? ''} className="h-36 w-full" testid="live-content-poster" forClient />
        {rank !== undefined && (
          <span className="tnum absolute start-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-[11px] font-bold text-white">
            <Num>{`#${rank}`}</Num>
          </span>
        )}
      </div>
      <div className="min-w-0">
        <div className="truncate text-sm font-bold text-text-primary" title={content.name ?? undefined}>{content.name ?? '—'}</div>
        {provider && (
          <div className="mt-0.5 flex items-center gap-1.5 text-[11px] text-text-muted">
            <span className="inline-block h-2 w-2 shrink-0 rounded-full" style={{ background: platformColor(provider) }} aria-hidden />
            {providerLabel(provider, locale)}
          </div>
        )}
      </div>
      {/*
        Rows on a phone, columns above it. Three columns in a two-up phone grid left each cell about
        fifty pixels: a label cut to «نسبة ا…» and «3.11%» broken across two lines. A figure is never
        truncated — «…05 SAR» hides the one part of it that matters.
      */}
      {figures.length > 0 && (
        <dl className="grid grid-cols-1 gap-1 sm:grid-cols-3 sm:gap-1.5 sm:text-center">
          {figures.map((f) => (
            <div key={f.label} className="flex min-w-0 items-baseline justify-between gap-2 rounded-lg bg-surface-secondary px-2 py-1 sm:block sm:px-1 sm:py-1.5">
              <dt className="truncate text-[10px] font-semibold text-text-muted">{f.label}</dt>
              <dd className="tnum whitespace-nowrap text-[11px] font-bold leading-tight text-text-primary"><Num>{f.value}</Num></dd>
            </div>
          ))}
        </dl>
      )}
    </>
  )

  const shell = 'flex min-w-0 flex-col gap-2 overflow-hidden rounded-2xl border border-border bg-surface p-3 text-start'

  return openable ? (
    <button
      type="button"
      data-testid="live-content-tile"
      data-content-key={content.content_key}
      onClick={() => onOpen?.(content)}
      aria-label={ar ? `تفاصيل ${content.name ?? ''}` : `Details for ${content.name ?? ''}`}
      className={`${shell} transition-colors hover:border-brand-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500`}
    >
      {body}
    </button>
  ) : (
    <article data-testid="live-content-tile" className={shell}>{body}</article>
  )
}

/**
 * The content drilldown's dialog — the card's figures, the media, and the content's own trend.
 *
 * The figures shown first are the ones the reader clicked, from the list they clicked them in, so the
 * dialog opens instantly; the trend arrives after. Its three states stay three: a skeleton while it is
 * asked for, a sentence when it could not be read, and the chart — or «no delivery in this period» when
 * every point came back unreported, which is a fact about the content rather than a failure.
 */
export function LiveContentDetail({
  token,
  secret,
  content,
  from,
  to,
  currency,
  locale,
  onClose,
}: {
  token: string
  secret?: string
  content: ReportAd
  from: string
  to: string
  currency: string
  locale: Locale
  onClose: () => void
}) {
  const ar = locale === 'ar'
  const [state, setState] = useState<{ kind: 'pending' } | { kind: 'failed' } | { kind: 'ready'; data: LiveContentPayload }>({ kind: 'pending' })
  const key = content.content_key ?? ''

  useEffect(() => {
    let live = true
    setState({ kind: 'pending' })
    fetchLiveContent(token, key, { from, to, password: secret })
      .then(({ status, envelope }) => {
        if (!live) return
        setState(status === 200 && envelope.data ? { kind: 'ready', data: envelope.data } : { kind: 'failed' })
      })
      .catch(() => live && setState({ kind: 'failed' }))

    return () => { live = false }
  }, [token, key, from, to, secret])

  const shown = state.kind === 'ready' ? asContent(state.data.content) : content
  const reported = state.kind === 'ready' ? state.data.trend.filter((p) => p.reported) : []
  const spendDrawable = reported.length > 0 && reported.every((p) => typeof p.spend === 'number')

  return (
    <ReportAdDetail ad={shown} currency={currency} locale={locale} onClose={onClose}>
      <section data-testid="live-content-trend" data-state={state.kind} className="rounded-xl border border-border p-3">
        <h4 className="mb-2 text-sm font-bold text-text-primary">
          {state.kind === 'ready' && state.data.granularity === 'week'
            ? (ar ? 'الأداء أسبوعيًا' : 'Weekly performance')
            : (ar ? 'الأداء يوميًا' : 'Daily performance')}
        </h4>
        {state.kind === 'pending' && <div className="h-44 animate-pulse rounded-lg bg-surface-secondary" aria-busy="true" />}
        {state.kind === 'failed' && (
          <p className="py-8 text-center text-sm text-text-secondary">{ar ? 'تعذّر تحميل اتجاه هذا المحتوى.' : 'This content’s trend could not be loaded.'}</p>
        )}
        {state.kind === 'ready' && reported.length === 0 && (
          <p data-testid="live-content-trend-silent" className="py-8 text-center text-sm text-text-secondary">
            {ar ? 'لم يُعرض هذا المحتوى في هذه الفترة.' : 'This content did not run in this period.'}
          </p>
        )}
        {state.kind === 'ready' && reported.length > 0 && (
          <MetricLineChart
            data={state.data.trend}
            currency={currency}
            height={200}
            rightAxisFor="conversions"
            series={[
              ...(spendDrawable ? [{ key: 'spend', name: ar ? 'الإنفاق' : 'Spend', color: 'var(--brand-600)', kind: 'money' as const }] : []),
              ...(spendDrawable ? [] : [{ key: 'clicks', name: ar ? 'النقرات' : 'Clicks', color: 'var(--info)', kind: 'num' as const }]),
              { key: 'conversions', name: ar ? 'النتائج' : 'Results', color: 'var(--purple)', kind: 'num' as const },
            ]}
          />
        )}
      </section>
    </ReportAdDetail>
  )
}
