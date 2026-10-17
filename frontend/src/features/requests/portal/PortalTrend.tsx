import { useState } from 'react'
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { SERIES, tooltipProps } from '@/features/analytics/components'
import { compact } from '@/features/analytics/format'
import { days as countedDays } from '@/lib/counted'
import { useUi } from '@/stores/ui'
import type { PortalOverview } from './portalOverviewApi'

type Metric = 'conversions' | 'clicks' | 'impressions'

const LABEL: Record<Metric, { ar: string; en: string }> = {
  conversions: { ar: 'النتائج', en: 'Results' },
  clicks: { ar: 'النقرات', en: 'Clicks' },
  impressions: { ar: 'مرات الظهور', en: 'Impressions' },
}

/**
 * CP-DASH-001 — the client's delivery over time, one measure at a time.
 *
 * An Area chart because the question is «is it rising», and only of the delivery measures a client is always
 * allowed: money never rides here. Days with no data are absent from the series rather than drawn as zeros,
 * the measure is the reader's to switch, the unit and period are said on the chart, and the same figures are
 * available as text for a screen reader (a chart is an SVG a reader cannot walk).
 *
 * Drawn only when at least two days carry the chosen measure: a single point is a number, not a trend.
 */
export function PortalTrend({ trend, days }: { trend: NonNullable<PortalOverview['trend']>; days: number }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const available = (['conversions', 'clicks', 'impressions'] as const).filter((m) => trend.filter((p) => p[m] !== null).length >= 2)
  const [picked, setPicked] = useState<Metric | null>(null)
  const metric = picked !== null && available.includes(picked) ? picked : available[0]

  if (metric === undefined) return null

  const points = trend.filter((p) => p[metric] !== null).map((p) => ({ date: p.date, v: p[metric] as number }))
  const latin = (d: string) => d.slice(5)

  return (
    <div data-testid="portal-trend" className="mt-4 rounded-2xl border border-border bg-surface p-4">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-bold text-text-primary">
          {ar ? 'الأداء عبر الوقت' : 'Over time'} <span className="font-normal text-text-muted">· {ar ? `آخر ${countedDays(days, 'ar')}` : `last ${countedDays(days, 'en')}`}</span>
        </h3>
        {available.length > 1 && (
          <div role="group" aria-label={ar ? 'المؤشر' : 'Measure'} className="flex gap-1">
            {available.map((m) => (
              <button
                key={m}
                type="button"
                aria-pressed={m === metric}
                data-testid={`trend-${m}`}
                onClick={() => setPicked(m)}
                className={`rounded-lg border px-2.5 py-1 text-xs font-semibold ${m === metric ? 'border-brand-500 bg-[var(--brand-background)] text-brand-700' : 'border-border text-text-secondary hover:bg-surface-hover'}`}
              >
                {LABEL[m][ar ? 'ar' : 'en']}
              </button>
            ))}
          </div>
        )}
      </div>
      <div className="h-40" dir="ltr" role="img" aria-label={`${LABEL[metric][ar ? 'ar' : 'en']} — ${countedDays(points.length, ar ? 'ar' : 'en')}`}>
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={points} margin={{ top: 6, right: 8, bottom: 0, left: 0 }}>
            <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="date" tickFormatter={latin} tick={{ fontSize: 11, fill: 'var(--text-muted)' }} tickLine={false} axisLine={false} minTickGap={24} />
            <YAxis tickFormatter={(v: number) => compact(v)} tick={{ fontSize: 11, fill: 'var(--text-muted)' }} tickLine={false} axisLine={false} width={44} />
            <Tooltip {...tooltipProps} formatter={(v) => [compact(Number(v)), LABEL[metric][ar ? 'ar' : 'en']]} labelFormatter={(l) => String(l)} />
            <Area type="monotone" dataKey="v" connectNulls={false} stroke={SERIES[metric === 'conversions' ? 'conversions' : 'clicks']} fill={SERIES[metric === 'conversions' ? 'conversions' : 'clicks']} fillOpacity={0.15} strokeWidth={2} dot={points.length <= 14} isAnimationActive={false} />
          </AreaChart>
        </ResponsiveContainer>
      </div>
      <ul className="sr-only" data-testid="trend-text">
        {points.map((p) => <li key={p.date}>{p.date}: {compact(p.v)}</li>)}
      </ul>
    </div>
  )
}
