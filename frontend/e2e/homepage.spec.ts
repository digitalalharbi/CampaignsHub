import { expect, test } from '@playwright/test'

/**
 * Public homepage journey (guest). Covers language + theme toggles, the interactive product preview,
 * the journey CTAs into real routes, and mobile navigation. No auth — `/` is public.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('homepage: hero, language/theme, preview, journeys and CTAs into real routes', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveURL(/\/$/)

  // Hero uses the official terminology and shows the three primary actions.
  await expect(page.getByRole('heading', { level: 1 })).toContainText(/الإعلانية المدفوعة|paid advertising/i)
  await expect(page.getByRole('link', { name: /ابدأ إدارة حملاتك|Start managing campaigns/ }).first()).toBeVisible()

  // Language toggle switches copy (ar → en).
  await page.getByRole('button', { name: 'Toggle language' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toContainText(/paid advertising/i)

  // Theme toggle works (no crash, still on the page).
  await page.getByRole('button', { name: 'Toggle theme' }).click()
  await expect(page).toHaveURL(/\/$/)

  // Interactive preview: switching a tab changes the shown metrics.
  await page.getByRole('button', { name: /^Analytics$/ }).click()
  await expect(page.getByText('CTR').first()).toBeVisible()

  // No horizontal overflow.
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)
  expect(overflow).toBe(false)

  // Journey CTA "create account" points to /register (real route).
  await expect(page.getByRole('link', { name: /Create account/ }).first()).toHaveAttribute('href', '/register')

  // Service-request CTA opens the real /requests/new route.
  await page.getByRole('link', { name: /Request a service|Request campaign management/ }).first().click()
  await expect(page).toHaveURL(/\/requests\/new$/)
})

test('homepage mobile (375): no horizontal scroll, hero + CTA reachable', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 })
  await page.goto('/')
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)
  expect(overflow).toBe(false)
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
})

// Visual-regression baseline for the homepage (chromium only — cross-browser pixel diffs are noisy).
test.describe('homepage visual regression', () => {
  test.skip(({ browserName }) => browserName !== 'chromium', 'baselines are chromium-only')

  test('/ light matches baseline', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' })
    await page.goto('/')
    await page.waitForLoadState('networkidle')
    await expect(page).toHaveScreenshot('home-light.png', { fullPage: true, maxDiffPixelRatio: 0.02 })
  })

  test('/ dark matches baseline', async ({ page }) => {
    await page.goto('/')
    await page.getByRole('button', { name: 'Toggle theme' }).click()
    await page.waitForTimeout(250)
    await expect(page).toHaveScreenshot('home-dark.png', { fullPage: true, maxDiffPixelRatio: 0.02 })
  })
})
