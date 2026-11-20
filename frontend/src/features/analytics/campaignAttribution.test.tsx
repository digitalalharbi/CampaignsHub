import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { CampaignAttributionView } from './CampaignAttribution'
import type { CampaignAttribution } from './api'

const row = (id: string, platform: number, store: number | null, verdict: CampaignAttribution['verdict']): CampaignAttribution => ({
  id, name: `Campaign ${id}`, provider: 'snapchat', spend: 100, platform_orders: platform, platform_revenue: 0, platform_roas: null,
  store_orders: store, store_revenue: store === null ? null : store * 50, store_refunds: 0, store_roas: store === null ? null : (store * 50) / 100,
  store_cpa: null, profit: store === null ? null : store * 50 - 100, verdict,
})

describe('ATTRIB-CAMPAIGN-001 — platform vs store per campaign', () => {
  it('draws each campaign with its verdict and filters by it', () => {
    render(<CampaignAttributionView rows={[row('a', 100, 10, 'over'), row('b', 22, 63, 'under'), row('c', 5, 5, 'match')]} currency="SAR" ar={false} />)
    expect(screen.getByTestId('attribution-campaign-a')).toHaveAttribute('data-verdict', 'over')
    expect(screen.getByTestId('attribution-campaign-b')).toHaveTextContent('Platform under-claims')
    fireEvent.click(screen.getByTestId('attribution-campaigns-filter-over'))
    expect(screen.queryByTestId('attribution-campaign-b')).toBeNull()
    expect(screen.getByTestId('attribution-campaign-a')).toBeInTheDocument()
  })

  it('is not drawn without a store — no second number, no verdict', () => {
    const { container } = render(<CampaignAttributionView rows={[row('a', 100, null, null)]} currency="SAR" ar />)
    expect(container).toBeEmptyDOMElement()
  })
})
