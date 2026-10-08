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
/*
 * FIFTH occurrence, 2026-09-28 (#569) — and the first to exceed this ceiling.
 *
 * `/agency/tasks` «rendered nothing» on webkit while chromium and firefox passed the same commit,
 * 620 tests passing around it. Reproduced per the protocol above before anything was re-run, and the
 * evidence says the page is not the subject: `git diff 60f4c1f7 33cf861e -- src/features/tasks
 * src/app` is EMPTY — the route's code is byte-identical to the commit whose webkit gate was green
 * an hour earlier — and the same spec run locally on webkit passed all eighteen cases, the walk
 * among them, in 1.1 minutes.
 *
 * What is new is that forty-five seconds was not enough. The number above was chosen to be generous
 * rather than tuned to the observed edge, and the edge has now passed it. Raising it again would be
 * tuning to the last failure, which this file already argues against — so it stays, and the finding
 * is recorded where the next reader of a red webkit gate will meet it.
 *
 * The structural fix was never a bigger number. The gate USED TO serve a dev server, so a first
 * paint included an on-demand transform of the route's module graph, and `/agency/tasks` is the
 * largest graph in the rail — which is why the same route, on the slowest browser, was the one that
 * kept timing out. #572 removed it: the gate builds once and both servers run `vite preview` over
 * the result, so there is no first visit left to be slow. Measured on the same walk that timed out
 * at forty-five seconds here — webkit 6.5s from a deleted `dist`.
 *
 * This ceiling stays anyway. It costs nothing when a page paints in seven seconds, and it is the
 * thing that would absorb a slow runner rather than a slow app if one ever appears again.
 */
/*
 * SIXTH occurrence, 2026-10-04 (#586) — and the protocol followed before anything was re-run.
 *
 * Two webkit cases failed while chromium, firefox, backend, frontend and image all passed the same
 * commit: `/agency/alerts` «never rendered», and the English rail walk's `main` never appearing.
 * Both were reproduced locally on webkit FIRST, per the rule above, and both passed —
 * `cross-product-consistency` 12 of 12, `agency-portal` 10 of 10 with the failing case taking 20.8
 * seconds on an idle laptop.
 *
 * Two more facts say the page is not the subject. The CI run's own backend log for that window
 * contains no error of any kind — only mail debug lines — so nothing server-side refused. And the
 * change under test touches `features/reports` and `features/branding` only; the rail surfaces that
 * timed out import none of it.
 *
 * So the ceiling is not raised again. 20.8 seconds idle against a 45-second bound is a page that
 * paints fine and a runner that was two and a half times slower than a laptop — which is the one
 * thing every occurrence here has ever measured. The number stays, the evidence is recorded, and the
 * job is re-run.
 */
/*
 * SEVENTH occurrence, 2026-10-06 (#596) — and the protocol followed before anything was re-run.
 *
 * `/agency/settings` «never rendered» on webkit at 47.4s while chromium, firefox, backend, frontend
 * and image all passed the same commit. 632 of 633 cases passed around it, in 38.9 minutes on one
 * worker — the shape of a loaded runner rather than of a broken page.
 *
 * Three facts, gathered before the re-run:
 *
 *   REPRODUCED FIRST. `cross-product-consistency` on webkit locally: 12 of 12 in 1.4 minutes, the
 *   failing case among them.
 *
 *   THE ROUTE IS UNCHANGED. `git diff origin/main..HEAD -- src/features/settings src/app
 *   src/layouts` is EMPTY. The page is byte-identical to the commit whose webkit gate was green, and
 *   this branch's diff reaches only content, analytics, campaigns and reports.
 *
 *   NOTHING REFUSED. The job's own log carries no 500, no 502 and no exception — its three matches
 *   for those strings are a Vite chunk-size warning and two test NUMBERS.
 *
 * The ceiling stays where it is, for the reason this file already gives: a number tuned to the last
 * failure is a number that fails again on a busier day, and a gate that needs a retry teaches its
 * readers that red means «try again». What is owed is the record, which is this.
 */
export const RAIL_PAINT_TIMEOUT = 45_000

/** Per-path budget for a walk that visits many routes in one test, on the same reasoning. */
export const RAIL_PATH_BUDGET = 15_000

/**
 * Playwright's own default test budget, named so the arithmetic below can be checked against it.
 *
 * This is the number that made the ceiling above unreachable. `expect(...).toBeVisible({ timeout:
 * RAIL_PAINT_TIMEOUT })` asks for forty-five seconds inside a test the runner stops at thirty, so a
 * walk that hand-rolled its own loop and forgot `test.setTimeout` could never spend what this file
 * argues for. It died at 30.2 seconds on webkit against a wait that had never started counting down
 * from forty-five.
 *
 * The codebase had already met this contradiction from the other side — `contentLength()`'s docblock
 * records an inner wait of twenty seconds expiring inside a forty-five-second test — and the lesson
 * is the same either way: the inner wait and the outer budget are one decision, and a file that sets
 * one without the other has set neither.
 */
/*
 * EIGHTH occurrence, 2026-10-09 (#621) — and the FIRST that is not a paint timeout at all.
 *
 * `/agency/tasks did not render` on webkit, chromium and firefox green on the same head. The shape
 * matches the family above, and the cause does not: the browser reported, repeatedly,
 *
 *     WebKit encountered an internal error
 *
 * while loading the JS bundle, the CSS bundle, the manifest and the fonts. That is the browser
 * PROCESS failing to fetch static assets, not a page taking too long to paint — raising the
 * ceiling would have been waiting longer for a fetch that never completes, and would have hidden
 * it.
 *
 * Reproduced before anything was re-run, per the protocol: the whole `portal-audit.spec.ts` was run
 * on clean webkit THREE times on this head, 20 tests each, 60 passes, no internal error and no slow
 * paint. The branch touches `CreativeResultAttribution` and the content surfaces; it reaches no
 * portal, asset-serving or build file.
 *
 * Recorded as runner/WebKit infrastructure evidence. The ceiling is untouched, because this
 * occurrence is not evidence about the ceiling.
 */
export const PLAYWRIGHT_DEFAULT_TIMEOUT = 30_000

/**
 * What a walk over `paths` costs at worst, and never less than one page's paint.
 *
 * Callers no longer compute this: {@see walkRail} claims it, so forgetting is not a thing a test can
 * do. It is exported because the arithmetic is worth checking on its own — a budget that came out
 * below Playwright's default would reintroduce the defect while looking like a fix.
 */
export function railBudget(paths: number): number {
  return RAIL_PAINT_TIMEOUT + Math.max(paths, 1) * RAIL_PATH_BUDGET
}
