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
      command: 'php artisan serve --no-reload --port=8000',
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
