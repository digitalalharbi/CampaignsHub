import type { ReactNode } from 'react'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Scatter,
  ScatterChart,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { platformColor, tooltipProps } from './components'
import { compact, money, moneyExact, num, percent, ratio } from './format'
import { readMetricValue } from '@/lib/metricValue'
import { funnelStageLabel } from './metricLabels'
import { useUi } from '@/stores/ui'
import { Link } from 'react-router-dom'

/**
 * Shared chart design system for dashboard + analytics + reports. One tooltip/legend/color/typography
 * language, RTL + dark aware, responsive, with data-agnostic building blocks (no data logic inside).
 */

export const CHART_SERIES = ['var(--brand-600)', 'var(--info)', 'var(--purple)', 'var(--teal)', 'var(--warning)']
/**
 * CHART-TICK-DIRECTION-001 — an axis tick is laid out in the direction recharts anchors it.
 *
 * ## The defect
 *
 * Recharts draws a tick as `<text text-anchor="end" x="120">`, which right-aligns the label against
 * the axis — exactly what a horizontal bar chart's category column needs. But `text-anchor` resolves
 * in the WRITING direction, and on an Arabic page the chart inherits `direction: rtl` from the
 * document. «End» then means the left-hand end, so every label is laid out from its anchor OUTWARD
 * INTO the plot area, where the chart clips it.
 *
 * Measured on `/app/leads` in Arabic: «جديد» occupied 23px starting at the axis line and running
 * under the bars, of which one glyph was visible. Every other label on that axis was in the same
 * state, and so was every horizontal ranking chart in the product read in Arabic.
 *
 * ## It has already cost the product a chart
 *
 * `PlatformResultsBars` in the live report is hand-rolled markup, and its docblock says why: «under
 * `dir="rtl"` that chart dropped its category labels». The conclusion drawn at the time was that
 * recharts does not work in RTL; the actual cause is this one inherited property, and the price was
 * a second bar-chart implementation.
 *
 * ## Why the tick and not the chart
 *
 * Only text LAYOUT is affected. Recharts computes every x and y in JavaScript and writes them as SVG
 * attributes, so no axis, bar or line moves because of direction — a blanket rule on the container
 * would fix the same labels and also take the LEGEND, which is HTML whose reading order should keep
 * following the page. The Arabic label still shapes and renders right-to-left inside its own run;
 * that is the bidi algorithm, not the box.
 *
 * Applied in both languages rather than behind `rtl:`, because the anchor is the same in both and a
 * page should not depend on an inherited default that nothing states.
 */
const TICK_LTR = '[&_.recharts-cartesian-axis-tick_text]:[direction:ltr]'

const AXIS = { stroke: 'var(--text-muted)', fontSize: 12 }
const GRID = <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />

type FmtKind = 'money' | 'num' | 'percent' | 'ratio' | 'compact'
/*
 * CHART-CURRENCY-DEFAULT-001 — a chart is handed its currency; it never decides one.
 *
 * Every money formatter here defaulted to «SAR», so a caller that passed nothing drew a riyal label
 * on whatever it was plotting — the rule MONEY-USD-001 wrote for the formatters, broken one layer
 * up. The default is now the EMPTY currency, which `money()` prints as a bare figure: incomplete,
 * and reading as incomplete, rather than a unit nobody stated.
 */
const fmt = (kind: FmtKind, currency = '') => (v: number | null) => {
  switch (kind) {
    case 'money':
      return money(v, currency)
    case 'percent':
      return percent(v, 2)
    case 'ratio':
      return ratio(v)
    case 'compact':
      return compact(v ?? 0)
    default:
      return num(v)
  }
}

// ---- Line: one or more metrics over time, previous-period comparison -----------------------------

