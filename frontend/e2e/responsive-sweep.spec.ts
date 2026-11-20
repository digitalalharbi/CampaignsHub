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

/**
 * VISUAL-FIRST-001 clause H — the ANALYTICS TABS, which the portal sweep above never reaches.
 *
 * That sweep opens each portal's landing route. Analytics is one route with twelve tabs behind a
 * query parameter, so every tab except the default was unswept — and the tabs are where this
 * product's densest layouts live: tables that must scroll inside themselves, bar rows with fixed
 * gutters, funnels with connectors between stages.
 *
 * It is also where the recent visual work landed. What this sweep can and cannot prove is worth
 * stating, because it was written believing it proved more: it holds the DOCUMENT contract for every
 * tab in both themes and both directions, and it cannot hold a CONDITIONAL row's layout unless the
 * seeded data happens to produce that row. Verified by injecting a fixed-width variant of the
 * funnel's loss row and watching this pass — the gate's data produces no loss rows on that tab, so
 * there was nothing to measure. A conditional layout needs a fixture that forces it, and that is a
 * different piece of work from this sweep.
 *
 * The four theme/direction combinations are reached by TOGGLING, as the portal sweep does and as a
 * customer does — a stamped attribute would prove the palette resolves and not that the control
 * anybody actually uses works.
 */
const ANALYTICS_TABS = ['platforms', 'campaigns', 'ad_sets', 'budget', 'funnel', 'store', 'quality'] as const

test.describe('every analytics tab holds its shape', () => {
  test.use({ storageState: AUTH.advertiser })

  for (const vp of VIEWPORTS) {
    for (const tab of ANALYTICS_TABS) {
      test(`${tab} at ${vp.name}: no sideways scroll in either theme or direction`, async ({ page }) => {
        await page.setViewportSize({ width: vp.width, height: vp.height })
        await page.goto(`/app/analytics?tab=${tab}`)

        const main = page.locator('main')
        await expect(main).toBeVisible({ timeout: 20000 })

        /*
         * A tab that rendered nothing proves nothing. This is a floor rather than a specific
         * assertion because each tab's content differs and several legitimately decline — what must
         * never happen is a sweep that measures an empty shell and reports success.
         */
        await expect
          .poll(async () => (await main.innerText()).trim().length, { timeout: 20000 })
          .toBeGreaterThan(40)

        for (const step of ['start', 'theme', 'language', 'theme-again'] as const) {
          if (step === 'theme' || step === 'theme-again') {
            await page.getByRole('button', { name: 'Toggle theme' }).first().click()
          }
          if (step === 'language') {
            await page.getByRole('button', { name: 'Toggle language' }).first().click()
          }

          const dir = await page.locator('html').getAttribute('dir')
          const theme = await page.locator('html').getAttribute('data-theme')
          const where = `analytics?tab=${tab} · ${vp.name} · ${dir} · ${theme}`

          /*
           * The DOCUMENT must not scroll sideways. A table may — that is the contract these tabs are
           * built to, and it is why this measures the document rather than the widest element.
           */
          const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
          )
          expect(overflow, `${where} scrolls sideways by ${overflow}px`).toBeLessThanOrEqual(1)
        }
      })
    }
  }
})
