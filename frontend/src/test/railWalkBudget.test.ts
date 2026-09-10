import { describe, expect, it } from 'vitest'
import { PLAYWRIGHT_DEFAULT_TIMEOUT, RAIL_PAINT_TIMEOUT, RAIL_PATH_BUDGET, railBudget } from '../../e2e/railWalkTimeout'

/**
 * GATE-WK-001 — the ceiling `e2e/railWalkTimeout.ts` argues for has to be one a test can reach.
 *
 * ## Why this file is here and not beside the constant
 *
 * It was, and the gate refused it: Playwright's default `testMatch` is `**\/*.@(spec|test).*`, so a
 * `.test.ts` inside `e2e/` is a Playwright spec whatever it imports — all three browsers died on
 * `describe is not a function` before a single page loaded. Vitest owns `src/`, Playwright owns
 * `e2e/`, and the arithmetic is imported across the line rather than moving the constant away from
 * the specs that read it.
 *
 * `the advanced destinations still open` walked three admin routes, each asserted with
 * `{ timeout: RAIL_PAINT_TIMEOUT }` — forty-five seconds — inside a test Playwright stops at thirty,
 * because it hand-rolled its own loop and never called `test.setTimeout`. Its two siblings in the
 * same file do. The webkit leg died at 30.2 seconds against a wait that had never counted down from
 * forty-five, and the report read «rendered nothing», which is a claim about the product.
 *
 * That is the same contradiction `contentLength()` records from the other direction: an inner wait
 * of twenty seconds expiring inside a forty-five-second test. Raising either number alone fixes
 * nothing. The pair is one decision.
 */
describe('a rail walk can spend what its assertions ask for', () => {
  it('gives even a single-page walk more than the runner’s default', () => {
    expect(railBudget(1)).toBeGreaterThan(PLAYWRIGHT_DEFAULT_TIMEOUT)
  })

  /* The exact case that failed: three admin routes under one test. */
  it('covers a paint on every page of a multi-page walk', () => {
    expect(railBudget(3)).toBeGreaterThanOrEqual(RAIL_PAINT_TIMEOUT + 3 * RAIL_PATH_BUDGET)
  })

  /*
   * An empty list is not a free test.
   *
   * A walk built from a rail the page never rendered has no hrefs, and a budget of zero would turn
   * the runner's own default back on — the defect, restored by arithmetic rather than by omission.
   */
  it('never budgets less than one page', () => {
    expect(railBudget(0)).toBe(railBudget(1))
  })
})
