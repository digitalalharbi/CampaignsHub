import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { AttributionModels } from './AttributionModels'
import type { ModelChannel, ModelComparison } from './api'
import { renderWithProviders } from '@/test/utils'

/**
 * ATTRIBUTION-MODELS-001 — the model comparison shows whole orders per model, the platform's claim and
 * GA4 beside them, and the paths; a link that hides revenue shows no money anywhere in it.
 */
const row = (over: Partial<ModelChannel>): ModelChannel => ({
  channel: 'meta',
  kind: 'platform',
  last_touch: { orders: 3, revenue: 900 },
  first_touch: { orders: 1, revenue: 300 },
  assisted: { orders: 0, revenue: 0 },
  evidence: { click_id: 1, utm: 2, coupon: 0 },
  platform_claimed_orders: 5,
  claim_includes_view_through: true,
  claim_click_through_days: 7,
  claim_view_through_days: 1,
  ga4_purchases: 4,
  ...over,
})

const data = (over: Partial<ModelComparison> = {}): ModelComparison => ({
  available: true,
  unavailable_reason: null,
  basis_ar: 'كل لمسة هنا طلبٌ سجّله المتجر بدليله.',
  basis_en: 'Each touch here is an order the store recorded with its evidence.',
  currency: 'SAR',
  channels: [
    row({}),
    row({ channel: 'google', last_touch: { orders: 0, revenue: 0 }, first_touch: { orders: 2, revenue: 600 }, assisted: { orders: 2, revenue: 600 }, platform_claimed_orders: 3, claim_includes_view_through: false, ga4_purchases: null }),
    row({ channel: 'unattributed', kind: 'unattributed', platform_claimed_orders: null, claim_includes_view_through: null, ga4_purchases: null, evidence: { click_id: 0, utm: 0, coupon: 0 } }),
  ],
  paths: { distinct: 3, cap: 10, max_length: 5, multi_touch_orders: 2, rows: [{ steps: ['google', 'meta'], truncated: false, orders: 2, revenue: 600 }] },
  coverage: { orders: 6, orders_with_customer: 4, share_with_customer: 0.6667, revenue_withheld_orders: 0 },
  ...over,
})

describe('AttributionModels', () => {
  it('sets each model beside the claim and GA4, and says which claims count views', () => {
    renderWithProviders(<AttributionModels data={data()} ar />)

    const section = screen.getByTestId('attribution-models')
    expect(within(section).getByText('آخر لمسة')).toBeInTheDocument()
    expect(within(section).getByText('أول لمسة')).toBeInTheDocument()
    expect(within(section).getByText('ساعدت في')).toBeInTheDocument()
    expect(screen.getByTestId('model-row-unattributed')).toHaveTextContent('غير مُسند')
    expect(screen.getByTestId('model-claim-basis-meta')).toHaveTextContent('يشمل المشاهدة')
    expect(screen.getByTestId('model-claim-basis-google')).toHaveTextContent('نقر فقط')
    expect(screen.getByTestId('attribution-models-coverage')).toHaveTextContent('4 / 6')
    expect(screen.getByTestId('attribution-path')).toHaveTextContent('جوجل ← ميتا')
  })

  it('shows no money when the link hides revenue', () => {
    const hidden = data({
      channels: data().channels.map((c) => ({
        ...c,
        last_touch: { ...c.last_touch, revenue: null },
        first_touch: { ...c.first_touch, revenue: null },
        assisted: { ...c.assisted, revenue: null },
      })),
      paths: { ...data().paths!, rows: [{ steps: ['google', 'meta'], truncated: false, orders: 2, revenue: null }] },
    })
    renderWithProviders(<AttributionModels data={hidden} ar={false} />)

    expect(screen.getByTestId('attribution-models')).not.toHaveTextContent(/SAR|ر\.س/)
    expect(screen.getByTestId('attribution-paths')).toHaveTextContent('2 orders')
  })

  it('says there is nothing to compare without a store', () => {
    renderWithProviders(<AttributionModels data={data({ available: false, unavailable_reason: 'no_store_connected', channels: [], paths: null, coverage: null })} ar={false} />)

    expect(screen.getByTestId('attribution-models-unavailable')).toHaveTextContent('No connected store')
    expect(screen.queryByTestId('attribution-paths')).toBeNull()
  })
})
