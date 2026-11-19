import { useMemo } from 'react'
import { useUrlList } from '@/features/analytics/filterUrlState'
import type { Range } from '@/features/analytics/api'
import { useLiveMetricReader } from '@/features/reports/live/liveMetrics'
import { headlineKeys, resultSource } from '@/features/reportLogic'
import { useUi } from '@/stores/ui'
import { useReportView } from './api'

/**
 * ANALYTICS-REPORT-LOGIC-001 — the board's one reading of the report payload.
 *
 * `all` is the project's payload for the period over EVERY platform: the cards, the split, the ranking and the funnel keep every
 * platform on screen so one can be picked, swapped or cleared. `view` is the same builder narrowed to the page's platform and
 * campaign filters (the URL's `provider` and `campaign`, shared with every other panel): the content, the figures and the daily
 * line read it. With no filter the two are one request.
 *
 * Clicking a platform or a campaign anywhere writes those two URL lists through `useUrlList`, so the whole page narrows, a
 * refresh keeps it, and a shared link carries it.
 */
export function useBoardReport(projectId: string, range: Range) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [providers, setProviders] = useUrlList('provider')
  const [campaigns, setCampaigns] = useUrlList('campaign')
  const all = useReportView(projectId, range, [], [])
  const view = useReportView(projectId, range, providers, campaigns)
  const payload = view.data
  const currency = payload?.currency ?? all.data?.currency ?? ''
  const reader = useLiveMetricReader(currency, ar)
  const allSource = useMemo(() => (all.data ? resultSource(all.data, reader, headlineKeys(all.data, reader).result, ar) : null), [all.data, reader, ar])
  const viewSource = useMemo(() => (payload ? resultSource(payload, reader, headlineKeys(payload, reader).result, ar) : null), [payload, reader, ar])

  /** One platform at a time from a chart: the same platform again clears it. */
  const pickPlatform = (key: string | null) => setProviders(key === null || (providers.length === 1 && providers[0] === key) ? [] : [key])
  /** One campaign at a time from a chart: the same campaign again clears it. */
  const pickCampaign = (id: string | null) => setCampaigns(id === null || (campaigns.length === 1 && campaigns[0] === id) ? [] : [id])

  return { ar, all: all.data, allSource, view: payload, viewSource, reader, currency, providers, campaigns, pickPlatform, pickCampaign, pending: all.isPending, failed: all.isError, refetch: all.refetch }
}

export type BoardReport = ReturnType<typeof useBoardReport>