export function MetricLineChart({
  data,
  series,
  height = 288,
  currency = '',
  rightAxisFor,
}: {
  data: Array<Record<string, unknown>>
  series: Array<{ key: string; name: string; color?: string; kind?: FmtKind }>
  height?: number
  currency?: string
  /**
   * Move this series onto its own scale.
   *
   * Two series of different ORDERS of magnitude on one axis is not a comparison, it is one line and
   * one flat mark along the bottom — and a line pinned to the axis reads as «this was zero», which
   * is a claim the data does not make. A daily spend of 300 beside a daily impression count of
   * 10,000 is exactly that shape.
   */
  rightAxisFor?: string
}) {
  /*
   * The second axis follows the READER's direction, not the chart library's default. An RTL page
   * puts the primary axis on the right, so a second axis pinned to «right» lands on top of it and
   * the chart shows two scales down one side with nothing down the other.
   */
  const ar = useUi((s) => s.locale) === 'ar'
  const split = rightAxisFor !== undefined && series.some((s) => s.key === rightAxisFor)
  const axisOf = (key: string) => (split && key === rightAxisFor ? 'right' : 'left')

  return (
    <div className={TICK_LTR}>
    <ResponsiveContainer width="100%" height={height}>
      <LineChart data={data} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
        {GRID}
        <XAxis dataKey="date" tick={AXIS} tickFormatter={(d) => String(d).slice(5)} minTickGap={24} />
        <YAxis yAxisId="left" tick={AXIS} tickFormatter={(v) => compact(Number(v))} width={44} />
        {split && (
          <YAxis
            yAxisId="right"
            orientation={ar ? 'left' : 'right'}
            tick={AXIS}
            tickFormatter={(v) => compact(Number(v))}
            width={44}
          />
        )}
        <Tooltip {...tooltipProps} formatter={(v: number, name, item) => fmt((item?.payload && series.find((s) => s.name === name)?.kind) || 'num', currency)(v)} />
        <Legend wrapperStyle={{ fontSize: 13 }} />
        {series.map((s, i) => (
          <Line key={s.key} yAxisId={axisOf(s.key)} name={s.name} type="monotone" dataKey={s.key} stroke={s.color ?? CHART_SERIES[i % CHART_SERIES.length]} strokeWidth={2} dot={false} activeDot={{ r: 4 }} isAnimationActive={false} />
        ))}
      </LineChart>
    </ResponsiveContainer>
    </div>
  )
}

// ---- Area: spend vs revenue accumulation ---------------------------------------------------------

/**
 * Spend and revenue over the window.
 *
 * The two series names are TRANSLATED. They were Arabic string literals, so an English reader met a
 * legend reading «الإنفاق · الإيرادات» under an English title — observed on the campaigns overview
 * at `/agency/campaigns` in English. A chart's legend is its only key; printing it in a language the
 * rest of the page is not in makes the chart unreadable rather than merely untidy.
 *
 * The locale is read here rather than passed, so every existing call site is corrected without being
 * touched — and `SpendEfficiencyScatter` in this same file already takes `ar` as a prop, which is
 * what made this one's absence easy to miss.
 */
/**
 * The two series names, as a function of the locale.
 *
 * Exported so the decision can be asserted without rendering a chart: `ResponsiveContainer`
 * measures its parent, jsdom reports every element as 0×0, and recharts then draws nothing — so a
 * legend assertion in a unit test passes or fails for reasons that have nothing to do with the
 * names. The component below is the only caller; a regression to a string literal would have to go
 * around this function, which the E2E chrome guard also watches for.
 */
export function spendRevenueSeriesNames(ar: boolean): { spend: string; revenue: string } {
  return ar
    ? { spend: 'الإنفاق', revenue: 'الإيرادات' }
    : { spend: 'Spend', revenue: 'Revenue' }
}

