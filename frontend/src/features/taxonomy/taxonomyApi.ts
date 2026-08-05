import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { api, deleteData, getData, patchData, postData } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'
import type { ApiEnvelope } from '@/lib/api/types'
import type { Option, OptionDraft } from '@/components/forms'

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
  /**
   * The KEY of the parent option (attached by the engine, may live in another definition — e.g. a
   * request.category option's parent is a request.service option). This is what dependent selects filter on.
   */
  parent_key?: string | null
  sort_order: number
  is_default: boolean
  is_active: boolean
  is_system: boolean
  tenant_id: string | null
  metadata: Record<string, unknown> | null
  /** Hierarchical definitions return roots with their children nested one level deep. */
  children?: TaxonomyOption[]
  usage_count?: number
}

/** Payload for creating/updating an option. */
export interface OptionPayload {
  /** Stable option key (the value the backend validates/stores). Required by the create endpoint. */
  key?: string
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

/**
 * Map a backend option row to the pure `Option` the form controls render.
 *
 * The option VALUE is the backend option `key` — i.e. the exact enum/validator value the API stores against
 * (awareness, active, meta, …). This is what makes adopting the engine a safe drop-in: a submitted select value
 * is a key the backend already accepts (no 422, no data loss). `parentValue` is the parent's KEY so dependent
 * selects resolve across definitions (request.category → request.service).
 */
export function toFormOption(row: TaxonomyOption): Option {
  return {
    value: row.key,
    label_ar: row.label_ar,
    label_en: row.label_en,
    description: row.description,
    color: row.color,
    icon: row.icon,
    disabled: !row.is_active,
    parentValue: row.parent_key ?? undefined,
    isSystem: row.is_system,
  }
}

/** Flatten a (possibly nested) option tree into a single list of rows, roots first, then their children. */
export function flattenOptions(rows: TaxonomyOption[]): TaxonomyOption[] {
  const out: TaxonomyOption[] = []
  for (const row of rows) {
    out.push(row)
    if (row.children && row.children.length > 0) out.push(...flattenOptions(row.children))
  }
  return out
}

/** Convenience: map a whole (possibly nested) list to flat, render-ready, key-valued options. */
export function toFormOptions(rows: TaxonomyOption[]): Option[] {
  return flattenOptions(rows).map(toFormOption)
}

/**
 * Filter a dependent option set down to the children of a selected parent key. Used to build
 * Service → Category → Type style dependent selects: pass the child definition's options and the currently
 * selected parent key. With no parent selected, returns an empty list (the child select stays empty/disabled).
 */
export function optionsForParent(options: Option[], parentKey: string | null | undefined): Option[] {
  if (!parentKey) return []
  return options.filter((o) => o.parentValue === parentKey)
}

// ---- API surface (matches OPTION_MANAGEMENT_SPEC endpoints) ------------------

/**
 * TAX-ADMIN-001 — which layer's options these calls address.
 *
 * This manager is mounted twice: in `/admin/settings` for the platform operator, and in a tenant's own
 * settings for a customer. The engine behind it is the same one and it resolves «platform ∪ current
 * tenant» from the session, so the ONLY difference is which route group the request enters through —
 * and the operator holds no tenant, so the tenant-scoped group refused them before the controller ran.
 * That is what made the التصنيفات tab show «تعذّر تحميل البيانات».
 *
 * Read from the store rather than threaded as a prop because it is a property of WHO IS SIGNED IN, not
 * of where the component happens to sit: a platform operator has no tenant whose options they could be
 * editing instead, so there is nothing here for a caller to get wrong.
 */
const base = () => (useAuth.getState().user?.is_platform_admin === true ? '/admin' : '')

/** GET /taxonomies — every classification field visible to the caller. */
export const getDefinitions = () => getData<TaxonomyDefinition[]>(`${base()}/taxonomies`)

/** GET /taxonomies/{key}/options?scope=… — effective option set for a field. */
export async function getOptions(key: string, scope?: TaxonomyScope): Promise<TaxonomyOption[]> {
  const res = await api.get<ApiEnvelope<TaxonomyOption[]>>(`${base()}/taxonomies/${encodeURIComponent(key)}/options`, {
    params: scope ? { scope } : {},
  })
  return res.data.data ?? []
}

/** POST /taxonomies/{key}/options — create a tenant option. */
export const createOption = (key: string, payload: OptionPayload) =>
  postData<TaxonomyOption>(`${base()}/taxonomies/${encodeURIComponent(key)}/options`, payload)

/** Slugify a free-text label into a stable option key (lowercase, non-alphanumerics → underscore). */
export function slugifyKey(input: string): string {
  return input.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
}

/**
 * Create a tenant option from a drawer draft and return it as a render-ready, KEY-valued `Option`. The option
 * key is derived from the label so the value the select submits is a real backend key (create-when-permitted).
 */
export async function createOptionFromDraft(definitionKey: string, draft: OptionDraft): Promise<Option> {
  const key = slugifyKey(draft.label_en || draft.label_ar) || `opt_${Date.now()}`
  const created = await createOption(definitionKey, {
    key,
    label_ar: draft.label_ar,
    label_en: draft.label_en,
    description: draft.description ?? null,
    color: draft.color ?? null,
    icon: draft.icon ?? null,
  })
  return toFormOption(created)
}

/** PATCH /options/{id} — update (system options: label/translation/color/active only). */
export const updateOption = (id: string, patch: Partial<OptionPayload>) =>
  patchData<TaxonomyOption>(`${base()}/options/${encodeURIComponent(id)}`, patch)

/** POST /options/reorder — persist a new ordering (array of option ids in order). */
export const reorderOptions = (ids: string[]) => postData<TaxonomyOption[]>(`${base()}/options/reorder`, { ids })

/** POST /options/{id}/merge — reassign bound records to `into`, then soft-retire `id`. */
export const mergeOption = (id: string, into: string) =>
  postData<TaxonomyOption>(`${base()}/options/${encodeURIComponent(id)}/merge`, { into })

/** POST /options/{id}/reassign — move bound records to `into` without retiring `id`. */
export const reassignOption = (id: string, into: string) =>
  postData<TaxonomyOption>(`${base()}/options/${encodeURIComponent(id)}/reassign`, { into })

/** POST /options/{id}/deactivate — soft-disable (never a hard delete when in use). */
export const deactivateOption = (id: string) =>
  postData<TaxonomyOption>(`${base()}/options/${encodeURIComponent(id)}/deactivate`)

/** GET /options/{id}/usage — usage count + delete-protection verdict. */
export const optionUsage = (id: string) => getData<OptionUsage>(`${base()}/options/${encodeURIComponent(id)}/usage`)

/** DELETE /options/{id} — only permitted for unused, non-system options. */
export const deleteOption = (id: string) => deleteData<{ deleted: boolean }>(`${base()}/options/${encodeURIComponent(id)}`)

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
