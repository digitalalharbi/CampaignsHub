import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SlideBody, isClientAudience } from './InteractiveReport'

/**
 * CLIENT-DIAGNOSTIC-SEPARATION-001 — the data-quality slide is the operator's, not the client's.
 *
 * It lists every source, its state and when we last read it, and closes with «مؤشرات لا ترسلها
 * المنصات المرتبطة». That is the sentence the owner found on their own client link. Every line of it
 * is a fact about our plumbing.
 *
 * The fact underneath IS the client's and is not lost: a metric no platform reported shows as blank
 * rather than zero, and a reader who is not told that reads the blank as a zero. So a client gets
 * that one sentence, in their own words, and nothing else.
 */
const base = {
  currency: 'SAR',
  kpis: {},
  reported: { spend: true, landing_page_views: false, revenue: false },
  freshness: { state: 'failed', last_sync_at: '2026-08-18T23:59:00Z', missing_days: 3, sources: [{ provider: 'meta', name: 'Meta', state: 'failed', last_sync_at: '2026-08-18T23:59:00Z' }] },
  platforms: [],
  slides: [],
} as unknown as Record<string, unknown>

const meta = { reportName: 'R', platforms: [] }
const slide = { id: 's', type: 'data_quality', order: 1, visible: true } as never

describe('the data-quality slide', () => {
  it('names the audiences a client report is addressed to', () => {
    expect(isClientAudience('client')).toBe(true)
    expect(isClientAudience('executive')).toBe(true)
    expect(isClientAudience('internal')).toBe(false)
    // An unlabelled report is one somebody generated for themselves.
    expect(isClientAudience(undefined)).toBe(false)
  })

  it('shows the operator the sources, their states and the clock', () => {
    render(<SlideBody slide={slide} data={{ ...base, audience: 'internal' } as never} meta={meta} />)

    expect(screen.getByText(/جودة البيانات/)).toBeInTheDocument()
    // Twice on purpose: the overall clock, and the per-source column beside it.
    expect(screen.getAllByText(/آخر مزامنة/).length).toBeGreaterThan(0)
  })

  it('shows the client which figures are missing, and nothing about our plumbing', () => {
    render(<SlideBody slide={slide} data={{ ...base, audience: 'client' } as never} meta={meta} />)

    expect(screen.getByText(/اكتمال الأرقام/)).toBeInTheDocument()
    expect(screen.getByText(/لم تصل هذه المؤشرات/)).toBeInTheDocument()

    expect(screen.queryAllByText(/آخر مزامنة/)).toEqual([])
    expect(screen.queryByText(/المنصات المرتبطة/)).toBeNull()
    expect(screen.queryByText(/تعذّرت المزامنة/)).toBeNull()
  })

  /** A section whose only content is «all good» teaches a reader to skip it, including on the day it speaks. */
  it('says nothing to a client when every figure arrived', () => {
    const { container } = render(
      <SlideBody slide={slide} data={{ ...base, audience: 'client', reported: { spend: true } } as never} meta={meta} />,
    )

    expect(container.textContent).toBe('')
  })
})