export function SpendRevenueAreaChart({ data, height = 288, currency = '' }: { data: Array<Record<string, unknown>>; height?: number; currency?: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const series = spendRevenueSeriesNames(ar)

  return (
    <div className={TICK_LTR}>
    <ResponsiveContainer width="100%" height={height}>
      <AreaChart data={data} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
        <defs>
          <linearGradient id="aSpend" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="var(--brand-600)" stopOpacity={0.28} /><stop offset="100%" stopColor="var(--brand-600)" stopOpacity={0} /></linearGradient>
          <linearGradient id="aRev" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="var(--info)" stopOpacity={0.28} /><stop offset="100%" stopColor="var(--info)" stopOpacity={0} /></linearGradient>
        </defs>
        {GRID}
        <XAxis dataKey="date" tick={AXIS} tickFormatter={(d) => String(d).slice(5)} minTickGap={24} />
        <YAxis tick={AXIS} tickFormatter={(v) => compact(Number(v))} width={44} />
        <Tooltip {...tooltipProps} formatter={(v: number) => money(v, currency)} />
        <Legend wrapperStyle={{ fontSize: 13 }} />
        <Area name={series.spend} type="monotone" dataKey="spend" stroke="var(--brand-600)" strokeWidth={2} fill="url(#aSpend)" isAnimationActive={false} />
        <Area name={series.revenue} type="monotone" dataKey="revenue" stroke="var(--info)" strokeWidth={2} fill="url(#aRev)" isAnimationActive={false} />
      </AreaChart>
    </ResponsiveContainer>
    </div>
  )
}

// ---- Donut: distribution with total in the center, share %, Others grouping ----------------------

/**
 * The «Others» slice's name, as a function of the locale.
 *
 * It was the Arabic literal «أخرى», so an English reader met one Arabic slice in an otherwise English
 * legend — the same defect `spendRevenueSeriesNames` records for the area chart's two series, in the
 * one place in this file the fix did not reach. Exported for the same reason that one is: recharts
 * draws nothing in jsdom, so a legend assertion through the component proves nothing about the name.
 */
export function donutOthersName(ar: boolean): string {
  return ar ? 'أخرى' : 'Others'
}

export function PlatformDonutChart({
  data,
  height = 260,
  centerLabel,
  centerValue,
  currency = '',
  colorBy = 'platform',
}: {
  /** `key` is the platform's stored key, for its colour; `name` is what the reader sees. */
  data: Array<{ name: string; value: number; key?: string }>
  height?: number
  centerLabel?: string
  centerValue?: string
  currency?: string
  colorBy?: 'platform' | 'series'
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const total = data.reduce((a, b) => a + b.value, 0)
  // Group small slices (<4%) into "Others".
  const big = data.filter((d) => d.value / (total || 1) >= 0.04)
  const small = data.filter((d) => d.value / (total || 1) < 0.04)
  const rows = small.length > 1 ? [...big, { name: donutOthersName(ar), value: small.reduce((a, b) => a + b.value, 0) }] : data
  const keyOf = (name: string) => data.find((d) => d.name === name)?.key ?? name
  // A platform's colour is looked up by its KEY: a translated name («جوجل») matched no colour, so every slice was drawn the same.
  const color = (name: string, i: number) => (colorBy === 'platform' ? platformColor(keyOf(name)) : CHART_SERIES[i % CHART_SERIES.length])
  return (
    <div className="relative" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie data={rows} dataKey="value" nameKey="name" innerRadius="62%" outerRadius="92%" paddingAngle={2} strokeWidth={2} stroke="var(--surface)">
            {rows.map((r, i) => <Cell key={r.name} fill={color(r.name, i)} />)}
          </Pie>
          <Tooltip {...tooltipProps} formatter={(v: number) => `${money(v, currency)} · ${percent(v / (total || 1), 1)}`} />
          <Legend wrapperStyle={{ fontSize: 13 }} />
        </PieChart>
      </ResponsiveContainer>
      {(centerValue || centerLabel) && (
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center" style={{ paddingBottom: 28 }}>
          {centerLabel && <span className="text-xs text-text-muted">{centerLabel}</span>}
          {centerValue && <span className="tnum text-xl font-extrabold text-text-primary">{centerValue}</span>}
        </div>
      )}
    </div>
  )
}

// ---- Bar: ranking / comparison, optional benchmark line ------------------------------------------

export function RankingBarChart({
  data,
  bars,
  height = 264,
  horizontal = false,
  colorByPlatform = false,
  currency = '',
}: {
  data: Array<Record<string, unknown>>
  bars: Array<{ key: string; name: string; color?: string; kind?: FmtKind }>
  height?: number
  horizontal?: boolean
  colorByPlatform?: boolean
  currency?: string
}) {
  const cat = horizontal ? { type: 'category' as const } : {}
  return (
    <div className={TICK_LTR}>
    <ResponsiveContainer width="100%" height={height}>
      <BarChart data={data} layout={horizontal ? 'vertical' : 'horizontal'} margin={{ top: 8, right: 12, left: 8, bottom: 0 }}>
        {GRID}
        {/*
          CHART-AXIS-DISCOVERY-001 — an axis inside a FRAGMENT is an axis this chart does not have.
          *
          * Recharts finds its axes by walking `props.children` for elements of the axis types, and it
          * does not descend into fragments. Both branches of this used to return `<>…</>`, so every
          * ranking chart in the product rendered with NO axes at all — and a bar chart without a
          * category axis cannot band its bars either: measured on the content format comparison, four
          * bars came out as one full-width rectangle and three of zero size.
          *
          * One ternary per axis keeps each one a DIRECT child, which is the only shape the discovery
          * walk sees. The props still differ by orientation; what changed is where they are returned.
        */}
        {horizontal
          ? <XAxis type="number" tick={AXIS} tickFormatter={(v) => compact(Number(v))} />
          : <XAxis dataKey="label" tick={AXIS} interval={0} angle={data.length > 5 ? -15 : 0} textAnchor={data.length > 5 ? 'end' : 'middle'} height={data.length > 5 ? 48 : 24} />}
        {horizontal
          ? <YAxis type="category" dataKey="label" tick={AXIS} width={120} {...cat} />
          : <YAxis tick={AXIS} tickFormatter={(v) => compact(Number(v))} width={44} />}
        <Tooltip {...tooltipProps} formatter={(v: number, name) => fmt(bars.find((b) => b.name === name)?.kind ?? 'num', currency)(v)} />
        {bars.length > 1 && <Legend wrapperStyle={{ fontSize: 13 }} />}
        {bars.map((b, i) => (
          <Bar key={b.key} name={b.name} dataKey={b.key} radius={horizontal ? [0, 6, 6, 0] : [6, 6, 0, 0]} fill={b.color ?? CHART_SERIES[i % CHART_SERIES.length]}>
            {colorByPlatform && data.map((d) => <Cell key={String(d.label)} fill={platformColor(String(d.platform ?? d.label))} />)}
          </Bar>
        ))}
      </BarChart>
    </ResponsiveContainer>
    </div>
  )
}

// ---- Funnel: stages with transition rate + drop-off ----------------------------------------------

/**
 * The conversion funnel, drawn from counts that may legitimately be absent (FUNNEL-NULL-001).
 *
 * `count` is nullable and a null is NOT a zero-length bar. A bar is a measurement, so a stage the
 * platform never reported gets no bar at all — it gets a dashed, empty track saying so in words. The
 * alternative was what shipped before: `null / top` is `0` in JavaScript, which drew the minimum-width
 * bar with «—» inside it and read, to a client, as «this step happened almost never».
 *
 * `top` is the largest count that was actually reported rather than the first stage's, because the
 * first stage is exactly the one that can be missing on a platform that reports conversions without
 * impressions — and scaling every bar to `undefined` would have collapsed the whole chart.
 */
/**
 * A count, read the way every other surface reads one — NUMBER-PRESENTATION-001.
 *
 * `readMetricValue` is the same function the analytical tables and the KPI cards call, so «6.6M»
 * here and «6.6M» on the card above agree by construction rather than by both happening to reach
 * for the same helper.
 */
function countRead(value: number | null | undefined): { text: string; exact: string | null } {
  const read = readMetricValue('number', value ?? null)

  return { text: read.text, exact: read.exact }
}

export function ConversionFunnelChart({ stages, currency = '', ar = false }: { stages: Array<{ stage?: string; label: string; count: number | null; step_rate: number | null; cost_per: number | null; exceeds_previous?: boolean }>; currency?: string; ar?: boolean }) {
  const counts = stages.map((s) => s.count).filter((c): c is number => c !== null && c !== undefined)
  const top = counts.length > 0 ? Math.max(...counts) : 1
  return (
    <div className="space-y-2.5">
      {stages.map((s, i) => {
        const reported = s.count !== null && s.count !== undefined
        const w = reported ? Math.max(8, ((s.count as number) / (top || 1)) * 100) : 0
        return (
          <div key={s.label} className="flex items-center gap-3">
            {/* UX-COPY-001 — the payload labels its stages in English only. */}
            <span className="w-32 shrink-0 text-sm font-medium text-text-secondary">{funnelStageLabel(s.stage ?? '', s.label, ar)}</span>
            {reported ? (
              <div className="h-9 flex-1 overflow-hidden rounded-xl bg-surface-secondary">
                <div className="flex h-full items-center justify-between rounded-xl px-3 text-sm font-semibold text-white transition-all" style={{ width: `${w}%`, background: `color-mix(in oklab, var(--brand-600) ${100 - i * 10}%, var(--brand-700))` }}>
                  {/*
                    NUMBER-PRESENTATION-001 — the funnel wrote its counts at full width.
                    *
                    * Found on the live client report: the KPI card above read «6.6M» and the
                    * impressions bar below it read «6,596,500». One figure, two shapes, on one page,
                    * in front of a client — who has no second view of their account to work out that
                    * they are the same number.
                    *
                    * `num()` is the EXACT formatter; every other analytical surface reads a count
                    * through the value law and compacts it. This is the only place that printed one
                    * raw, and it is on the most public surface in the product.
                    *
                    * The exact figure travels as the `title`, which is what makes an abbreviation
                    * legitimate: «6.6M» a reader cannot get back to 6,596,500 is a figure they
                    * cannot audit.
                  */}
                  <span className="tnum" title={countRead(s.count).exact ?? undefined}>{countRead(s.count).text}</span>
                </div>
              </div>
            ) : (
              <div className="flex h-9 flex-1 items-center rounded-xl border border-dashed border-border px-3 text-xs text-text-muted">
                {ar ? 'لم ترسل المنصة هذه المرحلة' : 'This stage was never reported'}
              </div>
            )}
            <div className="w-36 shrink-0 text-end text-xs text-text-muted">
              {/*
                FUNNEL-NOT-NESTED-001 — «165%» is not a conversion rate, and this said it was.
                *
                * Seen on the live client report: «بدء الدفع 7.42K 165%». The aggregator has computed
                * `exceeds_previous` since the rule was written, and refuses to invert it into a
                * negative drop-off — it knows these stages do not nest. A buy-now flow reaches
                * checkout without an add-to-cart, and each event is attributed on its own window.
                *
                * The flag was in the API type and rendered NOWHERE, so every surface printed the
                * ratio as though it were an ordinary step and left a client to work out how a funnel
                * widened. The figure stays, because it is real and hiding it would hide a fact about
                * the account; what changes is that it stops claiming to be a conversion.
              */}
              {s.step_rate !== null && (
                <span
                  className={`tnum ${s.exceeds_previous ? 'text-warning' : ''}`}
                  title={s.exceeds_previous
                    ? (ar
                        ? 'هذه المرحلة أكبر من التي قبلها، فالمرحلتان لا تتداخلان: يمكن الوصول إلى الدفع دون إضافة للسلة، وكل حدث يُنسب في نافذته. النسبة ليست معدل تحويل.'
                        : 'This stage is larger than the one above it, so the two do not nest: checkout is reachable without an add to cart, and each event is attributed in its own window. This ratio is not a conversion rate.')
                    : undefined}
                >
                  {percent(s.step_rate, 0)}
                  {s.exceeds_previous && <span aria-hidden> ⚠</span>}
                </span>
              )}
              {s.cost_per !== null && <span className="tnum ms-2">{moneyExact(s.cost_per, currency ?? null)}</span>}
            </div>
          </div>
        )
      })}
    </div>
  )
}

// ---- Progress ring: budget consumption, goal attainment, freshness -------------------------------

export function ProgressRing({ value, label, sublabel, size = 128, tone = 'brand' }: { value: number; label?: string; sublabel?: string; size?: number; tone?: 'brand' | 'warning' | 'danger' | 'success' }) {
  const pct = Math.max(0, Math.min(1, value))
  const stroke = 10
  const r = (size - stroke) / 2
  const c = 2 * Math.PI * r
  const color = { brand: 'var(--brand-600)', warning: 'var(--warning)', danger: 'var(--danger)', success: 'var(--success)' }[tone]
  return (
    <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--surface-secondary)" strokeWidth={stroke} />
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={color} strokeWidth={stroke} strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c * (1 - pct)} style={{ transition: 'stroke-dashoffset .6s ease' }} />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="tnum text-xl font-extrabold text-text-primary">{label ?? percent(pct, 0)}</span>
        {sublabel && <span className="text-xs text-text-muted">{sublabel}</span>}
      </div>
    </div>
  )
}

