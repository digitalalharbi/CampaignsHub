import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { moneyExact, num, percent, ratio } from '@/features/analytics/format'
import { metricLabel } from '@/features/analytics/metricLabels'
import { SPECS } from '@/features/analytics/metricCatalog'
import { extraMetricLabel } from './findingCopy'
import { relativeChange, type FindingKpi } from './findings'

/*
 * RECOMMENDATIONS-VISUAL-001 — the before/now KPI tile, on its own.
 *
 * Extracted from `FindingCard` so the client report's attention blocks (REPORT-RECOMMENDATION-BLOCKS-001)
 * draw a figure exactly as the operator's action centre does: one tile, one rule for which direction
 * is good, one formatter. It reads nothing but its props, so it renders the same on a public link and
 * in a printed PDF, where the card's router links and data hooks cannot run.
 */

/** The catalogue's name first — it carries CPA, ROAS and CTR — then the shorter label map. */
export function metricName(key: string, ar: boolean): string {
  const spec = SPECS[key]
  return extraMetricLabel(key, ar) ?? (spec ? (ar ? spec.label.ar : spec.label.en) : metricLabel(key, ar))
}

export function formatKpi(k: FindingKpi, v: number | null): string {
  if (v === null) return '—'
  if (k.kind === 'money') return moneyExact(v, k.currency ?? null)
  if (k.kind === 'ratio') return ratio(v)
  if (k.kind === 'percent') return percent(v, 2)
  return num(v)
}

/**
 * A figure before and now, with the direction coloured by what is GOOD for that metric — a CPA that
 * fell is green. Where there is no before, only the current figure is shown: an arrow from nothing
 * would claim a movement nobody measured.
 */
export function FindingKpiTile({ kpi, ar }: { kpi: FindingKpi; ar: boolean }) {
  const change = relativeChange(kpi)
  const better = change === null || change === 0 ? null : (change > 0) === kpi.higherIsBetter
  const Icon = change === null || change === 0 ? Minus : change > 0 ? ArrowUpRight : ArrowDownRight
  const max = Math.max(Math.abs(kpi.before ?? 0), Math.abs(kpi.current ?? 0))

  return (
    <div className="flex min-w-0 flex-col gap-1 rounded-xl border border-border p-2.5" data-testid={`kpi-${kpi.key}`}>
      <dt className="truncate text-[11px] text-text-muted">{metricName(kpi.key, ar)}</dt>
      <dd className="flex flex-wrap items-baseline gap-x-2">
        <span className="text-base font-extrabold text-text-primary" data-testid="kpi-current"><Num>{formatKpi(kpi, kpi.current)}</Num></span>
        {change !== null && change !== 0 && (
          <span className={`inline-flex items-center text-xs font-bold ${better ? 'text-success' : 'text-danger'}`} data-testid="kpi-change">
            <Icon size={12} aria-hidden />
            <Num>{percent(Math.abs(change), 0)}</Num>
          </span>
        )}
      </dd>
      {kpi.before !== null && (
        <>
          <dd className="text-[11px] text-text-muted" data-testid="kpi-before">
            {ar ? 'قبل' : 'Before'}: <Num>{formatKpi(kpi, kpi.before)}</Num>
          </dd>
          {max > 0 && kpi.current !== null && (
            <dd className="flex flex-col gap-1" aria-hidden>
              <span className="h-1.5 rounded-full bg-text-muted/40" style={{ width: `${(Math.abs(kpi.before) / max) * 100}%` }} />
              <span className={`h-1.5 rounded-full ${better === false ? 'bg-danger' : better ? 'bg-success' : 'bg-brand-600'}`} style={{ width: `${(Math.abs(kpi.current) / max) * 100}%` }} />
            </dd>
          )}
        </>
      )}
    </div>
  )
}
