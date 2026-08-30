import { defineConfig, devices } from '@playwright/test'
import { E2E_API_TARGET, E2E_BACKEND_ENV, E2E_BACKEND_PORT, E2E_FRONTEND_PORT, E2E_ORIGIN, E2E_PRINT_ORIGIN, E2E_PRINT_PORT } from './e2e/env'

/**
 * E2E config. Self-contained: Playwright starts BOTH servers itself.
 *
 *   - Backend  (:8000): `php artisan serve --no-reload` with PHP_CLI_SERVER_WORKERS=4 so the built-in server
 *     handles concurrent requests. Single-worker serving was the root cause of write-heavy specs (signup,
 *     link/move) intermittently timing out under the full 3-browser load — this fixes it at the source, not
 *     with test retries.
 *   - Frontend (:5173): `npm run dev`, which proxies /api and /sanctum to :8000 (shared origin).
 *
 * Visual-regression specs (tagged @visual) run on CHROMIUM ONLY — cross-browser pixel diffs are noisy — so
 * they are excluded from firefox/webkit via grepInvert (never scheduled there, so never counted as skipped).
 */
/**
 * `evidence.spec.ts` is a camera, not a gate — it photographs the product and asserts nothing.
 *
 * It is excluded from every ordinary run, including CI, because 28 screenshots would add minutes to
 * a gate that exists to answer a different question. Run it deliberately:
 *
 *   EVIDENCE=1 npx playwright test evidence.spec.ts --project=chromium
 */
