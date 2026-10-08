import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { CreativeInsightCard } from './CreativeInsightCard'
import { renderWithProviders } from '@/test/utils'
import type { CreativeInsight } from './api'

/**
 * UI-FINDING-DENSITY-001 — the finding leads; its account opens on demand.
 *
 * Thirteen of these stack on the dashboard and each printed three paragraphs — title, detail, and
 * suggested action. Measured on the demo tenant that is 2,330 characters of prose against two charts
 * on the first screen an operator opens: «حاول تقليل الجانب النصي … وليست نصية فقط».
 *
 * Nothing is removed, and the two things that must NOT be hidden are the subject of their own test
 * below: the title, which is what a reader scans, and the evidence line, because a confidence and a
 * window are what stop a finding being read as settled. Burying a caveat would be hiding the
 * qualification rather than the explanation, which is the opposite of the point.
 */
const insight = (over: Partial<CreativeInsight> = {}): CreativeInsight => ({
  id: 'i1',
  severity: 'warning',
  confidence: 'high',
  title_ar: 'تراجع معدل النقر',
  title_en: 'Click-through is falling',
  detail_ar: 'انخفض معدل النقر من 1.62% إلى 1.08% مقارنة بالفترة السابقة.',
  detail_en: 'Click-through fell from 1.62% to 1.08% against the previous period.',
  action_ar: 'جهّز نسخة جديدة من هذا المحتوى بافتتاحية مختلفة.',
  action_en: 'Prepare a fresh cut of this creative with a different opening.',
  period: { from: '2026-09-09', to: '2026-10-08' },
  previous_period: { from: '2026-08-10', to: '2026-09-08' },
  needs_human_review: false,
  creative_id: null,
  creative_name: null,
  ...over,
} as unknown as CreativeInsight)

describe('a finding on the dashboard', () => {
  it('shows its title without being opened', () => {
    renderWithProviders(<CreativeInsightCard item={insight()} locale="en" creativeHref={null} />, { locale: 'en' })

    expect(screen.getByText('Click-through is falling')).toBeVisible()
  })

  /*
    The detail and the action stay in the DOM — a disclosure hides them from the eye, not from the
    page. That is what keeps them findable by the browser's own search and by anything that asserts
    the finding said what it said.
  */
  it('keeps the detail and the action in the document, behind the disclosure', () => {
    renderWithProviders(<CreativeInsightCard item={insight()} locale="en" creativeHref={null} />, { locale: 'en' })

    expect(screen.getByText(/Click-through fell from 1\.62%/)).toBeInTheDocument()
    expect(screen.getByText(/fresh cut of this creative/)).toBeInTheDocument()
  })

  it('offers the disclosure as a real control, named', () => {
    renderWithProviders(<CreativeInsightCard item={insight()} locale="en" creativeHref={null} />, { locale: 'en' })

    const summary = screen.getByText('Detail and action')

    expect(summary.closest('summary')).not.toBeNull()
    expect(summary.closest('details')).not.toBeNull()
  })

  /**
   * The qualification is never what gets collapsed.
   *
   * A finding whose confidence is hidden reads as settled, and one whose window is hidden cannot be
   * checked against anything. Those stay on the face of the card with the title; only the
   * explanation moves.
   */
  it('leaves the evidence on the face of the card', () => {
    renderWithProviders(
      <CreativeInsightCard item={insight({ confidence: 'insufficient_data', needs_human_review: true })} locale="en" creativeHref={null} />,
      { locale: 'en' },
    )

    const card = screen.getByRole('listitem')
    /*
      Asserted STRUCTURALLY, because jsdom applies no Tailwind: `toBeVisible()` cannot see a `hidden`
      class, and an earlier version of this test passed happily against a card that never collapsed
      at all — `querySelector('.hidden')` returned null, and `(null ?? '').not.toContain(…)` is true
      of everything. So the collapsed region has to be FOUND first, and then checked for what it
      must and must not hold.
    */
    const collapsed = card.querySelector('details .hidden')

    expect(collapsed, 'the detail and action are not inside a collapsed region').not.toBeNull()
    expect(collapsed?.textContent ?? '').toContain('Click-through fell')

    expect(card.textContent).toContain('2026-09-09')
    expect(card.textContent).toContain('2026-08-10')
    /* …and none of the qualification sits inside that region. */
    expect(collapsed?.textContent ?? '').not.toContain('2026-09-09')
    expect(collapsed?.textContent ?? '').not.toContain('2026-08-10')
  })
})