// ---- KPI sparkline (tiny area) -------------------------------------------------------------------

export function KpiSparkline({ points, color = 'var(--brand-600)', height = 34 }: { points: number[]; color?: string; height?: number }) {
  if (points.length < 2) return null
  const id = `spk-${Math.round(points[0] * 1000)}-${points.length}`
  return (
    <ResponsiveContainer width="100%" height={height}>
      <AreaChart data={points.map((v, i) => ({ i, v }))} margin={{ top: 4, bottom: 0, left: 0, right: 0 }}>
        <defs><linearGradient id={id} x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor={color} stopOpacity={0.35} /><stop offset="100%" stopColor={color} stopOpacity={0} /></linearGradient></defs>
        <Area type="monotone" dataKey="v" stroke={color} strokeWidth={2} fill={`url(#${id})`} isAnimationActive={false} />
      </AreaChart>
    </ResponsiveContainer>
  )
}

// ---- Chart panel shell with states --------------------------------------------------------------

export function ChartCard({ title, subtitle, action, children, className = '' }: { title: string; subtitle?: string; action?: ReactNode; children: ReactNode; className?: string }) {
  return (
    /*
     * `min-w-0`, because a chart card is nearly always a grid or flex item.
     *
     * Those default to `min-width: auto`, meaning they refuse to shrink below their content's
     * min-content width — and a chart's min-content is whatever its longest axis label happens to be.
     * One campaign called «Meta — Advantage+ Shopping» was enough to push a two-column row to 433px
     * inside a 343px phone and scroll the whole page sideways.
     *
     * A chart is responsive by definition: it is *supposed* to redraw itself at whatever width it is
     * given. Refusing to narrow is never the behaviour anybody wanted here, so the card allows it
     * everywhere rather than each caller remembering to.
     */
    <section className={`flex min-w-0 flex-col rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)] ${className}`}>
      <div className="mb-4 flex items-start justify-between gap-3">
        <div>
          <h3 className="text-base font-bold tracking-tight text-text-primary">{title}</h3>
          {subtitle && <p className="mt-0.5 text-sm text-text-secondary">{subtitle}</p>}
        </div>
        {action}
      </div>
      {children}
    </section>
  )
}

