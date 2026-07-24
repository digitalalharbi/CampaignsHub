import { expect, test, type Page } from '@playwright/test'

/**
 * Full-path campaigns E2E against the seeded demo tenant (owner@demo-agency.local / password).
 * Run the backend (migrate:fresh --seed + serve) and frontend (npm run dev) first — see
 * playwright.config.ts. All external/platform data here is Sandbox (Demo), never production.
 */

async function login(page: Page) {
  await page.goto('/login')
  // The demo login form is pre-filled with the demo owner; submit it.
  await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
  await expect(page).not.toHaveURL(/\/login$/)
}

async function switchToEnglish(page: Page) {
  // The app defaults to Arabic; flip to English for stable selectors if a toggle is present.
  const toggle = page.getByRole('button', { name: /EN|English|اللغة/ }).first()
  if (await toggle.count()) await toggle.click().catch(() => {})
}

test.beforeEach(async ({ page }) => {
  await login(page)
})

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
