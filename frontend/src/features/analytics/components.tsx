import type { ReactNode } from 'react'
import { Area, AreaChart, ResponsiveContainer } from 'recharts'
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { compact, percent, trend } from './format'
import type { Trend } from './format'

/** Brand-consistent series colors (CSS vars resolve in light + dark). */
export const SERIES = {
  spend: 'var(--brand-600)',
  revenue: 'var(--info)',
  conversions: 'var(--purple)',
  clicks: 'var(--teal)',
  neutral: 'var(--text-muted)',
}
export const PLATFORM_COLORS: Record<string, string> = {
  meta: '#1877f2',
  google: '#ea4335',
  tiktok: '#000000',
  snapchat: '#f7b500',
  x: '#111111',
  linkedin: '#0a66c2',
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

export function KpiCard({
  label,
  value,
  delta,
  invertGood = false,
  spark,
  hint,
  accent = SERIES.spend,
}: {
  label: string
  value: string
  delta?: number | null
  invertGood?: boolean
  spark?: number[]
  hint?: string
  accent?: string
}) {
  return (
    <div className="group relative flex flex-col gap-2 rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)] transition-all hover:-translate-y-0.5 hover:shadow-[var(--shadow-medium)]">
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-text-secondary" title={hint}>
          {label}
        </span>
        {delta !== undefined && <TrendPill delta={delta} invertGood={invertGood} />}
      </div>
      <div className="tnum text-[length:var(--text-kpi)] font-extrabold leading-none tracking-tight text-text-primary">
        {value}
      </div>
      {spark && spark.length > 1 && (
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
      )}
    </div>
  )
}

/** Card shell for a chart/section with title, optional action, and loading/empty/error handling. */
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
        <div>
          <h3 className="text-base font-bold tracking-tight text-text-primary">{title}</h3>
          {description && <p className="mt-0.5 text-sm text-text-secondary">{description}</p>}
        </div>
        {action}
      </div>
      {loading ? (
        <div className="space-y-2">
          <Skeleton className="h-40 w-full" />
        </div>
      ) : error ? (
        <ErrorState title="تعذّر تحميل البيانات" description="حدث خطأ أثناء جلب المقاييس. حاول مجدداً." />
      ) : empty ? (
        <div className="flex h-40 flex-col items-center justify-center rounded-xl border border-dashed border-border text-sm text-text-muted">
          لا توجد بيانات لهذه الفترة
        </div>
      ) : (
        children
      )}
    </section>
  )
}

const RANGES = [
  { days: 7, label: '7 أيام' },
  { days: 30, label: '30 يوم' },
  { days: 90, label: '90 يوم' },
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
          {r.label}
        </button>
      ))}
    </div>
  )
}

export function DemoBadge() {
  return (
    <span className="inline-flex items-center gap-1 rounded-full bg-[var(--warning-background)] px-2 py-0.5 text-xs font-semibold text-warning">
      بيانات تجريبية · Demo
    </span>
  )
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