/**
 * VISUAL-DECISION-001 — spend against what it bought, for the one decision a ranking cannot answer.
 *
 * A ranked bar says which campaign spends most; a cost-per-result column says which is efficient.
 * Neither answers «where is the money going that is NOT working», because that is a question about
 * two axes at once: a campaign high on spend and high on cost per result is the one to open first,
 * and it can sit mid-table on both lists.
 *
 * ## What it refuses to plot
 *
 * A point needs both coordinates to mean anything. A campaign whose spend is withheld, or whose
 * cost per result the platform never reported, has no position — and placing it at zero would put
 * the campaigns we know least about in the corner that reads «cheap and efficient». They are
 * excluded and COUNTED, and the count is rendered by the caller, because a chart that silently drops
 * rows is a chart that lies about the estate.
 */
export function SpendEfficiencyScatter({
  points,
  currency,
  ar,
  height = 240,
}: {
  points: Array<{ id: string; name: string; spend: number; costPer: number }>
  currency: string | null
  ar: boolean
  height?: number
}) {
  if (points.length === 0) {
    return (
      <div className="flex items-center justify-center text-center text-xs text-text-muted" style={{ height }}>
        {ar
          ? 'لا حملة تحمل إنفاقًا وتكلفة نتيجة معًا في هذه الفترة'
          : 'No campaign has both a spend and a cost per result in this period'}
      </div>
    )
  }

  return (
    <div className={TICK_LTR}>
    <ResponsiveContainer width="100%" height={height}>
      <ScatterChart margin={{ top: 8, right: 12, bottom: 24, left: 8 }}>
        <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" />
        <XAxis
          type="number"
          dataKey="spend"
          name={ar ? 'الإنفاق' : 'Spend'}
          tick={{ fontSize: 11, fill: 'var(--color-text-muted)' }}
          tickFormatter={(v: number) => compact(v)}
        />
        <YAxis
          type="number"
          dataKey="costPer"
          name={ar ? 'تكلفة النتيجة' : 'Cost / result'}
          tick={{ fontSize: 11, fill: 'var(--color-text-muted)' }}
          tickFormatter={(v: number) => compact(v)}
          width={48}
        />
        <Tooltip
          cursor={{ strokeDasharray: '3 3' }}
          contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 12, fontSize: 12 }}
          formatter={(value: number, key: string) => [money(value, currency ?? undefined), key]}
          labelFormatter={() => ''}
        />
        <Scatter data={points} fill="var(--brand-600)" />
      </ScatterChart>
    </ResponsiveContainer>
    </div>
  )
}

