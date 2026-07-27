import { api } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'

export interface AppNotification {
  id: string
  type: string
  severity: 'info' | 'success' | 'warning' | 'critical'
  title: string
  message: string | null
  project_id: string | null
  client_workspace_id: string | null
  action_url: string | null
  status: 'unread' | 'read' | 'snoozed' | 'resolved'
  read_at: string | null
  created_at: string | null
}

export interface NotificationDeliveryRow {
  id: string
  channel: 'in_app' | 'email'
  status: string
  attempts: number
  type: string
  title: string
  created_at: string | null
}

export async function listNotifications(): Promise<{ items: AppNotification[]; unread: number }> {
  const res = await api.get<ApiEnvelope<AppNotification[]>>('/notifications')
  return { items: res.data.data ?? [], unread: (res.data.meta as { unread?: number } | undefined)?.unread ?? 0 }
}

export const markNotificationRead = (id: string) => api.post(`/notifications/${id}/read`)
export const markAllNotificationsRead = () => api.post('/notifications/read-all')

export async function listDeliveries(): Promise<NotificationDeliveryRow[]> {
  const res = await api.get<ApiEnvelope<NotificationDeliveryRow[]>>('/notifications/deliveries')
  return res.data.data ?? []
}
