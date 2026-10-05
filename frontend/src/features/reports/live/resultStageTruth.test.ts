import { describe, expect, it } from 'vitest'
import { stageCount } from './liveMetrics'
import type { LivePayload } from '../api'

/**
 * RESULT-STAGE-TRUTH-001 — «الإضافة إلى السلة لا تظهر في التقارير».
 *
 * The card read the basket count out of `payload.funnel`, which is a SECTION's data. An executive
 * summary empties that list, and so does an operator who switches the funnel off — so the figure
 * disappeared from reports whose operator had ticked «الإضافات للسلة» by name. A control that
 * shortens a document was deleting a measurement.
 *
 * `result_stages` belongs to no section and carries `reported` per stage, which is the distinction
 * the totals pivot cannot hold: it coalesces every stage to zero, so «never counted» and «counted
 * none» arrive as the same number.
 */
const payload = (over: Partial<LivePayload>): LivePayload => ({ funnel: [], ...over } as unknown as LivePayload)

const stage = (s: string, count: number | null, reported: boolean) =>
  ({ stage: s, label: s, reported, count, from_stage: null, step_rate: null, cost_per: null })

describe('reading a funnel stage as a figure', () => {
  /** The case the owner reported: a summary, whose funnel list is empty by design. */
  it('reads the stage when the funnel section has been dropped', () => {
    const p = payload({ funnel: [], result_stages: { add_to_cart: { count: 42, reported: true } } })

    expect(stageCount(p, 'add_to_cart')).toBe(42)
  })

  /** A stage nobody reported is unavailable — never zero. */
  it('never turns an unreported stage into a zero', () => {
    const p = payload({ funnel: [], result_stages: { add_to_cart: { count: null, reported: false } } })

    expect(stageCount(p, 'add_to_cart')).toBeUndefined()
  })

  /** A measured zero is a measurement and survives as one. */
  it('keeps a measured zero', () => {
    const p = payload({ funnel: [], result_stages: { add_to_cart: { count: 0, reported: true } } })

    expect(stageCount(p, 'add_to_cart')).toBe(0)
  })

  /**
   * A link created before the readings existed still renders.
   *
   * Its payload carries a funnel and no `result_stages`, and the detailed form carries both — so the
   * fallback is not dead code, it is every link already in somebody's inbox.
   */
  it('falls back to the funnel for a link made before the readings existed', () => {
    const p = payload({ funnel: [stage('add_to_cart', 17, true)] })

    expect(stageCount(p, 'add_to_cart')).toBe(17)
  })

  it('respects the funnel’s own reported flag in that fallback', () => {
    const p = payload({ funnel: [stage('add_to_cart', null, false)] })

    expect(stageCount(p, 'add_to_cart')).toBeUndefined()
  })

  /** Nothing is invented for a report with no such stage at all. */
  it('says nothing about a stage the report does not carry', () => {
    expect(stageCount(payload({ funnel: [] }), 'add_to_cart')).toBeUndefined()
  })
})
