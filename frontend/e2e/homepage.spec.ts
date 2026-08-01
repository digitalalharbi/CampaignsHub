import { expect, test, type Page } from '@playwright/test'

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
  await expect(header.getByRole('link', { name: /متابعة طلباتي|Track my requests/ })).toHaveAttribute('href', '/portal/login')

  // Language toggle switches copy (ar → en).
  await page.getByRole('button', { name: 'Toggle language' }).click()
  await expect(h1).toContainText(/paid ad/i)

  // Theme toggle works (no crash, still on the page).
  await page.getByRole('button', { name: 'Toggle theme' }).click()
  await expect(page).toHaveURL(/\/$/)

  // The demo preview uses the SAME UnifiedCampaignOverview component as the dashboard, fed labeled demo data.
  const preview = page.getByTestId('campaign-overview')
  await expect(preview).toBeVisible()
  await expect(preview.getByText('Meta').first()).toBeVisible()

  // No horizontal overflow.
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)
  expect(overflow).toBe(false)

  // The start card «How do you want to start?» maps each journey to a real, param-carrying route. The
  // journeys are a selector now, so a route is carried either by the card's call to action (the selected
  // path) or by the always-present list of the others — what matters is that every route is reachable.
  const hrefs = await page.locator('a[href]').evaluateAll((els) => els.map((e) => e.getAttribute('href')))
  expect(hrefs).toContain('/register?journey=self-service&module=paid-media')
  expect(hrefs).toContain('/register?journey=multi-client&module=paid-media')
  // Withdrawn in this release (INFL-OFF-001) — and absent from the page, not merely unlinked in one
  // of the three places this card used to appear.
  expect(hrefs).not.toContain('/requests/new?module=influencer-marketing')

  // «I need paid-media services» opens the services selector in a dialog (it does not navigate). It is a
  // long list, so it lives in a dialog rather than stretching the hero down the page.
  const reveal = page.getByRole('button', { name: /I need paid-media services/ })
  await expect(reveal).toHaveAttribute('aria-expanded', 'false')
  await reveal.click()
  await expect(reveal).toHaveAttribute('aria-expanded', 'true')
  await expect(page.getByRole('button', { name: /Continue your request/ })).toBeVisible()
  // Close it again — the rest of this test drives the page behind the dialog.
  await page.keyboard.press('Escape')
  await expect(reveal).toHaveAttribute('aria-expanded', 'false')

  // Below the card: returning-user actions point at the client + user logins.
  const optionsCard = page.locator('div').filter({ has: page.getByRole('heading', { name: 'How do you want to start?' }) }).last()
  await expect(optionsCard.getByRole('link', { name: /^Log in$/ })).toHaveAttribute('href', '/login')
  await expect(optionsCard.getByRole('link', { name: /Track my requests/ })).toHaveAttribute('href', '/portal/login')

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

  /*
   * Wait for the data-driven sections to have RENDERED, not merely for the network to go quiet.
   *
   * The services grid shows six pulse blocks while its catalogue loads and ten real cards once it
   * arrives — about 320px taller. `networkidle` is 500ms of network silence, which under the full
   * three-browser load can fall between the response and React committing it, so the full-page
   * screenshot was taken while the page was still growing. Playwright then failed with «Failed to
   * take two consecutive stable screenshots» — 3451px, then 3774px — and NOT with a pixel diff: the
   * final render is byte-identical to the baseline.
   *
   * So this is a precondition the test was missing, not a visual defect, and it is fixed by waiting
   * for the thing that arrives last rather than by loosening the comparison or updating the image.
   */
  async function homepageSettled(page: Page) {
    await expect(page.getByTestId('home-service-categories')).toBeVisible({ timeout: 20000 })
    await expect(page.getByTestId('closing-journeys')).toBeVisible({ timeout: 20000 })
    await page.waitForLoadState('networkidle')
  }

  test('/ light matches baseline', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' })
    await page.goto('/')
    await homepageSettled(page)
    await expect(page).toHaveScreenshot('home-light.png', { fullPage: true, maxDiffPixelRatio: 0.02 })
  })

  test('/ dark matches baseline', async ({ page }) => {
    await page.goto('/')
    await homepageSettled(page)
    await page.getByRole('button', { name: 'Toggle theme' }).click()
    await page.waitForTimeout(250)
    await expect(page).toHaveScreenshot('home-dark.png', { fullPage: true, maxDiffPixelRatio: 0.02 })
  })
})