// ---- Status mix: a composition, as one divided bar ----------------------------------------------

/** The tones a band may carry. Semantic, so «needs attention» is the same colour wherever it appears. */
export type MixTone = 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'neutral'

const MIX_FILL: Record<MixTone, string> = {
  brand: 'bg-brand-500',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  info: 'bg-info',
  neutral: 'bg-border-strong',
}

/**
 * VIZ-MIX-001 — a composition drawn as one bar, because concentration is a shape.
 *
 * Four counts beside each other say what each band holds. They do not say that one band holds most
 * of the estate, and that is the thing a portfolio reader is looking for: «12 active · 3 onboarding ·
 * 9 needing attention» has to be added up and divided before it answers «how much of my book is in
 * trouble». A divided bar answers it without being read.
 *
 * ## The denominator is the whole component
 *
 * The bands are parts OF something, and `total` is that something — it is passed in rather than
 * summed, because the sum is exactly what cannot be trusted. A portfolio of 24 clients whose named
 * bands cover 20 of them has 4 clients in a state nobody labelled; summing the bands would quietly
 * redefine the estate as 20 and make every share too large. So:
 *
 * - bands shorter than the total leave a REMAINDER, drawn as its own segment and named, never left
 *   as empty track that reads like «the bar failed to load»;
 * - bands longer than the total are a CONTRADICTION — they cannot be parts of that whole — and the
 *   component declines instead of normalising, because normalising produces a bar that looks
 *   perfectly correct over a denominator nothing measured;
 * - a total of zero has no shares at all, and 0/0 drawn as an empty bar reads as «all of it is in
 *   the first band».
 *
 * Not recharts: a 100% stacked bar of two to six known counts is plain layout, and keeping it as DOM
 * means it survives the PDF renderer, states its own composition to a screen reader, and can be
 * asserted in a unit test — `ResponsiveContainer` measures its parent, and jsdom reports every
 * element as 0×0, so a recharts version of this could only ever be tested through the browser.
 */
