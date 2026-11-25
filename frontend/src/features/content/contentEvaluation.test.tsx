import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { ContentEvaluation } from './CreativeDetailTabs'
import type { CreativeDetail } from './api'

const data = (metrics: Record<string, number> | null) => ({
  metrics,
  creative: { provider: 'tiktok', objective: 'sales' },
} as unknown as CreativeDetail)

/** CONTENT-EVALUATION-001 — one verdict on the ad as content, the stages against their normal range, and what to change. */
describe('the content evaluation', () => {
  it('rates the content, names the weak stage and says what to change', () => {
    renderWithProviders(<ContentEvaluation data={data({ impressions: 100_000, video_views: 10_000, clicks: 300, spend: 1000, revenue: 6000, purchases: 50, add_to_cart: 120 })} />, { locale: 'en' })
    const card = screen.getByTestId('content-evaluation')
    expect(card.getAttribute('data-score')).not.toBe('')
    expect(within(screen.getByTestId('content-evaluation-stages')).getByText('Hook')).toBeInTheDocument()
    expect(screen.getByTestId('content-evaluation-fixes')).toHaveTextContent(/first three seconds/)
  })

  it('does not grade an ad with too little delivery', () => {
    renderWithProviders(<ContentEvaluation data={data({ impressions: 200, clicks: 3 })} />, { locale: 'en' })
    expect(screen.getByTestId('content-evaluation')).toHaveTextContent('Not rated yet')
    expect(screen.queryByTestId('content-evaluation-fixes')).toBeNull()
  })
})
