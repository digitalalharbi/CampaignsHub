import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
import type { Range } from '@/features/analytics/api'
import type { LivePayload } from '@/features/reports/api'
import { createLiveLink, liveBuilderOptions } from '@/features/reports/api'

/**
 * ANALYTICS-REPORT-LOGIC-001 — the report's own payload for a project, a period and a platform/campaign choice.
 *
 * Served by `analytics/report-view`, which runs the same builder as the /r/ live report, so what the board draws from this
 * is what the report draws. The key carries every axis that narrows the request, so a cached answer is never served under
 * a different filter.
 */
export const reportViewKey = (projectId: string | null, range: Range, providers: string[], campaigns: string[]) =>
  ['analytics', 'report-view', projectId, range.from, range.to, [...providers].sort().join(','), [...campaigns].sort().join(',')] as const

export function reportViewUrl(projectId: string, range: Range, providers: string[], campaigns: string[]): string {
  const qs = new URLSearchParams({ from: range.from, to: range.to })
  providers.forEach((p) => qs.append('providers[]', p))
  campaigns.forEach((c) => qs.append('campaigns[]', c))

  return `/projects/${projectId}/analytics/report-view?${qs.toString()}`
}

export function useReportView(projectId: string | null, range: Range, providers: string[] = [], campaigns: string[] = [], enabled = true) {
  return useQuery({
    queryKey: reportViewKey(projectId, range, providers, campaigns),
    queryFn: () => getData<LivePayload>(reportViewUrl(projectId!, range, providers, campaigns)),
    enabled: enabled && Boolean(projectId),
    staleTime: 60_000,
    placeholderData: keepPreviousData,
  })
}

/**
 * «إنشاء تقرير من هذا العرض» — the view's own filters and period, turned into a live /r/ link by the EXISTING creation API.
 *
 * `POST /projects/{p}/reports/live` (the builder's own endpoint, which enforces `reports.share` and checks every account and
 * campaign against the project). The scope is the view's: the chosen platforms' ad accounts (all when none is chosen), narrowed
 * to the chosen campaigns inside the accounts that ran them, over exactly the period on screen. Spend stays hidden, as on every
 * client link, because the client report framing keeps spend off a link unless it is chosen in the report's own setup.
 */
export async function createReportFromView(args: { projectId: string; range: Range; providers: string[]; campaigns: string[]; name: string }) {
  const { projectId, range, providers, campaigns, name } = args
  const options = await liveBuilderOptions(projectId, range)
  const accountsAll = options.ad_accounts ?? []
  const chosen = options.campaigns.filter((c) => campaigns.includes(c.id))
  const byProvider = accountsAll.filter((a) => providers.length === 0 || providers.includes(a.provider))
  // A chosen campaign narrows to the accounts that ran it; with none chosen the accounts are the platforms' own.
  const accountIds = (chosen.length > 0
    ? [...new Set(chosen.flatMap((c) => (c.accounts ?? []).map((a) => a.id)))].filter((id) => byProvider.some((a) => a.id === id))
    : byProvider.map((a) => a.id))
  if (accountIds.length === 0) throw new Error('no-accounts')
  const accounts: Record<string, { campaign_ids: string[] }> = {}
  for (const id of accountIds) {
    const own = chosen.filter((c) => c.accounts?.some((a) => a.id === id)).map((c) => c.id)
    if (own.length > 0) accounts[id] = { campaign_ids: own }
  }
  const platformsOfAccounts = [...new Set(byProvider.filter((a) => accountIds.includes(a.id)).map((a) => a.provider))]

  const created = await createLiveLink(projectId, {
    name,
    account_ids: accountIds,
    ...(Object.keys(accounts).length > 0 ? { accounts } : {}),
    providers: providers.length > 0 ? providers.filter((p) => platformsOfAccounts.includes(p)) : platformsOfAccounts,
    from: range.from,
    to: range.to,
    period_kind: 'custom',
    period_mode: 'fixed',
    form: 'detailed',
    detail_level: 'standard',
  })

  return created as typeof created & { path?: string }
}

export function useCreateReportFromView() {
  return useMutation({ mutationFn: createReportFromView })
}
