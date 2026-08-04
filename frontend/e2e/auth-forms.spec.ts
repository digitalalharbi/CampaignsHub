import { expect, test } from '@playwright/test'

/**
 * Auth form design contract: professional wide fields (~54px, ≥16px text, labels above, not pills),
 * no horizontal overflow, RTL + light/dark, and validation. No auth needed (public routes).
 */
const PAGES = ['/login', '/register', '/forgot-password'] as const

for (const path of PAGES) {
  test(`${path}: wide labeled fields, no horizontal overflow`, async ({ page }) => {
    await page.goto(path)
    const input = page.locator('input').first()
    await expect(input).toBeVisible()

    /*
     * Field is wide and tall (≥ 50px) — not a small pill.
     *
     * "Wide" is measured against the FORM, not in absolute pixels. Registration pairs fields two to a
     * row — the organisation name beside the mobile number, the full name beside the address — so no
     * single field spans the form any more, and a fixed 280px threshold was really asserting that
     * every field sits on a row of its own. What it is actually guarding against is a field squeezed
     * into a corner, and a control filling its column is not that.
     */
    const box = await input.boundingBox()
    const formWidth = (await page.locator('form').first().boundingBox())!.width

    expect(box!.height).toBeGreaterThanOrEqual(50)
    expect(box!.width, 'the field does not fill its column').toBeGreaterThanOrEqual(formWidth * 0.45)

    // 16px text prevents iOS auto-zoom.
    const fontPx = await input.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))
    expect(fontPx).toBeGreaterThanOrEqual(16)

    // Not pill-shaped (radius well under half the height).
    const radius = await input.evaluate((el) => parseFloat(getComputedStyle(el).borderTopLeftRadius))
    expect(radius).toBeLessThan(box!.height / 2)

    // No horizontal overflow on the document.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)
    expect(overflow).toBe(false)

    // RTL by default (Arabic).
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  })
}

for (const width of [320, 375, 390]) {
  test(`/login mobile ${width}px: single-column, no horizontal scroll, submit validates`, async ({ page }) => {
    await page.setViewportSize({ width, height: 812 })
    await page.goto('/login')
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)
    expect(overflow).toBe(false)
    /*
     * The form must be reachable at the top (marketing panel is hidden below lg).
     *
     * The identifier field is `type="text"`, not `type="email"` — since LOGIN-UNIFIED-001 it accepts
     * a phone number too, and the server decides what that identifier signs in with. Asserting on
     * the STEP rather than on an input type keeps this test about the layout it is named for.
     */
    await expect(page.getByTestId('login-identify')).toBeVisible()
    await expect(page.getByTestId('login-identify').locator('input')).toBeVisible()

    // An empty submit goes nowhere: the identifier is required before the server is asked anything.
    await page.getByTestId('login-identify').locator('button[type="submit"]').click()
    await expect(page).toHaveURL(/\/login/)
    await expect(page.getByTestId('login-password')).toHaveCount(0)
  })
}

for (const path of PAGES) {
  test(`${path}: no leftover InfluencerHub branding`, async ({ page }) => {
    await page.goto(path)
    const body = (await page.locator('body').innerText()).toLowerCase()
    expect(body).not.toContain('influencerhub')
    expect(body).not.toContain('influencer hub')
  })
}
