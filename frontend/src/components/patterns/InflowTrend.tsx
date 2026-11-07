import { KpiSparkline } from '@/features/analytics/charts'
import { Num } from '@/components/ui/Num'

export interface InflowPoint {
  date: string
  count: number
}

/**
 * UX-COMPACT-CHARTS-001 (Owner, 2026-11-01) — a real trend in a small strip of its own, under the cards.
 *
 * Kept out of the KPI cards so the page head's height does not change. It draws only what happened: records that arrived per
 * day, from the server. A series too short or flat to have a shape draws nothing at all rather than a line that implies one.
 */
export function InflowTrend({ series, label, ar, testId }: { series: InflowPoint[] | undefined | null; label: { ar: string; en: string }; ar: boolean; testId: string }) {
  if (!series || series.length < 2) return null
  const points = series.map((p) => p.count)
  if (points.every((v) => v === points[0])) return null
  const total = points.reduce((a, b) => a + b, 0)

  return (
    <div data-testid={testId} className="mb-3 flex min-w-0 items-center gap-3 rounded-xl border border-border bg-surface px-3 py-1.5">
      <span className="shrink-0 text-[12px] text-text-secondary">
        {ar ? label.ar : label.en} · {ar ? `آخر ${series.length} يومًا` : `last ${series.length} days`}: <Num className="font-semibold text-text-primary">{total.toLocaleString('en-US')}</Num>
      </span>
      <div className="min-w-0 flex-1" aria-hidden><KpiSparkline points={points} height={22} /></div>
    </div>
  )
}
