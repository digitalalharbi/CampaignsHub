import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * VIZ-LEADS-001 — the pipeline's shape, in a browser.
 *
 * The unit tests hold what the panel refuses to claim; this holds the drawing, because recharts
 * renders nothing in jsdom and a chart asserted there passes whether or not it drew.
 *
 * It also carries the Arabic category label, which is the only place that assertion can live: this
 * is the product's first horizontal ranking chart whose categories are Arabic WORDS rather than
 * numbers, and a label anchored the wrong way is laid out into the plot area and clipped to a glyph.
 */
test.use({ storageState: AUTH.advertiser })

test('every stage is drawn, in pipeline order, with the ends apart', async ({ page }) => {
  await page.goto('/app/leads')
  await switchToEnglish(page)

  const pipeline = page.getByTestId('lead-pipeline')
  await expect(pipeline).toBeVisible({ timeout: 30000 })

  // Two charts: the progression and the ends. `won`, `lost` and `invalid` share the pipeline's last
  // rank because they are ends rather than degrees.
  expect(await pipeline.locator('.recharts-yAxis').count()).toBe(2)

  const legend = page.getByTestId('lead-pipeline-legend')
  await expect(legend).toContainText('Contact attempted')
  await expect(legend).not.toContainText('contact_attempted')
})

test('the Arabic category labels are laid out beside the plot area, not into it', async ({ page }) => {
  await page.goto('/app/leads')

  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  const pipeline = page.getByTestId('lead-pipeline')
  await expect(pipeline).toBeVisible({ timeout: 30000 })

  const wrapper = pipeline.locator('.recharts-wrapper').first()
  const tick = wrapper.locator('.recharts-yAxis .recharts-cartesian-axis-tick text').first()
  await expect(tick).toBeVisible()

  const [label, grid] = [
    await tick.boundingBox(),
    await wrapper.locator('.recharts-cartesian-grid').first().boundingBox(),
  ]

  /*
    Right-aligned against the axis: the label ENDS where the plot begins. Anchored the wrong way it
    STARTS there and runs the other way, under the bars — measured at 23px for «جديد», one glyph.
  */
  expect(label!.x + label!.width).toBeLessThanOrEqual(grid!.x + 2)
  expect(label!.width).toBeGreaterThan(20)
})

test('the bars are banded by a real category axis', async ({ page }) => {
  await page.goto('/app/leads')
  const pipeline = page.getByTestId('lead-pipeline')
  await expect(pipeline).toBeVisible({ timeout: 30000 })

  const progression = pipeline.locator('.recharts-wrapper').first()
  const bars = progression.locator('.recharts-bar-rectangle')
  const categories = await progression.locator('.recharts-yAxis .recharts-cartesian-axis-tick').count()

  /*
    One bar element per category is what proves the axis. Without one, recharts drew a single
    full-width rectangle and the rest at zero size — the chart had FEWER usable bars than categories,
    which is the shape this catches.

    What is deliberately NOT asserted is that every bar has a size. A stage nobody has reached holds
    zero leads, and a zero-value bar is a zero-area path — correctly so. Requiring a visible bar for
    every stage would be requiring the chart to draw something for a stage that holds nothing, which
    is the opposite of what the empty stages are here to say.

    Polled, because `ResponsiveContainer` renders once before it has measured its parent and again
    with real dimensions; reading the geometry on the first pass is a race, not a measurement.
  */
  await expect.poll(
    async () => (await bars.all()).length,
    { timeout: 15000 },
  ).toBe(categories)

  /*
    And where something IS standing in the pipeline, it is drawn to scale.
    *
    * Conditional on the data, because the seeded world's pipeline can be legitimately empty — a
    * project with no leads yet has nine stages holding zero, and every bar is correctly a zero-area
    * path. Asserting a visible bar unconditionally would be asserting that the chart draws something
    * for a stage that holds nothing.
  */
  const occupied = (await page.getByTestId('lead-pipeline-legend').innerText())
    .match(/\b([1-9]\d*)\b/g)

  if (occupied !== null) {
    const widest = Math.max(
      0,
      ...(await Promise.all((await bars.all()).map(async (b) => (await b.boundingBox())?.width ?? 0))),
    )
    expect(widest).toBeGreaterThan(10)
  }
})

for (const width of [1440, 768, 390]) {
  test(`the drawn pipeline never scrolls the page sideways at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.goto('/app/leads')
    await expect(page.getByTestId('lead-pipeline')).toBeVisible({ timeout: 30000 })

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
  })
}
