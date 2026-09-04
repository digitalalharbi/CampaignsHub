import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { providerLabel } from '@/features/campaigns/labels'
import { clientKpiKeys } from './clientKpis'
import { ClientAttention } from './ClientAttention'
import { readMetricValue, type MetricValue } from '@/lib/metricValue'
import { LiveDetailTables } from './LiveDetailTables'
import { ReportAdDetail } from './ReportAdDetail'
import { ReportAdsSection, type ReportAd } from './ReportAdsSection'
import { canonicalPlatform } from '@/lib/platforms'
import { AlertTriangle, RefreshCw } from 'lucide-react'
import {
  ChartCard,
  ConversionFunnelChart,
  MetricLineChart,
  PlatformDonutChart,
} from '@/features/analytics/charts'
import { KpiCard, platformColor } from '@/features/analytics/components'
import { money, moneyExact, moneyFromTotals, ratio } from '@/features/analytics/format'
import { formatMoneyReading, moneyState, rankableMoney, readCostPer, readRoas, type MoneyTotals } from '@/lib/money/contract'
import { fetchLiveShared, type LivePayload } from './api'
import { useUi } from '@/stores/ui'

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

const isoDaysAgo = (days: number) => {
  const d = new Date()
  d.setDate(d.getDate() - days + 1)
  return d.toISOString().slice(0, 10)
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
   * REPORT-PRODUCT-MODEL-001 — which of the two LIVE products this is.
   *
   * `executive_summary` is the Live Dashboard: the figures, filterable, recomputed on open.
   * `detailed` is the Live Detailed Report: the same dashboard, and then every campaign and every
   * platform in the window as tables. Before this the page rendered the dashboard either way, under
   * a label that promised «every platform, campaign and creative» — the composition and the sentence
   * above it disagreed, and the client was reading the sentence.
   */
  form?: 'executive_summary' | 'detailed'
}) {
  const { locale } = useUi()
  const ar = locale === 'ar'

  const [days, setDays] = useState(30)
  /*
   * CLIENT-REPORT-ENTITY-BOUNDARY-001 — platform is the only scope a client narrows by.
   *
   * There was a campaign picker beside this one, offering «كل الحملات» over a list of the agency's
   * own campaign names. The narrowing is still here; what a client narrows BY is the aggregate
   * channel, which is what they were reading everywhere else on the page anyway. The request still
   * carries an empty campaign list because the server's ceiling is expressed in campaign ids — the
   * link's own scope, which the reader neither sets nor sees.
   */
  const [providers, setProviders] = useState<string[]>([])
  const [payload, setPayload] = useState<LivePayload | null>(null)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState<string | null>(null)
  /* REPORT-AD-PREVIEW-001 §C — the ad whose detail is open, or none. */
  const [openAd, setOpenAd] = useState<ReportAd | null>(null)

  /*
   * A request counter, so a slow answer cannot overwrite a fast one.
   *
   * Every filter change starts a fetch, and they do not come back in the order they were sent. Without
   * this, clicking «7 days» then «90 days» can leave the 7-day figures on screen under a «90 days»
   * label — numbers that are real, for a period the reader is not looking at. That is worse than a
   * spinner, because nothing about it looks wrong.
   */
  const latest = useRef(0)

  const load = useCallback(async () => {
    const ticket = ++latest.current
    setBusy(true)
    const { status, envelope } = await fetchLiveShared(token, {
      from: isoDaysAgo(days),
      to: new Date().toISOString().slice(0, 10),
      providers,
      campaigns: [],
      password,
    })
    if (ticket !== latest.current) return
    if (status === 200) {
      setPayload(envelope.data as LivePayload)
      setError(null)
    } else {
      setError(envelope.message ?? (ar ? 'تعذّر تحديث البيانات.' : 'Could not refresh the figures.'))
    }
    setBusy(false)
  }, [token, days, providers, password, ar])

  useEffect(() => {
    void load()
  }, [load])

  const toggle = (list: string[], set: (v: string[]) => void, value: string) =>
    set(list.includes(value) ? list.filter((v) => v !== value) : [...list, value])

  /*
   * `asMoney`, not `money`: this closure already carries the payload's currency, and a second thing
   * called `money` in a file that also formats money is how a missing currency hides in plain sight.
   *
   * ## Why it delegates rather than formatting its own way — MONEY-SCOPE-TRUTH-001
   *
   * It used `Intl` with `style: 'currency'`, which printed «$7,420» — while every table on the SAME
   * page, drawn by `MetricTable` through the money contract, printed «7.75K USD». One document, two
   * notations for one currency, observed on the owner's live link. A reader comparing the strip to
   * the table below it has to decide whether they are looking at the same units, and that is a
   * question a report must never make them ask.
   *
   * Two further things the symbol form got wrong and this does not: a currency the scope cannot
   * state comes back bare rather than under a guessed symbol, and `$` is the symbol of a dozen
   * currencies — «USD» spelled out is the one form that cannot be misread.
   */
  const asMoney = useMemo(() => (v: number | null | undefined) => money(v, currency || null), [currency])
  /*
   * A cost-per is stated EXACTLY — §58, and found by reading the page rather than the code.
   *
   * Every cost-per here went through `asMoney`, which compacts: a CPC of about one and a half dollars
   * printed «2 USD» on a client's link. On a total the fraction is noise; on a cost-per it IS the
   * figure, and «2» instead of «1.50» is a different decision about the same campaign.
   */
  const asExactMoney = useMemo(() => (v: number | null | undefined) => moneyExact(v, currency || null), [currency])
  /*
   * A count, through the product's one value law — §58.
   *
   * This formatted every count in full: the owner's screenshot shows «29,210», «4,127,676» and
   * «1,723,184» beside «5.54K USD» in one row of six cards. Three notations for one idea, on the page
   * a client reads, and the client has to decide for themselves whether four million one hundred
   * twenty-seven thousand is a lot. A figure read for SCALE is read faster compact, and the exact one
   * stays a hover away rather than being lost.
   */
  const count = (v: number | null | undefined) => readMetricValue('number', v)

  /** A reading that is already final — money through the contract, a percentage, a ratio. */
  const plain = (text: string): MetricValue => ({ text, exact: null, value: null })

  /*
   * How each chosen metric is rendered.
   *
   * A table rather than a chain of conditionals, because the SET is chosen by the operator at link
   * time and the page must be able to render any subset in the order they picked. Formatting lives
   * with the metric so «spend» is always money and «ROAS» is always a multiplier, wherever it appears.
   */
  const METRIC_META: Record<string, {
    ar: string
    en: string
    invertGood?: boolean
    spark?: boolean
    format: (
      t: Record<string, number | null>,
      p: LivePayload,
      money: (v: number | null | undefined) => string,
      count: (v: number | null | undefined) => MetricValue,
    ) => MetricValue
  }> = {
    // PARTIAL-WITHHELD-001 — a client link is the one place the reader has no other view, so money
    // goes through the contract: partial/mixed ⇒ «—», withheld ⇒ the original in its own currency,
    // never the coalesced 0 or the converted subset.
    spend: { ar: 'الإنفاق', en: 'Spend', invertGood: true, spark: true, format: (t, p) => plain(moneyFromTotals(t as MoneyTotals, 'spend', ar, p.currency).text) },
    impressions: { ar: 'الظهور', en: 'Impressions', spark: true, format: (t, _p, _m, count) => count(t.impressions) },
    clicks: { ar: 'النقرات', en: 'Clicks', spark: true, format: (t, _p, _m, count) => count(t.clicks) },
    ctr: { ar: 'نسبة النقر', en: 'CTR', format: (t) => plain(t.ctr === null || t.ctr === undefined ? '—' : `${(t.ctr * 100).toFixed(2)}%`) },
    conversions: { ar: 'النتائج', en: 'Results', spark: true, format: (t, _p, _m, count) => count(t.conversions) },
    // Add-to-cart is a funnel stage rather than a total, so it is read from where it actually lives.
    add_to_cart: { ar: 'الإضافات للسلة', en: 'Add to cart', format: (_t, p, _m, count) => count(p.funnel.find((f) => f.stage === 'add_to_cart')?.count) },
    purchases: { ar: 'المشتريات', en: 'Purchases', format: (t, _p, _m, count) => count(t.purchases) },
    revenue: { ar: 'الإيرادات', en: 'Revenue', format: (t, p) => plain(moneyFromTotals(t as MoneyTotals, 'revenue', ar, p.currency).text) },
    roas: { ar: 'العائد على الإنفاق', en: 'ROAS', format: (t) => { const r = readRoas(t as MoneyTotals, ar); return plain(ratio(r.value)) } },
    cpa: { ar: 'تكلفة النتيجة', en: 'Cost per result', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpa', 'conversions', p.currency, ar), (v) => asExactMoney(v))) },
    /*
     * The rest of what a marketing path is judged on — ANALYTICS-OBJECTIVE-SYSTEM-001.
     *
     * `objective_performance` names these per path (`headline_metrics`), and the client block could
     * render none of them: an awareness report is judged on reach, frequency and CPM, and the block
     * offered impressions and add-to-cart. Every cost-per goes through the money contract, exactly as
     * `cpa` does — a rate whose numerator is partly withheld is «—», never the converted subset.
     */
    reach: { ar: 'الوصول', en: 'Reach', format: (t, _p, _m, count) => count(t.reach) },
    frequency: { ar: 'التكرار', en: 'Frequency', format: (t) => plain(t.frequency === null || t.frequency === undefined ? '—' : ratio(t.frequency)) },
    cpm: { ar: 'تكلفة الألف ظهور', en: 'CPM', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpm', (Number(t.impressions ?? 0)) / 1000, p.currency, ar), (v) => asExactMoney(v))) },
    cpc: { ar: 'تكلفة النقرة', en: 'CPC', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpc', 'clicks', p.currency, ar), (v) => asExactMoney(v))) },
    leads: { ar: 'العملاء المحتملون', en: 'Leads', spark: true, format: (t, _p, _m, count) => count(t.leads) },
    cpl: { ar: 'تكلفة العميل المحتمل', en: 'CPL', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpl', 'leads', p.currency, ar), (v) => asExactMoney(v))) },
    installs: { ar: 'التثبيتات', en: 'Installs', format: (t, _p, _m, count) => count(t.installs) },
    cpi: { ar: 'تكلفة التثبيت', en: 'CPI', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpi', 'installs', p.currency, ar), (v) => asExactMoney(v))) },
    engagements: { ar: 'التفاعلات', en: 'Engagements', format: (t, _p, _m, count) => count(t.engagements) },
    cpe: { ar: 'تكلفة التفاعل', en: 'CPE', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cpe', 'engagements', p.currency, ar), (v) => asExactMoney(v))) },
    landing_page_views: { ar: 'زيارات الصفحة', en: 'Landing page views', format: (t, _p, _m, count) => count(t.landing_page_views) },
    cost_per_lpv: { ar: 'تكلفة الزيارة', en: 'Cost per visit', invertGood: true, format: (t, p) => plain(formatMoneyReading(readCostPer(t as MoneyTotals, 'cost_per_lpv', 'landing_page_views', p.currency, ar), (v) => asExactMoney(v))) },
    conversion_rate: { ar: 'معدل التحويل', en: 'Conversion rate', format: (t) => plain(t.conversion_rate === null || t.conversion_rate === undefined ? '—' : `${(t.conversion_rate * 100).toFixed(2)}%`) },
    /*
     * Average order value is deliberately NOT here yet.
     *
     * Its numerator is REVENUE, and every money reading on this block runs through `readCostPer`,
     * which is built around SPEND: a withheld amount falls back to spend ÷ denominator, so an AOV
     * routed through it would state the average order as a figure derived from what we PAID. The card
     * needs a revenue-numerator reading of its own, and inventing one here — on the page a client
     * cannot cross-check — is the fabrication the money contract exists to prevent.
     */
  }

  /*
   * Which cards this report shows — decided by what it is ABOUT, not by a fixed list.
   *
   * See `clientKpis.ts`: the block printed «Results 581» beside «Purchases 581» and never once showed
   * what a result cost, on a link whose own hard rule names the cost per result first.
   */
  const visibleMetrics = clientKpiKeys(
    { metrics: payload?.metrics, objective_performance: payload?.objective_performance, totals: payload?.totals },
    new Set(Object.keys(METRIC_META)),
  )

  if (!payload && busy) {
    return <p className="py-20 text-center text-text-secondary">{ar ? 'جارٍ التحميل…' : 'Loading…'}</p>
  }
  if (!payload) {
    return (
      <div className="mx-auto max-w-md rounded-2xl border border-border bg-surface p-8 text-center">
        <p className="text-sm text-text-secondary">{error}</p>
      </div>
    )
  }

  const t = payload.totals
  const d = payload.deltas
  const series = (key: string) => payload.timeseries.map((r) => Number(r[key] ?? 0))

  /*
   * PARTIAL-WITHHELD-001 (client charts) — a money chart is a claim in the report's currency, so it
   * may only be drawn from money that IS in that currency. A withheld/partial/mixed spend line
   * labelled in the report currency, or a spend-share donut summed across currencies, is a fabricated
   * figure on the one page the client cannot cross-check.
   *
   * The two BREAKDOWN charts drop and disclose rather than refuse outright: a client whose account
   * runs on four platforms is better served by the three that are known plus «1 not included» than by
   * a blank panel. `rankableMoney` keeps only rows comparable in one currency and reports how many it
   * left off, and the count is printed beneath the chart — never silently swallowed. The spend LINE
   * is different: it is one series claiming to be the scope's spend, so it fails closed.
   */
  const spendState = moneyState(t as MoneyTotals, 'spend').state
  const spendChartable = spendState === 'complete_converted' || spendState === 'zero'
  const platformSpendRank = rankableMoney(payload.platforms as MoneyTotals[], 'spend', currency)

  return (
    /*
     * `[&>*]:min-w-0` — without it this page scrolls sideways on a phone.
     *
     * A grid item's `min-width` is `auto`, so a track is at least as wide as its widest item's
     * min-content. Here that item is a KPI card holding a chart container, whose min-content is far
     * wider than a 375px screen: the track went to 420px, every sibling stretched to match, and the
     * whole document scrolled 61px sideways. Letting the items shrink below min-content is what makes
     * the inner `flex-wrap` and the responsive chart containers do their job.
     *
     * This is the same mechanism that made `/agency/clients` overflow at 343px. It is worth naming
     * twice, because it looks like an overflowing CHILD and is actually a track that refuses to narrow.
     */
    <div className="grid gap-4 [&>*]:min-w-0" data-testid="live-report">
      {/*
        Filters first, and they are the only controls on the page.
        A client is not configuring a workspace; they are asking «which weeks, which platform».
      */}
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

        {payload.available.providers.length > 1 && (
          <div className="flex flex-wrap gap-1" role="group" aria-label={ar ? 'المنصات' : 'Platforms'}>
            {payload.available.providers.map((p) => (
              <button
                key={p}
                type="button"
                data-testid={`live-platform-${p}`}
                onClick={() => toggle(providers, setProviders, p)}
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

        <button
          type="button"
          data-testid="live-refresh"
          onClick={() => void load()}
          className="ms-auto inline-flex items-center gap-1.5 rounded-xl border border-border px-2.5 py-1.5 text-xs font-semibold text-text-secondary hover:bg-surface-hover"
        >
          <RefreshCw size={13} className={busy ? 'animate-spin' : ''} aria-hidden />
          {ar ? 'تحديث' : 'Refresh'}
        </button>
      </div>

      <FreshnessStrip freshness={payload.freshness} ar={ar} />

      {error && (
        <p className="rounded-xl border border-border bg-[var(--warning-background)] px-3 py-2 text-xs text-warning">
          {error}
        </p>
      )}

      {/* Dimmed, not blanked, while refreshing — see the note at the top of this file. */}
      <div className={busy ? 'pointer-events-none opacity-60 transition-opacity' : 'transition-opacity'}>
        {/*
          LIVEREP-002 — only the KPIs the operator chose.
          An unchosen metric is ABSENT, not blanked: a card showing «—» still tells the reader a figure
          exists and is being withheld, which is a different and worse message than not offering it.
          An empty selection means «all of them» — what a link built before this existed carries.
        */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="live-kpis">
          {visibleMetrics.map((key) => {
            const meta = METRIC_META[key]
            if (!meta) return null

            const read = meta.format(t, payload, asMoney, count)

            return (
              <KpiCard
                key={key}
                label={ar ? meta.ar : meta.en}
                value={read.text}
                /* The un-abbreviated figure, one hover away — and absent where compacting changed nothing. */
                exact={read.exact ?? undefined}
                delta={d[key]}
                invertGood={meta.invertGood}
                spark={meta.spark ? series(key) : undefined}
              />
            )
          })}
        </div>

        {/*
          CLIENT-FACING-PRESENTATION-001 — the order IS the requirement.

          The client's six questions, in the order they ask them: what was spent, what was
          achieved, at what cost, what improved or declined, WHERE, and what needs attention. The
          objective split answers the third and fourth; it used to sit below the platform and
          campaign charts, which answer the fifth — so a reader met «where» before «at what cost»,
          and had to scroll past two charts to learn whether the headline cost per order was even
          the right question.

          It moves directly under the KPI cards, where the figure it corrects is still on screen.
        */}
        {/*
          Ranked INSIDE each path, never across them.

          The platform split above is ordered by spend, which answers «where did the money go» and
          never «which of these worked». One ranked list across a mixed programme answers it wrongly:
          a brand path sits at the bottom of a return table for not producing revenue it was never
          asked to produce.

          The buckets are PLATFORMS since CLIENT-REPORT-ENTITY-BOUNDARY-001 — they were campaigns, by
          internal name. «Snapchat costs 43 SAR a sale where Google costs 119» is the same finding a
          client can act on, and it is one they can take to the person running it.
        */}
        {(payload.objective_leaders?.paths ?? []).some((p) => p.comparable) && (
          <div data-testid="live-objective-leaders" className="mt-6 rounded-2xl border border-border bg-surface p-4">
            <h3 className="text-base font-bold text-text-primary">
              {ar ? 'الأقوى والأضعف داخل كل مسار' : 'Strongest and weakest, inside each path'}
            </h3>

            <div className="mt-2 flex flex-col gap-2">
              {(payload.objective_leaders?.paths ?? []).filter((p) => p.comparable && p.strongest && p.weakest).map((path) => (
                <div key={path.path} data-testid={`live-leaders-${path.path}`} className="rounded-xl border border-border p-3 text-sm">
                  <div className="font-semibold text-text-primary">{ar ? path.label_ar : path.label_en}</div>
                  <div className="mt-0.5 text-text-secondary">
                    {ar ? 'الأقوى ' : 'Strongest '}
                    <span className="font-bold text-text-primary">{providerLabel(canonicalPlatform(path.strongest?.name ?? ''), ar ? 'ar' : 'en')}</span>
                    {' · '}
                    {ar ? 'الأضعف ' : 'weakest '}
                    <span className="font-bold text-text-primary">{providerLabel(canonicalPlatform(path.weakest?.name ?? ''), ar ? 'ar' : 'en')}</span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/*
          REPORT-OBJECTIVE-003/004 — Direct beside Blended, before the ads and after the platforms.

          The headline above this rolls the whole scope together: its cost per order divides every
          campaign's spend by the orders the SALES campaigns produced. That is the right answer to
          «what did this programme cost» and the wrong one to «what does an order cost» — and this is
          the page where the second question gets asked, by the person paying for it.
        */}
        {payload.objective_performance && (
          <div data-testid="live-objective-split" className="mt-6 rounded-2xl border border-border bg-surface p-4">
            <h3 className="text-base font-bold text-text-primary">
              {ar ? 'المباشر مقابل المخلوط' : 'Direct against blended'}
            </h3>

            <div className="mt-2 grid gap-3 sm:grid-cols-2">
              {([['direct', payload.objective_performance.direct], ['blended', payload.objective_performance.blended]] as const).map(([kind, block]) => (
                <div key={kind} data-testid={`live-objective-${kind}`} className="rounded-xl border border-border p-3">
                  <div className="text-sm font-semibold text-text-primary">{ar ? block.label_ar : block.label_en}</div>
                  <dl className="mt-1 grid grid-cols-2 gap-1.5 text-xs">
                    <div>
                      <dt className="text-text-muted">{ar ? 'الإنفاق' : 'Spend'}</dt>
                      <dd dir="ltr" className="tnum font-semibold text-text-primary">{asMoney(block.spend)}</dd>
                    </div>
                    <div>
                      <dt className="text-text-muted">{ar ? 'تكلفة الطلب' : 'Cost per order'}</dt>
                      <dd dir="ltr" className="tnum font-semibold text-text-primary">
                        {/*
                          Null stays «—». A cost per order that nobody could compute is not a cost of
                          zero, and this is the figure a client acts on.
                        */}
                        {kind === 'direct'
                          ? (block as typeof payload.objective_performance.direct).cpa === null
                            ? '—'
                            : asMoney((block as typeof payload.objective_performance.direct).cpa)
                          : (block as typeof payload.objective_performance.blended).blended_cpa === null
                            ? '—'
                            : asMoney((block as typeof payload.objective_performance.blended).blended_cpa)}
                      </dd>
                    </div>
                  </dl>
                  <p className="mt-1 text-[11px] leading-relaxed text-text-muted">
                    {kind === 'direct'
                      ? (ar
                          ? 'إنفاق الحملات البيعية وحدها، مقسومًا على طلباتها.'
                          : 'The spend of the sales campaigns alone, over the orders they produced.')
                      : (ar
                          ? 'كل الإنفاق في الفترة، بما فيه ما لم يكن يشتري طلبًا.'
                          : 'All the spend in the window, including what was not buying an order.')}
                  </p>
                </div>
              ))}
            </div>
          </div>
        )}

        <div className="mt-3 grid gap-3 lg:grid-cols-3" data-testid="live-platforms">
          <ChartCard title={ar ? 'الأداء بمرور الوقت' : 'Performance over time'} className="lg:col-span-2">
            <MetricLineChart
              data={payload.timeseries}
              currency={currency}
              height={220}
              series={[
                // The spend line is drawn only when spend is in the report currency; otherwise it would
                // label a withheld/partial figure with a currency it is not in.
                ...(spendChartable ? [{ key: 'spend', name: ar ? 'الإنفاق' : 'Spend', color: 'var(--brand-600)', kind: 'money' as const }] : []),
                { key: 'clicks', name: ar ? 'النقرات' : 'Clicks', color: 'var(--info)', kind: 'num' as const },
                { key: 'conversions', name: ar ? 'النتائج' : 'Results', color: 'var(--purple)', kind: 'num' as const },
              ]}
            />
            {!spendChartable && (
              <p className="mt-1 text-center text-[11px] text-text-muted">{ar ? 'خط الإنفاق غير معروض: المبالغ بانتظار سعر صرف أو بعملات متعددة' : 'Spend line hidden: amounts await an exchange rate or span currencies'}</p>
            )}
          </ChartCard>
          <ChartCard title={ar ? 'توزيع الإنفاق' : 'Spend by platform'}>
            {platformSpendRank === null ? (
              <p className="flex h-[220px] items-center justify-center text-center text-sm text-text-muted">{ar ? 'توزيع الإنفاق غير متاح — مبالغ بانتظار سعر صرف أو بعملات متعددة لا تُجمع' : 'Spend share unavailable — amounts await a rate or span currencies'}</p>
            ) : (
              <>
                <PlatformDonutChart
                  data={payload.platforms.flatMap((p, i) => {
                    const value = platformSpendRank.values[i]
                    // The platform's NAME, not its key: «snapchat» is a database value, «سناب شات» is a platform.
                    return value === null ? [] : [{ name: providerLabel(canonicalPlatform(p.provider), ar ? 'ar' : 'en'), value }]
                  })}
                  currency={platformSpendRank.currency ?? currency}
                  height={220}
                />
                {platformSpendRank.dropped > 0 && (
                  <p className="mt-1 text-center text-[11px] text-text-muted">
                    {ar
                      ? `${platformSpendRank.dropped} منصة غير مُدرجة: مبالغ بانتظار سعر صرف أو بعملات متعددة`
                      : `${platformSpendRank.dropped} platform(s) not included: amounts await a rate or span currencies`}
                  </p>
                )}
              </>
            )}
          </ChartCard>
        </div>

        {/*
          CLIENT-REPORT-ENTITY-BOUNDARY-001 — the campaign ranking that stood here is gone.

          It was a bar chart of the top eight campaigns by spend, drawn by internal name. «Where did
          the money go» is the question it answered, and the donut above answers it by platform; what
          only the campaign chart could add was the roster, which is the plan rather than the result.
        */}
        <div className="mt-3 grid gap-3" data-testid="live-funnel">
          <ChartCard title={ar ? 'قمع الأداء' : 'Performance funnel'}>
            <ConversionFunnelChart stages={payload.funnel} currency={currency} ar={ar} />
            {/* FUNNEL-NULL-001 — said once in a sentence as well as drawn. The client has no second
                view of their account to check a gap against, so the gap must explain itself. */}
            {payload.funnel.some((s) => !s.reported) && (
              <p className="mt-3 text-xs text-text-muted" data-testid="shared-funnel-unreported">
                {ar
                  ? `لم ترسل أي منصة هذه المراحل في هذه الفترة: ${payload.funnel.filter((s) => !s.reported).map((s) => s.label).join('، ')}. الفراغ ليس صفرًا.`
                  : `No platform reported these stages in this period: ${payload.funnel.filter((s) => !s.reported).map((s) => s.label).join(', ')}. A gap is not a zero.`}
              </p>
            )}
          </ChartCard>
        </div>

        {/*
          * FUNNEL-001 — the store half, shown to the client only when there IS a store.
          *
          * Each row carries the system that produced it, exactly as the operator's own analytics tab
          * does. A client asking «من أين جاء هذا الرقم؟» gets the same answer their agency would.
          */}
        {/*
          REPORT-AD-PREVIEW-001 — the same section as the deck and the PDF, from the same payload key.
        */}
        <div className="mt-6">
          <ReportAdsSection
            ads={payload.ads}
            groups={payload.ads_groups}
            currency={currency}
            absentReason={payload.ads_absent_reason}
            level={payload.ads_level}
            reading={payload.ads_reading}
            locale={ar ? 'ar' : 'en'}
            onOpen={setOpenAd}
          />
        </div>

        {/*
          REPORT-PRODUCT-MODEL-001 §D — the difference between the two live products, on screen.

          The dashboard stops at the charts. The detailed report keeps going: every campaign and
          every platform in the window, sortable, with the money contract deciding what a cell may
          claim. A label promising «every campaign» over a top-eight bar chart is the defect this
          block closes, and `liveReportForm.test.tsx` is what stops the two drifting apart again.
        */}
        {form === 'detailed' && <LiveDetailTables payload={payload} currency={currency} locale={ar ? 'ar' : 'en'} />}

        {/*
          CLIENT-FACING-PRESENTATION-001 — the last three blocks, and the only ones a client ACTS on.
          Budget status, then what needs a decision, then nothing else: the composition ends where
          the reader's work begins.
        */}
        <ClientAttention payload={payload} currency={currency} locale={ar ? 'ar' : 'en'} />

        {payload.store_funnel && (
          <div data-testid="shared-store-funnel" className="rounded-2xl border border-border bg-surface p-4">
            <h3 className="font-bold text-text-primary">{ar ? 'الفانل والمتجر' : 'Funnel & store'}</h3>
            <ol className="mt-3 space-y-1.5">
              {payload.store_funnel.stages.map((stage) => (
                <li key={stage.key} data-testid={`shared-stage-${stage.key}`} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-sm">
                  <span className="flex items-center gap-2">
                    <span className="font-semibold text-text-primary">{ar ? stage.label_ar : stage.label_en}</span>
                    <span className="text-[11px] text-text-muted">
                      {stage.source.kind === 'stores'
                        ? (ar ? 'المتجر' : 'Store')
                        : stage.source.kind === 'ad_platforms'
                          ? (ar ? 'بكسل المنصات' : 'Platform pixel')
                          : (ar ? 'لا يوجد مصدر' : 'No source')}
                    </span>
                  </span>
                  <span className="tnum font-extrabold text-text-primary">
                    {stage.value === null
                      ? <span className="text-xs font-semibold text-text-muted">{ar ? 'لا يُقاس' : 'Not measured'}</span>
                      : stage.value.toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB')}
                  </span>
                </li>
              ))}
            </ol>
            {/*
              * COMMERCE-FX-001 — if an order could not be converted, the revenue above is short and
              * the client is told so. They have no second view of their own account to check against.
              */}
            {payload.store_funnel.coverage.orders_with_money_withheld > 0 && (
              <p data-testid="shared-money-withheld" className="mt-2 text-[11px] text-warning">
                {ar
                  ? `${payload.store_funnel.coverage.orders_with_money_withheld} طلبًا بعملة (${payload.store_funnel.coverage.money_withheld_currencies.join('، ')}) لا يوجد لها سعر صرف مؤرّخ، فلم تُحتسب ضمن الإيرادات أعلاه.`
                  : `${payload.store_funnel.coverage.orders_with_money_withheld} order(s) in ${payload.store_funnel.coverage.money_withheld_currencies.join(', ')} have no dated exchange rate and are NOT included in the revenue above.`}
              </p>
            )}
            {/*
              * COMMERCE-TZ-001 — the client link says which clock its days were measured on. The
              * reader has no second view of their own account to check a boundary against.
              */}
            {(payload.store_funnel.coverage.orders_with_assumed_timezone ?? 0) > 0 && (
              <p data-testid="shared-assumed-timezone" className="mt-2 text-[11px] text-warning">
                {ar
                  ? `${payload.store_funnel.coverage.orders_with_assumed_timezone} طلبًا لم يذكر متجرها المنطقة الزمنية، فاعتُبرت UTC.`
                  : `${payload.store_funnel.coverage.orders_with_assumed_timezone} order(s) come from a store that states no timezone, so UTC was assumed.`}
              </p>
            )}
            <p className="mt-2 text-[11px] text-text-muted">
              {ar ? 'الطلبات في الفترة' : 'Orders in the period'}:{' '}
              {payload.store_funnel.coverage.reporting_timezone && (
                <>
                  <span data-testid="shared-reporting-timezone" className="tnum" dir="ltr">
                    {payload.store_funnel.coverage.reporting_timezone}
                  </span>
                  {' · '}
                </>
              )}
              <span className="tnum">{payload.store_funnel.coverage.orders_in_window}</span>
              {/*
                CLIENT-DIAGNOSTIC-SEPARATION-001 — the store's sync clock left this page.

                «Store last synced: 18 Aug 23:59» is a fact about our plumbing. A client reading
                their own report cannot act on it, cannot ask us to change it, and cannot tell
                whether it means their orders are wrong. The order COUNT and the timezone stay,
                because those are facts about their period; the clock is ours and belongs in the
                operator's Data Quality surface, where it is the whole point.
              */}
            </p>
          </div>
        )}
      </div>

      {/* REPORT-AD-PREVIEW-001 §C — read-only, and only what this share already carries. */}
      {openAd && (
        <ReportAdDetail
          ad={openAd}
          currency={currency}
          locale={ar ? 'ar' : 'en'}
          onClose={() => setOpenAd(null)}
        />
      )}
    </div>
  )
}

/**
 * What the figures are actually as-of, per platform.
 *
 * The single most dishonest thing this page could do is print «live» and stop there. A platform we have
 * never successfully read is reported as such — in words, next to its name — rather than being allowed
 * to contribute a silent zero to every chart above.
 */
function FreshnessStrip({
  freshness,
  ar,
}: {
  freshness: LivePayload['freshness']
  ar: boolean
}) {
  /*
   * CLIENT-DIAGNOSTIC-SEPARATION-001 — what a client can act on, and nothing else.
   *
   * This printed a sync clock per platform — «ميتا: 18 أغسطس 23:59» — and, for an unconnected one,
   * «بانتظار بيانات الاعتماد». Both are facts about US. A client cannot act on the timestamp,
   * cannot ask anyone to change it, and «credentials» is a word from our side of the wall.
   *
   * The fact underneath is theirs and must NOT be lost: a total that silently omits a platform is
   * worse than any diagnostic. So the sentence survives, in their vocabulary — these figures do not
   * include Snapchat — and everything about our plumbing goes. The platform is NAMED rather than
   * keyed, because `snapchat` is a database value and «سناب شات» is a platform they buy on.
   */
  const excluded = freshness.filter((f) => f.state === 'awaiting_credentials')

  if (excluded.length === 0) return null

  const names = excluded
    .map((f) => providerLabel(canonicalPlatform(f.provider), ar ? 'ar' : 'en'))
    .join(ar ? '، ' : ', ')

  return (
    <p
      data-testid="live-freshness"
      className="flex items-start gap-1.5 rounded-xl border border-border bg-[var(--warning-background)] px-3 py-2 text-xs text-warning"
    >
      <AlertTriangle size={13} className="mt-0.5 shrink-0" aria-hidden />
      <span>
        {ar
          ? `الأرقام في هذا التقرير لا تشمل: ${names}.`
          : `The figures in this report do not include: ${names}.`}
      </span>
    </p>
  )
}
