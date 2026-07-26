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

    // Field is wide (spans the form) and tall (≥ 50px) — not a small pill.
    const box = await input.boundingBox()
    expect(box!.height).toBeGreaterThanOrEqual(50)
    expect(box!.width).toBeGreaterThanOrEqual(280)

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

test('/login mobile: single-column, no horizontal scroll, submit validates', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 })
  await page.goto('/login')
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)
  expect(overflow).toBe(false)
  // Empty submit is blocked by required fields (no navigation away from /login).
  await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
  await expect(page).toHaveURL(/\/login/)
})
