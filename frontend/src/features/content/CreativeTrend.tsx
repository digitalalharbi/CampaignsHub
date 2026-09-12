import { useQuery } from '@tanstack/react-query'
import { MetricLineChart } from '@/features/analytics/charts'
import { metricLabel } from './metrics'
import { getCreativeInReach } from './api'
import { Skeleton } from '@/components/ui/States'
import type { Locale } from '@/stores/ui'
import { Num } from '@/components/ui/Num'


/**
 * One creative, from the endpoint that can actually reach it — CONTENT-POPUP-TREND-404-001.
 *
 * ## The drift this component exists to prevent, in this component
 *
 * `CreativeTrend`'s whole reason for being is that the modal and the detail page must not derive a
 * creative's trend two different ways. It then asked a different ENDPOINT from the detail page, and
 * the difference was not cosmetic: `/projects/{project}/creatives/{creative}` is scoped to one
 * project, and `CreativeAnalysisController::detail` says in its own docblock why the other one
 * exists — «the library spans projects and a card does not carry a project id».
 *
 * So opening the quick panel on a card whose creative belongs to a different project than the one
 * currently selected returned 404 «العنصر المطلوب غير موجود», and the panel drew «تعذّر تحميل
 * الاتجاه الزمني» — a load error, for a creative sitting in the library the click came from. Found
 * by reading the panel's own network traffic, not by a test: every unit test mocks this call.
 *
 * `getCreativeInReach` is what `CreativeDetailPage` has always used. Its ceiling is the reader's
 * MEMBERSHIP, which is the only version of this that cannot be widened by editing a URL.
 *
 * `projectId` stays in the signature and in the key: it is what the caller knows it is asking
 * about, and two projects' windows must not share a cache entry.
 */
function useCreativeInReach(projectId: string, creativeId: string, window: { from?: string; to?: string }) {
  return useQuery({
    queryKey: ['creative', projectId, creativeId, window.from, window.to],
    queryFn: () => getCreativeInReach(creativeId, window),
    enabled: Boolean(creativeId),
  })
}

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

  const q = useCreativeInReach(projectId, creativeId, window)

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

/**
 * How this creative moved against the window before it — the server's own comparison.
 *
 * «Comparison against the previous period where truthfully available.» `getCreative` already returns
 * `previous` and the dates it covers, so this asks the SAME query as `CreativeTrend` — React Query
 * dedupes on the key, so a modal showing both fires one request — and reads the figures the server
 * computed rather than differencing two windows here.
 *
 * ## «Where truthfully available» is the whole contract
 *
 * `previous` is null when the creative did not exist in that window, or when the caller asked for no
 * comparison. A percentage against a baseline of zero is infinite, and a metric neither window
 * reported has no change to state. Each of those renders as nothing rather than as «0%», which would
 * be a claim that it held steady.
 */
export function CreativeComparison({
  projectId,
  creativeId,
  window,
  locale,
  metrics = ['spend', 'impressions', 'clicks', 'conversions'],
}: {
  projectId: string
  creativeId: string
  window: { from?: string; to?: string }
  locale: Locale
  /** Which figures to compare, in the order they should read. */
  metrics?: string[]
}) {
  const ar = locale === 'ar'

  const q = useCreativeInReach(projectId, creativeId, window)

  const previous = q.data?.previous ?? null
  const current = q.data?.metrics ?? null

  if (q.isPending || q.isError || previous === null || current === null) {
    return null
  }

  const rows = metrics.flatMap((key) => {
    const now = (current as Record<string, number | null | undefined>)[key]
    const before = (previous as Record<string, number | null | undefined>)[key]

    /* Both windows must have REPORTED it — a missing figure has no movement, and zero is not a base. */
    if (typeof now !== 'number' || typeof before !== 'number' || before === 0) {
      return []
    }

    return [{ key, change: (now - before) / before }]
  })

  if (rows.length === 0) {
    return null
  }

  return (
    <div data-testid="creative-comparison" className="mt-3">
      <h4 className="mb-1 text-xs font-bold text-text-secondary">
        {ar ? 'مقارنة بالفترة السابقة' : 'Against the previous period'}
      </h4>

      <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-4">
        {rows.map((r) => (
          <div key={r.key} data-testid={`creative-comparison-${r.key}`} className="rounded-lg bg-surface-secondary p-2 text-center">
            <div className="text-[11px] font-semibold leading-tight text-text-muted">{metricLabel(r.key, locale)}</div>
            <div className={`tnum text-start text-sm font-bold ${r.change >= 0 ? 'text-success' : 'text-danger'}`}>
              <Num>{r.change >= 0 ? '+' : ''}{(r.change * 100).toFixed(1)}%</Num>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
