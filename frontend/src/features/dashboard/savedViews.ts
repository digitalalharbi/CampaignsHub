import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query'
import { deleteData, getData, patchData, postData } from '@/lib/api/client'

/**
 * DASH-010-E-FE — client for server-persisted saved dashboard views (never localStorage). Each view stores
 * the full filter set + date range + comparison so applying one restores the exact dashboard state.
 */
export interface SavedViewFilters {
  provider?: string[]
  objective?: string
  /** CAMPAIGNS-TABLE-001 — what the campaigns table view keeps; unknown to the dashboard. */
  status?: string
  columns?: string[]
  sort?: string
  dir?: 'asc' | 'desc'
}
export interface SavedView {
  id: string
  name: string
  module: string
  filters: SavedViewFilters | null
  date_range: { days?: number } | null
  comparison: { mode?: 'none' | 'previous' } | null
  sort_order: number
  is_default: boolean
}
export interface SaveViewPayload {
  name: string
  filters: SavedViewFilters
  date_range: { days: number }
  comparison?: { mode: 'none' | 'previous' }
}

const BASE = '/dashboard/saved-views'
/*
 * CAMPAIGNS-TABLE-001 — one saved-views store, several surfaces. The server keys views by `module`; every hook takes the
 * module and defaults to the dashboard it always served. The query key carries the module, so one surface's save never
 * refreshes another's list.
 */
const key = (module: string) => ['dashboard', 'saved-views', module] as const

export function useSavedViews(enabled = true, module = 'dashboard'): UseQueryResult<SavedView[]> {
  return useQuery({ queryKey: key(module), queryFn: () => getData<SavedView[]>(`${BASE}?module=${encodeURIComponent(module)}`), enabled, staleTime: 60_000 })
}

export function useSaveView(module = 'dashboard') {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (p: SaveViewPayload) => postData<SavedView>(BASE, { ...p, module }),
    onSuccess: () => qc.invalidateQueries({ queryKey: key(module) }),
  })
}

export function useRenameView(module = 'dashboard') {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) => patchData<SavedView>(`${BASE}/${id}`, { name }),
    onSuccess: () => qc.invalidateQueries({ queryKey: key(module) }),
  })
}

export function useDeleteView(module = 'dashboard') {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteData<{ deleted: boolean }>(`${BASE}/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: key(module) }),
  })
}

export function useSetDefaultView(module = 'dashboard') {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => postData<SavedView>(`${BASE}/${id}/default`),
    onSuccess: () => qc.invalidateQueries({ queryKey: key(module) }),
  })
}
