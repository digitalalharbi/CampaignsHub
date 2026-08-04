import { expect, test, type Page } from '@playwright/test'
import { signIn } from './helpers'

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

/** 1024 is the exact width the two-column layout switches on — the tightest desktop case, and the one
 *  a pull toward the divider overflows first. 1280 is the next breakpoint. Both are covered on purpose. */
const LG_EDGE = { width: 1024, height: 768 }
const XL_EDGE = { width: 1280, height: 800 }

for (const [name, size] of [
  ['1024x768', LG_EDGE],
  ['1280x800', XL_EDGE],
  ['1366x768', DESKTOP],
  ['1440x900', LAPTOP],
  ['375x812', PHONE],
] as const) {
  test(`login has no horizontal scroll and a reachable submit at ${name}`, async ({ page }) => {
    await page.setViewportSize(size)
    await page.goto('/login')
    await page.waitForLoadState('networkidle')

    const m = await metrics(page)
    expect(m.hScroll, 'the page must never scroll sideways').toBe(false)

    // Nothing may sit outside the viewport. A pull toward the divider that is wider than the column
    // silently clips the fields rather than scrolling, so "no h-scroll" alone would not catch it.
    const form = (await page.locator('form').boundingBox())!
    expect(Math.round(form.x), 'the form is clipped at the leading edge').toBeGreaterThanOrEqual(0)
    const layoutWidth = await page.evaluate(() => document.documentElement.clientWidth)
    expect(Math.round(form.x + form.width), 'the form runs past the trailing edge').toBeLessThanOrEqual(layoutWidth)

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

    const regForm = (await page.locator('form').boundingBox())!
    expect(Math.round(regForm.x), 'the form is clipped at the leading edge').toBeGreaterThanOrEqual(0)
    const regLayoutWidth = await page.evaluate(() => document.documentElement.clientWidth)
    expect(Math.round(regForm.x + regForm.width), 'the form runs past the trailing edge').toBeLessThanOrEqual(regLayoutWidth)

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

/**
 * There is nothing to switch between any more (LOGIN-UNIFIED-001).
 *
 * This test used to click each portal pill and assert the panel's heading changed — proving the
 * switcher was not decoration. The switcher is gone: the visitor never picks a portal, so the panel
 * carries the product's one approved message and the page asks for an identifier first.
 *
 * What is worth asserting on the same screen is what replaced it.
 */
test('the sign-in page offers one message and no portal to choose', async ({ page }) => {
  await page.setViewportSize(LAPTOP)
  await page.goto('/login')
  await page.waitForLoadState('networkidle')

  const heading = page.getByTestId('auth-panel').getByRole('heading')
  expect(((await heading.textContent()) ?? '').trim().length).toBeGreaterThan(0)

  await expect(page.getByTestId('login-portals')).toHaveCount(0)
  for (const key of ['default', 'agency', 'client', 'influencer', 'admin']) {
    await expect(page.getByTestId(`login-portal-${key}`)).toHaveCount(0)
  }

  // The identity step, and nothing secret before the server has named the account.
  await expect(page.getByTestId('login-identify')).toBeVisible()
  await expect(page.locator('input[type="password"]')).toHaveCount(0)
  await expect(page.locator('button[type="submit"]')).toBeVisible()
})

/**
 * Below `lg` there is no second column, so the form must be CENTRED. Pulling it toward a divider that
 * is not being rendered leaves half the screen empty — which is exactly what happened when the desktop
 * alignment was written without a breakpoint. Checked at every width where the panel is hidden, and in
 * both writing directions, because the pull is expressed in logical (inline) properties.
 */
// 1023 is the last width below Tailwind's `lg` (1024) — the exact boundary the bug lived on.
for (const width of [375, 414, 640, 768, 1023]) {
  test(`the form is centred at ${width}px, where no panel is beside it`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.goto('/login')
    await page.waitForLoadState('networkidle')

    await expect(page.getByTestId('auth-panel'), 'the panel must not be rendered below lg').toBeHidden()

    for (const dir of ['rtl', 'ltr'] as const) {
      await expect(page.locator('html')).toHaveAttribute('dir', dir)

      const box = (await page.locator('form').boundingBox())!
      // Measure against the LAYOUT viewport, not the configured width: Firefox reserves space for a
      // classic scrollbar, so `width` overstates the usable area and a perfectly centred form would
      // read as ~7px off. This is about how the page is measured, not how it is laid out.
      const layoutWidth = await page.evaluate(() => document.documentElement.clientWidth)
      const leftGap = box.x
      const rightGap = layoutWidth - (box.x + box.width)
      expect(
        Math.abs(leftGap - rightGap),
        `form is off-centre in ${dir} at ${width}px: ${Math.round(leftGap)}px vs ${Math.round(rightGap)}px`,
      ).toBeLessThanOrEqual(2)

      expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)).toBe(false)

      if (dir === 'rtl') {
        await page.getByRole('button', { name: 'Toggle language' }).click()
        await page.waitForTimeout(150)
      }
    }
  })
}

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

