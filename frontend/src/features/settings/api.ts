import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, putData } from '@/lib/api/client'
import type { ResolvedDisclaimer } from '@/features/disclaimers/api'

// ---- General (organization) ---------------------------------------------------------------------

export interface OrgGeneral {
  account_type: string
  logo_url: string | null
  contact_email: string | null
  contact_phone: string | null
  country: string
  default_locale: 'ar' | 'en'
  default_currency: string
  timezone: string
  date_format: string
  number_format: string
  fiscal_year_start_month: number
  demo_mode: boolean
}
export interface OrgSettings {
  name: string
  slug: string
  general: OrgGeneral
  options: { account_types: string[]; date_formats: string[]; number_formats: string[] }
}

export function useOrgSettings() {
  return useQuery({ queryKey: ['settings', 'organization'], queryFn: () => getData<OrgSettings>('/settings/organization') })
}

export function useUpdateOrgSettings() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (body: { name: string; general: OrgGeneral }) => putData<OrgSettings>('/settings/organization', body),
    onSuccess: (data) => qc.setQueryData(['settings', 'organization'], data),
  })
}

// ---- Disclaimers management ----------------------------------------------------------------------

export type DisclaimerScope = 'organization' | 'client' | 'project'
export interface DisclaimerOverride {
  id: string
  scope: DisclaimerScope
  scope_id: string | null
  payload: Partial<ResolvedDisclaimer>
  version: number
  is_active: boolean
  effective_at: string | null
  updated_at: string
}
export interface DisclaimerSettings {
  defaults: ResolvedDisclaimer
  overrides: DisclaimerOverride[]
}

export function useDisclaimerSettings() {
  return useQuery({ queryKey: ['settings', 'disclaimers'], queryFn: () => getData<DisclaimerSettings>('/settings/disclaimers') })
}

export function useSaveDisclaimer() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (body: {
      scope: DisclaimerScope
      scope_id?: string | null
      payload: Record<string, unknown>
      is_active?: boolean
      effective_at?: string | null
    }) => putData<DisclaimerOverride>('/settings/disclaimers', body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings', 'disclaimers'] })
      qc.invalidateQueries({ queryKey: ['disclaimer'] })
    },
  })
}
