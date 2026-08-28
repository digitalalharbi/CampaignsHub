import { describe, expect, it } from 'vitest'

import type { Diagnosis } from './diagnose'
import { recommendedActions } from './recommendedActions'

/**
 * ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — advice is where a diagnosis starts costing money.
 *
 * Everything here is about what this layer REFUSES to say. A wrong description misleads a reader; a
 * wrong action sends them into an ad platform to change live spend, and the sentence that sent them
 * carries no trace of how thin its evidence was.
 */
const diagnosis = (over: Partial<Diagnosis> = {}): Diagnosis => ({
  state: 'diagnosed',
  findings: [],
  missing: [],
  ...over,
})

describe('what the recommendation layer will and will not propose', () => {
  it('answers an observed finding with an action', () => {
    const actions = recommendedActions(diagnosis({
      findings: [{ stage: 'value', confidence: 'observed', evidence: ['conversions', 'revenue'], code: 'conversions_without_value' }],
    }))

    expect(actions).toHaveLength(1)
    expect(actions[0].kind).toBe('adjust')
    expect(actions[0].evidence).toEqual(['conversions', 'revenue'])
  })

  /**
   * The rule that matters most. A ratio suggested this; it did not measure it. «Raise the bid because
   * click-through looks low» spends real money on an inference, and nothing in that sentence tells the
   * reader the ground under it was a ratio.
   */
  it('never lets an inference become an instruction to change something', () => {
    const actions = recommendedActions(diagnosis({
      findings: [
        { stage: 'value', confidence: 'probable', evidence: ['conversions', 'revenue'], code: 'conversions_without_value' },
      ],
    }))

    expect(actions[0].kind).toBe('investigate')
    expect(actions[0].confidence).toBe('probable')
  })

  /** A stage nobody reported was never examined; advice about it would be invented outright. */
  it('proposes nothing that stands on a metric nobody reported', () => {
    const actions = recommendedActions(diagnosis({
      findings: [{ stage: 'visit', confidence: 'observed', evidence: ['clicks', 'landing_page_views'], code: 'clicks_not_arriving' }],
      missing: ['landing_page_views'],
    }))

    expect(actions).toEqual([])
  })

  /**
   * «We could not read your account, and here is what to do about it» has no ground under it.
   *
   * The findings are supplied deliberately, which `diagnose()` itself never does — it cannot reach
   * `not_diagnosable` with anything in the list. That invariant belongs to `diagnose`, not to this
   * function's signature, and this layer takes a `Diagnosis` from whoever calls it. Asserting the
   * contract at the boundary is what makes the state check load-bearing rather than decorative.
   */
  it('proposes nothing when the account could not be examined', () => {
    const actions = recommendedActions(diagnosis({
      state: 'not_diagnosable',
      missing: ['spend'],
      findings: [{ stage: 'value', confidence: 'observed', evidence: ['conversions', 'revenue'], code: 'conversions_without_value' }],
    }))

    expect(actions).toEqual([])
  })

  /** Examined and healthy is a real answer, and it comes with nothing to do. */
  it('proposes nothing when nothing is wrong', () => {
    expect(recommendedActions(diagnosis())).toEqual([])
  })

  /** An unknown finding gets silence, not a confidently generic instruction. */
  it('says nothing about a finding it has not been taught', () => {
    const actions = recommendedActions(diagnosis({
      findings: [{ stage: 'delivery', confidence: 'observed', evidence: ['impressions'], code: 'something_new' }],
    }))

    expect(actions).toEqual([])
  })
})