export function StatusMixBar({
  bands,
  total,
  ar,
  label,
  residualLabel,
  testId,
  height = 10,
  format = num,
}: {
  bands: Array<{
    key: string
    label: string
    count: number
    tone: MixTone
    /**
     * An explicit fill, for a band whose place in an ORDERED ramp the semantic tones cannot express.
     *
     * Debt ageing is the case this exists for: not due · 1–30 · 31–60 · 61–90 · 90+ is five steps of
     * one severity scale, and the semantic set has four names for it. Collapsing two pairs together
     * would tell a reader that money 40 days late and money 80 days late are the same thing, which
     * is the single judgement a receivables page exists to make.
     *
     * Only for ramps. A set of CATEGORIES — platforms, objectives, statuses — has no order, so a
     * colour chosen for one is a colour that means nothing, and those take a `tone`.
     */
    fill?: string
    /**
     * DASHBOARD-DRILLDOWN-001 — where this band's members are listed, when somewhere lists them.
     *
     * A composition says «18 of 24 are active»; the question it raises is «which 18». A band that
     * can answer carries the address of the list filtered to exactly its members, and its legend
     * entry becomes the link. The bar itself stays a drawing — a 4% sliver is no click target.
     */
    to?: string
  }>
  /** The whole these bands divide. Never the sum of the bands — see the note above. */
  total: number
  ar: boolean
  /** Names the whole, for the one-sentence spoken version. */
  label?: string
  /** Names the un-named remainder. Without it the remainder is drawn but called «other». */
  residualLabel?: string
  testId: string
  height?: number
  /**
   * How each band's figure is printed. Defaults to a plain count.
   *
   * The bands are not always counts: outstanding money divided by how late it is has exactly this
   * shape, and a composition is a composition whatever its parts are measured in. What a share of
   * money still needs is its CURRENCY — «7,500» beside «2,500» under a heading somewhere else on the
   * page is the sort of unmarked figure the product's money rules exist to prevent — so the caller
   * passes `money(v, currency)` and the same string reaches the legend and the spoken summary.
   *
   * The band's own arithmetic is untouched: shares are computed from the raw numbers, never from
   * whatever this returns.
   */
  format?: (value: number) => string
}) {
  const named = bands.reduce((sum, b) => sum + Math.max(0, b.count), 0)

  if (total <= 0) {
    return (
      <p data-testid={`${testId}-empty`} className="rounded-xl border border-dashed border-border px-4 py-5 text-center text-sm text-text-muted">
        {ar ? 'لا شيء لتوزيعه بعد.' : 'Nothing to divide yet.'}
      </p>
    )
  }

  if (named > total) {
    return (
      <p data-testid={`${testId}-undrawable`} className="rounded-xl border border-dashed border-warning/40 bg-warning/5 px-4 py-5 text-center text-sm text-text-secondary">
        {ar
          ? 'الفئات تتجاوز المجموع الذي تقسمه، فلا يمكن رسم نسب منها.'
          : 'These bands add up to more than the whole they divide, so no share of them can be drawn.'}
      </p>
    )
  }

  const residual = total - named
  const share = (n: number) => (n / total) * 100
  const pct = (n: number) => `${Math.round(share(n))}%`
  const segments = [
    ...bands.filter((b) => b.count > 0),
    ...(residual > 0
      ? [{ key: 'residual', label: residualLabel ?? (ar ? 'أخرى' : 'Other'), count: residual, tone: 'neutral' as MixTone, fill: undefined, to: undefined }]
      : []),
  ]

  const spoken = [label, ...segments.map((s) => `${s.label} ${format(s.count)} (${pct(s.count)})`)].filter(Boolean).join(' · ')

  return (
    <div className="min-w-0">
      <div
        data-testid={`${testId}-bar`}
        role="img"
        aria-label={spoken}
        className="flex w-full overflow-hidden rounded-full bg-surface-secondary"
        style={{ height }}
      >
        {segments.map((s) => (
          <div
            key={s.key}
            data-testid={`${testId}-segment-${s.key}`}
            className={`h-full ${s.fill ?? MIX_FILL[s.tone]}`}
            style={{ width: `${share(s.count)}%` }}
          />
        ))}
      </div>
      <ul data-testid={`${testId}-legend`} className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
        {segments.map((s) => (
          <li key={s.key} className="flex items-center gap-1.5 text-sm">
            <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${s.fill ?? MIX_FILL[s.tone]}`} aria-hidden />
            {s.to ? (
              <Link to={s.to} className="text-text-secondary underline decoration-dotted underline-offset-4 hover:text-brand-600">
                {s.label}
              </Link>
            ) : (
              <span className="text-text-secondary">{s.label}</span>
            )}
            <span className="tnum font-bold text-text-primary" dir="ltr">{format(s.count)}</span>
            <span className="tnum text-xs text-text-muted" dir="ltr">{pct(s.count)}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

// ---- Ratio bars: a unitless ratio against the value that decides it -----------------------------

/**
 * VIZ-RATIO-001 — ranked ratios against a drawn reference.
 *
 * Budget pace is `projected / budget`: 1.0 lands on budget, above it overruns. A column of
 * «1.24 / 0.88 / 1.02» asks the reader to make that comparison on every row; a bar against a line
 * drawn at 1.0 makes «which of these is over» a glance, and ordering worst-first puts the row that
 * needs opening at the top.
 *
 * ## Why a ratio may cross currencies when money may not
 *
 * `projected / budget` carries no unit — a client budgeted in SAR and one in USD both pace at 1.2 for
 * the same reason, and the two are genuinely comparable. That is why this exists as a ratio chart
 * rather than being folded into a money chart: the money rule («never mix incompatible currencies»)
 * is not being bent, it simply does not apply to a dimensionless figure. What the CALLER still owes
 * is that each row's own ratio is internally sound — a row whose budget is itself a mix of
 * currencies has no single budget to divide by, and belongs in the withheld count, not here.
 *
 * ## What it refuses
 *
 * Only drawable rows arrive: a row whose ratio is unknown is filtered and COUNTED by the caller,
 * which is `SpendEfficiencyScatter`'s contract in this same file rather than a second one. Placing an
 * unknown ratio at zero would draw the rows we know least about as the best-behaved ones.
 */
export function RatioBars({
  rows,
  reference,
  referenceLabel,
  ar,
  testId,
  toneFor,
  format = (v) => ratio(v),
}: {
  rows: Array<{ id: string; label: string; value: number }>
  reference: number
  referenceLabel?: string
  ar: boolean
  testId: string
  /** Defaults to «over the reference is danger, near it is warning, under it is success». */
  toneFor?: (value: number) => MixTone
  format?: (value: number) => string
}) {
  if (rows.length === 0) {
    return (
      <p data-testid={`${testId}-empty`} className="rounded-xl border border-dashed border-border px-4 py-5 text-center text-sm text-text-muted">
        {ar ? 'لا صف تتوفّر له نسبة قابلة للرسم.' : 'No row has a ratio that can be drawn.'}
      </p>
    )
  }

  const tone = toneFor ?? ((v: number): MixTone => (v > reference * 1.05 ? 'danger' : v >= reference * 0.95 ? 'warning' : 'success'))
  /*
   * The scale tops out at the largest of the values AND the reference, so the reference line is
   * always on the chart. A set of rows all pacing at 0.4 against a reference of 1.0 would otherwise
   * scale to 0.4 and draw every bar full — «everyone is at the limit», which is the opposite of what
   * the data says.
   */
  const ceiling = Math.max(reference, ...rows.map((r) => r.value))
  const ordered = [...rows].sort((a, b) => b.value - a.value)
  const at = reference / ceiling
  /*
   * The reference label flips to the INSIDE of the line once the line is near the end of the track.
   *
   * The common case for a pace chart is that nothing is overrunning, which puts the ceiling at the
   * reference itself and the line at exactly 100% — and a label anchored to the line's start then
   * runs off the card. Observed on the agency dashboard with five clients all pacing under 1.0:
   * «On budget» rendered as «On bu». Past three-quarters of the track the label reads back toward
   * the bars instead, where there is always room, because that side holds the shorter bars.
   */
  const labelSide = at > 0.75 ? 'insetInlineEnd' : 'insetInlineStart' 

  return (
    <div className="min-w-0">
      <div className="relative">
        <div
          data-testid={`${testId}-reference`}
          className="pointer-events-none absolute inset-y-0 z-10 border-s border-dashed border-text-muted"
          style={{ insetInlineStart: `${(reference / ceiling) * 100}%` }}
        >
          {referenceLabel && (
            <span
              data-testid={`${testId}-reference-label`}
              className="absolute -top-0.5 whitespace-nowrap px-1.5 text-[11px] font-semibold text-text-muted"
              style={{ [labelSide]: 0 }}
            >
              {referenceLabel}
            </span>
          )}
        </div>
        <ul className={`space-y-2.5 ${referenceLabel ? 'pt-4' : ''}`}>
          {ordered.map((r) => (
            <li key={r.id} data-testid={`${testId}-row-${r.id}`}>
              <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="truncate font-semibold text-text-primary">{r.label}</span>
                <span className="tnum shrink-0 font-bold text-text-secondary" dir="ltr">{format(r.value)}</span>
              </div>
              <div className="mt-1 h-2.5 overflow-hidden rounded-full bg-surface-secondary">
                <div
                  data-testid={`${testId}-bar-${r.id}`}
                  className={`h-full rounded-full ${MIX_FILL[tone(r.value)]}`}
                  style={{ width: `${(r.value / ceiling) * 100}%` }}
                />
              </div>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}
