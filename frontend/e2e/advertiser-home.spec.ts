import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * ADV-HOME-001 — the advertiser's own dashboard, signed in as the advertiser (not the agency owner).
 *
 * The Owner saw the advertiser portal unchanged while the agency's grew. This drives the real `/app` session:
 * the dashboard names the advertiser's projects and switches between them in place, states where this
 * project's data comes from, and stays inside the phone's width.
 */
test.use({ storageState: AUTH.advertiser })

test('the dashboard names every project and switches between them in place', async ({ page }) => {
  await page.goto('/app/dashboard')

  const strip = page.getByTestId('adv-projects')
  await expect(strip).toBeVisible({ timeout: 30_000 })
  const projects = strip.locator('button[data-testid^="adv-project-"]')
  expect(await projects.count()).toBeGreaterThan(1)
  await expect(strip.locator('button[aria-current="true"]')).toHaveCount(1)

  const other = strip.locator('button:not([aria-current="true"])').first()
  const name = (await other.locator('span').first().textContent())?.trim() ?? ''
  await other.click()
  await expect(strip.locator('button[aria-current="true"]')).toContainText(name)
  await expect(page).toHaveURL(/\/app\/dashboard/)
})

test('the dashboard states this project’s ads, store and GA4 sources, each linking to where it is fixed', async ({ page }) => {
  await page.goto('/app/dashboard')

  const sources = page.getByTestId('adv-sources')
  await expect(sources).toBeVisible({ timeout: 30_000 })
  for (const key of ['ads', 'store', 'ga4']) {
    const tile = page.getByTestId(`adv-source-${key}`)
    await expect(tile).toBeVisible()
    await expect(tile).toHaveAttribute('data-state', /connected|unassigned|other_project|none|error/)
    await expect(tile).toHaveAttribute('href', /^\/app\//)
  }
})

test('on a phone the dashboard context stays inside the screen', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/app/dashboard')
  await expect(page.getByTestId('adv-context')).toBeVisible({ timeout: 30_000 })

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)
})
