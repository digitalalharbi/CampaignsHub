import { useCallback, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { useSearchParams } from 'react-router-dom'
import { FileText, Image as ImageIcon, LayoutDashboard, Layers, RefreshCw } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { platformColor } from '@/features/analytics/components'
import { canonicalPlatform } from '@/lib/platforms'
import { useUi } from '@/stores/ui'
import { Num } from '@/components/ui/Num'
import type { ReportAd } from './ReportAdsSection'
import { ReportAdDetail } from './ReportAdDetail'
import { LiveContentDetail } from './live/LiveContent'
import { LivePlatformDrawer } from './live/LivePlatformDrawer'
import { FreshnessStrip } from './live/LiveSections'
import { ContentView, DashboardView, PlatformsView, platformsOf, SummaryView } from './live/LiveViews'
import { useLiveMetricReader } from './live/liveMetrics'
import { MODE_LABELS, modesFor, ownsPlatformChoice, readMode, type LiveMode } from './live/modes'
import { sectionShown } from './reportSections'
import { useLivePayload } from './live/useLivePayload'

/**
 * LIVEREP-001 — the client's own view of a live shared link.
 *
 * ## Why this is a separate page from `PublicReport`
 *
 * A snapshot report is a **document**: it was generated, signed off, and says the same thing every
 * time it is opened. This is a **dashboard**: it recomputes, and the reader is expected to poke at it.
 * They want different things from their reader, so folding them into one component would mean a page
 * that is half-filtered and half-frozen, with no honest way to label either half.
 *
 * ## The two rules this page follows
 *
 * 1. **Filters never reload the page.** Changing the period or unticking a platform re-fetches one
 *    endpoint and re-renders; the shell, the branding and the scroll position stay where they were.
 *    While that request is in flight the previous figures stay on screen, dimmed — blanking them would
 *    make every filter change feel like a page load, which is the thing being avoided.
 * 2. **The page never claims more freshness than it has.** «Live» here means *this system recomputed
 *    just now*, which is not the same as *the ad platform reported just now*. Both are shown, per
 *    platform, and a platform with no credentials says so in the place where its number would be —
 *    because a zero and «we cannot see this account» look identical on a chart and mean the opposite.
 */

/** Period choices, in days. Presets rather than a date picker: a client wants «this month», not a range. */
const RANGES = [
  { days: 7, ar: '7 أيام', en: '7 days' },
  { days: 30, ar: '30 يومًا', en: '30 days' },
  { days: 90, ar: '90 يومًا', en: '90 days' },
] as const

const MODE_ICONS: Record<LiveMode, typeof LayoutDashboard> = {
  summary: FileText,
  dashboard: LayoutDashboard,
  platforms: Layers,
  content: ImageIcon,
}

export function LiveSharedReport({
  token,
  password,
  currency,
  form = 'detailed',
}: {
  token: string
  password?: string
  currency: string
  /**
   * REPORT-PRODUCT-MODEL-001 — which product the operator sent. A summary link is the summary and
   * nothing else; a detailed link opens on the dashboard and offers every mode (`live/modes.ts`).
   */
  form?: 'executive_summary' | 'detailed'
}) {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const [search, setSearch] = useSearchParams()

  /*
   * The mode and the platform live in the ADDRESS, so a client can send «the Snapchat view» to a
   * colleague, reload without losing their place, and use Back between modes.
   */
  const mode = readMode(search.get('view'), form)
  const offered = modesFor(form)
  const rawPlatform = search.get('platform')

  const navigate = useCallback((next: LiveMode, platform?: string | null) => {
    setSearch((prev) => {
      const params = new URLSearchParams(prev)
      if (next === (form === 'executive_summary' ? 'summary' : 'dashboard')) params.delete('view')
      else params.set('view', next)
      if (platform) params.set('platform', platform)
      else params.delete('platform')
      return params
    })
  }, [setSearch, form])

  const [days, setDays] = useState(30)
  /*
   * CLIENT-REPORT-ENTITY-BOUNDARY-001 — platform is the only scope a client narrows by. The chips
   * belong to the summary and the dashboard; the platform and content modes choose one platform
   * themselves and read the whole link for their comparisons.
   */
  const [providers, setProviders] = useState<string[]>([])
  const [open, setOpen] = useState<ReportAd | null>(null)
  /* REPORT-DRILLDOWN-001 — the platform opened from the comparison. Not in the address: a drawer is a glance, not a place. */
  const [drawer, setDrawer] = useState<string | null>(null)

  const failedMessage = ar ? 'تعذّر تحميل التقرير.' : 'The report could not be loaded.'
  const main = useLivePayload({
    token,
    secret: password,
    days,
    providers: ownsPlatformChoice(mode) ? [] : providers,
    failedMessage,
    refreshEveryMs: 5 * 60_000,
  })

  const whole = main.load.state === 'ready' ? main.load.payload : null
  /*
   * REPORT-SECTION-SURFACES-001 — a view whose section the report does not carry is not offered:
   * the platform view reads the comparison's rows, the content view the content section.
   */
  const offeredHere = whole === null ? offered : offered.filter((m) => (
    m === 'platforms' ? sectionShown(whole, 'platform_comparison')
      : m === 'content' ? sectionShown(whole, 'content_performance')
        : true
  ))
  const known = whole ? platformsOf(whole) : []
  const platform = ownsPlatformChoice(mode)
    ? (rawPlatform && known.includes(rawPlatform) ? rawPlatform : (mode === 'platforms' ? known[0] ?? null : null))
    : null

  /* The narrowed read for the platform and content modes — the same endpoint, one platform. */
  const narrowed = useLivePayload({
    token,
    secret: password,
    days,
    providers: platform ? [platform] : [],
    enabled: platform !== null,
    failedMessage,
  })

  const reader = useLiveMetricReader(currency, ar)
  const onOpenContent = useMemo(() => (c: ReportAd) => setOpen(c), [])

  if (main.load.state === 'pending') {
    return (
      <div data-testid="live-skeleton" aria-busy="true" className="grid gap-4">
        <div className="h-14 animate-pulse rounded-2xl bg-surface-secondary" />
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {[0, 1, 2, 3].map((i) => <div key={i} className="h-32 animate-pulse rounded-2xl bg-surface-secondary" />)}
        </div>
        <div className="h-72 animate-pulse rounded-2xl bg-surface-secondary" />
      </div>
    )
  }
  if (main.load.state === 'failed') {
    return (
      <div data-testid="live-failed" className="mx-auto max-w-md rounded-2xl border border-border bg-surface p-8 text-center">
        <p className="text-sm text-text-secondary">{main.load.message}</p>
        <button
          type="button"
          onClick={main.reload}
          className="mt-4 rounded-xl border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary hover:bg-surface-hover"
        >
          {ar ? 'إعادة المحاولة' : 'Try again'}
        </button>
      </div>
    )
  }

  const { payload, refreshing, refreshError, computedAt } = main.load
  const stamp = computedAt.toLocaleTimeString(ar ? 'ar-SA-u-nu-latn' : 'en-GB', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
  const common = { reader, currency, locale, onOpenContent }

  return (
    /*
     * `[&>*]:min-w-0` — without it this page scrolls sideways on a phone: a grid item's `min-width`
     * is `auto`, and a chart container's min-content is wider than a 375px screen.
     */
    <div className="grid gap-4 [&>*]:min-w-0" data-testid="live-report" data-mode={mode}>
      {offeredHere.length > 1 && (
        <nav role="tablist" aria-label={ar ? 'طريقة العرض' : 'View'} data-testid="live-modes" className="grid gap-1 rounded-2xl border border-border bg-surface p-1" style={{ gridTemplateColumns: `repeat(${offeredHere.length}, minmax(0, 1fr))` }}>
          {offeredHere.map((m) => {
            const Icon = MODE_ICONS[m]
            const selected = m === mode

            return (
              <button
                key={m}
                type="button"
                role="tab"
                aria-selected={selected}
                data-testid={`live-mode-tab-${m}`}
                onClick={() => navigate(m)}
                className={`flex min-w-0 flex-col items-center justify-center gap-1 rounded-xl px-1 py-2 text-xs font-semibold transition-colors sm:flex-row sm:gap-1.5 sm:px-3.5 sm:text-sm ${
                  selected ? 'bg-brand-600 text-white' : 'text-text-secondary hover:bg-surface-hover'
                }`}
              >
                <Icon size={15} aria-hidden />
                {ar ? MODE_LABELS[m].ar : MODE_LABELS[m].en}
              </button>
            )
          })}
        </nav>
      )}

      <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-border bg-surface p-3">
        <div className="flex gap-1" role="group" aria-label={ar ? 'الفترة' : 'Period'}>
          {RANGES.map((r) => (
            <button
              key={r.days}
              type="button"
              data-testid={`live-range-${r.days}`}
              onClick={() => setDays(r.days)}
              className={`rounded-xl px-3 py-1.5 text-sm font-semibold transition-colors ${
                days === r.days
                  ? 'bg-brand-600 text-white'
                  : 'border border-border text-text-secondary hover:bg-surface-hover'
              }`}
            >
              {ar ? r.ar : r.en}
            </button>
          ))}
        </div>

        {!ownsPlatformChoice(mode) && payload.available.providers.length > 1 && (
          <div className="flex flex-wrap gap-1" role="group" aria-label={ar ? 'المنصات' : 'Platforms'}>
            {payload.available.providers.map((p) => (
              <button
                key={p}
                type="button"
                data-testid={`live-platform-${p}`}
                aria-pressed={providers.includes(p)}
                onClick={() => setProviders((list) => (list.includes(p) ? list.filter((v) => v !== p) : [...list, p]))}
                className={`rounded-xl border px-2.5 py-1.5 text-xs font-semibold transition-colors ${
                  providers.includes(p)
                    ? 'border-transparent text-white'
                    : 'border-border text-text-secondary hover:bg-surface-hover'
                }`}
                style={providers.includes(p) ? { background: platformColor(p) } : undefined}
              >
                {providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en')}
              </button>
            ))}
          </div>
        )}

        <div className="ms-auto flex items-center gap-2">
          {/* «Live» means this system recomputed at this time — not that a platform reported just now. */}
          <span data-testid="live-computed-at" className="inline-flex items-center gap-1.5 text-xs text-text-muted">
            <span className="relative flex h-2 w-2" aria-hidden>
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-success opacity-60 motion-reduce:hidden" />
              <span className="relative inline-flex h-2 w-2 rounded-full bg-success" />
            </span>
            <span className="tabular-nums"><Num>{stamp}</Num></span>
          </span>
          <button
            type="button"
            data-testid="live-refresh"
            onClick={main.reload}
            className="inline-flex items-center gap-1.5 rounded-xl border border-border px-2.5 py-1.5 text-xs font-semibold text-text-secondary hover:bg-surface-hover"
          >
            <RefreshCw size={13} className={refreshing ? 'animate-spin' : ''} aria-hidden />
            {ar ? 'تحديث' : 'Refresh'}
          </button>
        </div>
      </div>

      <FreshnessStrip freshness={payload.freshness} ar={ar} />

      {refreshError && (
        <p data-testid="live-refresh-failed" className="rounded-xl border border-border bg-[var(--warning-background)] px-3 py-2 text-xs text-warning">
          {ar ? 'تعذّر تحديث الأرقام — المعروض هو آخر ما حُسب.' : 'The figures could not be refreshed — what is shown was computed last.'}
        </p>
      )}

      {/* Dimmed, not blanked, while refreshing: blanking would make every filter change feel like a page load. */}
      <div className={refreshing ? 'pointer-events-none opacity-60 transition-opacity' : 'transition-opacity'}>
        {mode === 'summary' && <SummaryView payload={payload} {...common} />}
        {mode === 'dashboard' && <DashboardView payload={payload} {...common} goTo={(m, p) => navigate(m, p)} onOpenPlatform={setDrawer} />}
        {mode === 'platforms' && offeredHere.includes('platforms') && (
          <PlatformsView
            whole={payload}
            {...common}
            platform={platform}
            onPlatform={(p) => navigate('platforms', p)}
            narrowed={narrowed.load}
            goToContent={(p) => navigate('content', p)}
          />
        )}
        {mode === 'content' && offeredHere.includes('content') && (
          <ContentView
            whole={payload}
            currency={currency}
            locale={locale}
            onOpenContent={onOpenContent}
            platform={platform}
            onPlatform={(p) => navigate('content', p)}
            narrowed={narrowed.load}
          />
        )}
      </div>

      {drawer && mode === 'dashboard' && (
        <LivePlatformDrawer
          token={token}
          secret={password}
          provider={drawer}
          whole={payload}
          reader={reader}
          currency={currency}
          locale={locale}
          onOpenContent={onOpenContent}
          onClose={() => setDrawer(null)}
        />
      )}

      {/*
        Portalled with the platform drawer: a content tile inside the drawer opens this dialog, and a
        dialog left inside the report's stacking context would open BENEATH the drawer on the body.
      */}
      {open && createPortal(open.content_key && payload.breakdowns?.content_drilldown !== false
        ? (
          <LiveContentDetail
            token={token}
            secret={password}
            content={open}
            from={payload.period.from}
            to={payload.period.to}
            currency={currency}
            locale={locale}
            onClose={() => setOpen(null)}
          />
        )
        : <ReportAdDetail ad={open} currency={currency} locale={locale} onClose={() => setOpen(null)} />,
      document.body,
      )}
    </div>
  )
}
