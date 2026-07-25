import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * Core campaigns paths (authenticated as the demo owner via reused storage state).
 * All external/platform data is Sandbox (Demo), never production.
 */
test.use({ storageState: AUTH.owner })

test('create a unified campaign and see it in the list', async ({ page }) => {
  await page.goto('/campaigns')
  await switchToEnglish(page)

  // A demo project is auto-selected by the switcher.
  await page.getByRole('button', { name: /New campaign|حملة جديدة/ }).click()

  const name = `E2E Campaign ${Date.now()}`
  await page.getByLabel(/Campaign name|اسم الحملة/).fill(name)
  await page.getByRole('button', { name: /^Save$|^حفظ$/ }).click()

  // Refetched from the API — the new campaign appears in the table.
  await expect(page.getByText(name)).toBeVisible()
})

test('open a campaign detail and switch tabs', async ({ page }) => {
  await page.goto('/campaigns')
  await switchToEnglish(page)

  await page.getByRole('button', { name: /^Open$|^فتح$/ }).first().click()
  await expect(page).toHaveURL(/\/campaigns\/[^/]+\/[^/]+$/)

  // Tabs render; Performance shows the honest "pending C3" empty state (not fake data).
  await page.getByRole('tab', { name: /Performance|الأداء/ }).click()
  await expect(page.getByText(/metrics layer|طبقة المقاييس/)).toBeVisible()

  // Linked tab lists linked external campaigns (or an empty state).
  await page.getByRole('tab', { name: /Linked campaigns|الحملات المرتبطة/ }).click()
})

test('link-external modal opens and labels sandbox data as Demo', async ({ page }) => {
  await page.goto('/campaigns')
  await switchToEnglish(page)
  await page.getByRole('button', { name: /^Open$|^فتح$/ }).first().click()

  await page.getByRole('tab', { name: /Linked campaigns|الحملات المرتبطة/ }).click()
  await page.getByRole('button', { name: /Link external campaign|ربط حملة خارجية/ }).click()

  // The modal makes clear this is demo data, not a production connection.
  await expect(page.getByText(/Sandbox data is demo|بيانات Sandbox تجريبية/)).toBeVisible()
})
