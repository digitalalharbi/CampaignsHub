/**
 * AUDIENCE-001 — the audience reading, four kinds kept apart (see backend AudienceCenter).
 * A share is numerator ÷ denominator of ONE group (one platform, one dimension, one metric); groups are never summed.
 */
export interface Share { numerator: number; denominator: number; ratio: number }
export interface SpendPart { currency: string; amount: number; source: string }
export interface DeliveryRow { value: string; impressions: number | null; share: Share | null; clicks: number | null; reach: number | null; spend?: SpendPart[] }
export interface DeliveryGroup {
  kind: 'paid_delivery'
  provider: string
  dimension: string
  metric: 'impressions'
  source_field: string
  denominator: number
  reach_note: string | null
  coverage: { days_with_rows: number; days_in_period: number; ad_sets_with_rows: number; ad_sets_delivering: number }
  synced_at: string | null
  rows: DeliveryRow[]
}
export interface SiteRow { value: string; sessions: number; share: Share | null }
export interface SiteGroup { kind: 'site_events'; provider: 'ga4'; dimension: 'country' | 'device'; metric: 'sessions'; denominator: number; rows: SiteRow[] }
export interface TargetingItem {
  kind: 'targeting'
  provider: string
  level: 'ad_set'
  ad_set_id: string
  ad_set?: string
  campaign?: string
  settings: Record<string, unknown> | null
  not_stored: string[]
  synced_at: string | null
}
export interface Missing { provider: string; dimension: string; state: string }
export interface Capability { dimensions: Record<string, { targeting: boolean; delivery: string }>; docs: string | null }
export interface AudienceReading {
  period: { from: string; to: string; days: number }
  providers: string[]
  cross_platform: { summed: false; reason: string }
  targeting: { state: 'ready' | 'unavailable'; reason?: string | null; items: TargetingItem[] }
  paid_delivery: { state: 'ready' | 'unavailable'; groups: DeliveryGroup[]; unavailable: Missing[] }
  site_events: { state: 'ready' | 'unavailable'; reason?: string | null; groups: SiteGroup[] }
  organic: { state: 'unavailable'; reason: string }
  capabilities: Record<string, Capability>
}

/** The report parts: one key per data type, each a SHOW/HIDE of its own. */
export const AUDIENCE_DELIVERY_PARTS = ['audience_regions', 'audience_cities', 'audience_gender', 'audience_age', 'audience_devices', 'audience_placements', 'audience_interests', 'audience_professional'] as const
export type AudiencePartKey = 'audience_targeting' | (typeof AUDIENCE_DELIVERY_PARTS)[number] | 'audience_site'
export interface AudienceParts {
  audience_targeting?: { items: TargetingItem[] } | null
  audience_site?: { groups: SiteGroup[] } | null
  audience_regions?: { groups: DeliveryGroup[] } | null
  audience_cities?: { groups: DeliveryGroup[] } | null
  audience_gender?: { groups: DeliveryGroup[] } | null
  audience_age?: { groups: DeliveryGroup[] } | null
  audience_devices?: { groups: DeliveryGroup[] } | null
  audience_placements?: { groups: DeliveryGroup[] } | null
  audience_interests?: { groups: DeliveryGroup[] } | null
  audience_professional?: { groups: DeliveryGroup[] } | null
}
