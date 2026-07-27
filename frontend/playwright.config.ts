import { defineConfig, devices } from '@playwright/test'

/**
 * E2E config for the campaigns full-path spec.
 *
 * Prereqs (both must be running):
 *   1. Backend:  cd backend  && php artisan migrate:fresh --seed && php artisan serve   (:8000)
 *   2. Frontend: cd frontend && npm run dev                                              (:5173)
 * Then:  npx playwright test
 *
 * The dev server proxies /api and /sanctum to :8000, so the SPA and API share an origin here.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
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
    // Cross-browser acceptance (Firefox + WebKit). Reuse the same seeded storage state from setup.
    // Run a subset with e.g. `npx playwright test --project=firefox --project=webkit e2e/auth-*.spec.ts`.
    { name: 'firefox', use: { ...devices['Desktop Firefox'] }, dependencies: ['setup'] },
    { name: 'webkit', use: { ...devices['Desktop Safari'] }, dependencies: ['setup'] },
  ],
  // Reuse the already-running dev server (do not auto-start — the backend must be up too).
  webServer: {
    command: 'npm run dev',
    url: 'http://localhost:5173',
    reuseExistingServer: true,
    timeout: 60_000,
  },
})
