import { useQuery } from '@tanstack/react-query'
import { listTasks } from './followupApi'

/** The follow-up list for the centre's tab and the page; refreshed every minute while shown. A 403 with `upgrade` is not retried. */
export function useFollowupTasks(status: 'open' | 'snoozed' | 'done' | 'all' = 'open', enabled = true) {
  return useQuery({ queryKey: ['followup', 'tasks', status], queryFn: () => listTasks(status), enabled, refetchInterval: enabled ? 60_000 : false, retry: false })
}
