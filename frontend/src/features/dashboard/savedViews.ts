import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query'
import { deleteData, getData, patchData, postData } from '@/lib/api/client'

/**
 * DASH-010-E-FE — client for server-persisted saved dashboard views (never localStorage). Each view stores
 * the full filter set + date range + comparison so applying one restores the exact dashboard state.
 */
export interface SavedViewFilters {
  provider?: string[]
  objective?: string
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
const key = ['dashboard', 'saved-views'] as const

export function useSavedViews(enabled = true): UseQueryResult<SavedView[]> {
  return useQuery({ queryKey: key, queryFn: () => getData<SavedView[]>(`${BASE}?module=dashboard`), enabled, staleTime: 60_000 })
}

export function useSaveView() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (p: SaveViewPayload) => postData<SavedView>(BASE, p),
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  })
}

export function useRenameView() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) => patchData<SavedView>(`${BASE}/${id}`, { name }),
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  })
}

export function useDeleteView() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteData<{ deleted: boolean }>(`${BASE}/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  })
}

export function useSetDefaultView() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => postData<SavedView>(`${BASE}/${id}/default`),
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  })
}
