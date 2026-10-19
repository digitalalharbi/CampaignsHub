import { percent } from './format'
import { useUi } from '@/stores/ui'

/**
 * BUDGET-HIERARCHY-001 — how much of the plan is spent, against how much SHOULD be by now.
 *
 * A bare progress bar says «75% spent» and leaves the reader to remember what day of the period it is. The tick
 * marks where the plan expected to be today (`expected ÷ total`, the same figure `pace` divides by), so «75%
 * and the tick at 50%» reads as running ahead at a glance, and «75% and the tick at 80%» as comfortably inside.
 * The colour follows the pace, not the percentage alone: 90% spent in the last days of a period is on plan.
 *
 * Every input is the server's: nothing is derived here. With no expected figure (a withheld or incomparable
 * row) there is no tick and the colour falls back to the absolute thresholds the table always used.
 */
export function ConsumptionBar({ consumed, expected, total, width = 'w-16' }: { consumed: number; expected?: number | null; total?: number | null; width?: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const marker = expected != null && total != null && total > 0 && expected >= 0 ? Math.min(1, expected / total) : null

  const tone = marker !== null
    ? consumed > marker * 1.2 + 0.02 ? 'bg-danger' : consumed > marker * 1.05 + 0.01 ? 'bg-warning' : 'bg-brand-500'
    : consumed >= 0.9 ? 'bg-danger' : consumed >= 0.8 ? 'bg-warning' : 'bg-brand-500'

  const label = marker === null
    ? (ar ? `المستهلك ${percent(consumed, 0)}` : `Consumed ${percent(consumed, 0)}`)
    : (ar ? `المستهلك ${percent(consumed, 0)} — المتوقع حتى اليوم ${percent(marker, 0)}` : `Consumed ${percent(consumed, 0)} — expected by now ${percent(marker, 0)}`)

  return (
    <span className="inline-flex items-center gap-2" data-testid="consumption-bar">
      <span role="img" aria-label={label} className={`relative h-1.5 ${width} overflow-hidden rounded-full bg-surface-secondary`} dir="ltr">
        <span className={`block h-full rounded-full ${tone}`} style={{ width: `${Math.min(100, consumed * 100)}%` }} />
        {marker !== null && (
          <span data-testid="consumption-expected" className="absolute inset-y-0 w-0.5 bg-text-primary/70" style={{ left: `calc(${marker * 100}% - 1px)` }} />
        )}
      </span>
      <span className="tnum text-xs">{percent(consumed, 0)}</span>
    </span>
  )
}
