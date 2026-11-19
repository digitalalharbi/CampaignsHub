import { lazy, Suspense, useCallback, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useSearchParams } from 'react-router-dom'
import { FileText, Globe, Image as ImageIcon, LayoutDashboard, Layers, RefreshCw, Table as TableIcon } from 'lucide-react'
import { ProviderName } from '@/components/ProviderMark'
import { platformColor } from '@/features/analytics/components'
import { canonicalPlatform, isAdPlatform, sortPlatforms } from '@/lib/platforms'
import { formatSpan } from '@/lib/period/periodMath'
import { providerLabel } from '@/features/campaigns/labels'
import { useUi } from '@/stores/ui'
import type { ReportAd } from './ReportAdsSection'
import { ReportAdDetail } from './ReportAdDetail'
import { LiveContentDetail } from './live/LiveContent'
import { FreshnessStrip } from './live/LiveSections'
import { ContentView, DashboardView, PlatformsView, platformsOf, StandardView, SummaryView, WebsiteView } from './live/LiveViews'
import { websiteHasContent } from './Ga4ReportBlocks'
import { TablesView } from './live/LiveTables'
import { useLiveMetricReader } from './live/liveMetrics'
import { levelOf, MODE_LABELS, modesFor, offeredViews, ownsPlatformChoice, readMode, type LiveLevel, type LiveMode } from './live/modes'
import { sectionShown } from './reportSections'
import { useLivePayload } from './live/useLivePayload'
import { ReportFooter, reportStamp } from './reportFooter'
import { LiveDeliveryNote } from './live/LiveDeliveryNote'

/*
 * REPORT-DATE-PICKER-001 — the picker (calendar, presets, typed fields) is loaded when the period bar renders, not with the
 * route: it pulled the /r/ closure 11.8 KB over PUBLIC_REPORT_BUDGET_BYTES. The fallback is the same-size button, so nothing shifts.
 */
const DateRangeField = lazy(() => import('@/components/ui/DateRangeField').then((m) => ({ default: m.DateRangeField })))

/*
 * ANALYTICS-REPORT-LOGIC-001 — the platform drawer is read after a click, so it is loaded then, not with the route.
 * Moving the report's logic into the shared layer put the /r/ closure over its byte budget; the drawer is the largest piece of the
 * page that nobody sees on arrival.
 */
const LivePlatformDrawer = lazy(() => import('./live/LivePlatformDrawer').then((m) => ({ default: m.LivePlatformDrawer })))

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

/*
 * REPORT-MOBILE-APP-002 — Owner 2026-11-18: the period is chosen in the system's own picker only. The 7 / 30 / 90 buttons beside it are gone;
 * the picker keeps its presets inside. Until the reader chooses, the link opens on the last 30 days.
 */
const DEFAULT_DAYS = 30

/* The period in the system's own date style («10 نوفمبر – 16 نوفمبر 2026»), the one the picker prints. */
const periodText = (from: string, to: string, ar: boolean): string => formatSpan({ from, to }, ar)

const MODE_ICONS: Record<LiveMode, typeof LayoutDashboard> = {
  summary: FileText,
  standard: FileText,
  tables: TableIcon,
  dashboard: LayoutDashboard,
  platforms: Layers,
  content: ImageIcon,
  website: Globe,
}

