/**
 * GATE-WK-001 — how long a rail walk waits for a page to paint, and why it is not 20 seconds.
 *
 * ## The pattern this exists for
 *
 * WebKit under CI load times out during page setup or first paint, never on a product claim. The
 * matrix row records the shape across four separate occurrences: `/agency/tasks` «did not render»
 * or «rendered nothing» on webkit while chromium and firefox pass the same commit, and the identical
 * walk then passes locally on webkit in ten to thirteen seconds. Each one was reproduced locally
 * before being re-run, and not one turned out to be a defect in the page.
 *
 * ## Why a longer wait is not a weaker test
 *
 * The assertion is «this page renders at all». A route that fell through to a not-found, a portal
 * refusal, or an empty shell still fails — at 45 seconds exactly as it did at 20. What changes is
 * only how long a correct page is allowed to take on a runner three times slower than a laptop,
 * which is the one thing these failures have ever measured.
 *
 * Re-running the job each time hides the cost rather than removing it: a gate that needs a retry to
 * go green teaches its readers that red means «try again», and that is how a real failure gets
 * waved through.
 *
 * The number is deliberately generous rather than tuned to the observed edge. A ceiling set just
 * above the last failure is a ceiling that fails again on a busier day.
 */
export const RAIL_PAINT_TIMEOUT = 45_000

/** Per-path budget for a walk that visits many routes in one test, on the same reasoning. */
export const RAIL_PATH_BUDGET = 15_000
