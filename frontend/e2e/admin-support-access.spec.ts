import { expect, test } from '@playwright/test'
import { csrfHeaders, signIn, switchToEnglish } from './helpers'

/**
 * SUPERADMIN-SUPPORT-ACCESS-001 — «الدخول للدعم» end to end, against the real API.
 *
 * Signs in FRESH rather than using the shared admin storage state: a support visit is held in the server session, and the stored
 * admin session is shared by every admin spec running in parallel — entering support there would put all of them in it.
 */
test.describe('support access', () => {
  test.use({ storageState: { cookies: [], origins: [] } })

  test('enters a workspace read-only with a banner, and returns to the console', async ({ page }) => {
    // A fresh sign-in, a language switch, a visit and a return: the 30s default is the budget for the WHOLE test, and the
    // sign-in alone can spend most of it on a cold CI run. Both earlier failures were that budget, not the feature.
    test.setTimeout(120_000)
    await signIn(page, 'admin@campaignshub.io', 'password')
    await page.goto('/admin/tenants')
    await switchToEnglish(page)
    await expect(page.getByTestId('tenant-list')).toBeVisible({ timeout: 30_000 })

    // The list is 25 a page, by name, and other specs open workspaces that sort before «Demo Agency»: find it by name first.
    await page.getByRole('textbox', { name: 'Search tenants' }).fill('Demo Agency')
    await page.getByRole('textbox', { name: 'Search tenants' }).press('Enter')
    await page.getByTestId('support-enter-demo-agency').click()
    await page.getByTestId('support-reason').fill('E2E: checking support access')
    await page.getByTestId('support-password').fill('password')
    await page.getByTestId('support-start').click()

    await page.waitForURL(/\/agency/)
    const banner = page.getByTestId('support-banner')
    await expect(banner).toBeVisible()
    await expect(banner).toContainText(/Demo Agency/)

    // Read-only, decided by the server whatever the page offers.
    // The page's own cookie session, through the API helper every spec uses for unsafe calls.
    const request = page.context().request
    const headers = await csrfHeaders(request)
    const response = await request.post('/api/v1/projects', { headers, data: { name: 'E2E injected' } })
    const refused = { status: response.status(), code: (await response.json()).code }
    expect(refused).toEqual({ status: 403, code: 'support_read_only' })

    await page.getByTestId('support-exit').click()
    await page.waitForURL(/\/admin\/tenants/)
    await expect(page.getByTestId('support-banner')).toHaveCount(0)
  })
})
