import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * PORTAL-DELTA-WIDTH-001 — a four-digit movement must not widen a phone.
 *
 * Found in CI on the first run after a UTC midnight: the seeded demo's comparison window slid, the previous period's figures were tiny,
 * and the client dashboard's three primary cards read «+2,681%». The pill is wider than the card's header row has room for at 375px, it
 * overflowed its card by 10px and the whole page scrolled sideways — on Firefox and WebKit, on every PR, while the same commit had been
 * green the day before. A property of the DATA the layout was never tested against.
 *
 * So the data is fixed here instead of left to the calendar: the real overview is fetched and every movement in it is replaced by +2,681%
 * before the page sees it. Read-only; nothing is written anywhere.
 */
test.use({ storageState: AUTH.client, viewport: { width: 375, height: 667 } })

test('a +2,681% movement stays inside its card and the page does not scroll sideways', async ({ page }) => {
  // CLIENT-DASHBOARD-PARITY-001 — the portal's cards are the agency dashboard's, read through the client's metrics route.
  await page.route('**/api/v1/client/projects/*/metrics/summary*', async (route) => {
    const res = await route.fetch()
    const body = await res.json()
    const deltas = (body.data?.deltas ?? {}) as Record<string, number | null>
    for (const k of Object.keys(deltas)) deltas[k] = 26.81
    for (const k of ['impressions', 'clicks', 'ctr', 'conversions']) deltas[k] = 26.81
    body.data = { ...body.data, deltas }
    await route.fulfill({ response: res, json: body })
  })

  await page.goto('/portal')
  // PORTAL-REPORT-HOME-001 — the home opens the client's shared report, or the live dashboard where none is shared.
  await expect(page.locator('[data-testid="portal-home-report"], [data-testid="client-kpis"]').first(), 'the home draws its figures').toBeVisible({ timeout: 30_000 })
  await page.waitForLoadState('networkidle').catch(() => undefined)

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)

  // And nothing in the cards pokes out of the viewport on either side.
  const outside = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>('[data-testid="client-kpis"] *, [data-testid="portal-home-report"] [data-testid$="-kpis"] *')]
    .map((el) => el.getBoundingClientRect())
    .filter((r) => r.width > 0 && (r.left < -1 || r.right > document.documentElement.clientWidth + 1)).length)
  expect(outside, 'card parts outside the screen').toBe(0)
})
