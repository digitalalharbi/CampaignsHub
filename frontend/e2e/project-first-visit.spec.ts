import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * PROJECT-FIRST-VISIT-001 — a first visit to a project-scoped page is a choice on the page, not a dead end.
 *
 * With nothing persisted, Campaigns, Analytics and Reports show every reachable project under its
 * client, with what it is and whether it has reported; one click lands on that project's data and
 * the sidebar switcher follows.
 *
 * Runs as the AGENCY owner: an agency operator reaches several clients, and the scope switcher
 * deliberately auto-selects nothing for them — that is the dead end this closes. The advertiser's
 * portal has one workspace and its switcher already picks a project, so the chooser never renders
 * there (the first gate run proved it: `/app/campaigns` rendered the project surface directly).
 */
test.use({ storageState: AUTH.owner })

async function freshVisit(page: import('@playwright/test').Page, path: string) {
  await page.goto('/agency/dashboard')
  await page.evaluate(() => { localStorage.removeItem('campaign-hub-project-storage'); localStorage.removeItem('campaign-hub-agency-client') })
  await page.goto(path)
}

test('campaigns: the chooser lists reachable projects and a click lands on the campaigns', async ({ page }) => {
  await freshVisit(page, '/agency/campaigns')

  const chooser = page.getByTestId('project-chooser')
  await expect(chooser).toBeVisible({ timeout: 20_000 })
  const choices = chooser.locator('[data-testid^="project-choice-"]')
  expect(await choices.count()).toBeGreaterThan(0)
  await expect(choices.first()).toContainText(/حملة|campaigns|لم تصل بيانات بعد|No data/)

  await choices.first().click()
  await expect(page.getByTestId('project-chooser')).toHaveCount(0)
  // The page now carries the project's own surface: the view switch and either cards or rows.
  await expect(page.getByTestId('view-cards')).toBeVisible({ timeout: 20_000 })

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)
})

test('analytics and reports show the same chooser on a fresh visit', async ({ page }) => {
  await freshVisit(page, '/agency/analytics')
  await expect(page.getByTestId('project-chooser')).toBeVisible({ timeout: 20_000 })

  await page.goto('/agency/reports')
  await expect(page.getByTestId('reports-need-project').getByTestId('project-chooser')).toBeVisible({ timeout: 20_000 })
})
