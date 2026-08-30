import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

test.use({ storageState: AUTH.advertiser })

/**
 * AD-PREVIEW-001 — the ads table shows what ran, and opens it in place.
 *
 * The table listed ads by name and number and showed nothing of the ad itself: a reader deciding
 * which of eleven to stop was choosing between eleven strings. The thumbnail comes from the same
 * library endpoint every other surface reads, and clicking it opens the ad where the reader is
 * standing — this is a comparison table, and a navigation costs them the comparison.
 */
test('an ad opens in place from the analytics table', async ({ page }) => {
  await page.goto('/app/analytics?tab=ads')
  await expect(page.getByRole('tab', { name: /الإعلانات|^Ads$/ })).toBeVisible({ timeout: 30_000 })

  const thumb = page.locator('[data-testid^="ad-preview-open-"]').first()

  // The seed may carry no creative for any ad in the window — that is a real state, and the table
  // renders no thumbnail rather than an empty frame. Nothing to assert about the dialog then.
  if ((await thumb.count()) === 0) test.skip(true, 'no ad in this window has a creative to preview')

  await thumb.click()
  await expect(page.getByTestId('ad-preview-dialog')).toBeVisible()
  await expect(page.getByTestId('ad-preview-dialog-figures')).toBeVisible()

  await page.getByTestId('ad-preview-dialog-close').click()
  await expect(page.getByTestId('ad-preview-dialog')).toBeHidden()

  // The page did not navigate: the comparison the reader was in the middle of is still on screen.
  await expect(page).toHaveURL(/\/app\/analytics/)
})
