import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * CAMPAIGNS-TABLE-001 — the campaigns table's metric presets and column chooser, in a real browser on seeded data.
 */
test.use({ storageState: AUTH.owner })

test('a preset swaps the metric columns and a hand-picked column survives a reload', async ({ page, request }) => {
  await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
  await page.goto('/agency/campaigns?view=table')

  const table = page.getByTestId('campaigns-table')
  await expect(table).toBeVisible({ timeout: 30_000 })
  await expect(table.getByTestId('campaigns-head-cost')).toBeVisible()
  await expect(table.locator('[data-testid="campaign-row"]').first()).toBeVisible()

  await page.getByTestId('campaigns-preset-engagement').click()
  await expect(table.getByTestId('campaigns-head-ctr')).toBeVisible()
  await expect(table.getByTestId('campaigns-head-cost')).toHaveCount(0)

  await page.getByTestId('campaigns-columns-toggle').click()
  await page.getByTestId('campaigns-column-revenue').check()
  await expect(table.getByTestId('campaigns-head-revenue')).toBeVisible()
  await expect(page.getByTestId('campaigns-preset-custom')).toBeVisible()

  await page.reload()
  await expect(page.getByTestId('campaigns-table').getByTestId('campaigns-head-revenue')).toBeVisible({ timeout: 30_000 })

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)

  // Leave the shared storage state as it was found.
  await page.getByTestId('campaigns-preset-performance').click()
})
