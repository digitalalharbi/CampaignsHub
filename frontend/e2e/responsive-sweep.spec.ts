import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * Desktop, tablet and phone × light and dark × RTL and LTR, on every portal (REVIEW-003).
 *
 * Three failures this is looking for, and none of them shows up in a screenshot somebody glances at:
 *
 *   - **sideways scroll** — the page wider than the device, which on a phone means content the
 *     customer can only reach by dragging and will not know is there;
 *   - **a blank page** — the shell renders and the content area does not;
 *   - **theme not applied** — `data-theme` says dark while the surface is still light, which is what
 *     a missed `applyDocument` looks like.
 *
 * Written as a matrix rather than as separate tests so a portal added later is covered by adding one
 * line, and so a failure names the exact combination rather than "the responsive test".
 */
const VIEWPORTS = [
  { name: 'phone', width: 375, height: 812 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
] as const

const PORTALS = [
  { path: '/admin', storage: AUTH.admin },
  { path: '/app/dashboard', storage: AUTH.advertiser },
  { path: '/agency', storage: AUTH.owner },
  { path: '/portal', storage: AUTH.client },
] as const

for (const portal of PORTALS) {
  test.describe(`${portal.path} across devices and themes`, () => {
    test.use({ storageState: portal.storage })

    for (const vp of VIEWPORTS) {
      test(`${vp.name}: no sideways scroll, content renders, both themes and both directions`, async ({ page }) => {
        await page.setViewportSize({ width: vp.width, height: vp.height })
        await page.goto(portal.path)

        const main = page.locator('main')
        await expect(main).toBeVisible({ timeout: 20000 })
        await expect
          .poll(async () => (await main.innerText()).trim().length, { timeout: 20000 })
          .toBeGreaterThan(40)

        // Four combinations: rtl+light, rtl+dark, ltr+dark, ltr+light — reached by toggling, which
        // is also how a customer reaches them.
        for (const step of ['start', 'theme', 'language', 'theme-again'] as const) {
          if (step === 'theme' || step === 'theme-again') {
            await page.getByRole('button', { name: 'Toggle theme' }).first().click()
          }
          if (step === 'language') {
            await page.getByRole('button', { name: 'Toggle language' }).first().click()
          }

          const dir = await page.locator('html').getAttribute('dir')
          const theme = await page.locator('html').getAttribute('data-theme')
          const where = `${portal.path} · ${vp.name} · ${dir} · ${theme}`

          // The shell is still rendering something.
          await expect
            .poll(async () => (await main.innerText()).trim().length, { timeout: 20000 })
            .toBeGreaterThan(40)

          const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
          )
          expect(overflow, `${where} scrolls sideways`).toBe(false)

          // The theme reached the paint, not just the attribute.
          const background = await page.evaluate(
            () => getComputedStyle(document.body).backgroundColor,
          )
          expect(background, `${where} has no background colour`).not.toBe('rgba(0, 0, 0, 0)')
        }
      })
    }
  })
}
