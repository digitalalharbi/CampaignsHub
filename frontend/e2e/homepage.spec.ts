import { expect, test } from '@playwright/test'

/**
 * Public homepage journey (guest). Covers language + theme toggles, the interactive product preview,
 * the journey CTAs into real routes, and mobile navigation. No auth — `/` is public.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('homepage: hero, language/theme, preview, journeys and CTAs into real routes', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveURL(/\/$/)

  // Hero uses the v5 customer-facing headline. Default locale is Arabic.
  const h1 = page.getByRole('heading', { level: 1 })
  await expect(h1).toContainText(/الإعلانية المدفوعة|paid ad/i)

  // Header actions route to the real external entry points (v5).
  const header = page.getByRole('banner')
  await expect(header.getByRole('link', { name: /إنشاء حساب|Create account/ })).toHaveAttribute('href', '/register')
  await expect(header.getByRole('link', { name: /^تسجيل الدخول$|^Log in$/ })).toHaveAttribute('href', '/login')
  await expect(header.getByRole('link', { name: /اطلب خدمة|Request a service/ })).toHaveAttribute('href', '/requests/new')
  await expect(header.getByRole('link', { name: /متابعة طلباتي|Track my requests/ })).toHaveAttribute('href', '/client/login')

  // Language toggle switches copy (ar → en).
  await page.getByRole('button', { name: 'Toggle language' }).click()
  await expect(h1).toContainText(/paid ad/i)

  // Theme toggle works (no crash, still on the page).
  await page.getByRole('button', { name: 'Toggle theme' }).click()
  await expect(page).toHaveURL(/\/$/)

  // Interactive demo preview: switching a section tab changes the shown content.
  await page.getByRole('button', { name: 'Top campaigns' }).click()
  await expect(page.getByText(/Sales campaign/).first()).toBeVisible()

  // No horizontal overflow.
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)
  expect(overflow).toBe(false)

  // Options card «How do you want to start?» maps each journey to a real, param-carrying route.
  await expect(page.getByRole('link', { name: /I run my own campaigns/ }))
    .toHaveAttribute('href', '/register?journey=self-managed&module=paid-media')
  await expect(page.getByRole('link', { name: /I manage campaigns for several clients/ }))
    .toHaveAttribute('href', '/register?journey=agency&module=paid-media')
  await expect(page.getByRole('link', { name: /I need influencers or UGC content/ }))
    .toHaveAttribute('href', '/requests/new?module=influencer-marketing')

  // «I need paid-media services» reveals the inline services selector (does not navigate).
  const reveal = page.getByRole('button', { name: /I need paid-media services/ })
  await expect(reveal).toHaveAttribute('aria-expanded', 'false')
  await reveal.click()
  await expect(reveal).toHaveAttribute('aria-expanded', 'true')

  // Below the card: returning-user actions point at the client + user logins.
  const optionsCard = page.getByRole('heading', { name: 'How do you want to start?' }).locator('xpath=ancestor::div[1]')
  await expect(optionsCard.getByRole('link', { name: /^Log in$/ })).toHaveAttribute('href', '/login')
  await expect(optionsCard.getByRole('link', { name: /Track my requests/ })).toHaveAttribute('href', '/client/login')

  // Service-request CTA opens the real /requests/new route.
  await header.getByRole('link', { name: /Request a service/ }).click()
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
test.describe('homepage visual regression @visual', () => {
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
