import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * AGENCY-DECISION-SURFACES-001 — Recommendations and Spend limits exist for the agency operator.
 *
 * Before: `/agency/recommendations` and `/agency/spend-limits` answered the not-found page while
 * the API accepted agency operators on both routes. Now both open from the rail, start on the
 * project choice when nothing is persisted, and one click lands on the project's own surface.
 */
test.use({ storageState: AUTH.owner })

async function freshVisit(page: import('@playwright/test').Page, path: string) {
  await page.goto('/agency/dashboard')
  await page.evaluate(() => { localStorage.removeItem('campaign-hub-project-storage'); localStorage.removeItem('campaign-hub-agency-client') })
  await page.goto(path)
}

test('recommendations: rail link, chooser on a fresh visit, a click lands on the action centre', async ({ page }) => {
  await freshVisit(page, '/agency/recommendations')
  await expect(page.getByText('الصفحة غير موجودة')).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'التوصيات' })).toBeVisible()

  const chooser = page.getByTestId('project-chooser')
  await expect(chooser).toBeVisible({ timeout: 20_000 })
  await chooser.locator('[data-testid^="project-choice-"]').first().click()
  await expect(page.getByTestId('project-chooser')).toHaveCount(0)
  // The intro names the chosen project; the counts badge only exists when the team wrote some.
  await expect(page.getByTestId('recommendations-intro')).toBeVisible({ timeout: 20_000 })
  await expect(page.getByRole('heading', { name: 'ما يحتاج قرارًا الآن' })).toBeVisible()

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)
})

test('spend limits: rail link, chooser on a fresh visit, a click lands on the limits', async ({ page }) => {
  await freshVisit(page, '/agency/spend-limits')
  await expect(page.getByText('الصفحة غير موجودة')).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'حدود الإنفاق' })).toBeVisible()

  const chooser = page.getByTestId('project-chooser')
  await expect(chooser).toBeVisible({ timeout: 20_000 })
  await chooser.locator('[data-testid^="project-choice-"]').first().click()
  await expect(page.getByTestId('project-chooser')).toHaveCount(0)
  await expect(page.getByTestId('spend-limits-enforcement')).toBeVisible({ timeout: 20_000 })

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)
})
