/**
 * GA4-ATTRIBUTION-MATCH-001 / GA4-GAP-EXPLAINED-001 — the shapes the server states.
 *
 * The match is channel / campaign level from GA4's own session attribution; it is never an order-level
 * match, and `order_level: false` rides on every payload. A figure the server withheld arrives as null.
 */
export type Confidence = 'id' | 'utm' | 'channel' | 'unmatched'

export interface MatchSide { sessions: number | null; purchases: number | null; revenue: number | null; revenue_usd: number | null; aov_usd: number | null; conversion_rate: number | null }
export interface MatchClaim { conversions: number | null; revenue_usd: number | null; spend_usd?: number | null; clicks?: number | null }
export interface MatchGap { conversions: number | null; conversions_share: number | null; revenue_usd: number | null; revenue_share: number | null }

export interface MatchCampaign {
  platform: string
  external_id: string
  name: string
  confidence: Confidence | null
  claimed: MatchClaim | null
  ga4: MatchSide | null
  gap: MatchGap
  spend_usd?: number | null
  cost_per_purchase_usd?: number | null
  roas_ga4?: number | null
}

export interface MatchPlatform {
  platform: string
  claimed: MatchClaim | null
  ga4: MatchSide | null
  campaigns?: MatchCampaign[]
  channel?: { confidence: Confidence | null; ga4: MatchSide | null } | null
  /** Client framing: the platform's best confidence and how many campaigns matched, never their names. */
  confidence?: Confidence | null
  matched_campaigns?: number
  gap?: MatchGap
  matched_share: number | null
}

export interface MatchPayload {
  state: 'not_connected' | 'not_selected' | 'empty' | 'ready' | 'hidden'
  basis: 'ga4'
  order_level: false
  summed?: false
  period?: { from: string; to: string }
  last_synced_at?: string | null
  platforms?: MatchPlatform[]
  other_paid?: { ga4: MatchSide } | null
  unattributed?: { sessions: number; classes: Array<MatchSide & { class: string }> } | null
  unmatched?: { campaigns: number; spend_usd: number | null; clicks: number | null } | null
  previous?: { period: { from: string; to: string }; platforms: Array<{ platform: string; ga4: MatchSide | null }> }
  evidence?: { click_id_sessions: Array<{ family: string; sessions: number }>; id_pairs: number }
}

export interface GapReason {
  key: 'no_trace' | 'attribution_rule' | 'untagged_channel' | 'no_platform_trace'
  label_ar: string
  label_en: string
  measured: true
  value: number | null
  value_usd: number | null
  share: number | null
  direction: 'widens' | 'narrows'
  cause_unmeasured: boolean
  rows: Array<{ platform?: string; campaign?: string; source?: string; claimed?: number | null; ga4?: number | null }>
}

export interface GapContext { key: string; measured: boolean; value: number | null; unit: string; rows?: Array<Record<string, unknown>>; store?: number; ga4?: number; currency?: string | null }

export interface GapReasonsPayload {
  state?: 'not_connected' | 'not_selected' | 'empty' | 'ready' | 'hidden'
  basis: 'ga4'
  order_level: false
  period?: { from: string; to: string }
  headline?: { unit: 'conversions'; claimed: number | null; measured: number | null; gap: number | null; gap_share: number | null; gap_usd: number | null; excluded_campaigns: number }
  bar?: Array<{ key: string; value: number; share: number | null }>
  reasons?: GapReason[]
  unexplained?: { key: 'unexplained'; value: number }
  unmeasured?: Array<{ key: string; label_ar: string; label_en: string; measured: false; value: null }>
  context?: GapContext[]
  check?: { sum: number; gap: number }
}

export interface ClassificationRow { label: string; sessions?: number | null; engaged_sessions?: number | null; purchases?: number | null; revenue?: number | null; revenue_usd?: number | null; conversion_rate?: number | null; aov_usd?: number | null; share?: number | null; items_purchased?: number | null; item_revenue?: number | null }
export interface ClassificationPayload {
  group: string
  state: 'not_connected' | 'empty' | 'ready' | 'hidden'
  rows: ClassificationRow[]
  total: number
  pages: number
  page?: number
  per_page?: number
  currency?: string | null
  unsupported?: string[]
}

export const CLASSIFY_GROUPS = ['channel', 'source_medium', 'platform', 'campaign', 'landing_page', 'product', 'product_category', 'device', 'country', 'user_type'] as const
export type ClassifyGroup = (typeof CLASSIFY_GROUPS)[number]
