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
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { platformColor, tooltipProps } from './components'
import { compact, money, num, percent, ratio } from './format'

/**
 * Shared chart design system for dashboard + analytics + reports. One tooltip/legend/color/typography
 * language, RTL + dark aware, responsive, with data-agnostic building blocks (no data logic inside).
 */

export const CHART_SERIES = ['var(--brand-600)', 'var(--info)', 'var(--purple)', 'var(--teal)', 'var(--warning)']
const AXIS = { stroke: 'var(--text-muted)', fontSize: 12 }
const GRID = <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />

type FmtKind = 'money' | 'num' | 'percent' | 'ratio' | 'compact'
const fmt = (kind: FmtKind, currency = 'SAR') => (v: number | null) => {
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
  currency = 'SAR',
}: {
  data: Array<Record<string, unknown>>
  series: Array<{ key: string; name: string; color?: string; kind?: FmtKind }>
  height?: number
  currency?: string
}) {
  return (
    <ResponsiveContainer width="100%" height={height}>
      <LineChart data={data} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
        {GRID}
        <XAxis dataKey="date" tick={AXIS} tickFormatter={(d) => String(d).slice(5)} minTickGap={24} />
        <YAxis tick={AXIS} tickFormatter={(v) => compact(Number(v))} width={44} />
        <Tooltip {...tooltipProps} formatter={(v: number, name, item) => fmt((item?.payload && series.find((s) => s.name === name)?.kind) || 'num', currency)(v)} />
        <Legend wrapperStyle={{ fontSize: 13 }} />
        {series.map((s, i) => (
          <Line key={s.key} name={s.name} type="monotone" dataKey={s.key} stroke={s.color ?? CHART_SERIES[i % CHART_SERIES.length]} strokeWidth={2} dot={false} activeDot={{ r: 4 }} isAnimationActive={false} />
        ))}
      </LineChart>
    </ResponsiveContainer>
  )
}

// ---- Area: spend vs revenue accumulation ---------------------------------------------------------

export function SpendRevenueAreaChart({ data, height = 288, currency = 'SAR' }: { data: Array<Record<string, unknown>>; height?: number; currency?: string }) {
  return (
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
        <Area name="الإنفاق" type="monotone" dataKey="spend" stroke="var(--brand-600)" strokeWidth={2} fill="url(#aSpend)" isAnimationActive={false} />
        <Area name="الإيرادات" type="monotone" dataKey="revenue" stroke="var(--info)" strokeWidth={2} fill="url(#aRev)" isAnimationActive={false} />
      </AreaChart>
    </ResponsiveContainer>
  )
}

// ---- Donut: distribution with total in the center, share %, Others grouping ----------------------

export function PlatformDonutChart({
  data,
  height = 260,
  centerLabel,
  centerValue,
  currency = 'SAR',
  colorBy = 'platform',
}: {
  data: Array<{ name: string; value: number }>
  height?: number
  centerLabel?: string
  centerValue?: string
  currency?: string
  colorBy?: 'platform' | 'series'
}) {
  const total = data.reduce((a, b) => a + b.value, 0)
  // Group small slices (<4%) into "Others".
  const big = data.filter((d) => d.value / (total || 1) >= 0.04)
  const small = data.filter((d) => d.value / (total || 1) < 0.04)
  const rows = small.length > 1 ? [...big, { name: 'أخرى', value: small.reduce((a, b) => a + b.value, 0) }] : data
  const color = (name: string, i: number) => (colorBy === 'platform' ? platformColor(name) : CHART_SERIES[i % CHART_SERIES.length])
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
  currency = 'SAR',
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
    <ResponsiveContainer width="100%" height={height}>
      <BarChart data={data} layout={horizontal ? 'vertical' : 'horizontal'} margin={{ top: 8, right: 12, left: 8, bottom: 0 }}>
        {GRID}
        {horizontal ? (
          <>
            <XAxis type="number" tick={AXIS} tickFormatter={(v) => compact(Number(v))} />
            <YAxis type="category" dataKey="label" tick={AXIS} width={120} {...cat} />
          </>
        ) : (
          <>
            <XAxis dataKey="label" tick={AXIS} interval={0} angle={data.length > 5 ? -15 : 0} textAnchor={data.length > 5 ? 'end' : 'middle'} height={data.length > 5 ? 48 : 24} />
            <YAxis tick={AXIS} tickFormatter={(v) => compact(Number(v))} width={44} />
          </>
        )}
        <Tooltip {...tooltipProps} formatter={(v: number, name) => fmt(bars.find((b) => b.name === name)?.kind ?? 'num', currency)(v)} />
        {bars.length > 1 && <Legend wrapperStyle={{ fontSize: 13 }} />}
        {bars.map((b, i) => (
          <Bar key={b.key} name={b.name} dataKey={b.key} radius={horizontal ? [0, 6, 6, 0] : [6, 6, 0, 0]} fill={b.color ?? CHART_SERIES[i % CHART_SERIES.length]}>
            {colorByPlatform && data.map((d) => <Cell key={String(d.label)} fill={platformColor(String(d.platform ?? d.label))} />)}
          </Bar>
        ))}
      </BarChart>
    </ResponsiveContainer>
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
export function ConversionFunnelChart({ stages, currency = 'SAR', ar = false }: { stages: Array<{ label: string; count: number | null; step_rate: number | null; cost_per: number | null }>; currency?: string; ar?: boolean }) {
  const counts = stages.map((s) => s.count).filter((c): c is number => c !== null && c !== undefined)
  const top = counts.length > 0 ? Math.max(...counts) : 1
  return (
    <div className="space-y-2.5">
      {stages.map((s, i) => {
        const reported = s.count !== null && s.count !== undefined
        const w = reported ? Math.max(8, ((s.count as number) / (top || 1)) * 100) : 0
        return (
          <div key={s.label} className="flex items-center gap-3">
            <span className="w-32 shrink-0 text-sm font-medium text-text-secondary">{s.label}</span>
            {reported ? (
              <div className="h-9 flex-1 overflow-hidden rounded-xl bg-surface-secondary">
                <div className="flex h-full items-center justify-between rounded-xl px-3 text-sm font-semibold text-white transition-all" style={{ width: `${w}%`, background: `color-mix(in oklab, var(--brand-600) ${100 - i * 10}%, var(--brand-700))` }}>
                  <span className="tnum">{num(s.count)}</span>
                </div>
              </div>
            ) : (
              <div className="flex h-9 flex-1 items-center rounded-xl border border-dashed border-border px-3 text-xs text-text-muted">
                {ar ? 'لم ترسل المنصة هذه المرحلة' : 'This stage was never reported'}
              </div>
            )}
            <div className="w-36 shrink-0 text-end text-xs text-text-muted">
              {s.step_rate !== null && <span className="tnum">{percent(s.step_rate, 0)}</span>}
              {s.cost_per !== null && <span className="tnum ms-2">{money(s.cost_per, currency)}</span>}
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
