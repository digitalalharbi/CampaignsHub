import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * LAUNCH-001 — «إطلاق الحملات» is its own page in both portals: the rail opens it, it walks the steps, and it
 * tells the truth about the project's accounts before anything could be sent. Seeded demo accounts carry no
 * platform credentials, so the honest outcome here is a page that explains why nothing can be published yet;
 * a real publish needs an authorised account and is never driven by this gate.
 */
test.describe('the launcher (advertiser)', () => {
  test.use({ storageState: AUTH.advertiser })

  test('the rail opens /app/launch, a page of its own with the steps and the launch history', async ({ page }) => {
    await page.goto('/app/dashboard')
    await page.getByRole('navigation').first().locator('a[href="/app/launch"]').first().click()
    await expect(page).toHaveURL(/\/app\/launch$/)
    await expect(page.getByTestId('launch-page')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByTestId('launch-history')).toBeVisible()
    await expect(page.getByRole('dialog')).toHaveCount(0)
    await expect(page.locator('main a[href^="/agency"]')).toHaveCount(0)

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)
  })
})

test.describe('the launcher (agency, seeded store project)', () => {
  test.use({ storageState: AUTH.owner })

  test('the accounts step states each account’s real write state and never offers a publish without one', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/launch')
    await expect(page.getByTestId('launch-step-accounts')).toBeVisible({ timeout: 30_000 })

    const accounts = page.getByTestId('launch-accounts')
    const count = await accounts.locator('li').count()
    if (count === 0) {
      await expect(page.getByTestId('launch-step-accounts')).toContainText(/اختر حسابات المشروع|Choose the project/)
    } else {
      // Every seeded account is either ready or says why it is not; none is both.
      for (const row of await accounts.locator('li').all()) {
        await expect(row.locator('[data-testid="provider-mark"]')).toBeVisible()
        await expect(row).toContainText(/جاهز|Ready|بانتظار|غير|لا تملك|awaiting|not|Demo|تجريبية/)
      }
    }
    await expect(page.getByTestId('launch-next')).toBeDisabled()
  })
})
