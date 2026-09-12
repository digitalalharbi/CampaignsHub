import { useQuery } from '@tanstack/react-query'
import { MetricLineChart } from '@/features/analytics/charts'
import { metricLabel } from './metrics'
import { getCreative } from './api'
import { Skeleton } from '@/components/ui/States'
import type { Locale } from '@/stores/ui'

/**
 * ANALYTICS-CONTENT-PREVIEW-001 — one creative's trend, drawn wherever it is asked for.
 *
 * «The modal must include a performance trend chart … reuse canonical Content/Creative truth. Do NOT
 * create another preview or metrics pipeline.»
 *
 * `CreativeDetailPage` has drawn this chart from `getCreative` since it shipped. What did not exist
 * was a way to ask for it from anywhere else, so the obvious route to a trend inside the Analytics
 * modal was a second series derived from whatever that surface happened to hold — a second answer to
 * «how did this creative move», differing from the detail page by whatever the two windows disagreed
 * about.
 *
 * So the block moved here rather than being copied: same endpoint, same four series, same money
 * reader. The detail page renders this component too, which is what keeps the two from drifting.
 *
 * ## The states are the point
 *
 * A creative with no daily rows is «not enough history to draw», never an empty chart frame. The
 * request failing is its own answer, and neither is a flat line at zero — a chart that draws a line
 * through absent data is the coalesced zero with an axis.
 */
export function CreativeTrend({
  projectId,
  creativeId,
  window,
  locale,
  currency,
  height,
}: {
  projectId: string
  creativeId: string
  window: { from?: string; to?: string }
  locale: Locale
  /** The surface's own reporting currency — never re-derived here. */
  currency: string
  height?: number
}) {
  const ar = locale === 'ar'

  const q = useQuery({
    queryKey: ['creative', projectId, creativeId, window.from, window.to],
    queryFn: () => getCreative(projectId, creativeId, window),
    enabled: Boolean(projectId && creativeId),
  })

  if (q.isPending) {
    return <Skeleton className="h-40 w-full" />
  }

  if (q.isError) {
    return (
      <p data-testid="creative-trend-error" className="py-6 text-center text-sm text-text-secondary">
        {ar ? 'تعذّر تحميل الاتجاه الزمني لهذا المحتوى.' : 'Could not load this content item’s trend.'}
      </p>
    )
  }

  const rows = q.data?.trend ?? []

  if (rows.length === 0) {
    return (
      <p data-testid="creative-trend-empty" className="py-6 text-center text-sm text-text-secondary">
        {ar
          ? 'لا توجد أيام مسجَّلة لهذا المحتوى في هذه الفترة — لا يوجد اتجاه لرسمه.'
          : 'No days were reported for this content item in this period — there is no trend to draw.'}
      </p>
    )
  }

  return (
    <div data-testid="creative-trend">
      <MetricLineChart
        data={rows as Array<Record<string, unknown>>}
        currency={currency}
        height={height}
        series={[
          { key: 'spend', name: metricLabel('spend', locale), kind: 'money' },
          { key: 'impressions', name: metricLabel('impressions', locale), kind: 'compact' },
          { key: 'clicks', name: metricLabel('clicks', locale), kind: 'compact' },
          { key: 'conversions', name: metricLabel('conversions', locale), kind: 'num' },
        ]}
      />
    </div>
  )
}