export function LiveSharedReport({
  token,
  password,
  currency,
  form = 'detailed',
  periodMode,
}: {
  token: string
  password?: string
  currency: string
  /**
   * REPORT-PRODUCT-MODEL-001 — which product the operator sent. A summary link is the summary and
   * nothing else; a detailed link opens on the dashboard and offers every mode (`live/modes.ts`).
   */
  form?: 'executive_summary' | 'detailed'
  /**
   * REPORT-PERIOD-MODE-001 — `fixed` is exactly the saved dates: no 7/30/90-day presets, and nothing is asked of the server but the link.
   * Absent or `rolling` is the window that follows the reader's day, which is what every link made before the mode did.
   */
  periodMode?: 'fixed' | 'rolling'
}) {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const [search, setSearch] = useSearchParams()

  /*
   * The mode and the platform live in the ADDRESS, so a client can send «the Snapchat view» to a
   * colleague, reload without losing their place, and use Back between modes.
   */
  // The level is known once the payload lands; until then the link is read as the form states it.
  const firstMode = readMode(search.get('view'), form)
  const rawPlatform = search.get('platform')

  const levelRef = useRef<LiveLevel | null>(null)
  // The opening view once the payload says which views this link offers.
  const firstRef = useRef<LiveMode | null>(null)
  const navigate = useCallback((next: LiveMode, platform?: string | null) => {
    setSearch((prev) => {
      const params = new URLSearchParams(prev)
      if (next === (firstRef.current ?? modesFor(form, levelRef.current)[0])) params.delete('view')
      else params.set('view', next)
      if (platform) params.set('platform', platform)
      else params.delete('platform')
      return params
    })
  }, [setSearch, form])

  const fixed = periodMode === 'fixed'
  const days = fixed ? null : DEFAULT_DAYS
  /* REPORT-DATE-PICKER-001 — Owner 2026-11-17: the report's period is chosen in the system's own picker (presets, calendar, typed dates). */
  const [custom, setCustom] = useState<{ from: string; to: string } | null>(null)
  const range = fixed ? null : custom
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
    range,
    providers: ownsPlatformChoice(firstMode) ? [] : providers,
    failedMessage,
    refreshEveryMs: 5 * 60_000,
  })

  const whole = main.load.state === 'ready' ? main.load.payload : null
  // REPORT-ARCHITECTURE-001 — the product (summary / standard / detailed) decides which views exist.
  const level = levelOf(form, whole?.detail_level)
  levelRef.current = level
  const offered = modesFor(form, level)
  /*
   * REPORT-SECTION-SURFACES-001 — a view whose section the report does not carry is not offered:
   * the platform view reads the comparison's rows, the content view the content section.
   */
  const offeredHere = whole === null ? offered : offeredViews(offered, whole.report_views).filter((m) => (
    m === 'platforms' ? sectionShown(whole, 'platform_comparison')
      : m === 'content' ? sectionShown(whole, 'content_performance')
        : m === 'website' ? websiteHasContent(whole as never)
          : true
  ))
  // REPORT-COMPOSITION-CONTROL-001 — the address names a view; only one this link offers opens.
  const mode = whole === null ? readMode(search.get('view'), form, level) : (offeredHere.find((m) => m === search.get('view')) ?? offeredHere[0])
  firstRef.current = offeredHere[0] ?? null
  const known = whole ? platformsOf(whole) : []
  const platform = ownsPlatformChoice(mode)
    ? (rawPlatform && known.includes(rawPlatform) ? rawPlatform : (mode === 'platforms' ? known[0] ?? null : null))
    : null

  /* The narrowed read for the platform and content modes — the same endpoint, one platform. */
  const narrowed = useLivePayload({
    token,
    secret: password,
    days,
    range,
    providers: platform ? [platform] : [],
    enabled: platform !== null,
    failedMessage,
  })

  /*
   * REPORT-CURRENCY-TRUTH-001 — the money is formatted in the unit the PAYLOAD states, which is the
   * unit the rows are normalised to. The `currency` prop is the report row's stamp — written by the
   * controllers as the reporting default — and on the owner's live link it printed «45.9K USD» over
   * figures the same payload's budget block called 45.9K SAR. Until the first payload lands the stamp
   * is all there is; once it has, the rows win, and a scope held in two currencies formats bare.
   */
  const unit = whole === null ? currency : (whole.currency ?? '')
  const reader = useLiveMetricReader(unit, ar)
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
  const common = { reader, currency: unit, locale, onOpenContent }

  return (
    /*
     * `[&>*]:min-w-0` — without it this page scrolls sideways on a phone: a grid item's `min-width`
     * is `auto`, and a chart container's min-content is wider than a 375px screen.
     */
    <div className="grid gap-3 sm:gap-4 [&>*]:min-w-0" data-testid="live-report" data-mode={mode}>
      {offeredHere.length > 1 && (
        <nav role="tablist" aria-label={ar ? 'طريقة العرض' : 'View'} data-testid="live-modes" className="grid w-full gap-0.5 rounded-2xl border border-border bg-surface p-1 sm:gap-1" style={{ gridTemplateColumns: `repeat(${offeredHere.length}, minmax(0, 1fr))` }}>
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
                className={`flex min-h-14 min-w-0 flex-col items-center justify-center gap-0.5 rounded-xl px-0.5 py-1.5 text-center text-[11px] font-semibold leading-tight transition-colors active:scale-95 sm:min-h-0 sm:flex-row sm:gap-1.5 sm:px-3.5 sm:py-2 sm:text-sm ${
                  selected ? 'bg-brand-600 text-white' : 'text-text-secondary hover:bg-surface-hover'
                }`}
              >
                <Icon size={20} className="shrink-0" aria-hidden />
                {ar ? MODE_LABELS[m].ar : MODE_LABELS[m].en}
              </button>
            )
          })}
        </nav>
      )}

      <div className="flex flex-wrap items-center gap-1.5 rounded-2xl border border-border bg-surface p-2 sm:gap-2 sm:p-3" data-testid="live-controls">
        <div className="flex flex-wrap items-center gap-1" role="group" aria-label={ar ? 'الفترة' : 'Period'}>
          {!fixed && (payload.period.kind ?? 'custom') === 'custom' && (
            <div className="min-w-[14rem] max-w-full">
              <Suspense fallback={<div className="min-h-11 w-full rounded-xl border border-border-strong bg-surface sm:min-h-10" aria-hidden />}>
                <DateRangeField from={custom?.from ?? payload.period.from} to={custom?.to ?? payload.period.to} onChange={(from, to) => setCustom({ from, to })} testId="live-range-picker" />
              </Suspense>
            </div>
          )}
          {!fixed && (payload.period.kind ?? 'custom') !== 'custom' && (
            <span data-testid="live-period-kind" data-kind={payload.period.kind} className="rounded-xl border border-border px-3 py-1.5 text-sm font-semibold text-text-secondary">
              <bdi>{periodText(payload.period.from, payload.period.to, ar)}</bdi>
              {/* REPORT-CLIENT-NO-DELTAS-001 — «compared with the previous period» is the agency's view unless the Owner switched it on. */}
              {payload.sections?.previous_comparison !== false && (<>{' · '}{ar ? payload.period.comparison_ar : payload.period.comparison_en}</>)}
              {payload.period.partial ? (ar ? ' · الفترة لم تكتمل' : ' · in progress') : ''}
            </span>
          )}
          {fixed && (
            <span data-testid="live-period-fixed" className="tnum rounded-xl border border-border px-3 py-1.5 text-sm font-semibold text-text-secondary">
              <bdi>{periodText(payload.period.from, payload.period.to, ar)}</bdi>
            </span>
          )}
        </div>

        {!ownsPlatformChoice(mode) && payload.available.providers.filter(isAdPlatform).length > 1 && (
          <div className="flex flex-wrap gap-1.5" role="group" aria-label={ar ? 'المنصات' : 'Platforms'}>
            {/* PLATFORM-ORDER-002 — the product's order (سناب · تيك توك · ميتا · جوجل · إكس · لينكدإن · ChatGPT), and logos large enough to read on a phone. */}
            {sortPlatforms(payload.available.providers.filter(isAdPlatform)).map((p) => {
              const on = providers.includes(p)

              return (
                <button
                  key={p}
                  type="button"
                  data-testid={`live-platform-${p}`}
                  aria-pressed={on}
                  onClick={() => setProviders((list) => (list.includes(p) ? list.filter((v) => v !== p) : [...list, p]))}
                  title={providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en')}
                  aria-label={providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en')}
                  className={`inline-flex h-12 w-12 items-center justify-center rounded-2xl border-2 transition-colors sm:h-14 sm:w-14 ${
                    on ? '' : 'border-border hover:bg-surface-hover'
                  }`}
                  style={on ? { borderColor: platformColor(p), background: `color-mix(in srgb, ${platformColor(p)} 14%, transparent)` } : undefined}
                >
                  <span className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-surface-hover sm:h-11 sm:w-11">
                    <ProviderName provider={p} locale={ar ? 'ar' : 'en'} size={28} logoOnly />
                  </span>
                </button>
              )
            })}
          </div>
        )}

        <div className="ms-auto flex items-center gap-2">
          <button
            type="button"
            data-testid="live-refresh"
            onClick={main.reload}
            className="inline-flex items-center gap-1.5 rounded-xl border border-border px-2.5 py-1.5 text-xs font-semibold text-text-secondary hover:bg-surface-hover"
          >
            <RefreshCw size={13} className={refreshing ? 'animate-spin' : ''} aria-hidden />
            <span className="sr-only sm:not-sr-only">{ar ? 'تحديث' : 'Refresh'}</span>
          </button>
        </div>
      </div>

      <FreshnessStrip freshness={payload.freshness} ar={ar} />

      {refreshError && (
        <p data-testid="live-refresh-failed" className="rounded-xl border border-border bg-[var(--warning-background)] px-3 py-2 text-xs text-warning">
          {ar ? 'تعذّر تحديث الأرقام — المعروض هو آخر ما حُسب.' : 'The figures could not be refreshed — what is shown was computed last.'}
        </p>
      )}

      {/*
        LIVE-LINK-BEFORE-CAMPAIGNS-001 — a link opened before anything has run. The accounts are connected and the link is live: the figures
        appear here on their own when the first campaign syncs. A page of zeros would say the campaigns ran and produced nothing.
      */}
      {payload.no_data_yet === true && (
        <section data-testid="live-no-data-yet" className="rounded-2xl border border-border bg-surface px-5 py-6 text-center">
          <h2 className="text-base font-bold text-text-primary">{ar ? 'التقرير جاهز وبانتظار أول بيانات' : 'Your report is ready and waiting for its first data'}</h2>
          <p className="mx-auto mt-1 max-w-xl text-sm text-text-secondary">
            {ar
              ? 'هذا الرابط جاهز ومتصل. ستظهر الإعلانات والنتائج والأرقام هنا تلقائيًا بمجرد وصول أول بيانات، دون الحاجة إلى رابط جديد.'
              : 'This link is live and connected. Ads, results and figures will appear here automatically as soon as the first data arrives — no new link needed.'}
          </p>
        </section>
      )}

      {/* Dimmed, not blanked, while refreshing: blanking would make every filter change feel like a page load. */}
      <div className={refreshing ? 'pointer-events-none opacity-60 transition-opacity' : 'transition-opacity'}>
        {mode === 'summary' && <SummaryView payload={payload} {...common} onOpenWebsite={offeredHere.includes('website') ? () => navigate('website') : undefined} />}
        {mode === 'standard' && <StandardView payload={payload} {...common} onOpenWebsite={offeredHere.includes('website') ? () => navigate('website') : undefined} />}
        {mode === 'website' && offeredHere.includes('website') && <WebsiteView payload={payload} ar={ar} />}
        {mode === 'tables' && <TablesView payload={payload} reader={reader} locale={locale === 'ar' ? 'ar' : 'en'} onOpenContent={onOpenContent} />}
        {mode === 'dashboard' && <DashboardView payload={payload} {...common} goTo={(m, p) => navigate(m, p)} canGo={(m) => offeredHere.includes(m)} onOpenPlatform={setDrawer} />}
        {mode === 'platforms' && offeredHere.includes('platforms') && (
          <PlatformsView
            whole={payload}
            {...common}
            platform={platform}
            onPlatform={(p) => navigate('platforms', p)}
            narrowed={narrowed.load}
            goToContent={offeredHere.includes('content') ? (p) => navigate('content', p) : undefined}
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

      {/*
        REPORT-FOOTER-001 — the same footer every report surface ends with. The objective is stated only
        where the link's campaigns share ONE; a mixed programme names none rather than guessing.
      */}
      <LiveDeliveryNote delivery={payload.delivery} ar={ar} />

      <ReportFooter
        locale={ar ? 'ar' : 'en'}
        facts={{
          /*
           * REPORT-FRESHNESS-DATE-001 — Owner 2026-11-17: «إذا كان هناك تأخر لا تحدّث التاريخ بالأسفل». When a platform's figures are
           * not confirmed fresh, the report's one date is the oldest platform confirmation, never the moment it was recomputed; the
           * warning banner above the figures is gone, so this date is the only claim and it is a true one.
           */
          updatedAt: reportStamp(payload.freshness_summary) ?? computedAt.toISOString(),
          period: payload.period,
          currency: unit || null,
          timezone: 'Asia/Riyadh',
          objective: (payload.objective_analytics?.families?.length ?? 0) === 1 ? payload.objective_analytics?.families?.[0]?.family ?? null : null,
        }}
      />

      {drawer && mode === 'dashboard' && (
        <Suspense fallback={null}>
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
        </Suspense>
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
