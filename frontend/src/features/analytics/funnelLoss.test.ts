import { describe, expect, it } from 'vitest'
import { lossBefore } from './AnalyticsPage'

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the loss between stages, and the four times there is none to state.
 *
 * «FUNNEL → measurable stage funnel with loss between stages.» The bars were already proportional
 * and the drop-off was a caption reading «step 2%» — a rate the reader had to turn into a
 * subtraction to learn what actually left.
 *
 * Everything below is about the cases where a loss must NOT be drawn, because a funnel that invents
 * one is worse than a funnel that shows none: it sends an operator to fix a step that was never the
 * problem.
 */
const rows = (...counts: Array<number | null>) =>
  counts.map((count, i) => ({ stage: `s${i}`, count }))

describe('the loss between two funnel stages', () => {
  it('is the difference and the share of what reached the stage above', () => {
    const loss = lossBefore(rows(1000, 250), 1)

    expect(loss).toEqual({ lost: 750, share: 0.75, spans: false })
  })

  /** Nothing precedes the first measured stage, so nothing was lost before it. */
  it('is absent for the first stage', () => {
    expect(lossBefore(rows(1000, 250), 0)).toBeNull()
  })

  /** A stage the platform never reported says nothing about what reached it. */
  it('is absent where this stage was not reported', () => {
    expect(lossBefore(rows(1000, null), 1)).toBeNull()
  })

  /**
   * A count that went UP is real on a funnel whose stages come from different attribution windows,
   * and it is not a «negative loss» — drawing it as one would print a minus sign in front of a gain.
   */
  it('is absent where the count rose', () => {
    expect(lossBefore(rows(100, 250), 1)).toBeNull()
  })

  /** Nor where nothing moved: a loss of zero is not a finding, it is a full pass-through. */
  it('is absent where nothing was lost', () => {
    expect(lossBefore(rows(500, 500), 1)).toBeNull()
  })

  /**
   * When the previous MEASURED stage is not the previous stage, the gap spans an unreported one —
   * and the reader is told, because attributing the whole drop to one step would be a false
   * accusation against that step.
   */
  it('says when the gap spans a stage the platform never reported', () => {
    const loss = lossBefore(rows(1000, null, 200), 2)

    expect(loss).toEqual({ lost: 800, share: 0.8, spans: true })
  })

  /** A share needs a denominator: a previous stage of zero has none. */
  it('states the count but not the share when nothing reached the stage above', () => {
    expect(lossBefore([{ stage: 'a', count: 0 }, { stage: 'b', count: 0 }], 1)).toBeNull()
  })
})
