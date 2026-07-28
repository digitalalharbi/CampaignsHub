import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { api, deleteData, getData, patchData, postData } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'
import type { Option } from '@/components/forms'

/**
 * Thin client for the central Taxonomy & Option Engine (docs/OPTION_MANAGEMENT_SPEC.md).
 * This is the seam the pages will use later — it maps backend rows to the pure `Option` shape
 * the form-control library consumes. No page logic lives here.
 */

export type FieldType = 'single' | 'multi' | 'hierarchical'
export type TaxonomyScope = 'platform' | 'tenant' | 'client' | 'project' | 'module'

/** A classification field (taxonomy_definitions row). */
export interface TaxonomyDefinition {
  key: string
  module: string
  scope: TaxonomyScope
  field_type: FieldType
  label_ar: string
  label_en: string
  description: string | null
  is_system: boolean
  is_active: boolean
  allows_custom_options: boolean
  allows_multiple: boolean
  maximum_selections: number | null
  sort_order: number
  tenant_id: string | null
}

/** A value (taxonomy_options row) as returned by the API. */
export interface TaxonomyOption {
  id: string
  key: string
  taxonomy_definition_id: string
  label_ar: string
  label_en: string
  description: string | null
  color: string | null
  icon: string | null
  parent_option_id: string | null
  sort_order: number
  is_default: boolean
  is_active: boolean
  is_system: boolean
  tenant_id: string | null
  metadata: Record<string, unknown> | null
  usage_count?: number
}

/** Payload for creating/updating an option. */
export interface OptionPayload {
  label_ar: string
  label_en: string
  description?: string | null
  color?: string | null
  icon?: string | null
  parent_option_id?: string | null
  is_default?: boolean
  is_active?: boolean
  metadata?: Record<string, unknown> | null
}

/** Delete-protection usage report. */
export interface OptionUsage {
  option_id: string
  usage_count: number
  can_delete: boolean
  bound_records?: { type: string; count: number }[]
}

/** Map a backend option row to the pure `Option` the form controls render (value = row id). */
export function toFormOption(row: TaxonomyOption): Option {
  return {
    value: row.id,
    label_ar: row.label_ar,
    label_en: row.label_en,
    description: row.description,
    color: row.color,
    icon: row.icon,
    disabled: !row.is_active,
    parentValue: row.parent_option_id ?? undefined,
    isSystem: row.is_system,
  }
}

/** Convenience: map a whole list to render-ready options. */
export function toFormOptions(rows: TaxonomyOption[]): Option[] {
  return rows.map(toFormOption)
}

// ---- API surface (matches OPTION_MANAGEMENT_SPEC endpoints) ------------------

/** GET /taxonomies — every classification field visible to the caller. */
export const getDefinitions = () => getData<TaxonomyDefinition[]>('/taxonomies')

/** GET /taxonomies/{key}/options?scope=… — effective option set for a field. */
export async function getOptions(key: string, scope?: TaxonomyScope): Promise<TaxonomyOption[]> {
  const res = await api.get<ApiEnvelope<TaxonomyOption[]>>(`/taxonomies/${encodeURIComponent(key)}/options`, {
    params: scope ? { scope } : {},
  })
  return res.data.data ?? []
}

/** POST /taxonomies/{key}/options — create a tenant option. */
export const createOption = (key: string, payload: OptionPayload) =>
  postData<TaxonomyOption>(`/taxonomies/${encodeURIComponent(key)}/options`, payload)

/** PATCH /options/{id} — update (system options: label/translation/color/active only). */
export const updateOption = (id: string, patch: Partial<OptionPayload>) =>
  patchData<TaxonomyOption>(`/options/${encodeURIComponent(id)}`, patch)

/** POST /options/reorder — persist a new ordering (array of option ids in order). */
export const reorderOptions = (ids: string[]) => postData<TaxonomyOption[]>('/options/reorder', { ids })

/** POST /options/{id}/merge — reassign bound records to `into`, then soft-retire `id`. */
export const mergeOption = (id: string, into: string) =>
  postData<TaxonomyOption>(`/options/${encodeURIComponent(id)}/merge`, { into })

/** POST /options/{id}/reassign — move bound records to `into` without retiring `id`. */
export const reassignOption = (id: string, into: string) =>
  postData<TaxonomyOption>(`/options/${encodeURIComponent(id)}/reassign`, { into })

/** POST /options/{id}/deactivate — soft-disable (never a hard delete when in use). */
export const deactivateOption = (id: string) =>
  postData<TaxonomyOption>(`/options/${encodeURIComponent(id)}/deactivate`)

/** GET /options/{id}/usage — usage count + delete-protection verdict. */
export const optionUsage = (id: string) => getData<OptionUsage>(`/options/${encodeURIComponent(id)}/usage`)

/** DELETE /options/{id} — only permitted for unused, non-system options. */
export const deleteOption = (id: string) => deleteData<{ deleted: boolean }>(`/options/${encodeURIComponent(id)}`)

// ---- react-query hooks ------------------------------------------------------

export const taxonomyKeys = {
  definitions: ['taxonomies', 'definitions'] as const,
  options: (key: string, scope?: TaxonomyScope) => ['taxonomies', key, 'options', scope ?? 'default'] as const,
}

/**
 * Load an option set as ready-to-render `Option[]` for the form controls. Pages pass the returned
 * `options` straight to a SelectField/MultiSelectField; `raw` keeps the backend rows for management.
 */
export function useTaxonomyOptions(
  key: string,
  scope?: TaxonomyScope,
  enabled = true,
): UseQueryResult<TaxonomyOption[]> & { options: Option[] } {
  const query = useQuery({
    queryKey: taxonomyKeys.options(key, scope),
    queryFn: () => getOptions(key, scope),
    enabled: enabled && Boolean(key),
    staleTime: 5 * 60_000,
  })
  return { ...query, options: query.data ? toFormOptions(query.data) : [] }
}

/** Load the tenant's taxonomy definitions. */
export function useTaxonomyDefinitions(enabled = true): UseQueryResult<TaxonomyDefinition[]> {
  return useQuery({ queryKey: taxonomyKeys.definitions, queryFn: getDefinitions, enabled, staleTime: 5 * 60_000 })
}
