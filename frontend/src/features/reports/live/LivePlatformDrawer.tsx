import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { platformColor } from '@/features/analytics/components'
import { MetricLineChart } from '@/features/analytics/charts'
import { canonicalPlatform } from '@/lib/platforms'
import { Num } from '@/components/ui/Num'
import type { Locale } from '@/stores/ui'
import type { ReportAd } from '../ReportAdsSection'
import { fetchLivePlatform, type LivePayload, type LivePlatformPayload, type LiveShare } from '../api'
import { ContentTile } from './LiveContent'
import type { useLiveMetricReader } from './liveMetrics'

type Reader = ReturnType<typeof useLiveMetricReader>

/**
 * REPORT-DRILLDOWN-001 — one platform, opened from the comparison, in a drawer.
 *
 * Deeper analysis on request: the dashboard stays as it was, and a reader who wants «how did THIS
 * channel do» opens a row. Everything here is visual — KPI blocks per objective path, the platform's
 * trend, its share of spend beside its share of the outcome, and its strongest and weakest content —
 * with no paragraph a chart could say instead.
 *
 * The client path is platform → content. A tile opens the content dialog; nothing opens a campaign,
 * because the payload carries none (`LiveDrilldown` refuses one server-side).
 */

const OUTCOME_LABEL: Record<string, { ar: string; en: string }> = {
  revenue: { ar: 'الإيرادات', en: 'revenue' },
  conversions: { ar: 'النتائج', en: 'results' },
  clicks: { ar: 'النقرات', en: 'clicks' },
  impressions: { ar: 'الظهور', en: 'impressions' },
}

/** Metrics shown when the link carries no objective split — the operational figures every platform shares. */
const FALLBACK_KEYS = ['spend', 'impressions', 'clicks', 'conversions', 'cpa', 'ctr']

export function LivePlatformDrawer({
  token,
  secret,
  provider,
  whole,
  reader,
  currency,
  locale,
  onOpenContent,
  onClose,
}: {
  token: string
  secret?: string
  provider: string
  whole: LivePayload
  reader: Reader
  currency: string
  locale: Locale
  onOpenContent: (content: ReportAd) => void
  onClose: () => void
}) {
  const ar = locale === 'ar'
  const platform = canonicalPlatform(provider)
  const name = providerLabel(platform, locale)
  const [state, setState] = useState<{ kind: 'pending' } | { kind: 'failed' } | { kind: 'ready'; data: LivePlatformPayload }>({ kind: 'pending' })
  const { from, to } = whole.period

  useEffect(() => {
    let live = true
    setState({ kind: 'pending' })
    fetchLivePlatform(token, provider, { from, to, password: secret })
      .then(({ status, envelope }) => {
        if (!live) return
        setState(status === 200 && envelope.data ? { kind: 'ready', data: envelope.data } : { kind: 'failed' })
      })
      .catch(() => live && setState({ kind: 'failed' }))

    return () => { live = false }
  }, [token, provider, from, to, secret])

  /* Escape closes the drawer — unless a content dialog is open over it, which Escape closes first. */
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return
      if (document.querySelector('[data-testid="report-ad-detail"]')) return
      onClose()
    }
    document.addEventListener('keydown', onKey)

    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  /*
   * Portalled to the body: the shared page's sticky header sits in its own stacking context, and a
   * drawer rendered inside the report stayed beneath it — its close button was covered by the header's
   * download links, found by driving the real link in a browser.
   */
  return createPortal(
    <div
      role="dialog"
      aria-modal="true"
      dir={ar ? 'rtl' : 'ltr'}
      aria-label={ar ? `تفاصيل ${name}` : `${name} details`}
      data-testid="live-platform-drawer"
      data-provider={platform}
      data-state={state.kind}
      className="fixed inset-0 z-40 flex justify-end bg-black/50"
      onClick={onClose}
    >
      <aside
        className="flex h-full w-full max-w-3xl flex-col gap-4 overflow-y-auto border-s border-border bg-surface p-4 sm:p-5 [&>*]:min-w-0"
        onClick={(e) => e.stopPropagation()}
      >
        <header className="sticky -top-4 z-10 -mx-4 flex items-center justify-between gap-3 border-b border-border bg-surface px-4 py-2 sm:-top-5 sm:-mx-5 sm:px-5">
          <h2 className="flex min-w-0 items-center gap-2 truncate text-lg font-extrabold text-text-primary">
            <span className="inline-block h-3 w-3 shrink-0 rounded-full" style={{ background: platformColor(platform) }} aria-hidden />
            {name}
          </h2>
          <button
            type="button"
            data-testid="live-platform-drawer-close"
            aria-label={ar ? 'إغلاق' : 'Close'}
            onClick={onClose}
            className="rounded-lg p-1.5 text-text-secondary hover:bg-surface-hover"
          >
            <X size={18} aria-hidden />
          </button>
        </header>

        {state.kind === 'pending' && (
          <div aria-busy="true" className="grid gap-3">
            <div className="h-24 animate-pulse rounded-2xl bg-surface-secondary" />
            <div className="h-56 animate-pulse rounded-2xl bg-surface-secondary" />
          </div>
        )}
        {state.kind === 'failed' && (
          <p data-testid="live-platform-drawer-failed" className="rounded-2xl border border-border bg-surface p-6 text-center text-sm text-text-secondary">
            {ar ? 'تعذّر تحميل تفاصيل هذه المنصة.' : 'This platform’s details could not be loaded.'}
          </p>
        )}
        {state.kind === 'ready' && (
          <PlatformBody data={state.data} whole={whole} reader={reader} currency={currency} locale={locale} onOpenContent={onOpenContent} />
        )}
      </aside>
    </div>,
    document.body,
  )
}

