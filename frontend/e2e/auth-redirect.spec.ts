import { expect, test } from '@playwright/test'
import { signIn } from './helpers'

/*
 * The intended path is carried UNPREFIXED (LOGIN-002).
 *
 * These once expected `%2Fapp%2F…`, because the legacy root redirect prefixed every path with the
 * advertiser portal before anyone had signed in. That guess then stuck: after signing in, an agency
 * operator was delivered to the advertiser portal's copy of the page and refused. Nobody has a
 * portal while they are a guest, so none is chosen — the path is resolved into the right portal
 * once the user is known.
 */

/**
 * Post-login redirect contract (G-005 / R2.5): a guest hitting a protected route is bounced to
 * `/login?redirect=<intended>`, and after a successful login lands on that intended page — not `/`.
 * Runs as a guest (no stored auth state) so the guard actually fires.
 *
 * The pre-ADR-0002 paths (`/analytics`, `/dashboard`, `/reports`) are asserted deliberately: a guest
 * following an old bookmark should be redirected to its `/app/*` home AND have that carried through
 * login, so the two mechanisms compose rather than one swallowing the other.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('guest hitting a protected route is redirected to /login with the intended path', async ({ page }) => {
  await page.goto('/analytics')
  await expect(page).toHaveURL(/\/login\?redirect=%2Fanalytics/)
  // The login form is present (not the router's default error screen). The identifier field takes an
  // email OR a phone now (LOGIN-UNIFIED-001), so it is not an `input[type=email]` any more — asserting
  // on the step is both truer and stable across that kind of change.
  await expect(page.getByTestId('login-identify')).toBeVisible()
})

test('the homepage at / is public (no auth redirect)', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveURL(/\/$/)
  // The v5 marketing hero renders; we are NOT bounced to login.
  await expect(page.getByRole('heading', { level: 1 })).toContainText(/الإعلانية المدفوعة|paid ad/i)
})

test('a guest hitting /dashboard is redirected to login with that intended path', async ({ page }) => {
  await page.goto('/dashboard')
  await expect(page).toHaveURL(/\/login\?redirect=%2Fdashboard/)
})

test('after login, the user lands on the originally requested page, not the dashboard', async ({ page }) => {
  await page.goto('/reports')
  await expect(page).toHaveURL(/\/login\?redirect=%2Freports/)

  // The ADVERTISER account, because `/app/reports` is the advertiser portal's page (LOGIN-002).
  // Signing in as the agency owner here tested "land on the requested page" with an account that
  // does not hold that portal — which passed only while /app had no guard.
  await signIn(page, 'owner@demo-company.local', 'password')

  await expect(page).toHaveURL(/\/app\/reports$/)
  await expect(page).not.toHaveURL(/\/login/)
})
