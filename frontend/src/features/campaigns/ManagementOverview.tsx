import { useMemo } from 'react'
import { KpiCards } from '@/features/analytics/KpiCards'
import { ChartCard, MetricLineChart, PlatformDonutChart } from '@/features/analytics/charts'
import { Panel } from '@/features/analytics/components'
import { usePlatforms, useSummary, useTimeseries, type MetricFilters, type Range } from '@/features/analytics/api'
import { plotSeries } from '@/features/analytics/timeseriesMoney'
import { comparableWindows } from '@/lib/coverage/contract'
import { canonicalPlatform } from '@/lib/platforms'
import { providerLabel } from './labels'
import { useUi } from '@/stores/ui'

/**
 * MGMT-PAGES-001 — the operational analytics every management page carries above its table.
 *
 * The Owner's benchmark is the client report: a KPI strip with sparklines and deltas, the trend over the
 * window, where the results came from, then the table. These are the SAME blocks the dashboard and the
 * report draw (`KpiCards`, `MetricLineChart`, `PlatformDonutChart`) over the same project metrics under
 * the same filters as the table beneath — one data pipeline, not a second one. The strip states the
 * project's figures within the page's filters; the search box narrows rows, never the figures.
 */
export function ManagementOverview({ projectId, range, filters, testid }: { projectId: string; range: Range; filters: MetricFilters; testid: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const s = useSummary(projectId, range, filters)
  const ts = useTimeseries(projectId, range, filters)
  const platforms = usePlatforms(projectId, range, filters)

  const reportingCurrency = s.data?.currency ?? null
  const points = ts.data ?? []
  const series = useMemo(() => plotSeries(points, reportingCurrency, ar), [points, reportingCurrency, ar])
  const comparable = comparableWindows(s.data)

  const results = (platforms.data ?? [])
    .filter((p) => p.conversions > 0)
    .map((p) => ({ name: providerLabel(canonicalPlatform(p.provider), ar ? 'ar' : 'en'), value: p.conversions, key: canonicalPlatform(p.provider) }))
  const totalResults = results.reduce((a, b) => a + b.value, 0)

  return (
    <div className="flex flex-col gap-4" data-testid={testid}>
      <KpiCards
        objective="all"
        summary={s.data}
        series={points}
        comparable={comparable}
        ar={ar}
        loading={s.isPending}
        error={s.isError ? s.error : undefined}
        onRetry={() => void s.refetch()}
      />
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 [&>*]:min-w-0">
        <div className="lg:col-span-2">
          <Panel
            title={ar ? 'الأداء بمرور الوقت' : 'Performance over time'}
            description={series.note ?? (ar ? 'الإنفاق والنتائج والإيرادات يومًا بيوم' : 'Spend, results and revenue, day by day')}
            loading={ts.isPending}
            error={ts.isError}
            empty={!ts.isPending && !ts.isError && series.rows.length === 0}
          >
            <div data-testid={`${testid}-trend`}>
              <MetricLineChart
                data={series.rows}
                height={240}
                currency={series.currency ?? reportingCurrency ?? ''}
                rightAxisFor="conversions"
                series={[
                  { key: 'spend', name: ar ? 'الإنفاق' : 'Spend', kind: 'money' },
                  { key: 'conversions', name: ar ? 'النتائج' : 'Results', kind: 'num' },
                  ...(series.hasMoney ? [{ key: 'revenue', name: ar ? 'الإيرادات' : 'Revenue', kind: 'money' as const }] : []),
                ]}
              />
            </div>
          </Panel>
        </div>
        <ChartCard title={ar ? 'من أين جاءت النتائج' : 'Where the results came from'}>
          {platforms.isPending ? (
            <div className="h-[200px] animate-pulse rounded-xl bg-surface-secondary" />
          ) : results.length === 0 ? (
            <p className="flex h-[200px] items-center justify-center text-center text-sm text-text-muted" data-testid={`${testid}-share-empty`}>
              {ar ? 'لا نتائج مسجّلة ضمن هذه الفلاتر في الفترة.' : 'No results recorded under these filters in the period.'}
            </p>
          ) : (
            <div data-testid={`${testid}-share`}>
              <PlatformDonutChart data={results} height={200} centerValue={totalResults.toLocaleString('en-US')} centerLabel={ar ? 'النتائج' : 'Results'} />
            </div>
          )}
        </ChartCard>
      </div>
    </div>
  )
}