function PlatformBody({
  data,
  whole,
  reader,
  currency,
  locale,
  onOpenContent,
}: {
  data: LivePlatformPayload
  whole: LivePayload
  reader: Reader
  currency: string
  locale: Locale
  onOpenContent: (content: ReportAd) => void
}) {
  const ar = locale === 'ar'
  const platform = canonicalPlatform(data.provider)
  const color = platformColor(platform)
  const asPayload = { ...whole, currency } as LivePayload
  const spendDrawable = data.timeseries.length > 0 && data.timeseries.every((p) => typeof p.spend === 'number')

  const blocks = data.objectives.length > 0
    ? data.objectives.map((b) => ({
      key: b.path,
      title: ar ? b.label_ar : b.label_en,
      // `orders` is ObjectivePerformance's name for a path's results; the card reads it as results.
      totals: { ...b.metrics, conversions: b.metrics.orders ?? null } as LivePayload['totals'],
      keys: Object.keys(b.metrics).map((k) => (k === 'orders' ? 'conversions' : k)),
    }))
    : [{ key: 'all', title: ar ? 'الأداء' : 'Performance', totals: data.totals, keys: FALLBACK_KEYS }]

  return (
    <>
      {blocks.map((block) => {
        const keys = block.keys.filter((k) => reader.meta[k])
        if (keys.length === 0) return null

        return (
          <section key={block.key} data-testid={`live-platform-drawer-kpis-${block.key}`} className="rounded-2xl border border-border bg-surface p-3">
            <h3 className="mb-2 text-sm font-bold text-text-primary">{block.title}</h3>
            <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              {keys.map((k) => {
                const meta = reader.meta[k]
                const read = meta.format(block.totals, asPayload, reader.asMoney, reader.count)

                return (
                  <div key={k} className="min-w-0 rounded-xl bg-surface-secondary px-3 py-2">
                    <dt className="truncate text-[11px] font-semibold text-text-muted">{ar ? meta.ar : meta.en}</dt>
                    <dd className="tnum truncate text-lg font-extrabold text-text-primary" title={read.exact ?? undefined}><Num>{read.text}</Num></dd>
                  </div>
                )
              })}
            </dl>
          </section>
        )
      })}

      <section data-testid="live-platform-drawer-shares" className="rounded-2xl border border-border bg-surface p-3">
        <h3 className="mb-3 text-sm font-bold text-text-primary">{ar ? 'الحصة من الإجمالي' : 'Share of the total'}</h3>
        <div className="flex flex-col gap-3">
          {data.shares.spend && (
            <ShareBar testid="live-platform-share-spend" label={ar ? 'من الإنفاق' : 'of spend'} share={data.shares.spend} color={color} />
          )}
          <ShareBar
            testid="live-platform-share-outcome"
            label={ar ? `من ${OUTCOME_LABEL[data.shares.outcome.metric]?.ar ?? 'النتائج'}` : `of ${OUTCOME_LABEL[data.shares.outcome.metric]?.en ?? 'results'}`}
            share={data.shares.outcome}
            color="var(--success)"
          />
        </div>
      </section>

      <section data-testid="live-platform-drawer-trend" className="rounded-2xl border border-border bg-surface p-3">
        <h3 className="mb-2 text-sm font-bold text-text-primary">{ar ? 'الأداء بمرور الوقت' : 'Performance over time'}</h3>
        {data.timeseries.length === 0
          ? <p className="py-8 text-center text-sm text-text-secondary">{ar ? 'لا أرقام في هذه الفترة.' : 'No figures in this period.'}</p>
          : (
            <MetricLineChart
              data={data.timeseries}
              currency={currency}
              height={200}
              rightAxisFor="conversions"
              series={[
                ...(spendDrawable ? [{ key: 'spend', name: ar ? 'الإنفاق' : 'Spend', color, kind: 'money' as const }] : []),
                ...(spendDrawable ? [] : [{ key: 'clicks', name: ar ? 'النقرات' : 'Clicks', color: 'var(--info)', kind: 'num' as const }]),
                { key: 'conversions', name: ar ? 'النتائج' : 'Results', color: 'var(--purple)', kind: 'num' as const },
              ]}
            />
          )}
      </section>

      {data.breakdowns.content_drilldown && (
        <>
          <ContentGrid testid="live-platform-drawer-top" title={ar ? 'الأفضل أداءً' : 'Best performing'} items={data.ads.slice(0, 4)} ranked currency={currency} locale={locale} onOpen={onOpenContent} />
          <ContentGrid testid="live-platform-drawer-weakest" title={ar ? 'الأضعف أداءً' : 'Weakest performing'} items={data.ads_weakest.slice(0, 4)} currency={currency} locale={locale} onOpen={onOpenContent} />
        </>
      )}
    </>
  )
}

