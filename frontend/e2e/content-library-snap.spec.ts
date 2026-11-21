import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * CONTENT-LIBRARY-SNAP-001 — the content area opens the way the platforms' creative libraries do: one title, three tabs, a table.
 * `?view=grid` is the analysis view the older specs drive; the bare address is this one.
 */
test.use({ storageState: AUTH.owner })

test('the library opens on the creatives table under three tabs, and the media library has its folders', async ({ page }) => {
  await page.goto('/agency/content')
  await expect(page.getByTestId('library-tabs')).toBeVisible({ timeout: 20000 })
  await expect(page.getByRole('tab')).toHaveCount(3)
  await expect(page.getByTestId('creatives-table')).toBeVisible()
  await page.getByTestId('library-tab-media').click()
  await expect(page.getByTestId('media-folder-files')).toBeVisible()
  await page.getByTestId('library-tab-insights').click()
  await expect(page.getByTestId('creative-insights')).toBeVisible()
})

test('the library fits a phone without sideways scroll', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/agency/content')
  await expect(page.getByTestId('library-tabs')).toBeVisible({ timeout: 20000 })
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow).toBeLessThanOrEqual(1)
})
