import { describe, expect, it } from 'vitest'
import { leadObjective, verdictFor } from './formatVerdict'
import type { ContentIntelligence, FormatIntelligencePayload } from './api'

/**
 * CREATIVE-FORMAT-INTELLIGENCE-001 — the verdict is derived, and it refuses when it should.
 *
 * Preserving «لا توجد بيانات كافية» is the point of the exercise: the behaviour being generalised is
 * a comparison that declines to crown a format on two assets, not one that always produces a winner.
 */
const comparison = (over: Partial<ContentIntelligence> = {}): ContentIntelligence => ({
  metric: 'cpa',
  lower_is_better: true,
  objective: 'sales',
  formats: [
    { format: 'video', value: 10, spend: 900, creatives: 6 },
    { format: 'image', value: 30, spend: 900, creatives: 6 },
  ] as never,
  best: 'video',
  worst: 'image',
  share_of_spend_not_on_the_leading_format: 0.5,
  why_no_spend_share: null,
  too_few_to_speak_for_their_format: [],
  refusal: null,
  ...over,
})

describe('the format verdict', () => {
  it('names the better format when the evidence allows it', () => {
    const v = verdictFor(comparison(), 'high')

    expect(v.kind).toBe('winner')
    expect(v.winner).toBe('video')
    // Each sentence in its OWN language — one shared word put Arabic into the English string.
    expect(v.ar).toContain('الفيديو')
    expect(v.ar).not.toMatch(/[A-Za-z]/)
    expect(v.en).toContain('Video')
    expect(v.en).not.toMatch(/[\u0600-\u06FF]/)
  })

  /** Moderate evidence still answers, and says that it is moderate — it does not go silent. */
  it('hedges on moderate evidence rather than hiding the answer', () => {
    const v = verdictFor(comparison(), 'moderate')

    expect(v.kind).toBe('winner')
    expect(v.en).toContain('limited evidence')
  })

  it('refuses to name a winner on insufficient evidence', () => {
    const v = verdictFor(comparison(), 'insufficient')

    expect(v.kind).toBe('none')
    expect(v.winner).toBeNull()
    expect(v.ar).toContain('لا توجد أدلة كافية')
  })

  /** A server refusal is final whatever the evidence label says. */
  it('refuses when the server refused', () => {
    const v = verdictFor(comparison({ refusal: 'only_one_format_ran_enough_to_compare', best: null }), 'high')

    expect(v.kind).toBe('none')
  })

  /**
   * A dead heat is an answer.
   *
   * Calling it for whichever row sorted first would be a verdict manufactured by a tie-break, which
   * is exactly the false confidence this module exists to avoid.
   */
  it('says there is no clear difference when the formats are level', () => {
    const level = comparison({
      formats: [
        { format: 'video', value: 10, spend: 900, creatives: 6 },
        { format: 'image', value: 10.2, spend: 900, creatives: 6 },
      ] as never,
    })

    const v = verdictFor(level, 'high')

    expect(v.kind).toBe('split')
    expect(v.winner).toBeNull()
  })

  /** It never recommends moving budget — a reading, never an allocation. */
  it('never tells anyone to move money', () => {
    for (const evidence of ['high', 'moderate', 'insufficient'] as const) {
      const v = verdictFor(comparison(), evidence)

      expect(v.ar).not.toMatch(/انقل|حوّل|الميزانية/)
      expect(v.en.toLowerCase()).not.toMatch(/move|shift|budget|reallocate/)
    }
  })
})

describe('which objective a compact surface leads with', () => {
  const payload = (objectives: FormatIntelligencePayload['objectives']): FormatIntelligencePayload =>
    ({ objectives } as FormatIntelligencePayload)

  it('leads with the first objective that can actually be judged', () => {
    const lead = leadObjective(payload([
      { family: 'traffic', label: { ar: '', en: '' }, comparison: comparison(), evidence: 'insufficient' },
      { family: 'sales', label: { ar: '', en: '' }, comparison: comparison(), evidence: 'high' },
    ]))

    expect(lead?.family).toBe('sales')
  })

  /** With nothing judgeable it still returns one, so the surface can show the refusal rather than nothing. */
  it('still returns an objective when none can be judged', () => {
    const lead = leadObjective(payload([
      { family: 'traffic', label: { ar: '', en: '' }, comparison: comparison(), evidence: 'insufficient' },
    ]))

    expect(lead?.family).toBe('traffic')
  })

  it('answers nothing for a scope with no creatives', () => {
    expect(leadObjective(payload([]))).toBeNull()
    expect(leadObjective(undefined)).toBeNull()
  })
})
