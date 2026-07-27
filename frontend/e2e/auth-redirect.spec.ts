import { expect, test } from '@playwright/test'

/**
 * Post-login redirect contract (G-005 / R2.5): a guest hitting a protected route is bounced to
 * `/login?redirect=<intended>`, and after a successful login lands on that intended page — not `/`.
 * Runs as a guest (no stored auth state) so the guard actually fires.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('guest hitting a protected route is redirected to /login with the intended path', async ({ page }) => {
  await page.goto('/analytics')
  await expect(page).toHaveURL(/\/login\?redirect=%2Fanalytics/)
  // The login form is present (not the router's default error screen).
  await expect(page.locator('input[type="email"]')).toBeVisible()
})

test('the homepage at / is public (no auth redirect)', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveURL(/\/$/)
  // Marketing hero CTA is present; we are NOT bounced to login.
  await expect(page.getByRole('link', { name: /ابدأ إدارة حملاتك|Start managing campaigns/ }).first()).toBeVisible()
})

test('a guest hitting /dashboard is redirected to login with that intended path', async ({ page }) => {
  await page.goto('/dashboard')
  await expect(page).toHaveURL(/\/login\?redirect=%2Fdashboard/)
})

test('after login, the user lands on the originally requested page, not the dashboard', async ({ page }) => {
  await page.goto('/reports')
  await expect(page).toHaveURL(/\/login\?redirect=%2Freports/)

  await page.locator('input[type="email"]').fill('owner@demo-agency.local')
  await page.locator('input[type="password"]').fill('password')
  await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()

  await expect(page).toHaveURL(/\/reports$/)
  await expect(page).not.toHaveURL(/\/login/)
})
