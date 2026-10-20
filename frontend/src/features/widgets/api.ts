import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, putData } from '@/lib/api/client'

/** WIDGET-REGISTRY-001 — the three surfaces a widget can sit on. */
export type WidgetSurface = 'dashboard' | 'analytics' | 'report'
export type WidgetSize = 'third' | 'half' | 'full'

export interface ResolvedWidget {
  key: string
  visible: boolean
  order: number
  size: WidgetSize
  kind: string
  module: string | null
  /** Why it is not shown: its module is off, the project hid it, or a shared link hid it. */
  reason: 'module_off' | 'hidden_by_project' | 'hidden_by_link' | null
  title: { en?: string; ar?: string }
}

export interface WidgetLayoutResponse {
  surface: WidgetSurface
  widgets: ResolvedWidget[]
  sizes: WidgetSize[]
}

export interface WidgetLayoutItem {
  key: string
  visible: boolean
  order: number
  size: WidgetSize
}

const key = (projectId: string, surface: WidgetSurface) => ['widgets', projectId, surface] as const

export function useWidgetLayout(projectId: string | null | undefined, surface: WidgetSurface) {
  return useQuery({
    queryKey: key(projectId ?? '', surface),
    queryFn: () => getData<WidgetLayoutResponse>(`/projects/${projectId}/widgets/${surface}`),
    enabled: Boolean(projectId),
    staleTime: 30_000,
    retry: false,
  })
}

export function useSaveWidgetLayout(projectId: string, surface: WidgetSurface) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (items: WidgetLayoutItem[]) => putData<WidgetLayoutResponse>(`/projects/${projectId}/widgets/${surface}`, { items }),
    onSuccess: (data) => qc.setQueryData(key(projectId, surface), data),
  })
}
