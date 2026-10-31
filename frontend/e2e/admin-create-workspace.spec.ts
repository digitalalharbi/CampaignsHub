import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * SUPERADMIN-WORKSPACE-001 — a workspace is created from the console and its owner gets a one-time link, against the real API.
 *
 * The company name is unique per run and per browser project, so the three browsers never meet on one slug. The owner's link is
 * opened in a fresh context and shows the invitation — proof the link the console handed over is the one the product accepts.
 */
test.describe('create a workspace from the console', () => {
  test.use({ storageState: AUTH.admin })

  test('creates the workspace and hands over a working one-time owner link', async ({ page, browser }, info) => {
    const suffix = `${info.project.name}-${Date.now()}`
    await page.goto('/admin/tenants')
    await switchToEnglish(page)

    await page.getByTestId('add-workspace').click()
    await page.getByTestId('cw-company').fill(`E2E Agency ${suffix}`)
    await page.getByTestId('cw-workspace').fill('E2E HQ')
    await page.getByTestId('cw-email').fill(`owner-${suffix}@e2e.test`.toLowerCase())
    await page.getByTestId('cw-submit').click()

    await expect(page.getByTestId('create-workspace-result')).toContainText('was created')
    const link = (await page.getByTestId('owner-invitation-link').textContent())?.trim() ?? ''
    expect(link).toContain('/invite/accept?token=')

    const fresh = await browser.newContext()
    const owner = await fresh.newPage()
    await owner.goto(new URL(link).pathname + new URL(link).search)
    await expect(owner.locator('body')).toContainText(new RegExp(`E2E Agency ${suffix}`, 'i'))
    await fresh.close()
  })
})
