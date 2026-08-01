import { expect, test as setup } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * Logs in each demo role ONCE and saves its authenticated storage state, so the specs reuse the
 * session instead of logging in per-test (which trips the backend login throttle). Runs as a
 * dependency before the test project.
 */
const ROLES = [
  { email: 'owner@demo-agency.local', file: AUTH.owner },
  { email: 'analyst@demo-agency.local', file: AUTH.analyst },
  { email: 'viewer@demo-agency.local', file: AUTH.viewer },
  // A real advertiser, for the advertiser portal's own surfaces.
  { email: 'owner@demo-company.local', file: AUTH.advertiser },
  // The platform owner, and the agency side of the influencers portal. Without these two, `/admin`
  // and the operational half of `/influencers` had no signed-in session to audit with.
  { email: 'admin@demo-campaignshub.local', file: AUTH.admin },
  { email: 'talent@demo-agency.local', file: AUTH.talent },
]

for (const role of ROLES) {
  setup(`authenticate ${role.email}`, async ({ page }) => {
    await page.goto('/login')
    await page.locator('input[type="email"]').fill(role.email)
    await page.locator('input[type="password"]').fill('password')
    await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
    await expect(page).not.toHaveURL(/\/login$/)
    await page.context().storageState({ path: role.file })
  })
}
