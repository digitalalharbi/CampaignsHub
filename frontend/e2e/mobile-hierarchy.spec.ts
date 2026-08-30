import { expect, test } from '@playwright/test'
import { signIn } from './helpers'

/**
 * MOBILE-FILTERS-001 — on a phone, the numbers are above the fold.
 *
 * The dashboard's filter bar is six controls at 44px each. Stacked on a 390px screen they filled
 * the first screen and pushed every figure below it: a reader opening the dashboard on a phone met
 * the controls, scrolled, and only then met what they came for. The controls now start folded
 * behind a summary that says how many are narrowing the page.
 *
 * The assertion is on the fold, not on the pixels: the KPI row's top edge must be inside the
 * viewport when the page settles. A screenshot would freeze one phone's rendering; this holds for
 * any width the project ever runs at.
 */
test('the dashboard shows a figure before it asks for a filter', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await signIn(page, 'advertiser@campaignshub.io', 'password')
  await page.waitForURL((url) => !/\/login/.test(url.pathname), { timeout: 30_000 })
  await page.goto('/app/dashboard')

  const strip = page.getByTestId('dashboard-metrics').first()
  await expect(strip).toBeVisible({ timeout: 30_000 })

  const box = await strip.boundingBox()
  expect(box, 'the KPI row has no box at all').not.toBeNull()
  expect(box!.y, 'the figures start below the first screen on a phone').toBeLessThan(844)
})

/**
 * The filter controls are one tap away, and the tap says how many are applied.
 */
test('the folded filters open on a phone', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await signIn(page, 'advertiser@campaignshub.io', 'password')
  await page.waitForURL((url) => !/\/login/.test(url.pathname), { timeout: 30_000 })
  await page.goto('/app/dashboard')

  const toggle = page.getByTestId('dashboard-filters-toggle')
  await expect(toggle).toBeVisible({ timeout: 30_000 })
  await expect(page.getByTestId('dashboard-filters-controls')).toBeHidden()

  await toggle.click()
  await expect(page.getByTestId('dashboard-filters-controls')).toBeVisible()
})

/**
 * ANALYTICS-TABS-001 — eleven tabs scroll inside their own row, and the page does not.
 *
 * The row declared `flex-wrap` and `overflow-x-auto` at once, which is a contradiction: a wrapping
 * row never scrolls. On a phone it became three stacked lines with the last tab still under the
 * edge.
 */
test('the analytics tabs scroll inside their row on a phone', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await signIn(page, 'advertiser@campaignshub.io', 'password')
  await page.waitForURL((url) => !/\/login/.test(url.pathname), { timeout: 30_000 })
  await page.goto('/app/analytics')

  const tabs = page.getByRole('tablist').first()
  await expect(tabs).toBeVisible({ timeout: 30_000 })

  /*
   * One row, not three. `scrollWidth > clientWidth` does not discriminate here — a wrapping row
   * whose inner groups cannot wrap overflows too — so the assertion is on the tabs' own geometry:
   * every tab sits on the same line, which is only true when the row scrolls instead of wrapping.
   */
  const tops = await tabs.getByRole('tab').evaluateAll((els) =>
    [...new Set(els.map((el) => Math.round(el.getBoundingClientRect().top)))],
  )
  expect(tops.length, `the tabs are stacked on ${tops.length} lines instead of scrolling in one`).toBe(1)

  const scrolls = await tabs.evaluate((el) => el.scrollWidth > el.clientWidth + 1)
  expect(scrolls, 'a single row of eleven tabs must be scrollable to reach the last one').toBe(true)

  const bodyOverflows = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)
  expect(bodyOverflows, 'the page itself scrolls sideways').toBe(false)
})
