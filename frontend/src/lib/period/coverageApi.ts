import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

/** One account's gap inside the period, as the sync history recorded it. */
export interface AccountCoverage {
  account_id: string
  name: string
  provider: string
  timezone: string | null
  timezone_differs: boolean
  never_synced: boolean
  missing_count: number
  failed_count: number
  missing_days: string[]
  failed_days: string[]
  truncated: boolean
}

export interface PeriodCoverage {
  period: { from: string; to: string; ongoing: boolean; timezone: string }
  ongoing: boolean
  incomplete: boolean
  accounts: AccountCoverage[]
  summary: { accounts_checked: number; accounts_incomplete: number; missing_days_total: number; accounts_other_timezone: number }
}

/** DATE-RANGE-001 §4 — which complete days of the period have no figures, per account. Null project: no read. */
export function usePeriodCoverage(projectId: string | null | undefined, range: { from: string; to: string }) {
  return useQuery({
    queryKey: ['metrics', 'period-coverage', projectId, range.from, range.to],
    queryFn: () => getData<PeriodCoverage>(`/projects/${projectId}/metrics/period-coverage?from=${range.from}&to=${range.to}`),
    enabled: Boolean(projectId),
    staleTime: 60_000,
  })
}
