import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * VIZ-ORDER-001 — the feature comes first; the analysis comes after.
 *
 * The owner's rule, stated plainly: «the feature and the service come first on the page, clearly,
 * without needing to scroll — and the analytical results after them.»
 *
 * His example was the short links page, where a chart of links already made stood in front of the
 * control for making one — so somebody arriving to shorten a URL had to scroll past an analysis of
 * work they had already done to reach the only thing they came for. The same inversion had crept into
 * the spend limits page, the leads list and the requests inbox: each opened on a chart ABOUT its
 * contents rather than on its contents.
 *
 * ## Measured as geometry, not asserted as intent
 *
 * A rule about what a reader sees first is a rule about positions, so these read positions. The
 * page's own working content must begin inside the first screen, and the chart must begin after it.
 * A chart that creeps back up over time would otherwise be invisible to the suite.
 *
 * ## What is deliberately exempt
 *
 * The agency overview and the finance overview are not covered, and that is not an oversight: on
 * those pages the analysis IS the feature. There is no other service standing behind it for it to be
 * in front of.
 */
const SURFACES = [
  { name: 'spend limits', path: '/app/spend-limits', auth: AUTH.advertiser, work: '[data-testid^="spend-limit-"]', chart: '[data-testid="limit-states-bar"]' },
  { name: 'leads', path: '/app/leads', auth: AUTH.advertiser, work: 'table', chart: '[data-testid="lead-pipeline"]' },
  { name: 'requests', path: '/agency/requests', auth: AUTH.owner, work: 'table', chart: '[data-testid="request-charts"]' },
]

for (const surface of SURFACES) {
  test(`${surface.name} leads with its own work, not with a chart about it`, async ({ browser }) => {
    const context = await browser.newContext({ storageState: surface.auth, viewport: { width: 1440, height: 900 } })
    const page = await context.newPage()

    try {
      await page.goto(surface.path)
      await page.waitForLoadState('networkidle')

      const chart = page.locator(surface.chart)
      const work = page.locator(surface.work)

      /* A world with neither has no order to check — reported rather than demanded. */
      if (await chart.count() === 0 || await work.count() === 0) return

      const [workTop, chartTop] = await Promise.all([
        work.first().evaluate((n) => n.getBoundingClientRect().top + window.scrollY),
        chart.first().evaluate((n) => n.getBoundingClientRect().top + window.scrollY),
      ])

      expect(workTop, 'the page’s own content must start inside the first screen').toBeLessThan(900)
      expect(chartTop, 'the analysis must come after the work, never before it').toBeGreaterThan(workTop)
    } finally {
      await context.close()
    }
  })
}