/**
 * Zero dead links: each secondary action navigates to a page that actually renders.
 *
 * Each hop waits for the login FORM before clicking. `page.goto()` resolves on `load`, which on a
 * single-page app is well before React has hydrated and bound the router: a click that lands in that
 * window hits an anchor whose handler has already called `preventDefault()` but whose navigation has
 * not been wired, and is simply lost. The URL then sits on `/login` until the assertion gives up.
 *
 * It failed on firefox, once, in a full three-browser run — the browser that happened to be slowest
 * on the day. The fix is to click a live app, not to give the assertion longer to wait: a longer
 * timeout would have hidden the race instead of removing it, and left the same click landing on a
 * page that was not ready.
 */
test('forgot password and create account both lead somewhere real', async ({ page }) => {
  await page.setViewportSize(LAPTOP)

  /** The login form is on screen, so React has hydrated and the router is bound. */
  const loginReady = async () => {
    await page.goto('/login')
    await expect(page.getByTestId('login-identify')).toBeVisible({ timeout: 20000 })
  }

  /*
   * «نسيت كلمة المرور» belongs to the PASSWORD step (LOGIN-UNIFIED-001) — it is meaningless before
   * the server has said this account even has one, so it is reached the way a person reaches it.
   */
  await loginReady()
  await page.getByTestId('login-identify').locator('input').fill('owner@demo-agency.local')
  await page.getByTestId('login-identify').locator('button[type="submit"]').click()
  await expect(page.getByTestId('login-password')).toBeVisible({ timeout: 20000 })
  await page.getByRole('link', { name: /Forgot|نسيت/ }).click()
  await expect(page).toHaveURL(/\/forgot-password/)
  await expect(page.locator('input[type="email"]')).toBeVisible()

  await loginReady()
  await page.getByRole('link', { name: /Create an account|تسجيل حساب/ }).click()
  await expect(page).toHaveURL(/\/register/)
  await expect(page.locator('form input#tenant_name')).toBeVisible()

  /*
   * «متابعة طلباتي» is deliberately absent (LOGIN-UNIFIED-001).
   *
   * It was one of the three portal choices, and a client following it was picking a portal — the
   * thing the visitor no longer does. A client reaches the code step by typing their address into
   * the same field everybody else uses, because the server recognises them.
   */
  await loginReady()
  await expect(page.getByRole('link', { name: /Track my requests|متابعة طلباتي/ })).toHaveCount(0)
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
 * The end of the journey, not a 200.
 *
 * `?portal=agency` is left in the URL on purpose: it is inert now (LOGIN-UNIFIED-001), and the
 * account still reaches its own portal. A page that started honouring it again would be the
 * regression.
 */
test('signing in reaches the app, and a portal in the URL changes nothing', async ({ page }) => {
  await page.setViewportSize(LAPTOP)
  await page.goto('/login?portal=agency')
  await page.waitForLoadState('networkidle')

  await signIn(page, 'owner@demo-agency.local')

  await expect(page).toHaveURL(/\/(dashboard|agency|onboarding)/, { timeout: 15_000 })
})
