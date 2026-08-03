import { expect, test } from '@playwright/test'
import { AUTH, seededProject, switchToEnglish, selectProject } from './helpers'

/**
 * Core campaigns paths (authenticated as the demo owner via reused storage state).
 * All external/platform data is Sandbox (Demo), never production.
 */

/**
 * CAMPAIGN-010 gave the campaigns page five view modes and it opens on the overview, so the card list
 * is one click away. Every spec that works with cards goes through here.
 */
async function openCardsView(page: import('@playwright/test').Page) {
  await page.getByTestId('view-cards').click()
}

/**
 * The project whose campaigns carry real demo data.
 *
 * These specs used to trust that «a demo project is auto-selected by the switcher». That held while
 * the seeded projects were the only ones, and stopped holding as the suite grew: `campaigns-linking`
 * creates a project of its own, and once it exists the switcher's default can land there. The spec
 * then opened `E2E Link B <timestamp>` — a throwaway with no platform bindings — and timed out
 * waiting for a Link button that campaign could never have shown. Firefox only, because the project
 * did not exist yet when chromium ran.
 *
 * Pinning by NAME makes each run independent of what has run before it, which is the same fix
 * `pinnedProject` already applies elsewhere and the fourth time this suite has outgrown a selector
 * that guessed.
 */
const SEEDED_PROJECT = 'Growth — Acquisition'

/**
 * A campaign carrying real demo data, rather than whichever card happens to sort first.
 *
 * The specs below read a campaign's PERFORMANCE and its linked platform campaigns, and the throwaway
 * campaigns this file creates have neither — they are brand new and connected to nothing. With the
 * project pinned, the only throwaways that can appear here are this file's own.
 */
function seededCampaignCard(page: import('@playwright/test').Page) {
  return page.getByTestId('campaign-card').filter({ hasNotText: 'E2E ' }).first()
}

/*
 * The ADVERTISER account (LOGIN-002).
 *
 * This file exercises the advertiser portal's campaign surface — its project switcher, its view
 * modes, its mobile rail. It used to sign in as the demo AGENCY and assert that chrome anyway,
 * which only worked while `/app` had no portal guard: an agency operator now lands in `/agency`,
 * where the same engine renders without a project switcher because an agency picks a CLIENT first.
 */
test.use({ storageState: AUTH.advertiser })

test('create a unified campaign and see it in the list', async ({ page }) => {
  await page.goto('/app/campaigns')
  await switchToEnglish(page)

  // A demo project is auto-selected by the switcher. The page opens on the overview, which renders four
  // charts — wait for the view switcher to be interactive before clicking anything, otherwise a slow
  // parallel run can time out on a button that is still behind chart layout work.
  await expect(page.getByTestId('view-overview')).toBeVisible({ timeout: 20000 })
  await page.getByRole('button', { name: /New campaign|حملة جديدة/ }).click()

  const name = `E2E Campaign ${Date.now()}`
  await page.getByLabel(/Campaign name|اسم الحملة/).fill(name)
  await page.getByRole('button', { name: /^Save$|^حفظ$/ }).click()

  // Refetched from the API — the new campaign appears once the list view is shown.
  await openCardsView(page)
  await expect(page.getByText(name)).toBeVisible()
})

test('open a campaign detail and switch tabs', async ({ page }) => {
  await selectProject(page, await seededProject(page.request, SEEDED_PROJECT))
  await page.goto('/app/campaigns')
  await switchToEnglish(page)

  await openCardsView(page)
  await seededCampaignCard(page).click()
  await expect(page).toHaveURL(/\/campaigns\/[^/]+\/[^/]+$/)

  /*
   * Performance tab renders the real charts (Spend vs Revenue) from the campaign metrics API.
   *
   * 20s, not 15s, and the difference is measured rather than guessed: this assertion waits on a
   * metrics round-trip AND a recharts mount, and 15s cleared it comfortably in isolation (three runs,
   * ~14s each INCLUDING auth setup) while failing once during a full three-browser gate. That is a
   * budget sized for an idle machine, on the one wait in this file that depends on two slow things at
   * once. 20s is what every other data-then-chart wait in this suite already allows.
   */
  await page.getByRole('tab', { name: /Performance|الأداء/ }).click()
  await expect(page.getByText(/الإنفاق مقابل الإيرادات/)).toBeVisible({ timeout: 20000 })

  // Platforms tab lists linked external campaigns (or an empty state).
  await page.getByRole('tab', { name: /Platforms|المنصات/ }).click()
})

test('link-external modal opens and labels sandbox data as Demo', async ({ page }) => {
  await selectProject(page, await seededProject(page.request, SEEDED_PROJECT))
  await page.goto('/app/campaigns')
  await switchToEnglish(page)
  await openCardsView(page)
  await seededCampaignCard(page).click()

  await page.getByRole('tab', { name: /Platforms|المنصات/ }).click()
  await page.getByRole('button', { name: /Link external campaign|ربط حملة خارجية/ }).click()

  // The modal makes clear this is demo data, not a production connection.
  await expect(page.getByText(/Sandbox data is demo|بيانات Sandbox تجريبية/)).toBeVisible()
})
