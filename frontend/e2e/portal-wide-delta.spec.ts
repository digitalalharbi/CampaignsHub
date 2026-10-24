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
  await page.route('**/api/v1/client/overview*', async (route) => {
    const res = await route.fetch()
    const body = await res.json()
    const movement = (body.data?.movement ?? {}) as Record<string, number | null>
    for (const k of ['impressions', 'clicks', 'results', 'spend', 'revenue', 'roas', 'cpa']) movement[k] = 26.81
    for (const k of Object.keys(movement)) movement[k] = 26.81
    body.data = { ...body.data, movement }
    await route.fulfill({ response: res, json: body })
  })

  await page.goto('/portal')
  const first = page.locator('[data-testid^="portfolio-move-"]').first()
  await expect(first, 'the movement pill is drawn from the overview').toBeVisible({ timeout: 30_000 })
  await expect(first).toContainText('2,681')
  await page.waitForLoadState('networkidle').catch(() => undefined)

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)

  // And no pill pokes out of the viewport on either side.
  const outside = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>('[data-testid^="portfolio-move-"]')]
    .map((el) => el.getBoundingClientRect())
    .filter((r) => r.left < 0 || r.right > document.documentElement.clientWidth).length)
  expect(outside, 'movement pills outside the screen').toBe(0)
})
