import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, selectProject } from './helpers'

/**
 * TYPOGRAPHY-PRODUCT-POLISH-001 — the report list a phone can actually read.
 *
 * The list has two views and a toggle between them, and the toggle is a DESKTOP choice: the table is
 * 720px at its narrowest, so on a 390px screen it became a sideways scroll through a name broken
 * over three lines and a date range broken around its arrow. A phone gets the card list whichever
 * view is selected, with the same rows in the same order.
 *
 * What this catches that a unit test cannot: the fallback is CSS, so it is only true if the built
 * stylesheet says so at that width — which is exactly the kind of thing a class rename breaks
 * silently.
 */
test.use({ storageState: AUTH.advertiser })

test('the report list is readable on a phone, and is a table on a desktop', async ({ page }) => {
  /*
   * Open a project that HAS reports. The app opens the advertiser's default project, and a spec that
   * creates a project for this advertiser moves that default to one with no reports — the list was
   * then correctly empty and this check failed on a fact about another spec (Firefox CI, #755).
   */
  const projects = ((await (await page.request.get('/api/v1/projects', { headers: API_HEADERS })).json()).data ?? []) as Array<{ id: string }>
  let withReports: string | null = null
  for (const p of projects) {
    const r = await page.request.get(`/api/v1/projects/${p.id}/reports`, { headers: API_HEADERS })
    if (r.ok() && ((await r.json()).data?.reports ?? []).length > 0) { withReports = p.id; break }
  }
  expect(withReports, 'the advertiser has no project with a saved report to list').not.toBeNull()
  await selectProject(page, withReports!)

  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/app/reports')

  await expect(page.locator('main')).toBeVisible({ timeout: 20000 })

  // Either view on a phone renders cards; the table is not laid out at this width.
  await expect
    .poll(async () => await page.locator('[data-testid="report-card"]:visible').count(), { timeout: 20000 })
    .toBeGreaterThan(0)
  expect(await page.locator('main table:visible').count(), 'a five-column table cannot be read on a phone').toBe(0)

  const overflow = await page.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'the reports page scrolls sideways on a phone').toBeLessThanOrEqual(0)

  // The same page on a desktop still offers the table it was built for.
  await page.setViewportSize({ width: 1440, height: 900 })
  await expect.poll(async () => await page.locator('main table:visible').count(), { timeout: 20000 }).toBeGreaterThan(0)
})
