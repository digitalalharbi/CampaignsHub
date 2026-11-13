import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * AUDIENCE-001 — «الجمهور» in Analytics against the seeded demo project. No live provider is involved, and the demo
 * holds no paid breakdown rows: what a browser proves is that the panel renders, keeps the four kinds apart, says
 * «unavailable, not zero» where there is no breakdown, and fits a phone without sideways scrolling.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

test.describe('audience center', () => {
  test.use({ storageState: AUTH.owner })

  for (const width of [320, 390, 430, 1366]) {
    test(`the panel keeps the kinds apart and fits ${width}px`, async ({ page, request }) => {
      test.setTimeout(120_000)
      await page.setViewportSize({ width, height: 860 })
      await selectProject(page, await seededProject(request, STORE_PROJECT))
      await page.goto('/agency/analytics?tab=audience')

      const panel = page.getByTestId('audience-panel')
      await expect(panel).toBeVisible({ timeout: 30000 })
      for (const kind of ['paid_delivery', 'targeting', 'site_events', 'organic']) {
        await expect(panel.getByTestId(`audience-kind-${kind}`)).toBeVisible()
      }
      // Paid delivery: cards where rows exist, otherwise the explicit «unavailable, not zero».
      const cards = panel.locator('[data-testid^="audience-delivery-"]')
      if ((await cards.count()) === 0) await expect(panel).toContainText(/غير متاح، وليس صفرًا|unavailable, not zero/)

      await panel.getByTestId('audience-kind-organic').click()
      await expect(panel).toContainText(/غير متاح|unavailable/)
      await panel.getByTestId('audience-kind-targeting').click()
      await panel.getByTestId('audience-availability').locator('summary').click()

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
      expect(overflow, 'no horizontal scroll').toBeLessThanOrEqual(1)
    })
  }

  test('dark mode and English render the same panel', async ({ page, request }) => {
    test.setTimeout(120_000)
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.addInitScript(() => {
      localStorage.setItem('campaign-hub-locale', 'en')
      localStorage.setItem('campaign-hub-theme', 'dark')
    })
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/analytics?tab=audience')
    const panel = page.getByTestId('audience-panel')
    await expect(panel).toBeVisible({ timeout: 30000 })
    await expect(panel.getByTestId('audience-kind-paid_delivery')).toHaveText(/Paid delivery/)
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  })
})
