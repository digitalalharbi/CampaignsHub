import { sparkFor } from '@/features/analytics/metricCatalog'
import type { TimePoint } from '@/features/analytics/api'

/**
 * The shape of one KPI over the live link's window — by the product's one sparkline rule.
 *
 * `sparkFor` is what the dashboard's own cards use: a null day is a hole and not a zero, a series
 * more than a third missing is refused, and a flat line is not a trend. Every hero card asks, so a
 * card whose metric the payload carries daily draws its shape rather than leaving its row empty.
 */
export function liveSpark(key: string, series: ReadonlyArray<Record<string, unknown>>): number[] | undefined {
  return sparkFor(key, series as unknown as readonly TimePoint[], undefined)
}
