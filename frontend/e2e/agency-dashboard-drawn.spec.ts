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

  /*
    The defect's signature is BANDING, not width.

    Without a category axis the chart drew one full-width rectangle and three of zero SIZE — zero
    height as well as zero width, because there were no bands to lay the bars into. Height is
    therefore what proves the axis: a category band is the same height whatever its value, so a bar
    for a count of 1 beside a count of 500 still has the full band, while its WIDTH is a legitimate
    sub-pixel that firefox rounds to 0 and chromium does not.

    Polled rather than read once. `ResponsiveContainer` renders the chart twice — once before it has
    measured its parent, when every bar's path is still degenerate, and again with real dimensions.
    Reading the geometry on the first pass is a race that this spec lost on the firefox gate and then,
    once it was looking at height, on chromium too: the bars were correct both times and simply had
    not been laid out yet.
  */
  const bars = chart.locator('.recharts-bar-rectangle')
  const ticks = chart.locator('.recharts-yAxis .recharts-cartesian-axis-tick')

  /*
    The axis is counted INSIDE the poll, with the bars.

    It used to be read once, above, and passed to `toEqual` — where it is evaluated eagerly, before
    polling begins. On a browser that had not laid the axis out by that moment the expectation froze
    at zero categories while the bars climbed to their real number, and the poll could never
    converge. `requests-dashboard-drawn.spec.ts` lost exactly this on the webkit gate, reporting
    «Expected: 0, Received: 6» about a chart that was drawing correctly, and this spec carried the
    same latent bug on the same line.

    `matched` rather than the counts themselves: a poll that compares two live values has to settle
    on BOTH, and `categories > 0` is what stops an unrendered chart satisfying 0 === 0.
  */
  await expect.poll(async () => {
    const boxes = await Promise.all((await bars.all()).map((b) => b.boundingBox()))
    const categories = await ticks.count()

    return {
      matched: categories > 0 && boxes.length === categories,
      allBanded: boxes.length > 0 && boxes.every((b) => (b?.height ?? 0) > 0),
      // Drawn to scale: the largest category has a bar somebody can see.
      widestOverTenPx: Math.max(0, ...boxes.map((b) => b?.width ?? 0)) > 10,
    }
  }, { timeout: 15000 }).toEqual({ matched: true, allBanded: true, widestOverTenPx: true })
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
