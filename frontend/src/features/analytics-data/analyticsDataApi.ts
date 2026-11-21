import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getEnvelope, getData, postData } from '@/lib/api/client'

/**
 * ANALYTICS-DATA-001 — typed reads of the analytics sections of one project: site (GA4), store, influencers, attribution.
 *
 * Every number the server returns carries its `source`; none of these types allows a figure without one. The sources are
 * never added to each other (`blended_with_other_sources` is always false, `summed` is false on the reconciliation).
 * A null is «not stated», never zero.
 */

export type DataSource =
  | 'ga4'
  | 'store_confirmed'
  | 'platform_reported'
  | 'platform_spend'
  | 'short_link_counter'
  | 'order_evidence'
  | 'verified_touchpoints'
  | 'collaboration_fee'

export interface Sourced<T = number | null> {
  value: T
  source: DataSource
  previous?: number | null
  change_pct?: number | null
  currency?: string | null
  reason?: string | null
}

export interface AnalyticsRange { from?: string; to?: string; period?: string }

export type SectionState = 'ready' | 'empty' | 'not_linked' | 'no_links'

export interface Ga4Binding {
  state: 'bound' | 'awaiting_selection' | 'awaiting_credentials'
  status: 'BOUND' | 'AWAITING_SELECTION' | 'AWAITING_CREDENTIALS'
  property: { id: string; name: string | null } | null
  last_synced_at: string | null
  suggestion: { property_id: string; name: string | null } | null
  /** `needs_confirmation`: a person confirms the property; the server never binds one by itself. */
  auto_bind: 'confirmed' | 'needs_confirmation' | 'needs_google_sign_in'
}

export interface SiteKpi extends Sourced<number | null> {
  kind?: string
  definition?: string
  trend?: Array<{ date: string; value: number }>
  alias_of?: string | null
}

export interface SiteRow { source: 'ga4'; [metric: string]: string | number | null }

export interface ReconciliationRow {
  platform: string
  platform_reported: { orders: number; revenue: number; currency: string | null; source: 'platform_reported' } | null
  ga4_confirmed: { sessions: number; orders: number; revenue: number; currency: string | null; source: 'ga4' } | null
  store_confirmed: { orders: number; revenue: number; source: 'store_confirmed' } | null
  gap: {
    platform_minus_ga4_orders: number | null
    platform_minus_store_orders: number | null
    ga4_share_of_claim: number | null
    store_share_of_claim: number | null
    source: string
  }
}

export interface Reconciliation {
  summed: false
  available: { platform_reported: boolean; store_confirmed: boolean; ga4_measured: boolean }
  rows: ReconciliationRow[]
  ga4_other_paid: unknown
  store_unattributed: { orders: number; revenue: number } | null
}

export interface SitePayload {
  section: 'site'
  source: 'ga4'
  state: SectionState
  period: { from: string; to: string }
  binding: Ga4Binding
  kpis: Partial<Record<'visits' | 'users' | 'sessions' | 'avg_duration_seconds' | 'bounce_rate' | 'engagement_rate', SiteKpi>>
  channels: SiteRow[]
  devices: SiteRow[]
  countries: SiteRow[]
  pages: SiteRow[]
  funnel: Array<{ stage: 'visit' | 'cart' | 'checkout' | 'purchase'; count: number | null; step_rate: number | null; exceeds_previous: boolean; source: 'ga4' }>
  reconciliation: Reconciliation | null
  sales_hidden?: boolean
  computed_at?: string
}

export interface StorePayload {
  section: 'store'
  source: 'store_confirmed'
  state: SectionState
  /** `AWAITING_CREDENTIALS`: the Salla / Zid app is not configured yet. `AWAITING_LINK`: the app is ready, this project has not linked a store. */
  status: 'AWAITING_CREDENTIALS' | 'AWAITING_LINK' | 'LINKED_NO_ORDERS' | 'LINKED'
  period: { from: string; to: string }
  currency?: string
  stores: Array<{ provider: string; name: string; currency: string | null; last_synced_at: string | null; source: 'store_confirmed' }>
  provider_apps?: Array<{ provider: 'salla' | 'zid'; configured: boolean }>
  kpis: Partial<Record<'orders' | 'revenue' | 'aov' | 'abandoned_carts' | 'conversion', Sourced>>
  trend?: Array<{ date: string; orders: number; net: number }>
  products: ({ source: 'store_confirmed' } & Record<string, unknown>) | null
  coupons: Array<{ dimension_1: string; orders: number; confirmed: number; net_revenue: number; share: number; source: 'store_confirmed' }>
  abandoned: ({ source: 'store_confirmed'; carts: number; value: number | null } & Record<string, unknown>) | null
  campaign_ties: Array<{ dimension_1: string; dimension_2: string | null; orders: number; confirmed: number; net_revenue: number; source: 'store_confirmed' }>
  computed_at?: string
}

export interface InfluencerRow {
  id: string
  name: string
  influencer_id: string | null
  is_active: boolean
  link: { short_link_id: string; slug: string | null; state: string | null; utm: { source: string; medium: string; campaign: string }; coupon_code: string | null }
  clicks: Sourced<number> & { all_time: number; basis: string }
  visits: Sourced
  orders: Sourced & { confirmed?: number; evidence?: { utm_campaign: number; coupon: number } }
  revenue: Sourced & { revenue_withheld_orders?: number }
  cost: Sourced
  roas: number | null
  roi: number | null
  cpa: number | null
}

export interface ComparisonRow {
  kind: 'influencer' | 'platform'
  channel: string
  id: string
  spend: Sourced
  orders: Sourced
  revenue: Sourced
  roas: number | null
  cpa: number | null
  source: DataSource
}

