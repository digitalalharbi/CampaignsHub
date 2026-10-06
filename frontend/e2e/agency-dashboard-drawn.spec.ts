import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * VIZ-AGENCY-001 — the first surface an agency opens, drawn, in a real browser.
 *
 * The unit tests hold the DECISIONS: what is withheld, what the denominator is, which band is which.
 * They cannot hold the drawing, because `ResponsiveContainer` measures its parent and jsdom reports
 * every element as 0×0 — a recharts chart asserted in jsdom passes whether or not it drew anything.
 * So the ranked objective chart is checked here, where it has a width.
 *
 * The widths below are the ones the product accepts at: 1440, 768 and 390, in both languages and
 * both directions. A chart that reads at 1440 and scrolls the page sideways at 390 is not a chart
 * that shipped.
 */
test.use({ storageState: AUTH.owner })

test('the client book is divided against its total, and the remainder is named', async ({ page }) => {
  await page.goto('/agency/dashboard')
  await switchToEnglish(page)

  const bar = page.getByTestId('client-mix-bar')
  await expect(bar).toBeVisible({ timeout: 30000 })

  // The spoken version carries the same composition the segments draw — this is the chart's key,
  // not decoration, and it is what a screen reader and a printed page both fall back to.
  await expect(bar).toHaveAttribute('aria-label', /Clients/)
  await expect(page.getByTestId('client-mix-legend')).toBeVisible()
})

test('the objective chart actually draws its bars and its category axis', async ({ page }) => {
  await page.goto('/agency/dashboard')
  await switchToEnglish(page)

  const chart = page.getByTestId('agency-objective-chart')
  await expect(chart).toBeVisible({ timeout: 30000 })

  /*
    CHART-AXIS-DISCOVERY-001 — an axis inside a fragment is an axis the chart does not have, and the
    failure is silent: recharts renders the bars and simply omits the scale. So the assertion is on
    the axis ELEMENT, not on the chart being present.
  */
  await expect(chart.locator('.recharts-yAxis')).toHaveCount(1)
  const bars = chart.locator('.recharts-bar-rectangle')
  expect(await bars.count()).toBeGreaterThan(0)

  // Every bar has a real width. One full-width rectangle and three of zero size is what a missing
  // category axis looks like, and it looks like a chart until the widths are read.
  for (const box of await bars.all()) {
    expect((await box.boundingBox())?.width ?? 0).toBeGreaterThan(0)
  }
})

test('the pace chart ranks clients against a line it keeps on the card', async ({ page }) => {
  await page.goto('/agency/dashboard')
  await switchToEnglish(page)

  const chart = page.locator('[data-testid="client-pace-reference"], [data-testid="client-pace-empty"]').first()
  await expect(chart).toBeVisible({ timeout: 30000 })

  const label = page.getByTestId('client-pace-reference-label')
  if (await label.count() > 0) {
    const card = page.getByTestId('client-pace-reference').locator('xpath=ancestor::section[1]')
    const [labelBox, cardBox] = [await label.boundingBox(), await card.boundingBox()]
    // The label flips to the inside of the line near the end of the track. Clipped to «On bu» is
    // what the un-flipped version produced on exactly this page.
    expect(labelBox!.x).toBeGreaterThanOrEqual(cardBox!.x - 1)
    expect(labelBox!.x + labelBox!.width).toBeLessThanOrEqual(cardBox!.x + cardBox!.width + 1)
  }
})

for (const width of [1440, 768, 390]) {
  test(`the drawn dashboard never scrolls the page sideways at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.goto('/agency/dashboard')
    await expect(page.getByTestId('client-mix-bar')).toBeVisible({ timeout: 30000 })

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
  })
}

test('the pace ratios are Latin digits in Arabic, like every other figure in the product', async ({ page }) => {
  await page.goto('/agency/dashboard')

  const empty = page.getByTestId('client-pace-empty')
  const first = page.locator('[data-testid^="client-pace-row-"]').first()
  await expect(first.or(empty)).toBeVisible({ timeout: 30000 })

  if (await first.count() > 0) {
    // NUMERAL-PREFERENCE — language is not numerals. An Arabic page states 0.34×, never ٠٫٣٤.
    await expect(first).not.toHaveText(/[٠-٩]/)
  }
})
