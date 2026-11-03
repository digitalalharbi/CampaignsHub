/**
 * GATE-WK-ENGINE-FAULT-001 — the one line that separates «the server closed the connection» from
 * «the engine's network stack failed».
 *
 * Pure on purpose (no Playwright import): `src/test/failedRequest.test.ts` pins the format under
 * vitest, and `e2e/fixtures.ts` feeds it from `context.on('requestfailed')`.
 *
 * What the fields tell you when WebKit logs `WebKit encountered an internal error` on the entry bundle:
 *   - `timing` all -1 up to `requestStart`  → the request never left the engine (engine-side).
 *   - `connect` present, `response` -1       → the socket was reused or opened and then dropped before
 *                                              a response head arrived; correlate its timestamp with a
 *                                              `close` line in `e2e-logs/preview-*.log` whose idle time
 *                                              is ≈ the server's keepAliveTimeout (server-side race).
 */

/** The three ways the app's own entry module is requested: built bundle, Vite client, dev entry. */
export const ENTRY_BUNDLE_PATTERN = /\/assets\/index-[^/?#]+\.js(?:[?#]|$)|\/@vite\/client(?:[?#]|$)|\/src\/main\.tsx(?:[?#]|$)/

export function isEntryBundleUrl(url: string): boolean {
  return ENTRY_BUNDLE_PATTERN.test(url)
}

/** The subset of Playwright's `request.timing()` this needs; -1 means «did not happen». */
export interface RequestTiming {
  startTime: number
  domainLookupStart: number
  domainLookupEnd: number
  connectStart: number
  connectEnd: number
  requestStart: number
  responseStart: number
  responseEnd: number
}

export interface FailedRequestFacts {
  method: string
  url: string
  resourceType: string
  errorText: string | null | undefined
  timing: RequestTiming
}

export interface FailedRequestContext {
  project: string
  title: string
  /** `Date.now()` at the moment the failure was observed — comparable to the server's connection log. */
  at: number
}

/** Milliseconds relative to the request start, or `-` when the phase never happened. */
function phase(value: number): string {
  return value < 0 ? '-' : String(Math.round(value))
}

export function formatFailedRequest(facts: FailedRequestFacts, ctx: FailedRequestContext): string {
  const t = facts.timing
  const timing = [
    `dns=${phase(t.domainLookupEnd)}`,
    `connect=${phase(t.connectEnd)}`,
    `request=${phase(t.requestStart)}`,
    `response=${phase(t.responseStart)}`,
    `end=${phase(t.responseEnd)}`,
  ].join(' ')
  const entry = isEntryBundleUrl(facts.url) ? 'ENTRY' : 'other'

  return (
    `[wk-evidence] FAILED-REQUEST ${entry} at=${new Date(ctx.at).toISOString()} project=${ctx.project} ` +
    `test=${JSON.stringify(ctx.title)} ${facts.method} ${facts.url} resource=${facts.resourceType} ` +
    `error=${JSON.stringify(facts.errorText ?? 'unknown')} timing_ms[${timing}]`
  )
}
