import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import type { CreativeDetail } from './api'
import { CreativeVerdict } from './CreativeVerdict'

const trend = [['2026-11-10', 100, 500], ['2026-11-11', 120, 900], ['2026-11-12', 0, 0], ['2026-11-13', 0, 0], ['2026-11-14', 90, 300]]
  .map(([date, spend, revenue]) => ({ date, spend, revenue, conversions: 2, clicks: 10, impressions: 2000 }))
const data = {
  creative: { id: 'c', name: 'Ad', provider: 'tiktok', format: 'video', kind: 'video', duration_seconds: 43, dimensions: { width: 1080, height: 1920, aspect_ratio: null, file_size: null } },
  period: { from: '2026-11-01', to: '2026-11-19', days: 19 }, previous_period: { from: '', to: '' },
  metrics: { spend: 310, impressions: 10000, clicks: 50, conversions: 6, revenue: 1700, roas: 5.48, cpa: 51.6, ctr: 0.005, cpc: 6.2, cpm: 31, completion_rate: 0.0027 },
  previous: null, headline_metrics: [], path: 'conversion', trend, currency: 'USD',
  peers: { count: 12, provider: 'tiktok', roas: 4, cpa: 40, ctr: 0.008, cpc: 5, cpm: 30, completion_rate: 0.04 },
} as unknown as CreativeDetail

describe('CONTENT-WHY-VISUAL-001 — drawn, not written', () => {
  it('shows a verdict pill, a diverging bar per figure, the shape, the watch ring, the length and the days — and no sentences', () => {
    renderWithProviders(<CreativeVerdict data={data} ar mediaHref={null} />, { locale: 'ar' })
    const box = screen.getByTestId('creative-why')
    expect(screen.getByTestId('creative-why-verdict')).toHaveAttribute('data-verdict', 'win')
    expect(screen.getByTestId('why-cmp-roas')).toBeInTheDocument()
    expect(screen.getByTestId('why-shape')).toHaveTextContent('9:16')
    expect(screen.getByTestId('why-ring')).toBeInTheDocument()
    expect(screen.getByTestId('why-length')).toHaveTextContent('43s')
    const days = within(screen.getByTestId('why-days'))
    expect(days.getAllByTitle(/2026-11-1[23]/).every((e) => e.hasAttribute('data-stopped'))).toBe(true)
    expect(box.querySelector('[data-best]')?.getAttribute('title')).toBe('2026-11-11')
    // No written reading on the page: no list items, no paragraphs.
    expect(box.querySelectorAll('li, p')).toHaveLength(0)
  })
})
