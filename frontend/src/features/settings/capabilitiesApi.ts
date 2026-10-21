import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { deleteData, getData, putData } from '@/lib/api/client'

/** CAP-REGISTRY-001 — one module, as the server resolved it for a workspace, client or project. */
export type CapabilityReason =
  | 'platform_disabled'
  | 'not_entitled'
  | 'not_activated'
  | 'tenant_off'
  | 'client_off'
  | 'project_off'

export interface Capability {
  module: string
  label: string
  entitled: boolean
  enabled: boolean
  reason: CapabilityReason | null
  activation: 'default_on' | 'explicit'
  decided_at: 'tenant' | 'client' | 'project' | null
}

export interface CapabilityScope {
  clientId?: string | null
  projectId?: string | null
}

const key = (scope?: CapabilityScope) => ['settings', 'capabilities', scope?.clientId ?? null, scope?.projectId ?? null] as const

export function useCapabilities(scope?: CapabilityScope) {
  const qs = new URLSearchParams()
  if (scope?.clientId) qs.set('client_id', scope.clientId)
  if (scope?.projectId) qs.set('project_id', scope.projectId)
  const query = qs.toString()
  return useQuery({
    queryKey: key(scope),
    queryFn: () => getData<Capability[]>(`/settings/capabilities${query ? `?${query}` : ''}`),
    staleTime: 60_000,
  })
}

/**
 * Whether a module is on here. While the answer is loading, or if it failed, the module is treated as
 * OFF — fail closed, so a screen never shows a module the server has not confirmed.
 */
export function useCapability(module: string, scope?: CapabilityScope) {
  const q = useCapabilities(scope)
  const found = q.data?.find((c) => c.module === module)
  return { enabled: found?.enabled === true, reason: found?.reason ?? null, isLoading: q.isLoading }
}

export function useSetCapability() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { module: string; enabled: boolean; scope?: 'tenant' | 'client' | 'project'; scopeId?: string | null }) =>
      putData<Capability[]>(`/settings/capabilities/${v.module}`, { scope: v.scope ?? 'tenant', scope_id: v.scopeId ?? null, enabled: v.enabled }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings', 'capabilities'] }),
  })
}

export function useClearCapability() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { module: string; scope?: 'tenant' | 'client' | 'project'; scopeId?: string | null }) =>
      deleteData<Capability[]>(`/settings/capabilities/${v.module}`, { scope: v.scope ?? 'tenant', scope_id: v.scopeId ?? null }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings', 'capabilities'] }),
  })
}