export interface InfluencersPayload {
  section: 'influencers'
  state: SectionState
  period: { from: string; to: string }
  currency?: string | null
  has_store?: boolean
  ga4_state?: 'ga4' | 'no_ga4_property'
  influencers: InfluencerRow[]
  comparison: ComparisonRow[]
  totals: { influencers: number; clicks: Sourced<number>; orders: Sourced } | null
}

export type AttributionModel = 'last_click' | 'first_click' | 'linear' | 'position_based'
export const ATTRIBUTION_MODELS: readonly AttributionModel[] = ['last_click', 'first_click', 'linear', 'position_based']

export interface AttributionChannel {
  channel: string
  kind: 'platform' | 'influencer' | 'owned_link' | 'direct_organic'
  source: 'verified_touchpoints' | 'order_evidence'
  evidence: string[]
  models: Record<AttributionModel, { orders: number; revenue: number }>
  platform_reported: { orders: number; revenue: number; currency: string | null; source: 'platform_reported' } | null
  ga4_confirmed: { sessions: number; orders: number; revenue: number; currency: string | null; source: 'ga4' } | null
}

export interface AttributionPayload {
  section: 'attribution'
  state: SectionState
  status: string
  period: { from: string; to: string }
  models: AttributionModel[]
  default_model: AttributionModel
  currency?: string | null
  /** Every model shares the same total: pick one with a switch and read `channels[].models[model]`. */
  totals_by_model: Record<AttributionModel, { orders: number; revenue: number; source: 'store_confirmed' }> | null
  channels: AttributionChannel[]
  coverage: Record<string, number | string | null> | null
  claim_vs_confirmed: Reconciliation
  paths: {
    source: 'verified_touchpoints'
    rows: Array<{ steps: string[]; orders: number; revenue: number; verified_orders: number }>
    multi_touch_orders: number
    sankey: {
      source: 'verified_touchpoints'
      nodes: Array<{ id: string; channel: string; step: number }>
      links: Array<{ source: string; target: string; orders: number; revenue: number; verified_orders: number }>
    }
  } | null
  overlap: {
    orders_with_verified_touchpoints: number | null
    multi_channel_orders: number | null
    overlap_ratio: number | null
    pairs: Array<{ channels: [string, string]; orders_both: number; orders_either: number; ratio: number | null; source: 'verified_touchpoints' }>
    claim_overlap: { platform_claimed_orders: Sourced; store_orders: Sourced; claimed_per_store_order: number | null }
  }
  ga4_unpaid: { available: boolean; source: 'ga4'; rows: SiteRow[] }
  computed_at?: string
}

export interface InfluencerLink {
  id: string
  name: string
  influencer_id: string | null
  collaboration_id: string | null
  utm: { source: string; medium: string; campaign: string }
  coupon_code: string | null
  is_active: boolean
  short_link_id: string
  url: string | null
  clicks: { value: number; source: 'short_link_counter' } | null
}

export interface MintInfluencerLink {
  name: string
  destination: string
  influencer_id?: string
  collaboration_id?: string
  coupon_code?: string
}

/** The one place the paths are written, so a test can pin them against the router. */
export function analyticsPath(projectId: string, section: 'binding' | 'site' | 'store' | 'influencers' | 'attribution' | 'influencer-links', range: AnalyticsRange = {}): string {
  const q = new URLSearchParams()
  if (range.from && range.to) {
    q.set('from', range.from)
    q.set('to', range.to)
  } else if (range.period) {
    q.set('period', range.period)
  }
  const qs = q.toString()
  return `/projects/${projectId}/analytics/${section}${qs === '' ? '' : `?${qs}`}`
}

const STALE = 60_000

function useSection<T>(projectId: string | null | undefined, section: Parameters<typeof analyticsPath>[1], range: AnalyticsRange, enabled = true) {
  return useQuery({
    queryKey: ['analytics-data', projectId, section, range.from ?? null, range.to ?? null, range.period ?? null],
    queryFn: () => getData<T>(analyticsPath(projectId as string, section, range)),
    enabled: enabled && !!projectId,
    staleTime: STALE,
  })
}

export const useAnalyticsBinding = (projectId: string | null | undefined) => useSection<Ga4Binding>(projectId, 'binding', {})
export const useAnalyticsSite = (projectId: string | null | undefined, range: AnalyticsRange) => useSection<SitePayload>(projectId, 'site', range)
export const useAnalyticsStore = (projectId: string | null | undefined, range: AnalyticsRange) => useSection<StorePayload>(projectId, 'store', range)
export const useAnalyticsInfluencers = (projectId: string | null | undefined, range: AnalyticsRange) => useSection<InfluencersPayload>(projectId, 'influencers', range)
export const useAnalyticsAttribution = (projectId: string | null | undefined, range: AnalyticsRange) => useSection<AttributionPayload>(projectId, 'attribution', range)
export const useInfluencerLinks = (projectId: string | null | undefined) => useSection<InfluencerLink[]>(projectId, 'influencer-links', {})

/** Mint one influencer's dedicated link; the UTM is chosen by the server and cannot be sent. */
export function useMintInfluencerLink(projectId: string) {
  const client = useQueryClient()

  return useMutation({
    mutationFn: (body: MintInfluencerLink) => postData<InfluencerLink>(analyticsPath(projectId, 'influencer-links'), body),
    onSuccess: () => client.invalidateQueries({ queryKey: ['analytics-data', projectId] }),
  })
}

/** Sum of a model's channel credit: the board checks it equals `totals_by_model[model]` before it draws. */
export function creditTotal(payload: AttributionPayload, model: AttributionModel): number {
  return payload.channels.reduce((sum, c) => sum + c.models[model].orders, 0)
}

// Kept so a caller that needs the envelope (meta.period) can ask for it without a second client.
export { getEnvelope }
