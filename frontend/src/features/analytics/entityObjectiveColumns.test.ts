import { describe, expect, it } from 'vitest'
import { ENTITY_SERVED_METRICS, entityColumnIsLabelled, entityColumnLabel, entityColumnPlan, familiesIn } from './entityObjectiveColumns'
import { layoutFor } from './metricCatalog'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 — the entity tables' columns follow what the rows were bought for.
 *
 * The clause this closes: «the rungs are reached through the entity tables' own columns rather than
 * the family's objective-aware ones». A sales ad set was shown frequency and CPM and denied ROAS; an
 * awareness ad set was shown a cost per order it was never bought to produce.
 *
 * The rule is `layoutFor`'s, not a second one — a single family gets its own layout, several fall to
 * the mixed row which withholds cost-per and return on purpose. What is asserted here is the part a
 * TABLE adds: spend leads and is never repeated, only keys this grain actually reports are rendered,
 * and «several families» is stated as its own fact rather than inferred from the absence of one.
 */
const rows = (...objectives: (string | null)[]) => objectives.map((objective) => ({ objective }))

describe('the metric columns follow the rows’ objective', () => {
  it('gives a single family its own figures', () => {
    const sales = entityColumnPlan(rows('sales', 'sales'))

    expect(sales.family).toBe('sales')
    expect(sales.mixed).toBe(false)
    /*
     * What a sales buy is judged on, and was being denied.
     *
     * `purchases`, not `conversions`: the sales layout names the thing that was actually bought, and a
     * first version of this case asserted `conversions` — the generic table's own word — which is the
     * vocabulary this row exists to replace at this grain.
     */
    expect(sales.keys).toContain('roas')
    expect(sales.keys).toContain('purchases')
  })

  it('withholds another objective’s verdict when the rows span several', () => {
    const plan = entityColumnPlan(rows('sales', 'awareness'))

    expect(plan.mixed).toBe(true)
    expect(plan.family).toBeNull()
    /*
     * The refusal `MIXED_LAYOUT` exists for: a CPA across a brand budget and a sales budget divides
     * one objective's money by another objective's events, and a ROAS over a scope half of which was
     * never bought to earn is not a return.
     */
    expect(plan.keys).not.toContain('cpa')
    expect(plan.keys).not.toContain('roas')
  })

  it('shows an awareness table its own figures and no cost per order', () => {
    const plan = entityColumnPlan(rows('awareness'))

    expect(plan.keys).toContain('reach')
    expect(plan.keys).toContain('frequency')
    expect(plan.keys, 'an awareness ad set was priced on orders it was never bought to produce').not.toContain('cpa')
  })

  /** Spend is the operational fact an operator scans a table for, whatever the objective. */
  it('leads with spend on every objective, exactly once', () => {
    for (const objective of ['sales', 'awareness', 'traffic', 'leads', 'app', 'engagement', 'video', null]) {
      const plan = entityColumnPlan(rows(objective))

      expect(plan.keys[0], `«${objective}» does not lead with spend`).toBe('spend')
      expect(plan.keys.filter((k) => k === 'spend'), `«${objective}» repeats spend`).toHaveLength(1)
    }
  })

  /**
   * The false-absence trap this design exists to avoid.
   *
   * The awareness layout names `video_completions` and `video_completion_rate`; the aggregator emits
   * `video_p100` and `completion_rate`. Rendering layout keys blind would draw two permanently empty
   * columns, which is the defect this row's family is about — so every key must be one the endpoint
   * fills, on every objective.
   */
  it('never offers a column this grain cannot fill', () => {
    const served = new Set(ENTITY_SERVED_METRICS)

    for (const objective of ['sales', 'awareness', 'traffic', 'leads', 'app', 'engagement', 'video', 'unknown', null]) {
      for (const key of entityColumnPlan(rows(objective)).keys) {
        expect(served.has(key), `«${key}» is offered for «${objective}» and the endpoint never sends it`).toBe(true)
      }
    }
  })

  /** An unlinked row is «no answer», not the `unknown` family, and must not make a table mixed. */
  it('does not turn one family plus an unlinked row into mixed', () => {
    const plan = entityColumnPlan(rows('sales', null, 'sales'))

    expect(plan.family).toBe('sales')
    expect(plan.mixed).toBe(false)
    expect(plan.keys).toContain('roas')
  })

  it('states no family and no mixture for an empty table', () => {
    const plan = entityColumnPlan([])

    expect(plan.family).toBeNull()
    expect(plan.mixed).toBe(false)
    expect(plan.keys[0]).toBe('spend')
  })

  it('reads families off the rows, ignoring absent ones', () => {
    expect(familiesIn(rows('sales', null, 'awareness', 'sales', ''))).toEqual(['awareness', 'sales'])
  })

  /**
   * The served list is a claim about the ENDPOINT, and a claim can go stale.
   *
   * If a layout ever names a key the aggregator does not emit, the case above silently narrows the
   * column set instead of failing — so this asserts the intersection is not empty for any family, which
   * is what «the filter is doing its job rather than eating everything» looks like.
   */
  it('leaves every family with figures beyond spend', () => {
    for (const family of ['sales', 'awareness', 'traffic', 'leads', 'app', 'engagement', 'video']) {
      const plan = entityColumnPlan(rows(family))
      const layout = layoutFor('all', [family])

      expect(layout.primary.length, `«${family}» has no layout at all`).toBeGreaterThan(0)
      expect(plan.keys.length, `«${family}» was filtered down to spend alone`).toBeGreaterThan(2)
    }
  })

  /**
   * A raw metric key may never reach a column heading.
   *
   * `metricLabel` returns the KEY when `METRIC_LABELS` has no entry, and that map carries none of the
   * cost-per metrics and not `roas` — so the first version of this table printed a lowercase `roas` over
   * a column, the raw-key-on-screen defect this product has already had to fix once. `SPECS` has all of
   * them; this fails if a metric ever reaches a layout without a label in either.
   */
  it('has a real label for every column it can offer, in both languages', () => {
    for (const objective of ['sales', 'awareness', 'traffic', 'leads', 'app', 'engagement', 'video', 'unknown', null]) {
      for (const key of entityColumnPlan(rows(objective)).keys) {
        expect(entityColumnIsLabelled(key), `«${key}» would print as its own key`).toBe(true)

        for (const ar of [true, false]) {
          const label = entityColumnLabel(key, ar)
          expect(label, `«${key}» has no ${ar ? 'Arabic' : 'English'} label`).not.toBe(key)
          expect(label.length, `«${key}» has an empty ${ar ? 'Arabic' : 'English'} label`).toBeGreaterThan(1)
        }
      }
    }
  })

  /** The mixed set is not exempt: its columns are read by the most people. */
  it('has a real label for every mixed column too', () => {
    for (const key of entityColumnPlan(rows('sales', 'awareness')).keys) {
      expect(entityColumnIsLabelled(key), `«${key}» would print as its own key`).toBe(true)
    }
  })
})
