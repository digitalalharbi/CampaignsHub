import { defineConfig, devices } from '@playwright/test';

/**
 * EMAIL-CLIENT-COMPAT-001 — the email rendering simulations. Not part of the app suite (`playwright.config.ts`): no server, no
 * database, no login. It opens the rendered messages as files:
 *
 *   cd backend && php artisan notifications:preview --out=/some/dir --compat
 *   cd frontend && MAIL_PREVIEW_DIR=/some/dir npx playwright test -c playwright.mail.config.ts
 *
 * Firefox runs when it launches on the machine; chromium and webkit are the required engines.
 */
export default defineConfig({
  testDir: './e2e-mail',
  timeout: 300_000,
  fullyParallel: true,
  workers: 3,
  reporter: [['list']],
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
  ],
});
