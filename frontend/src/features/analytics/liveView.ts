import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

/**
 * LIVE-OPERATING-VIEW-001 — one row per source: latest successful sync · latest source timestamp ·
 * next sync · state · how the figures move. The server states `realtime: false` on the view and on
 * every row, and the interface repeats the statement rather than softening it.
 */
export interface LiveSource {
  kind: 'ad_platform' | 'store'
  provider: string
  account_id: string | null
  name: string | null
  state: string
  missing_grain: 'campaign' | 'ad_set' | 'ad' | null
  latest_successful_sync_at: string | null
  latest_attempt_at: string | null
  latest_source_timestamp: string | null
  latest_metric_date: string | null
  last_sync_error: string | null
  next_sync_at: string | null
  next_sync_reason: 'not_connected' | null
  connected: boolean
  bound_accounts: number
  mechanisms: {
    scheduled: { command: string; expression: string | null; next_run_at: string | null; last_outcome: string | null; overdue: boolean | null } | null
    incremental: { window_days: number } | { cursor: true }
    manual: boolean
    webhooks: 'supported' | 'polling_only' | 'requires_confirmation'
  }
  realtime: false
}

export interface SchedulerRow {
  command: string
  expression: string | null
  next_run_at: string | null
  last_outcome: string | null
  last_started_at?: string | null
  overdue: boolean | null
}

export interface LiveView {
  as_of: string
  realtime: false
  realtime_statement: { ar: string; en: string }
  window_days: number
  verdict: { state: string; last_sync_at: string | null; missing_days: number; sync_failed: boolean }
  sources: LiveSource[]
  scheduler: SchedulerRow[]
}

export function useLiveView(projectId: string | null) {
  return useQuery({
    queryKey: ['projects', projectId, 'live-view'],
    queryFn: () => getData<LiveView>(`/projects/${projectId}/live-view`),
    enabled: Boolean(projectId),
    // Live as of the latest sync, re-asked each minute while the tab is open — never a push, never «real-time».
    refetchInterval: 60_000,
  })
}
