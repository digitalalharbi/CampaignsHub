/**
 * GA4-STORE-ANALYTICS-001 — the store reading as the server states it (`Ga4StoreAnalytics::build`).
 *
 * One shape for Analytics, the live link and the saved report. A report surface receives the same
 * parts under its own section keys, with what a client may not read already left out by the server
 * (no campaign table, revenue nulled where the link hides revenue).
 */
export interface StoreKpi {
  key: string
  kind: 'count' | 'rate' | 'seconds' | 'money' | 'ratio'
  current: number | null
  previous: number | null
  change_pct: number | null
  currency: string | null
  current_usd?: number | null
  previous_usd?: number | null
  trend?: Array<{ date: string; value: number }>
}

export interface StoreSeriesDay { date: string; sessions: number | null; purchases: number | null; revenue: number | null; revenue_usd: number | null }
export interface StoreSlice { key: string; sessions: number; purchases: number | null; revenue: number | null; revenue_usd: number | null; share: number | null }
/** REPORT-WEBSITE-TAB-001 — how a breakdown closes onto the visits figure: a short one carries a computed `unclassified` slice, one that runs over keeps its measured slices and states `over`. */
export interface StoreBreakdownMeta { total: number; measured?: number; classified: number; unclassified: number; over?: number; days_covered: number; days_total: number }
export interface StoreFunnelStep { stage: string; count: number | null; step_rate: number | null; exceeds_previous: boolean }

export interface StoreGapSide { sessions: number; purchases: number; revenue: number; currency: string | null; revenue_usd: number | null; session_share: number | null }
export interface StoreGapRow {
  platform: string
  reported: { conversions: number | null; revenue: number | null; currency: string | null; revenue_usd: number | null } | null
  ga4: StoreGapSide | null
  gap: { conversions: number | null; conversions_share: number | null; revenue_usd: number | null; revenue_share: number | null }
}
export interface StoreGap {
  summed: false
  platforms_available?: boolean
  rows: StoreGapRow[]
  unpaid: Array<StoreGapSide & { class: 'organic' | 'direct' | 'referral' | 'other' }>
  other_paid: StoreGapSide | null
  ga4_sessions: number | null
}

export interface StoreSessionRow { name: string; sub: string | null; sessions: number; engagement_rate: number | null; purchases: number | null; conversion_rate: number | null; revenue: number | null; revenue_usd: number | null }
export interface StoreProductRow { name: string; views: number | null; carts: number | null; purchased: number | null; revenue: number | null; revenue_usd: number | null }
export interface StoreTable<R> { rows: R[]; total: number }
export type StoreTableKey = 'sources' | 'campaigns' | 'landing_pages' | 'products' | 'countries'

export interface StoreTables {
  sources?: StoreTable<StoreSessionRow> | null
  campaigns?: StoreTable<StoreSessionRow> | null
  landing_pages?: StoreTable<StoreSessionRow> | null
  countries?: StoreTable<StoreSessionRow> | null
  products?: StoreTable<StoreProductRow> | null
}

export interface StoreUsd { available: boolean; reason?: 'no_currency' | 'no_rate'; from?: string; rate?: number | null; rate_date?: string | null; source?: string | null }

export interface StorePayload {
  state: 'not_connected' | 'not_selected' | 'empty' | 'ready'
  period: { from: string; to: string }
  previous_period?: { from: string; to: string }
  property?: { id: string; name: string | null; timezone: string | null }
  last_synced_at?: string | null
  currency?: string | null
  usd?: StoreUsd
  not_summed?: boolean
  kpis?: StoreKpi[]
  series?: StoreSeriesDay[]
  channels?: StoreSlice[] | null
  devices?: StoreSlice[] | null
  channels_meta?: StoreBreakdownMeta | null
  devices_meta?: StoreBreakdownMeta | null
  funnel?: StoreFunnelStep[]
  gap?: StoreGap
  tables?: StoreTables
}

/** The four report sections, each present only when the server resolved it as shown. */
export interface Ga4OverviewSection { currency: string | null; usd: StoreUsd; period: { from: string; to: string }; previous_period: { from: string; to: string }; property?: { name?: string | null }; last_synced_at: string | null; kpis: StoreKpi[]; series: StoreSeriesDay[] }
export interface Ga4AcquisitionSection { currency: string | null; channels: StoreSlice[] | null; devices: StoreSlice[] | null; channels_meta?: StoreBreakdownMeta | null; devices_meta?: StoreBreakdownMeta | null; funnel: StoreFunnelStep[] }
export interface Ga4GapSection extends StoreGap { currency: string | null }
export interface Ga4TablesSection { currency: string | null; tables: StoreTables }
