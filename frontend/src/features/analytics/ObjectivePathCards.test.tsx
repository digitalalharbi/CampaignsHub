import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { ObjectivePathCards, type ObjectivePathsBlock } from './ObjectivePathCards'
import { renderWithProviders } from '@/test/utils'

/**
 * OBJECTIVE-PATHS-001 — a coffee brand's store squads and branch squads (figures invented).
 *
 * The Owner's rules: the total spend once, a split bar under it, one card per objective with its own
 * result; ROAS on the sales card only; on a link that hides spend, no money at all.
 */
const block: ObjectivePathsBlock = {
  total_spend: 1000,
  spend_withheld_rows: 0,
  mixed: true,
  mixed_campaigns: 1,
  paths: [
    {
      family: 'sales', label: { ar: 'المبيعات', en: 'Sales' }, spend: 600, spend_share: 0.6, revenue: 3000, roas: 5,
      campaigns: 1, ad_sets: 1, result_key: 'conversions', result: 30, result_label: { ar: 'الطلبات', en: 'Orders' },
      cost_per_result: 20, cost_per_result_label: { ar: 'تكلفة الطلب (CPA)', en: 'Cost per order (CPA)' }, result_missing_label: null,
    },
    {
      family: 'traffic', label: { ar: 'الزيارات', en: 'Traffic' }, spend: 400, spend_share: 0.4, revenue: null, roas: null,
      campaigns: 1, ad_sets: 1, result_key: 'landing_page_views', result: 2400, result_label: { ar: 'زيارات الصفحة', en: 'Landing page views' },
      cost_per_result: 0.17, cost_per_result_label: { ar: 'تكلفة الزيارة', en: 'Cost per visit' }, result_missing_label: null,
    },
  ],
}

describe('ObjectivePathCards', () => {
  it('states the total once, splits it, and gives each objective its own card', () => {
    renderWithProviders(<ObjectivePathCards block={block} locale="en" currency="SAR" />)

    expect(screen.getAllByTestId('objective-paths-total')).toHaveLength(1)
    const split = screen.getByTestId('objective-paths-split')
    expect(split.children).toHaveLength(2)
    expect((split.children[0] as HTMLElement).style.width).toBe('60%')
    expect(within(screen.getByTestId('objective-path-sales')).getByText('Orders')).toBeInTheDocument()
    expect(within(screen.getByTestId('objective-path-traffic')).getByText('Landing page views')).toBeInTheDocument()
  })

  it('puts ROAS on the sales card only', () => {
    renderWithProviders(<ObjectivePathCards block={block} locale="en" currency="SAR" />)

    expect(within(screen.getByTestId('objective-path-sales')).getByTestId('objective-path-roas')).toHaveTextContent('5.00×')
    expect(within(screen.getByTestId('objective-path-traffic')).queryByTestId('objective-path-roas')).toBeNull()
    expect(within(screen.getByTestId('objective-path-traffic')).queryByText('ROAS')).toBeNull()
  })

  it('shows no money on a link that hides spend, and keeps each result', () => {
    renderWithProviders(<ObjectivePathCards block={block} locale="ar" currency="SAR" showMoney={false} />)

    expect(screen.queryByTestId('objective-paths-total')).toBeNull()
    expect(screen.queryByTestId('objective-paths-split')).toBeNull()
    expect(screen.queryByTestId('objective-path-roas')).toBeNull()
    expect(within(screen.getByTestId('objective-path-traffic')).getByText('زيارات الصفحة')).toBeInTheDocument()
  })

  it('treats a redacted total as a link that hides spend', () => {
    renderWithProviders(<ObjectivePathCards block={{ ...block, total_spend: null, paths: block.paths.map((p) => ({ ...p, spend: null, spend_share: null, roas: null, revenue: null, cost_per_result: null })) }} locale="en" currency="SAR" />)

    expect(screen.queryByTestId('objective-paths-total')).toBeNull()
    expect(screen.queryByTestId('objective-path-roas')).toBeNull()
    expect(within(screen.getByTestId('objective-path-sales')).getByText('Orders')).toBeInTheDocument()
  })

  it('draws nothing without paths', () => {
    const { container } = renderWithProviders(<ObjectivePathCards block={{ ...block, paths: [] }} locale="en" currency="SAR" />)
    expect(container.querySelector('[data-testid="objective-path-cards"]')).toBeNull()
  })
})
