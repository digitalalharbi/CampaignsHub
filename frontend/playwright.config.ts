import { defineConfig, devices } from '@playwright/test'

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
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0, // root causes are fixed, not masked; a genuine flake should fail the gate
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: 'http://localhost:5173',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    locale: 'en-US',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    { name: 'chromium', use: { ...devices['Desktop Chrome'] }, dependencies: ['setup'] },
    // Cross-browser acceptance. Visual-baseline specs are chromium-only, so exclude @visual here.
    { name: 'firefox', use: { ...devices['Desktop Firefox'] }, dependencies: ['setup'], grepInvert: /@visual/ },
    { name: 'webkit', use: { ...devices['Desktop Safari'] }, dependencies: ['setup'], grepInvert: /@visual/ },
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
      command: 'sh -c "php artisan queue:work --queue=reports,default --tries=3 --sleep=1 --quiet & php artisan serve --no-reload --port=8000 >> storage/logs/serve-requests.log"',
      cwd: '../backend',
      url: 'http://localhost:8000/up',
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
      env: { PHP_CLI_SERVER_WORKERS: '4' },
    },
    {
      command: 'npm run dev',
      url: 'http://localhost:5173',
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
  ],
})