const EVIDENCE_OUT = process.env.EVIDENCE === '1' ? /$^/ : /@evidence/

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0, // root causes are fixed, not masked; a genuine flake should fail the gate
  workers: 1,
  reporter: [['list']],
  /*
   * Creates and resets `mediabuying_e2e` before either server starts (E2E-ISO-001).
   *
   * The gate used to serve the DEVELOPMENT database, so every run left a whole registration journey
   * behind; the residue reached 485 tenants and 2105 tasks and made live review of any list
   * meaningless. See `e2e/global-setup.ts` for the full account.
   */
  /*
   * Seeds the isolated database before either server starts (E2E-ISO-001).
   *
   * It runs ONCE per invocation, which is why `npm run gate` invokes Playwright once per browser
   * rather than once for all three (E2E-ISO-002). A single invocation gives chromium the seed,
   * firefox the seed plus everything chromium created, and webkit both — three browsers running
   * three different suites and reporting one number.
   *
   * `npx playwright test` still works and is the right tool for one spec or one project. It is not
   * the gate.
   */
  globalSetup: './e2e/global-setup.ts',
  use: {
    baseURL: E2E_ORIGIN,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    locale: 'en-US',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    { name: 'chromium', use: { ...devices['Desktop Chrome'] }, dependencies: ['setup'], grepInvert: EVIDENCE_OUT },
    // Cross-browser acceptance. Visual-baseline specs are chromium-only, so exclude @visual here.
    { name: 'firefox', use: { ...devices['Desktop Firefox'] }, dependencies: ['setup'], grepInvert: new RegExp(`@visual|${EVIDENCE_OUT.source}`) },
    { name: 'webkit', use: { ...devices['Desktop Safari'] }, dependencies: ['setup'], grepInvert: new RegExp(`@visual|${EVIDENCE_OUT.source}`) },
  ],
  webServer: [
    {
      /*
       * Serve + a queue worker: report generation (GenerateReportJob) is queued, so the PDF/XLSX export E2E
       * needs a worker draining it. The worker runs as a background child; serve owns the health URL.
       *
       * `serve`'s STDOUT is redirected to a file, and that redirect is load-bearing.
       *
       * Laravel's dev-server router (`Illuminate/Foundation/resources/server.php`, line 21) writes one
       * request line to `php://stdout` for EVERY request, unconditionally — there is no flag or env var
       * to turn it off. Over a 500-test three-browser run that is tens of thousands of writes into a
       * pipe. When the reader on the other end stalls, the write fails with EPIPE, PHP emits
       * `Notice: file_put_contents(): ... Broken pipe`, and because the CLI server has display_errors on,
       * that notice is prepended TO THE RESPONSE BODY. Every JSON response after that point is malformed,
       * `res.json().data` comes back null, and the suite collapses with `Cannot read properties of null`
       * — dozens of failures that look like application defects and are not.
       *
       * That is exactly how it presented: chromium passed clean, then firefox degraded, then webkit, with
       * the failures spreading as the run went on. Redirecting to a file guarantees a reader that never
       * stalls. STDERR stays on the pipe, so genuine startup errors still surface in the Playwright output.
       */
      /*
       * `trap "kill 0"` takes the whole process group down with the shell.
       *
       * Without it, `sh -c "worker & serve"` leaves orphans behind whenever a run is interrupted:
       * Playwright signals the shell, the shell exits, and the two children carry on holding their
       * ports. The next run then finds something already listening, `reuseExistingServer` adopts it,
       * and the suite talks to a half-dead server from a previous run — which is how a login came back
       * 502 (Vite proxying to a backend that was no longer there) and, on another attempt, 401.
       * Both looked like authentication defects and neither was.
       */
      command: `sh -c 'trap "kill 0" EXIT INT TERM; php artisan queue:work --queue=reports,default --tries=3 --sleep=1 --quiet & php artisan serve --no-reload --port=${E2E_BACKEND_PORT} >> storage/logs/serve-requests.log'`,
      cwd: '../backend',
      url: `${E2E_API_TARGET}/up`,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
      /*
       * The gate's own database, Redis keyspace and Sanctum origin, passed through the process
       * environment rather than a checked-in `.env.e2e`.
       *
       * Laravel's env repository is immutable, so a variable already present in the environment wins
       * over `.env` — which is what makes this work with no extra file on disk and nothing secret in
       * the repository. `e2e/global-setup.ts` has already created and seeded that database.
       */
      env: { ...E2E_BACKEND_ENV, PHP_CLI_SERVER_WORKERS: '4' },
    },
    {
      /*
       * :5273, not :5173 — and that is load-bearing, not cosmetic.
       *
       * `reuseExistingServer` is on outside CI. On the shared port, a dev stack left running against
       * the DEVELOPMENT database would simply be adopted by the gate, and every isolation measure
       * above would be bypassed silently, with a green run to show for it. A port nothing else uses
       * makes that impossible instead of merely unlikely.
       */
      command: `npm run dev -- --port ${E2E_FRONTEND_PORT}`,
      url: E2E_ORIGIN,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
      env: { VITE_API_TARGET: E2E_API_TARGET },
    },
    {
      /*
       * A SECOND frontend, for the report print browser alone — GATE-WK-001.
       *
       * `ChromiumPdfRenderer` drives a headless browser at `{REPORTS_PRINT_APP_URL}/reports/print/…`.
       * Pointed at the tests' own dev server it pulled the whole SPA module graph out of it at a
       * moment nothing coordinates, and a `page.goto` waiting on that server never saw `load` — four
       * webkit failures, only in a full run, moving between sibling tests, and reproducing on a
       * stashed tree, which is what proved it was never the product.
       *
       * Two servers rather than one switch: switching Chromium printing off also removes the proof
       * that the exported Arabic PDF is a real Chromium file, which this product had to fix once.
       */
      command: `npm run dev -- --port ${E2E_PRINT_PORT}`,
      url: E2E_PRINT_ORIGIN,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
      /*
       * Its OWN dependency cache — GATE-VITE-001.
       *
       * Two Vite servers sharing `node_modules/.vite` is not a tidiness question: each runs its own
       * optimizer, and either one re-optimizing rewrites the directory and bumps the hash every
       * client URL carries. The other server's in-flight module requests then 404/504, which reaches
       * the browser as «Load failed», the proxy as 502, and a `page.goto` as a navigation that never
       * fires `load`.
       */
      env: { VITE_API_TARGET: E2E_API_TARGET, VITE_CACHE_DIR: 'node_modules/.vite-print' },
    },
  ],
})
