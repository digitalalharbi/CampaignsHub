/**
 * The one place that says where the gate runs and which database it touches (E2E-ISO-001).
 *
 * Imported by `playwright.config.ts`, by `global-setup.ts` and by the specs' API helpers, so the
 * origin a spec sends as `Origin`/`Referer` cannot drift from the origin Sanctum is told to accept.
 * That pair silently decides whether a request is session-authenticated at all: a mismatch does not
 * error, it returns 401, which reads as an authentication defect and is not one.
 */

/** Deliberately NOT :5173 and :8000 — see `global-setup.ts` for why sharing them defeats isolation. */
export const E2E_FRONTEND_PORT = 5273
export const E2E_BACKEND_PORT = 8100

export const E2E_ORIGIN = `http://localhost:${E2E_FRONTEND_PORT}`
/** A second frontend, for the report print browser alone — see `REPORTS_PRINT_APP_URL`. */
export const E2E_PRINT_PORT = 5373
export const E2E_PRINT_ORIGIN = `http://localhost:${E2E_PRINT_PORT}`
export const E2E_API_TARGET = `http://127.0.0.1:${E2E_BACKEND_PORT}`

/**
 * Handed to `php artisan` — for the prepare step and for the server the gate talks to.
 *
 * Laravel's env repository is immutable, so anything set here wins over `backend/.env` and no
 * `.env.e2e` file has to exist on disk. Nothing secret belongs in this object: it is committed, and
 * every value in it is a local address or a database name.
 */
export const E2E_BACKEND_ENV: Record<string, string> = {
  /*
   * `APP_ENV` deliberately stays `local`.
   *
   * The safety property this unit needs is the DATABASE NAME, and `e2e:prepare` enforces that
   * directly by refusing any database not ending in `_e2e` — it does not rely on the environment
   * name for anything. Meanwhile `ConditionalThrottle` exempts `local` alone, and the whole suite was
   * written against an unthrottled server: at `workers: 1` a three-browser run replays the sign-in
   * and registration endpoints hundreds of times, and turning the limiter on would produce 429s that
   * are an artefact of running the tests rather than a defect in the product. Whether the gate should
   * exercise rate limiting is a real question, but it is a change to what the gate MEANS and belongs
   * in its own unit, not smuggled in beside a database rename.
   */
  APP_ENV: 'local',
  DB_DATABASE: 'mediabuying_e2e',
  APP_URL: E2E_API_TARGET,
  // Sessions, cache and queues all live in Redis and are all keyed by this prefix. Without its own,
  // the gate's queue worker would drain jobs a developer's own stack had queued, and vice versa —
  // isolation of the database alone would leave the two runs sharing everything else.
  REDIS_PREFIX: 'campaignshub-e2e-',
  // Sanctum treats a request as stateful only when its Origin matches this list; the gate's frontend
  // is on 5273, so the default 5173 entry from `.env` would refuse every authenticated call.
  SANCTUM_STATEFUL_DOMAINS: `localhost:${E2E_FRONTEND_PORT},127.0.0.1:${E2E_FRONTEND_PORT}`,
  SESSION_DOMAIN: 'null',
  /*
   * Where the backend sends a customer BACK to — the gate's frontend, not the developer's.
   *
   * `backend/.env` says `http://localhost:5173`, and every absolute return address the product
   * builds reads it: the sandbox gateway's redirect after a confirmed payment, the applicant's
   * notification links, the OAuth landing. Before E2E-ISO-001 the gate shared that port and it did
   * not matter. Moving the gate to :5273 left those redirects pointing at whatever is listening on
   * :5173 — which is a DIFFERENT frontend, talking to a DIFFERENT backend and a DIFFERENT database,
   * where the application that just paid does not exist.
   *
   * That is not a subtle failure: the whole paid-registration journey ends on a status page that
   * cannot find its own registration. It presents as a hang rather than an error, because the page
   * had no branch for a failed query — fixed in `AccountStatusPage`, and the redirect fixed here.
   * With nothing at all on :5173 it fails differently again (a connection error), which is why this
   * belongs beside `SANCTUM_STATEFUL_DOMAINS` above: both are addresses the gate has to own.
   */
  FRONTEND_URL: E2E_ORIGIN,
  /*
   * The same mistake in a second place, and it has to be set separately because it is a second key.
   *
   * `ChromiumPdfRenderer` mints a print token, then drives a headless browser to
   * `{REPORTS_PRINT_APP_URL}/reports/print/{token}` and prints what it finds. Left at its :5173
   * default the gate's renderer opened the DEVELOPER's frontend, which asks the DEVELOPER's backend
   * about a token that only exists in `mediabuying_e2e` — so the print route answered
   * «report_error / print route reported a data error» and the export was correctly marked FAILED.
   * The exporter was doing exactly the right thing; it was pointed at the wrong installation.
   */
  /*
   * …and it prints from a frontend OF ITS OWN — GATE-WK-001.
   *
   * Pointed at `E2E_ORIGIN` the print browser pulled the whole SPA module graph out of the SAME Vite
   * dev server the tests were driving, at a moment nothing coordinates. That is the four-failure
   * webkit hang exactly: only in long runs (a report spec has to have queued a job first), never in
   * isolation, moving between sibling tests between runs, and always a `page.goto` that never sees
   * `load` because the server it is waiting on is busy serving somebody else's print job.
   *
   * Switching Chromium printing OFF would have removed the contention and taken real coverage with
   * it — `report-pdf-download.spec.ts` asserts the downloaded Arabic PDF is a valid CHROMIUM file,
   * and it fails against the Dompdf fallback. Proven, not assumed: that run went 4 failures → 2, and
   * this was one of the two. So the print browser keeps Chromium and gets its own server instead.
   */
  REPORTS_PRINT_APP_URL: E2E_PRINT_ORIGIN,
  // The gate walks the paid-signup journey, which needs a provider it can actually settle against.
  SUBSCRIPTION_PROVIDER: 'sandbox',
}
