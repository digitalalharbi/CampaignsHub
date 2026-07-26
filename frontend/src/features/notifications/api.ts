import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, postData } from '@/lib/api/client'

export interface AppNotification {
  id: string
  type: string
  severity: 'info' | 'warning' | 'critical'
  title: string
  message: string | null
  source: string | null
  action_url: string | null
  status: 'unread' | 'read' | 'snoozed' | 'resolved'
  created_at: string | null
}

/** Project-scoped notification feed (alerts + system). */
export function useNotifications(projectId: string | null) {
  return useQuery({
    queryKey: ['notifications', projectId],
    enabled: !!projectId,
    refetchInterval: 60_000,
    queryFn: () => getData<AppNotification[]>(`/projects/${projectId}/notifications`),
  })
}

export function useNotificationActions(projectId: string | null) {
  const qc = useQueryClient()
  const inv = () => qc.invalidateQueries({ queryKey: ['notifications', projectId] })
  return {
    markRead: useMutation({ mutationFn: (id: string) => postData(`/notifications/${id}/read`), onSuccess: inv }),
    markAllRead: useMutation({ mutationFn: () => postData('/notifications/read-all'), onSuccess: inv }),
    resolve: useMutation({ mutationFn: (id: string) => postData(`/notifications/${id}/resolve`), onSuccess: inv }),
    snooze: useMutation({
      mutationFn: (id: string) => postData(`/notifications/${id}/snooze`, { until: new Date(Date.now() + 864e5).toISOString() }),
      onSuccess: inv,
    }),
  }
}
