import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

/**
 * Public, engine-managed paid-media service catalog.
 *
 * The marketing homepage and the anonymous request intake are unauthenticated, so they cannot use the
 * auth-gated `/taxonomies/{key}/options` endpoint. Instead the backend exposes a read-only public catalog
 * (`GET /api/v1/public/paid-services`) that serves ONLY the platform-scope, active options of the
 * `request.paid_service` taxonomy — never tenant data. This keeps the service list fully taxonomy-managed:
 * enabling/adding/translating/reordering a service in Settings → Taxonomies changes the homepage + intake
 * with no code change and no rebuild.
 *
 * Authenticated surfaces should prefer `useTaxonomyOptions('request.paid_service')` so tenant-added custom
 * services are merged in; this public hook is the anonymous fallback / marketing catalog.
 */

/** A single service option under a category (a `request.paid_service` child option). */
export interface PaidService {
  key: string
  label_ar: string
  label_en: string
  description: string | null
  icon: string | null
  color: string | null
  /** Surfaced on the homepage's short "popular services" strip. */
  popular: boolean
  /** Dynamic intake field tokens this service requires (drives the adaptive request form). */
  needs: string[]
}

/** A parent category (a `request.paid_service` root option) with its services nested. */
export interface PaidServiceCategory {
  key: string
  label_ar: string
  label_en: string
  icon: string | null
  color: string | null
  order: number
  services: PaidService[]
}

export interface PaidServiceCatalog {
  definition: string
  categories: PaidServiceCategory[]
}

/** GET /api/v1/public/paid-services — the engine-managed public service catalog. */
export const getPublicPaidServices = () => getData<PaidServiceCatalog>('/public/paid-services')

export const paidServiceKeys = {
  catalog: ['public', 'paid-services'] as const,
}

/** Flatten every service across categories (roots first) — handy for label/needs lookups by key. */
export function allServices(catalog: PaidServiceCatalog | undefined): PaidService[] {
  if (!catalog) return []
  return catalog.categories.flatMap((c) => c.services)
}

/** The short "popular services" strip shown in the homepage first viewport (cap defensively). */
export function popularServices(catalog: PaidServiceCatalog | undefined, limit = 8): PaidService[] {
  return allServices(catalog)
    .filter((s) => s.popular)
    .slice(0, limit)
}

/** Resolve a set of selected service keys back to their full option objects (order preserved). */
export function servicesForKeys(catalog: PaidServiceCatalog | undefined, keys: string[]): PaidService[] {
  const byKey = new Map(allServices(catalog).map((s) => [s.key, s]))
  return keys.map((k) => byKey.get(k)).filter((s): s is PaidService => Boolean(s))
}

/** The union of `needs` tokens across selected services (deduped) — the dynamic intake asks each once. */
export function mergedNeeds(catalog: PaidServiceCatalog | undefined, keys: string[]): string[] {
  const set = new Set<string>()
  for (const svc of servicesForKeys(catalog, keys)) for (const n of svc.needs) set.add(n)
  return [...set]
}

/**
 * Load the public paid-media catalog. Returns the raw catalog plus derived helpers so callers don't
 * re-implement popular/lookup logic. `staleTime` is generous — the catalog is slow-moving marketing data.
 */
export function usePublicPaidServices(enabled = true): UseQueryResult<PaidServiceCatalog> & {
  categories: PaidServiceCategory[]
  popular: PaidService[]
} {
  const query = useQuery({
    queryKey: paidServiceKeys.catalog,
    queryFn: getPublicPaidServices,
    enabled,
    staleTime: 10 * 60_000,
  })
  return { ...query, categories: query.data?.categories ?? [], popular: popularServices(query.data) }
}
