import { useUi } from '@/stores/ui'
import { formatPeriodBounds, formatPeriodLabel } from '@/lib/period/formatPeriodLabel'
import type { PeriodKey } from '@/lib/period/periodMath'

/**
 * PERIOD-LABEL-001 — a period on screen, in the Owner's one format (see `formatPeriodLabel`).
 *
 * `bdi` with the page language's own direction: an Arabic date range («12 نوفمبر – 18 نوفمبر 2026») keeps its
 * start on the right inside any `dir="ltr"` row, and an English one («Nov 12 – Nov 18, 2026») keeps its
 * start on the left inside an Arabic page. `presetKey` names the choice; without it the range is custom (dates only).
 */
export function PeriodText({ from, to, presetKey = null, inferName = false, className = '', testId }: {
  from: string | null | undefined
  to: string | null | undefined
  presetKey?: PeriodKey | null
  /** Name it after the preset its dates match today — for a page whose period IS the picker's. A stored snapshot never sets it. */
  inferName?: boolean
  className?: string
  testId?: string
}) {
  const ar = useUi((s) => s.locale) === 'ar'

  return (
    <bdi dir={ar ? 'rtl' : 'ltr'} className={`tnum ${className}`.trim()} data-testid={testId}>
      {from && to ? formatPeriodLabel({ key: presetKey, from, to, locale: ar ? 'ar' : 'en', inferName }) : formatPeriodBounds(from, to, ar ? 'ar' : 'en')}
    </bdi>
  )
}
