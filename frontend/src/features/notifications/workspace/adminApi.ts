import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { deleteData, getData, putData } from '@/lib/api/client'
import type { Channel, Priority, Scope } from '@/lib/notifications'
import type { Bilingual } from './api'

const BASE = '/admin/notifications-v2'

export interface AdminCatalogEvent {
  key: string; scope: Scope; priority: Priority; channels: Channel[]; default_roles: Record<string, string[]>
  entitlement: string | null; creates_task: boolean; internal_only: boolean; enabled: boolean; version: number; title: Bilingual
}
export interface AdminPlanFeature {
  id: string; key: string; plan_code: string | null; workspace_type: string | null; enabled: boolean
  limits: Record<string, number | null> | null; grace_days: number; keep_existing: boolean; paid_from: string | null
}
export interface AdminData {
  catalog: AdminCatalogEvent[]
  workspace_types: { key: string; roles: ({ key: string } & Bilingual)[]; client_role: string | null; responsible_role: string; terms: Record<string, Bilingual> }[]
  entitlement_keys: { key: string; default: boolean }[]
  limit_defaults: Record<string, number | null>
  grace_days_default: number
  plans: { code: string; name: string; name_ar: string | null }[]
  plan_features: AdminPlanFeature[]
  texts: Record<string, { value: Record<string, unknown>; customised: boolean }>
}
export interface AdminStats {
  period: string; messages_sent: number; messages_failed: number; failure_rate: number | null; unsubscribed: number
  unsubscribe_rate: number | null; footprint_clicks: number | null
  per_workspace: { workspace: string; plan: string | null; messages: number; recipients: number; push: number; charts: number }[]
}

export const useAdminNotifications = () => useQuery({ queryKey: ['nv2-admin'], queryFn: () => getData<AdminData>(BASE) })
export const useAdminStats = () => useQuery({ queryKey: ['nv2-admin', 'stats'], queryFn: () => getData<AdminStats>(`${BASE}/stats`) })

export function useAdminActions() {
  const qc = useQueryClient()
  const done = (d: AdminData) => qc.setQueryData(['nv2-admin'], d)

  return {
    event: useMutation({ mutationFn: (b: { key: string; body: Partial<Pick<AdminCatalogEvent, 'enabled' | 'priority' | 'scope' | 'channels' | 'creates_task'>> }) => putData<AdminData>(`${BASE}/events/${b.key}`, b.body), onSuccess: done }),
    planFeature: useMutation({ mutationFn: (b: Record<string, unknown>) => putData<AdminData>(`${BASE}/plan-features`, b), onSuccess: done }),
    removePlanFeature: useMutation({ mutationFn: (id: string) => deleteData<AdminData>(`${BASE}/plan-features/${id}`), onSuccess: done }),
    text: useMutation({ mutationFn: (b: { key: string; value: Record<string, unknown> }) => putData<AdminData>(`${BASE}/texts/${b.key}`, { value: b.value }), onSuccess: done }),
  }
}
