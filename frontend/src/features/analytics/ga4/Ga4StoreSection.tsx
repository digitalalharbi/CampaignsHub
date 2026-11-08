import { getData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { ChartBodySkeleton, KpiRowSkeleton } from '@/components/ui/Skeletons'
import { useSwrQuery } from '@/lib/query/swr'
import { ChartCard } from '../charts'
import { Ga4Acquisition, Ga4Gap, Ga4Series, Ga4Tables, Ga4Tiles } from './Ga4StoreView'
import type { StorePayload } from './storeTypes'
import { hideParam } from './visibility'

/**
 * GA4-STORE-ANALYTICS-001 — the store reading on the Analytics «site» tab: tiles first, then the
 * charts, then the tables (the Owner's order: the figure at the top, charts below).
 *
 * The tab above this decides «not connected / not selected / empty»; this part draws only a ready
 * reading and stays silent otherwise, so the page never says the same thing twice.
 */
export function Ga4StoreSection({ projectId, range, ar, hide = [], showGap = true }: { projectId: string; range: { from: string; to: string }; ar: boolean; hide?: string[]; showGap?: boolean }) {
  const q = useSwrQuery({
    name: 'ga4-store',
    scope: [projectId, [...hide].sort().join(',')],
    window: [range.from, range.to],
    queryFn: () => getData<StorePayload>(`/projects/${projectId}/measurement/store?from=${range.from}&to=${range.to}${hideParam(hide)}`),
  })

  if (q.isLoading) {
    return (
      <div data-testid="ga4-store-skeleton" aria-busy="true" className="space-y-4">
        <KpiRowSkeleton idPrefix="ga4-tile" columns="grid-cols-2 sm:grid-cols-3 xl:grid-cols-6" labels={ar ? ['الزيارات', 'المستخدمون', 'الجدد', 'الارتداد', 'التفاعل', 'المدة'] : ['Sessions', 'Users', 'New', 'Bounce', 'Engagement', 'Duration']} />
        <ChartCard title={ar ? 'الزيارات والمبيعات' : 'Sessions & sales'}><ChartBodySkeleton height={260} testid="ga4-series-skeleton" /></ChartCard>
      </div>
    )
  }
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} testId="ga4-store-failure" onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّرت القراءة' : 'Could not be read'} />
  }

  const d = q.data
  if (d.state !== 'ready') return null

  return (
    <div data-testid="ga4-store" aria-busy={q.isShowingPrevious} className={`space-y-4 ${q.isShowingPrevious ? 'opacity-60' : ''}`}>
      <Ga4Tiles kpis={d.kpis ?? []} ar={ar} />
      {d.usd && !d.usd.available && d.usd.reason === 'no_rate' && (
        <Badge tone="warning" data-testid="ga4-usd-withheld">{ar ? `بلا سعر صرف ${d.usd.from ?? ''}` : `No ${d.usd.from ?? ''} rate`}</Badge>
      )}
      <Ga4Series series={d.series ?? []} currency={d.currency} ar={ar} />
      <Ga4Acquisition channels={d.channels} devices={d.devices} channelsMeta={d.channels_meta} devicesMeta={d.devices_meta} funnel={d.funnel ?? []} ar={ar} />
      {d.gap && showGap && <Ga4Gap gap={d.gap} ar={ar} />}
      {d.tables && <Ga4Tables tables={d.tables} currency={d.currency} ar={ar} />}
    </div>
  )
}
