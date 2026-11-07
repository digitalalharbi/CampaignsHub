import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { StoreAnalyticsBlock, type StoreAnalyticsPayload } from './StoreAnalyticsBlock'
import { withStoreAnalyticsSlide } from './reportSections'

/** STORE-ANALYTICS-DEPTH-001 §3.1 — two labelled blocks, never added; a missing source is not drawn. */
type Slide = { id: string; type: string; order: number; visible: boolean }
const compared = (current: number | null) => ({ current, previous: null, change_pct: null })
const store: NonNullable<StoreAnalyticsPayload['store']> = {
  label_ar: 'ما أكّده المتجر', label_en: 'Store-confirmed', currency: 'SAR', demo_excluded: false,
  orders: { live: 40, confirmed: 30, cancelled: 2, refunded: 1 },
  sales: { gross: compared(5000), net: compared(4000), discounts: compared(null), shipping: compared(null), tax: compared(null), refunded: compared(1000) },
  averages: { aov: 100, items_per_order: 1.5 }, customers: { new: 10, returning: 5, repeat_rate: 0.2 },
  top_products: [{ name: 'Tee', quantity: 3, orders: 2, revenue: 900 }], trend: [], money_withheld_orders: 2,
  evidence: [{ method: 'unattributed', label_ar: 'غير منسوب', label_en: 'Unattributed', orders: 4, share: 0.1, net_revenue: 400 }],
}
const payload = (over: Partial<StoreAnalyticsPayload> = {}): StoreAnalyticsPayload => ({
  title_ar: 'تحليلات المتجر والزيارات', title_en: 'Store & traffic analytics', not_summed: true, note_ar: 'لا يُجمعان', note_en: 'They are not added',
  store, site: null, ...over,
})

describe('the store and traffic section', () => {
  it('names each source, says they are not added, and states what is unattributed and withheld', () => {
    render(<StoreAnalyticsBlock data={payload({ site: { label_ar: 'ما قاسه الموقع (GA4)', label_en: 'Site-measured (GA4)', currency: 'USD', totals: { sessions: 900, users: null, transactions: 11, revenue: 700 }, engagement_rate: 0.5, channels: [{ class: 'paid', sessions: 500, purchases: 3 }] } })} ar={false} />)
    expect(screen.getByTestId('store-analytics-not-summed')).toHaveTextContent('They are not added')
    expect(screen.getByTestId('store-analytics-store')).toHaveTextContent('Store-confirmed')
    expect(screen.getByTestId('store-analytics-site')).toHaveTextContent('Site-measured (GA4)')
    expect(screen.getByTestId('store-analytics-evidence')).toHaveTextContent('Unattributed')
    expect(screen.getByTestId('store-analytics-withheld')).toBeInTheDocument()
    expect(screen.getByTestId('store-analytics-channels')).toHaveTextContent('Paid')
  })

  it('draws no block for a source that has nothing', () => {
    render(<StoreAnalyticsBlock data={payload({ site: null })} ar />)
    expect(screen.queryByTestId('store-analytics-site')).toBeNull()
  })

  it('shows a hidden amount as a dash and a store with no evidence block without one', () => {
    render(<StoreAnalyticsBlock data={payload({ store: { ...store, currency: null, sales: { ...store.sales, net: compared(null) }, evidence: null } })} ar={false} />)
    expect(screen.queryByTestId('store-analytics-evidence')).toBeNull()
    expect(screen.getByTestId('store-analytics-store')).toHaveTextContent('—')
  })

  it('adds the snapshot page only when the server named the section', () => {
    expect(withStoreAnalyticsSlide<Slide>([], { store_analytics: payload() })).toHaveLength(0)
    expect(withStoreAnalyticsSlide<Slide>([], { report_sections: ['kpis'], store_analytics: payload() })).toHaveLength(0)
    expect(withStoreAnalyticsSlide<Slide>([], { report_sections: ['store_analytics'] })).toHaveLength(0)
    expect(withStoreAnalyticsSlide<Slide>([], { report_sections: ['store_analytics'], store_analytics: payload() })[0].type).toBe('__store_analytics')
  })
})
