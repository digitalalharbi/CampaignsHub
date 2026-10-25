import { expect, test } from '@playwright/test'

/**
 * /welcome — the legacy public page — must not scroll sideways on a phone.
 *
 * Measured, not eyeballed: at 375px the document was 385px wide. The culprit was the sticky header's
 * row, whose five full-size controls plus the compact wordmark could not share one line in Arabic, so
 * the primary button wrapped onto two lines and the row pushed past the viewport. The homepage header
 * (`PublicHeader`) had been through the same lesson, so the row now yields the way it does.
 *
 * Both languages are swept because they fail differently — Arabic labels are wider than English ones
 * here — and the language is reached by toggling, as a visitor reaches it. No sign-in is needed.
 */
test.describe('/welcome on a phone', () => {
  test('375px: document never wider than the viewport, in Arabic and in English', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 })
    await page.goto('/welcome')

    const header = page.locator('header')
    await expect(header).toBeVisible({ timeout: 20000 })

    for (const step of ['start', 'language'] as const) {
      if (step === 'language') {
        await page.getByRole('button', { name: 'Toggle language' }).first().click()
      }
      const dir = await page.locator('html').getAttribute('dir')

      const { scrollWidth, clientWidth } = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }))
      expect(scrollWidth, `/welcome · 375px · ${dir}: document is ${scrollWidth}px wide in a ${clientWidth}px viewport`)
        .toBeLessThanOrEqual(clientWidth)

      // The header row specifically — the element that overflowed — stays inside the viewport too.
      const right = await header.evaluate((el) => Math.ceil(el.getBoundingClientRect().right))
      expect(right, `/welcome · 375px · ${dir}: header right edge`).toBeLessThanOrEqual(clientWidth)
    }
  })
})
