import { expect, test, type Page } from '@playwright/test'

/**
 * AUTH-002 acceptance for the redesigned sign-in / sign-up pages.
 *
 * These run signed OUT, in all three browsers, at the three viewports the redesign was specified against.
 * They assert behaviour a screenshot cannot: that no page scrolls sideways, that the primary button is on
 * the first screen, that each portal really rewrites the panel, that the phone layout puts the form first,
 * and that every secondary link lands on a page that exists rather than 404-ing or bouncing back.
 */

const DESKTOP = { width: 1366, height: 768 }
const LAPTOP = { width: 1440, height: 900 }
const PHONE = { width: 375, height: 812 }

/** Signed-out context: /login and /register redirect to the app when a session cookie is present. */
test.use({ storageState: { cookies: [], origins: [] } })

async function metrics(page: Page) {
  return page.evaluate(() => ({
    hScroll: document.documentElement.scrollWidth > window.innerWidth,
    docH: document.documentElement.scrollHeight,
    viewportH: window.innerHeight,
    dir: document.documentElement.dir,
  }))
}

/** The dev build renders a demo-credentials card that production does not ship; discount its height. */
async function docHeightWithoutDevOnlyBlocks(page: Page) {
  return page.evaluate(() => {
    const dev = document.querySelector('div.border-dashed') as HTMLElement | null
    const prev = dev?.style.display ?? ''
    if (dev) dev.style.display = 'none'
    const h = document.documentElement.scrollHeight
    if (dev) dev.style.display = prev
    return h
  })
}

for (const [name, size] of [['1366x768', DESKTOP], ['1440x900', LAPTOP], ['375x812', PHONE]] as const) {
  test(`login has no horizontal scroll and a reachable submit at ${name}`, async ({ page }) => {
    await page.setViewportSize(size)
    await page.goto('/login')
    await page.waitForLoadState('networkidle')

    const m = await metrics(page)
    expect(m.hScroll, 'the page must never scroll sideways').toBe(false)

    // The primary action is on the first screen — the visitor never hunts for it.
    const submit = page.locator('button[type="submit"]')
    const box = await submit.boundingBox()
    expect(box).not.toBeNull()
    expect(box!.y + box!.height).toBeLessThanOrEqual(size.height)
  })

  test(`register has no horizontal scroll and a reachable submit at ${name}`, async ({ page }) => {
    await page.setViewportSize(size)
    await page.goto('/register?journey=multi-client&module=paid-media')
    await page.waitForLoadState('networkidle')

    expect((await metrics(page)).hScroll).toBe(false)

    // Every field the account needs is present — the compact layout dropped none of them.
    await expect(page.locator('form input#tenant_name')).toBeVisible()
    await expect(page.locator('form input#name')).toBeVisible()
    await expect(page.locator('form input#email')).toBeVisible()
    await expect(page.locator('form input#password')).toBeVisible()
    await expect(page.locator('form input#password_confirmation')).toBeVisible()

    // Desktop only. On a phone the pages are deliberately allowed to scroll — squeezing the panel and a
    // five-field form into 812px is what made the fields cramped and hard to reach.
    if (size !== PHONE) {
      const box = await page.locator('button[type="submit"]').boundingBox()
      expect(box!.y + box!.height).toBeLessThanOrEqual(size.height)
    }
  })
}

test('both pages fit a 1366x768 desktop without vertical scrolling', async ({ page }) => {
  await page.setViewportSize(DESKTOP)

  await page.goto('/register?journey=self-service&module=paid-media')
  await page.waitForLoadState('networkidle')
  expect(await docHeightWithoutDevOnlyBlocks(page)).toBeLessThanOrEqual(DESKTOP.height)

  await page.goto('/login')
  await page.waitForLoadState('networkidle')
  expect(await docHeightWithoutDevOnlyBlocks(page)).toBeLessThanOrEqual(DESKTOP.height)
})

