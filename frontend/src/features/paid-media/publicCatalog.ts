import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

/**
 * Public, engine-managed paid-media service catalog (BINDING contract — docs/PAID_MEDIA_SERVICES_SPEC.md §v3).
 *
 * The marketing homepage and the anonymous request intake are unauthenticated, so they cannot use the
 * auth-gated `/taxonomies/{key}/options` endpoint. The backend exposes ONE read-only public catalog
 * (`GET /api/v1/public/catalog/paid-media-services`) that serves ONLY the platform-scope, ACTIVE, PUBLIC
 * options of the `request.paid_service` taxonomy — never tenant data, never inactive/private options.
 *
 * This is the SINGLE SOURCE of services for every surface (homepage selector, intake, review, portal,
 * dashboard, quote, invoice). No hardcoded fallback arrays, no duplicated lists, no differing keys. On
 * failure callers MUST show an error state + retry — never substitute a static/demo list.
 *
 * Authenticated surfaces may additionally merge tenant-added custom services via
 * `useTaxonomyOptions('request.paid_service')`; this public hook is the anonymous catalog.
 */

/** A parent category (allowed public fields only). */
export interface PaidServiceCategory {
  key: string
  label_ar: string
  label_en: string
  icon: string | null
  sort_order: number
}

/** A service option (allowed public fields only — no color/popular/tenant/usage/internal ids). */
export interface PaidService {
  key: string
  category_key: string
  label_ar: string
  label_en: string
  description_ar: string | null
  description_en: string | null
  icon: string | null
  sort_order: number
  /** Dynamic intake field tokens this service requires (drives the adaptive request form). */
  required_field_rules: string[]
}

export interface PaidServiceCatalog {
  /** Bumped whenever options change; also the ETag basis. Lets callers cache-bust deterministically. */
  version: string
  categories: PaidServiceCategory[]
  services: PaidService[]
}

/** The single client of the public catalog endpoint. */
export const getPaidMediaCatalog = () => getData<PaidServiceCatalog>('/public/catalog/paid-media-services')

export const paidServiceKeys = {
  catalog: ['public', 'catalog', 'paid-media-services'] as const,
}

/** Deterministic global order: by (category sort_order, service sort_order). */
export function orderedServices(catalog: PaidServiceCatalog | undefined): PaidService[] {
  if (!catalog) return []
  const catOrder = new Map(catalog.categories.map((c) => [c.key, c.sort_order]))
  return [...catalog.services].sort(
    (a, b) =>
      (catOrder.get(a.category_key) ?? 0) - (catOrder.get(b.category_key) ?? 0) ||
      a.sort_order - b.sort_order,
  )
}

/** Services of one category, ordered. */
export function servicesInCategory(catalog: PaidServiceCatalog | undefined, categoryKey: string): PaidService[] {
  return orderedServices(catalog).filter((s) => s.category_key === categoryKey)
}

/**
 * The homepage "أبرز الخدمات / featured" strip. There is NO `popular` field in the public contract, so
 * this is DERIVED deterministically from sort_order: the lead service (lowest sort_order) of each category,
 * in category order, capped at `limit` — a diverse, stable set spanning categories.
 */
export function featuredServices(catalog: PaidServiceCatalog | undefined, limit = 8): PaidService[] {
  if (!catalog) return []
  const lead: PaidService[] = []
  for (const cat of [...catalog.categories].sort((a, b) => a.sort_order - b.sort_order)) {
    const first = servicesInCategory(catalog, cat.key)[0]
    if (first) lead.push(first)
  }
  return lead.slice(0, limit)
}

/** Resolve selected service keys back to full options (order preserved; unknown keys dropped). */
export function servicesForKeys(catalog: PaidServiceCatalog | undefined, keys: string[]): PaidService[] {
  const byKey = new Map((catalog?.services ?? []).map((s) => [s.key, s]))
  return keys.map((k) => byKey.get(k)).filter((s): s is PaidService => Boolean(s))
}

/** The deduped union of `required_field_rules` across selected services (each dynamic field asked once). */
export function mergedRequiredFields(catalog: PaidServiceCatalog | undefined, keys: string[]): string[] {
  const set = new Set<string>()
  for (const svc of servicesForKeys(catalog, keys)) for (const r of svc.required_field_rules) set.add(r)
  return [...set]
}

/** The reserved system key for a free-text custom request (NOT anonymous taxonomy creation). */
export const CUSTOM_REQUEST_KEY = 'custom_request'

/**
 * Load the public paid-media catalog. Returns the raw query (so callers can render loading/error/retry)
 * plus derived helpers. Never falls back to static data — on error, callers show an error state + retry.
 */
export function usePaidMediaCatalog(enabled = true): UseQueryResult<PaidServiceCatalog> & {
  categories: PaidServiceCategory[]
  featured: PaidService[]
} {
  const query = useQuery({
    queryKey: paidServiceKeys.catalog,
    queryFn: getPaidMediaCatalog,
    enabled,
    staleTime: 10 * 60_000,
    retry: 1,
  })
  return { ...query, categories: query.data?.categories ?? [], featured: featuredServices(query.data) }
}
