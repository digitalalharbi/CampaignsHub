import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * VIZ-REQUESTS-001 — the inbox's three cards, drawn by the product's own layer.
 *
 * The unit tests hold the composition's arithmetic; this holds the drawing, because recharts renders
 * nothing in jsdom and a chart asserted there passes whether or not it drew.
 */
test.use({ storageState: AUTH.owner })

test('the status and type breakdowns draw banded bars against a category axis', async ({ page }) => {
  await page.goto('/agency/requests')
  await switchToEnglish(page)

  const chart = page.getByTestId('requests-by-status')
  await expect(chart).toBeVisible({ timeout: 30000 })

  const bars = chart.locator('.recharts-bar-rectangle')
  const categories = await chart.locator('.recharts-yAxis .recharts-cartesian-axis-tick').count()

  /*
    Polled: `ResponsiveContainer` renders once before it has measured its parent and again with real
    dimensions, so reading the geometry on the first pass is a race rather than a measurement.
  */
  await expect.poll(async () => (await bars.all()).length, { timeout: 15000 }).toBe(categories)
  await expect(page.getByTestId('requests-by-status-legend')).toBeVisible()
})

test('the SLA card is one divided bar, and names what has no SLA at all', async ({ page }) => {
  await page.goto('/agency/requests')
  await switchToEnglish(page)

  await expect(page.getByTestId('sla-mix-bar')).toBeVisible({ timeout: 30000 })

  /*
    «On track» used to absorb the requests with no deadline, reporting a promise as kept where no
    promise exists. On the seeded world that is every request, so the bar reading «No SLA set 100%»
    IS the regression test: before the fix this card was entirely green.
  */
  const legend = page.getByTestId('sla-mix-legend')
  await expect(legend).toBeVisible()
  await expect(page.getByTestId('sla-mix-bar')).toHaveAttribute('aria-label', /Requests/)
})

test('an Arabic category label ends where the plot begins, rather than running under the bars', async ({ page }) => {
  await page.goto('/agency/requests')

  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  const chart = page.getByTestId('requests-by-status')
  await expect(chart).toBeVisible({ timeout: 30000 })

  const wrapper = chart.locator('.recharts-wrapper').first()
  const tick = wrapper.locator('.recharts-yAxis .recharts-cartesian-axis-tick text').first()
  await expect(tick).toBeVisible()

  await expect.poll(async () => {
    const [label, grid] = [
      await tick.boundingBox(),
      await wrapper.locator('.recharts-cartesian-grid').first().boundingBox(),
    ]

    return {
      endsBeforeThePlot: (label?.x ?? 0) + (label?.width ?? 0) <= (grid?.x ?? 0) + 2,
      // A whole word. Anchored the wrong way it was clipped to a single glyph.
      wholeWord: (label?.width ?? 0) > 20,
    }
  }, { timeout: 15000 }).toEqual({ endsBeforeThePlot: true, wholeWord: true })
})

for (const width of [1440, 768, 390]) {
  test(`the drawn inbox never scrolls the page sideways at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.goto('/agency/requests')
    await expect(page.getByTestId('sla-mix-bar')).toBeVisible({ timeout: 30000 })

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
  })
}