/** Each portal must change what the panel says — a switcher that only recolours a pill is decoration. */
test('every login portal rewrites the panel, by real clicks', async ({ page }) => {
  await page.setViewportSize(LAPTOP)
  await page.goto('/login')
  await page.waitForLoadState('networkidle')

  const panel = page.getByTestId('auth-panel')
  const heading = panel.getByRole('heading')
  const seen = new Set<string>()

  for (const portal of ['agency', 'influencer', 'client', 'default'] as const) {
    await page.getByTestId(`login-portal-${portal}`).click();
    await expect(page.getByTestId(`login-portal-${portal}`)).toHaveAttribute('aria-current', 'page')
    const text = ((await heading.textContent()) ?? '').trim()
    expect(text.length).toBeGreaterThan(0)
    seen.add(text)

    // One auth engine behind all of them: the credentials form never disappears.
    await expect(page.locator('input[type="email"]')).toBeVisible()
    await expect(page.locator('input[type="password"]')).toBeVisible()
    await expect(page.locator('button[type="submit"]')).toBeVisible()
  }

  // The influencer and client portals speak differently from the campaign ones.
  expect(seen.size).toBeGreaterThan(1)
})

test('on a phone the form comes first and the panel collapses below it', async ({ page }) => {
  await page.setViewportSize(PHONE)
  await page.goto('/login')
  await page.waitForLoadState('networkidle')

  // The wide gradient panel is not rendered on a phone at all.
  await expect(page.getByTestId('auth-panel')).toBeHidden()

  const form = await page.locator('form').boundingBox()
  const panel = await page.getByTestId('auth-panel-mobile').boundingBox()
  expect(form!.y, 'the form must be above the marketing panel').toBeLessThan(panel!.y)

  // Collapsed by default, and opening it does not introduce a sideways scroller.
  const toggle = page.getByTestId('auth-panel-mobile').getByRole('button').first()
  await expect(toggle).toHaveAttribute('aria-expanded', 'false')
  await toggle.click()
  await expect(toggle).toHaveAttribute('aria-expanded', 'true')
  expect((await metrics(page)).hScroll).toBe(false)
})

/** Zero dead links: each secondary action navigates to a page that actually renders. */
test('forgot password, create account and request tracking all lead somewhere real', async ({ page }) => {
  await page.setViewportSize(LAPTOP)

  await page.goto('/login')
  await page.getByRole('link', { name: /Forgot|نسيت/ }).click()
  await expect(page).toHaveURL(/\/forgot-password/)
  await expect(page.locator('input[type="email"]')).toBeVisible()

  await page.goto('/login')
  await page.getByRole('link', { name: /Create an account|تسجيل حساب/ }).click()
  await expect(page).toHaveURL(/\/register/)
  await expect(page.locator('form input#tenant_name')).toBeVisible()

  await page.goto('/login')
  await page.getByRole('link', { name: /Track my requests|متابعة طلباتي/ }).click()
  await expect(page).toHaveURL(/\/client\/login/)
  // The portal signs in by one-time code, not a password — assert its own control, not the staff form's.
  await expect(page.getByRole('button', { name: /Send code|إرسال الرمز/ })).toBeVisible()
})

/** The redesign must hold in both writing directions and both themes. */
test('layout survives RTL/LTR and light/dark without clipping', async ({ page }) => {
  await page.setViewportSize(LAPTOP)
  await page.goto('/login')
  await page.waitForLoadState('networkidle')

  for (const scheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme: scheme })
    for (let i = 0; i < 2; i++) {
      const m = await metrics(page)
      expect(m.hScroll, `no sideways scroll in ${m.dir}/${scheme}`).toBe(false)
      await expect(page.getByTestId('auth-panel')).toBeVisible()
      await expect(page.locator('button[type="submit"]')).toBeVisible()

      // Flip the direction and assert the other one too.
      await page.getByRole('button', { name: 'Toggle language' }).click()
      await page.waitForTimeout(150)
    }
  }
})

/**
 * The end of the journey, not a 200: signing in with a real account lands inside the app, and the
 * chosen portal never changes which engine authenticates.
 */
test('signing in from a portal reaches the app', async ({ page }) => {
  await page.setViewportSize(LAPTOP)
  await page.goto('/login?portal=agency')
  await page.waitForLoadState('networkidle')

  await page.locator('input[type="email"]').fill('owner@demo-agency.local')
  await page.locator('input[type="password"]').fill('password')
  await page.locator('button[type="submit"]').click()

  await expect(page).toHaveURL(/\/(dashboard|verify-email|onboarding)/, { timeout: 15_000 })
})
