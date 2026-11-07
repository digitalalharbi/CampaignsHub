/** STORE-ANALYTICS-DEPTH-001 — what the store's endpoints answer. A null is «not stated», never zero. */
export interface Compared { current: number | null; previous: number | null; change_pct: number | null }
export interface ProductRow { name: string; quantity: number; revenue: number; orders: number }

export interface StoreDepthPayload {
  state: 'no_store' | 'empty' | 'ready'
  period: { from: string; to: string; timezone: string }
  previous_period: { from: string; to: string }
  currency?: string
  basis?: { source: string; label_ar: string; label_en: string; demo_orders: number; live_orders: number; demo_excluded: boolean; kind: 'live' | 'demo' | 'mixed' }
  sales?: Record<'gross' | 'net' | 'discounts' | 'shipping' | 'tax' | 'refunded', Compared>
  orders?: { total: number; live: number; confirmed: number; pending: number; cancelled: number; refunded: number; previous_live: number | null }
  averages?: { aov: number | null; items_per_order: number | null; cancellation_rate: number; refund_rate: number | null; discount_share: number | null }
  conversion?: { value: number | null; confirmed_orders: number; visits: number | null; visits_source: 'ga4' | 'ad_platforms' | null; label_ar: string | null; label_en: string | null }
  products?: { best_by_revenue: ProductRow[]; best_by_orders: ProductRow[]; weak: ProductRow[]; unsold_active: { count: number; sample: string[] }; categories: Array<{ category: string; revenue: number; quantity: number; products: number }>; categories_note: { ar: string; en: string } | null }
  abandoned?: { carts: number; value: number | null; by_product: null; by_product_reason: { ar: string; en: string } }
  customers?: { new: number; returning: number; without_customer: number; repeat_rate: number | null; customers_in_ledger: number; ledger_since: string | null; lifetime_value: number | null; lifetime_value_reason: string | null; lifetime_value_basis: { ar: string; en: string } }
  trend?: Array<{ date: string; orders: number; net: number }>
  coverage?: { orders_with_money_withheld: number; money_withheld_currencies: string[]; duplicate_orders_collapsed: number }
}

export type SourceMethod = 'utm_campaign' | 'click_id' | 'utm_source' | 'influencer_coupon' | 'coupon' | 'referral' | 'conflict' | 'unattributed'

export interface SourceRow {
  dimension_1: string
  dimension_2: string | null
  kind: 'value' | 'unattributed' | 'conflict' | 'not_tagged' | 'method'
  orders: number
  confirmed: number
  net_revenue: number
  share: number
  money_withheld_orders: number
  methods: Partial<Record<SourceMethod, number>>
  ga4_sessions: number | null
  ga4_add_to_carts: number | null
  ga4_purchases: number | null
  ga4_revenue: number | null
  ga4_currency: string | null
  confirmed_per_session: number | null
}

export interface SourcesPage {
  state: 'no_store' | 'ready'
  rows: SourceRow[]
  total: number
  currency: string | null
  ga4_available: boolean
  totals: { orders: number | null; net_revenue: number | null; cancelled_excluded: number | null; money_withheld_orders: number | null; money_withheld_currencies: string[] }
}

export interface OrderRow {
  id: string
  reference: string | null
  placed_at: string | null
  status: string
  confirmed: boolean
  cancelled: boolean
  refunded: boolean
  original_total: number | null
  original_currency: string | null
  net: number | null
  money_withheld: boolean
  method: SourceMethod
  method_label: { ar: string; en: string }
  source: string | null
  medium: string | null
  campaign: string | null
  content: string | null
  term: string | null
  coupon: string | null
  click_id_platform: string | null
  landing_path: string | null
  is_demo: boolean
}

export interface OrdersPage { state: 'no_store' | 'ready'; rows: OrderRow[]; total: number; currency: string | null }

export interface ThreeSourceCell { orders: number | null; revenue: number | null; currency?: string | null; sessions?: number | null }

export interface ThreeSourcesPayload {
  period: { from: string; to: string; timezone: string }
  summed: false
  available: Record<'platform_reported' | 'store_confirmed' | 'ga4_measured', boolean>
  sources: Array<{ key: 'platform_reported' | 'store_confirmed' | 'ga4_measured'; label_ar: string; label_en: string; orders: number | null; note_ar: string; note_en: string }>
  rows: Array<{
    platform: string
    platform_reported: ThreeSourceCell | null
    store_confirmed: ThreeSourceCell | null
    ga4_measured: ThreeSourceCell | null
    differences: { platform_minus_store_orders: number | null; material: boolean }
    explanation: { ar: string; en: string }
  }>
  store_unattributed: { orders: number; revenue: number } | null
  store_currency: string | null
}
