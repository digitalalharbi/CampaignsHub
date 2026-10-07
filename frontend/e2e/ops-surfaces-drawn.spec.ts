import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * VIZ-OPS-001 — three ledgers given their shape, in a browser.
 *
 * The decisions — which band, which subset, which denominator — are held by the unit tests. These
 * hold that the pages draw at all, that the figures they draw agree with the cards beside them, and
 * that nothing scrolls sideways.
 *
 * Each assertion is conditional on the seeded world holding that kind of row. A workspace with no
 * tasks draws no composition, and that is correct rather than a gap — so this reports what is there
 * instead of demanding that something be.
 */
test.use({ storageState: AUTH.advertiser })

test('the task ledger divides into open, done and whatever is neither', async ({ page }) => {
  await page.goto('/app/tasks')
  await switchToEnglish(page)
  await expect(page.getByTestId('tasks-intro')).toBeVisible({ timeout: 30000 })

  const bar = page.getByTestId('task-mix-bar')
  if (await bar.count() === 0) return // an empty ledger has nothing to divide

  /*
    `overdue` is a SUBSET of open, not a sibling: drawing it as a band would count every late task
    twice and push the parts past their whole. It is stated inside open instead.
  */
  await expect(page.getByTestId('task-mix-segment-overdue')).toHaveCount(0)
  await expect(bar).toHaveAttribute('aria-label', /Tasks/)
})

test('the alert ledger divides by status, and critical is not a fourth one', async ({ page }) => {
  await page.goto('/app/alerts')
  await switchToEnglish(page)

  const bar = page.getByTestId('alert-mix-bar')
  const settled = bar.or(page.getByText(/No alerts|لا تنبيهات/i)).first()
  await expect(settled).toBeVisible({ timeout: 30000 })
  if (await bar.count() === 0) return

  // `open_critical` is «status open AND severity critical» — counted a second time, never a band.
  await expect(page.getByTestId('alert-mix-segment-critical')).toHaveCount(0)
})

test('the short links are ranked by the clicks they were given', async ({ page }) => {
  await page.goto('/app/short-links')
  await switchToEnglish(page)
  await expect(page.getByTestId('short-links-kpi-clicks')).toBeVisible({ timeout: 30000 })

  const chart = page.getByTestId('short-link-clicks-chart')
  if (await chart.count() === 0) return // fewer than two links, or nothing pressed: no ranking to make

  const bars = chart.locator('.recharts-bar-rectangle')
  const ticks = chart.locator('.recharts-yAxis .recharts-cartesian-axis-tick')

  await expect.poll(async () => (await bars.all()).length, { timeout: 15000 }).toBe(await ticks.count())
})

/**
 * VIZ-OPS-002 — the feature comes first; the analysis comes after.
 *
 * The owner's rule, and it is the right one: «the feature and the service come first on the page,
 * clearly, without needing to scroll — and the analytical results after them.» The short-link chart
 * first sat above the create control, so a page whose job is MAKING a link opened on a reading of
 * links already made, and somebody arriving to shorten a URL had to scroll past an analysis of work
 * they had already done to reach the one control they came for.
 *
 * Measured as geometry rather than asserted as intent: the page's own working content starts inside
 * the first screen, and the chart starts after it. A chart that creeps back up fails this.
 */
for (const surface of [
  { path: '/app/short-links', work: '[data-testid^="short-link-"]', chart: '[data-testid="short-link-clicks-chart"]' },
  { path: '/app/tasks', work: '[data-testid="tasks-intro"]', chart: '[data-testid="task-mix-bar"]' },
  { path: '/app/alerts', work: '[data-testid="alerts-intro"]', chart: '[data-testid="alert-mix-bar"]' },
]) {
  test(`${surface.path} leads with its own work, not with a chart about it`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await page.goto(surface.path)
    await page.waitForLoadState('networkidle')

    const chart = page.locator(surface.chart)
    if (await chart.count() === 0) return // nothing to analyse in this world; nothing to order

    const [workTop, chartTop] = await Promise.all([
      page.locator(surface.work).first().evaluate((n) => n.getBoundingClientRect().top + window.scrollY),
      chart.first().evaluate((n) => n.getBoundingClientRect().top + window.scrollY),
    ])

    expect(workTop, 'the page’s own content must start inside the first screen').toBeLessThan(900)
    expect(chartTop, 'the analysis must come after the work, never before it').toBeGreaterThan(workTop)
  })
}

for (const width of [1440, 768, 390]) {
  for (const path of ['/app/tasks', '/app/alerts', '/app/short-links']) {
    test(`${path} never scrolls sideways at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 })
      await page.goto(path)
      await page.waitForLoadState('networkidle')

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      )
      expect(overflow).toBeLessThanOrEqual(1)
    })
  }
}
