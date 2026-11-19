import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import type { CreativeCard } from '@/features/content/api'
import { ContentWhyVisual } from './ContentWhyVisual'

const card = (id: string, clicks: number, spend: number, freq: number | null, change: number | null): CreativeCard => ({
  id, name: `Item ${id}`, provider: 'meta', objective: 'sales',
  metrics: { impressions: 10000, clicks, spend, frequency: freq },
  fatigue: { status: 'watch', signals: change === null ? [] : [{ metric: 'ctr', direction: 'down', change }], reason_ar: '', reason_en: '' },
}) as unknown as CreativeCard

describe('ContentWhyVisual — the why, drawn (no paragraphs)', () => {
  const rows = [card('a', 300, 300, 1.5, null), card('b', 200, 100, null, null), card('c', 100, 100, 4.2, -0.3), card('d', 50, 100, null, null)]

  it('draws the matrix, the best and the weakest with gap, share, frequency and click-rate change, and opens an item', () => {
    const open = vi.fn()
    render(<ContentWhyVisual rows={rows} ar={false} onOpen={open} />)
    expect(screen.getByTestId('content-matrix')).toBeTruthy()
    expect(screen.getByTestId('content-why-win-a')).toHaveTextContent('Frequency 1.50')
    expect(screen.getByTestId('content-why-lag-d')).toBeTruthy()
    // no prose paragraph anywhere in the block
    expect(screen.getByTestId('content-why').querySelectorAll('p')).toHaveLength(0)
    fireEvent.click(screen.getByTestId('content-why-win-a'))
    expect(open).toHaveBeenCalledWith(rows[0])
  })

  it('draws nothing for a group too small to compare', () => {
    const { container } = render(<ContentWhyVisual rows={rows.slice(0, 2)} ar onOpen={() => {}} />)
    expect(container.innerHTML).toBe('')
  })
})
