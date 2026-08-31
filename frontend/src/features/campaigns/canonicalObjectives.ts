/**
 * ANALYTICS-OBJECTIVE-SYSTEM-001 — the five objectives the product offers, mirrored from the backend.
 *
 * ## Why this is a mirror and not a source
 *
 * The canonical grouping lives on `ObjectiveFamily::canonical()` in PHP, because that enum is what the
 * metric engines reason about. This file exists only so the filter control can render, and
 * `CanonicalObjectiveParityTest` fails the backend suite if the two ever disagree — the same
 * arrangement `PATH_OBJECTIVES` had before this replaced it.
 *
 * ## What this replaces
 *
 * `PATH_OBJECTIVES` and the «المسار التسويقي» control beside «الهدف». Those were two primary choices
 * over one server axis: the path control never filtered anything itself, it expanded into objectives
 * and sent them on the objective filter. So a reader was asked the same question twice and could be
 * shown «التحويل والمبيعات» and «المبيعات» simultaneously, as if they were alternatives.
 *
 * ## Why the raw list matters
 *
 * The metrics API filters by RAW objectives, so a canonical choice has to expand into all of them.
 * Sending the canonical value itself would narrow the label and leave the query untouched — the KPI
 * row would move and the chart beneath it would not, which is precisely the frontend-only filtering
 * ANALYTICS-FILTER-TRUTH-001 forbids.
 *
 * ## `other` and `store_visits` are deliberately in none of these
 *
 * The backend files both under the Unknown family: a footfall objective reports neither online revenue
 * nor leads, so filing it under Sales would headline figures that are structurally absent. Campaigns
 * carrying them stay reachable through «الكل», which narrows nothing. A sixth visible «unclassified»
 * choice would be the competing taxonomy this replaced.
 */

export type CanonicalObjectiveKey =
  | 'awareness_engagement'
  | 'traffic'
  | 'leads'
  | 'app_promotion'
  | 'sales'

/** The raw `CampaignObjective` values each canonical objective expands into, for the server filter. */
export const CANONICAL_OBJECTIVE_RAW: Record<CanonicalObjectiveKey, string[]> = {
  awareness_engagement: ['awareness', 'reach', 'video_views', 'engagement'],
  traffic: ['traffic', 'landing_page_views'],
  leads: ['leads'],
  app_promotion: ['app_installs'],
  sales: ['sales', 'conversions', 'add_to_cart', 'purchases'],
}

const LABELS: Record<CanonicalObjectiveKey, { ar: string; en: string }> = {
  awareness_engagement: { ar: 'الوعي والتفاعل', en: 'Awareness & Engagement' },
  traffic: { ar: 'الزيارات', en: 'Traffic' },
  leads: { ar: 'العملاء المحتملون', en: 'Leads' },
  app_promotion: { ar: 'الترويج للتطبيق', en: 'App Promotion' },
  sales: { ar: 'المبيعات', en: 'Sales' },
}

/** In the product's own order — the order the filter renders them. */
export const CANONICAL_OBJECTIVE_KEYS: CanonicalObjectiveKey[] = [
  'awareness_engagement',
  'traffic',
  'leads',
  'app_promotion',
  'sales',
]

export function canonicalObjectiveLabel(key: CanonicalObjectiveKey, locale: 'ar' | 'en'): string {
  return LABELS[key][locale]
}

/**
 * What to send on the metrics API's objective axis.
 *
 * `all` sends an empty list, which the API reads as «do not narrow» — deliberately not the union of
 * every objective, because a union would silently exclude any raw objective this map has not been
 * taught, turning an unmapped provider value into a campaign that vanishes from an unfiltered view.
 */
export function rawObjectivesFor(key: CanonicalObjectiveKey | 'all'): string[] {
  return key === 'all' ? [] : CANONICAL_OBJECTIVE_RAW[key]
}

/** Which canonical objective a raw provider objective belongs to, or null when unmapped. */
export function canonicalOfRaw(raw: string): CanonicalObjectiveKey | null {
  const found = CANONICAL_OBJECTIVE_KEYS.find((k) => CANONICAL_OBJECTIVE_RAW[k].includes(raw))

  return found ?? null
}
