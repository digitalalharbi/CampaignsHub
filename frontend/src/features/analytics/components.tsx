import { StatCard } from '@/components/ui/StatCard'
import type { ReactNode } from 'react'
import { Area, AreaChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { compact, percent, trend } from './format'
import type { Trend } from './format'

const AXIS = { stroke: 'var(--text-muted)', fontSize: 12 }

/** Brand-consistent series colors (CSS vars resolve in light + dark). */
export const SERIES = {
  spend: 'var(--brand-600)',
  revenue: 'var(--info)',
  conversions: 'var(--purple)',
  clicks: 'var(--teal)',
  neutral: 'var(--text-muted)',
}
/*
 * Platform identities, read from the token layer so each theme can state them.
 *
 * They were literals, and two of them — TikTok `#000000` and X `#111111` — were the brand's true
 * black. On the dark ground that is a donut slice you cannot see, a legend dot that reads as a hole
 * and a bar of zero apparent length, in every screen that draws a platform. The tokens keep the
 * exact brand value in light and swap in each brand's own light-on-dark mark for dark.
 */
export const PLATFORM_COLORS: Record<string, string> = {
  meta: 'var(--platform-meta)',
  google: 'var(--platform-google)',
  google_ads: 'var(--platform-google)',
  tiktok: 'var(--platform-tiktok)',
  snapchat: 'var(--platform-snapchat)',
  x: 'var(--platform-x)',
  linkedin: 'var(--platform-linkedin)',
}
export const platformColor = (p: string) => PLATFORM_COLORS[p] ?? 'var(--brand-500)'

export function TrendPill({ delta, invertGood = false }: { delta: number | null | undefined; invertGood?: boolean }) {
  const t: Trend = trend(delta)
  const good = t === 'flat' ? null : invertGood ? t === 'down' : t === 'up'
  const color =
    good === null ? 'text-text-muted bg-surface-secondary' : good ? 'text-success bg-[var(--positive-background)]' : 'text-danger bg-[var(--negative-background)]'
  const Icon = t === 'up' ? ArrowUpRight : t === 'down' ? ArrowDownRight : Minus
  return (
    <span className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-xs font-semibold ${color}`}>
      <Icon size={13} />
      {delta === null || delta === undefined ? '—' : percent(Math.abs(delta), 0)}
    </span>
  )
}

/**
 * UX-KPI-PRESENTATION-001 — the last card that drew itself.
 *
 * This was the third implementation of a labelled figure, and the widest-travelled: Analytics, the
 * dashboard strip and the client's own shared link all render it. It agreed with `StatCard` on the
 * idea and on nothing else — `p-4` against `p-4 sm:p-5`, a `text-sm` label against the product's
 * `text-[13px] font-semibold`, its own value class against `METRIC_VALUE_DENSE` — so a reader moving
 * from a client link to the campaign command centre met two designs of one object, and a row drawn by
 * both did not line up.
 *
 * The command centre's own `KpiCard` had already been reduced to a wrapper over `StatCard`, spark and
 * all. This is the same reduction. What is kept is what only this surface has: the trend pill, and a
 * sparkline drawn from a series the card is handed rather than one it fetches.
 *
 * Nothing about the DIRECTION rule changes, and that is the point of moving: `StatCard` marks the
 * value `dir="ltr"` itself, so «48.4K USD» cannot be reordered into «USD 48.4K» by an Arabic layout —
 * a rule this card was relying on a global stylesheet to apply on its behalf.
 */
export function KpiCard({
  label,
  value,
  exact,
  delta,
  invertGood = false,
  spark,
  hint,
  accent = SERIES.spend,
}: {
  label: string
  value: string
  /** The un-abbreviated figure behind a compacted one — NUMBER-PRESENTATION-001 §58. */
  exact?: string
  delta?: number | null
  invertGood?: boolean
  spark?: number[]
  hint?: string
  accent?: string
}) {
  return (
    <StatCard
      label={<span title={hint}>{label}</span>}
      value={value}
      exact={exact}
      trailing={delta !== undefined ? <TrendPill delta={delta} invertGood={invertGood} /> : undefined}
      spark={spark && spark.length > 1 ? (
        <div className="h-9 w-full">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={spark.map((v, i) => ({ i, v }))} margin={{ top: 4, bottom: 0, left: 0, right: 0 }}>
              <defs>
                <linearGradient id={`sp-${label}`} x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={accent} stopOpacity={0.35} />
                  <stop offset="100%" stopColor={accent} stopOpacity={0} />
                </linearGradient>
              </defs>
              <Area type="monotone" dataKey="v" stroke={accent} strokeWidth={2} fill={`url(#sp-${label})`} isAnimationActive={false} />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      ) : undefined}
    />
  )
}

/** Card shell for a chart/section with title, optional action, and loading/empty/error handling. */
/** The reader's language, read at render time — these helpers are shared by several surfaces. */
const ar = () => useUi.getState().locale === 'ar'

export function Panel({
  title,
  description,
  action,
  loading,
  error,
  empty,
  children,
  className = '',
}: {
  title: string
  description?: string
  action?: ReactNode
  loading?: boolean
  error?: boolean
  empty?: boolean
  children: ReactNode
  className?: string
}) {
  return (
    <section className={`flex flex-col rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)] ${className}`}>
      <div className="mb-4 flex items-start justify-between gap-3">
        {/*
          `min-w-0` on the text and `shrink-0` on the action, or the action loses.
          A flex item's minimum width is its content, so a two-word title with nowhere to wrap
          squeezed the button beside it until «حد جديد» broke across two lines on a phone — a control
          that reads as two controls. The title wraps; the control does not.
        */}
        <div className="min-w-0">
          <h3 className="text-base font-bold tracking-tight text-text-primary">{title}</h3>
          {description && <p className="mt-0.5 text-sm text-text-secondary">{description}</p>}
        </div>
        {action && <div className="shrink-0 whitespace-nowrap">{action}</div>}
      </div>
      {loading ? (
        <div className="space-y-2">
          <Skeleton className="h-40 w-full" />
        </div>
      ) : error ? (
        <ErrorState
          title={ar() ? 'تعذّر تحميل البيانات' : 'The data could not be loaded'}
          description={ar() ? 'حدث خطأ أثناء جلب المقاييس. حاول مجدداً.' : 'Something went wrong fetching the metrics. Please try again.'}
        />
      ) : empty ? (
        <div className="flex h-40 flex-col items-center justify-center rounded-xl border border-dashed border-border text-sm text-text-muted">
          {ar() ? 'لا توجد بيانات لهذه الفترة' : 'No data for this period'}
        </div>
      ) : (
        children
      )}
    </section>
  )
}

/*
 * Latin digits in both languages, per the product's standing rule — so «7 أيام» and «7 days» read
 * the same number, and a screenshot in either language is comparable with the other.
 */
const RANGES = [
  { days: 7, ar: '7 أيام', en: '7 days' },
  { days: 30, ar: '30 يوم', en: '30 days' },
  { days: 90, ar: '90 يوم', en: '90 days' },
]

export function RangeTabs({ value, onChange }: { value: number; onChange: (days: number) => void }) {
  return (
    <div className="inline-flex rounded-xl border border-border bg-surface-secondary p-0.5">
      {RANGES.map((r) => (
        <button
          key={r.days}
          onClick={() => onChange(r.days)}
          aria-pressed={value === r.days}
          // Selected range is brand-filled — the same clear selection language the other sections use.
          className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors ${
            value === r.days
              ? 'bg-brand-600 text-white shadow-[var(--shadow-small)]'
              : 'text-text-secondary hover:bg-surface-hover hover:text-text-primary'
          }`}
        >
          {ar() ? r.ar : r.en}
        </button>
      ))}
    </div>
  )
}

/**
 * Demo data, labelled in the reader's language.
 *
 * The badge is the product's promise that these figures are not a customer's real spend, so it has
 * to be legible to whoever is reading — a badge somebody cannot read is not a label.
 */
export function DemoBadge() {
  return (
    <span className="inline-flex items-center gap-1 rounded-full bg-[var(--warning-background)] px-2 py-0.5 text-xs font-semibold text-warning">
      {ar() ? 'بيانات تجريبية · Demo' : 'Demo data'}
    </span>
  )
}

/** What the backend says the rows in scope actually are. */
export type Provenance = { source: 'live' | 'demo' | 'mixed' | 'none'; live_rows?: number; demo_rows?: number }

/**
 * ANALYTICS-PROVENANCE-001 — the badge, derived rather than always-on.
 *
 * `DemoBadge` was rendered unconditionally on the dashboard, campaigns and analytics, so a project
 * syncing real Snapchat spend was labelled «بيانات تجريبية · Demo» beside its own money. A badge that
 * is always on carries no information, and this one carried something false: it is the product's
 * promise that a figure is NOT a customer's real spend.
 *
 * `live` shows nothing — the absence of a warning is the correct signal for real data, and a
 * «LIVE» chip on every screen would be the same noise in the other direction.
 *
 * `mixed` is named rather than resolved. A project holding both is a real state, and picking one
 * label would hide demo rows inside a live total.
 *
 * `none` shows nothing either: with no rows there is nothing to characterise, and the surfaces
 * already have their own empty states.
 */
export function ProvenanceBadge({ provenance }: { provenance?: Provenance | null }) {
  const source = provenance?.source

  if (source === 'demo') return <DemoBadge />

  if (source === 'mixed') {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-[var(--warning-background)] px-2 py-0.5 text-xs font-semibold text-warning">
        {ar() ? 'بيانات مختلطة · حقيقية وتجريبية' : 'Mixed · live and demo'}
      </span>
    )
  }

  return null
}

export function ChartTooltipStyle() {
  return null
}

export const tooltipProps = {
  contentStyle: {
    background: 'var(--surface)',
    border: '1px solid var(--border-strong)',
    borderRadius: 12,
    boxShadow: 'var(--shadow-medium)',
    fontSize: 13,
    color: 'var(--text-primary)',
  },
  labelStyle: { color: 'var(--text-secondary)', fontWeight: 600, marginBottom: 4 },
  itemStyle: { color: 'var(--text-primary)' },
}

export { compact }

/**
 * ANALYTICS-TRUTH-002 — one rate, one axis, one scale.
 *
 * ROAS, CPA and CTR were drawn as three lines on a shared axis. «3.20x», «21.96 USD» and «0.72%»
 * have no common unit, so two of the three were pressed flat against the floor and the chart could
 * only ever be read for the largest of them. Worse, the values arrived derived from a coalesced
 * zero, so what actually shipped was a single flat line at 0 under a title naming three metrics.
 *
 * A small panel each: the series keeps its own domain, the reader gets the shape, and the current
 * value is printed rather than being estimated off an axis.
 */
export function RateTrend({
  title,
  data,
  dataKey,
  color,
  loading,
  error,
  format,
}: {
  title: string
  data: Array<Record<string, unknown>>
  dataKey: string
  color: string
  loading?: boolean
  error?: boolean
  format: (v: number) => string
}) {
  const values = data.map((r) => r[dataKey]).filter((v): v is number => typeof v === 'number' && Number.isFinite(v))
  const latest = values.length > 0 ? values[values.length - 1] : null

  return (
    <Panel title={title} loading={loading} error={error} empty={!loading && values.length === 0}>
      <div className="mb-1 tnum text-xl font-extrabold leading-none text-text-primary">
        {latest === null ? '—' : format(latest)}
      </div>
      <div className="h-36">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={data} margin={{ top: 6, right: 8, left: 0, bottom: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
            <XAxis dataKey="date" tick={AXIS} tickFormatter={(v) => String(v).slice(5)} minTickGap={28} />
            <YAxis tick={AXIS} width={44} domain={['auto', 'auto']} tickFormatter={(v) => format(Number(v))} />
            <Tooltip {...tooltipProps} formatter={(v: number) => format(v)} />
            <Line type="monotone" dataKey={dataKey} stroke={color} strokeWidth={2} dot={false} connectNulls isAnimationActive={false} />
          </LineChart>
        </ResponsiveContainer>
      </div>
    </Panel>
  )
}