/** A share as a bar with its percentage — «—» when there was nothing to take a share of. */
function ShareBar({ testid, label, share, color }: { testid: string; label: string; share: LiveShare; color: string }) {
  const pct = share.share === null ? null : Math.round(share.share * 1000) / 10

  return (
    <div data-testid={testid} className="grid grid-cols-[4.5rem_1fr_7rem] items-center gap-3">
      <span className="tnum text-base font-extrabold text-text-primary"><Num>{pct === null ? '—' : `${pct}%`}</Num></span>
      <span className="h-3 overflow-hidden rounded-full bg-surface-secondary">
        {pct !== null && <span className="block h-full rounded-full" style={{ width: `${Math.max(1, pct)}%`, background: color }} />}
      </span>
      <span className="truncate text-xs text-text-secondary">{label}</span>
    </div>
  )
}

function ContentGrid({
  testid,
  title,
  items,
  ranked = false,
  currency,
  locale,
  onOpen,
}: {
  testid: string
  title: string
  items: ReportAd[]
  ranked?: boolean
  currency: string
  locale: Locale
  onOpen: (content: ReportAd) => void
}) {
  // A list with nothing in it is not drawn: an empty card under a heading reads as a failure.
  if (items.length === 0) return null

  return (
    <section data-testid={testid}>
      <h3 className="mb-2 text-sm font-bold text-text-primary">{title}</h3>
      <div className="grid grid-cols-2 gap-3">
        {items.map((c, i) => (
          <ContentTile key={c.content_key ?? `${c.name}-${i}`} content={c} locale={locale} currency={currency} onOpen={onOpen} rank={ranked ? i + 1 : undefined} />
        ))}
      </div>
    </section>
  )
}
