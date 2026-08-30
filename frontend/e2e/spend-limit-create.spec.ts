import { expect, test } from '@playwright/test'
import { signIn } from './helpers'

/**
 * BUDGET-GOVERNANCE-001 — an operator sets a limit, and the page shows it being watched.
 *
 * The unit test proves the form posts what was filled in. This proves the round trip: the limit
 * reaches the database, the governor reads it against today, and the figures come back on the page
 * — which is the half a mocked test cannot see, and the half that was missing from the product.
 */
test('an operator creates a spend limit and the page prices it', async ({ page }) => {
  await signIn(page, 'advertiser@campaignshub.io', 'password')
  await page.waitForURL((url) => !/\/login/.test(url.pathname), { timeout: 30_000 })
  await page.goto('/app/spend-limits')

  await expect(page.getByTestId('spend-limits-enforcement')).toBeVisible({ timeout: 30_000 })
  await expect(page.getByTestId('spend-limits-enforcement')).toContainText(/does not stop delivery|لا يوقف عرض الإعلانات/)

  await page.getByTestId('spend-limit-new').click()
  await expect(page.getByTestId('spend-limit-form')).toBeVisible()

  await page.getByTestId('spend-limit-amount').fill('25000')

  const posted = page.waitForResponse((r) => r.url().includes('/spend-limits') && r.request().method() === 'POST')
  await page.getByTestId('spend-limit-submit').click()
  const response = await posted
  expect(response.status(), `the server refused the limit: ${await response.text()}`).toBe(201)

  // The row the server computed, not the one the form sent: amount, and a state it decided.
  const card = page.locator('[data-testid^="spend-limit-"][data-testid$="-amount"]').first()
  await expect(card).toBeVisible({ timeout: 30_000 })
  await expect(card).toContainText('25K')

  const state = page.locator('[data-testid^="spend-limit-"][data-testid$="-state"]').first()
  await expect(state).toBeVisible()
})
