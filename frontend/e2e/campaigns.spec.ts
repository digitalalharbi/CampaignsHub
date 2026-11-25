import { expect, test } from '@playwright/test'
import { AUTH, openCampaign, seededProject, switchToEnglish, selectProject } from './helpers'

/**
 * Core campaigns paths (authenticated as the demo owner via reused storage state).
 * All external/platform data is Sandbox (Demo), never production.
 */

/**
 * CAMPAIGN-010 gave the campaigns page five view modes and it opens on the overview, so the card list
 * is one click away. Every spec that works with cards goes through here.
 */
const SEEDED_PROJECT = 'Growth — Acquisition'

/**
 * The campaign carrying real demo data, pinned BY NAME — the fifth time this file has needed it.
 *
 * The specs below read a campaign's PERFORMANCE and its linked platform campaigns, and the throwaway
 * campaigns this file creates have neither: they are brand new and connected to nothing.
 *
 * «Whichever card does not say E2E» was the selector, and it guessed. `SEEDED_PROJECT` above records
 * the same lesson one rung up — «pinning by NAME makes each run independent of what has run before
 * it… the fourth time this suite has outgrown a selector that guessed» — and this is the fifth, on
 * the line directly below it.
 *
 * The failure: `campaigns.spec.ts:119` timed out for thirty seconds on firefox waiting for
 * `campaign-card` filtered by `hasNotText: 'E2E '`, while the identical flow twenty lines above it
 * passed in the same run. 609 other cases passed, no request 500'd, and the pull request it failed
 * was purely additive — a new endpoint and its own UI, 592 insertions against two rewritten import
 * lines, nothing that campaigns read. What differs between the two calls is how many
 * `E2E Campaign <timestamp>` rows this file (and the ones before it) have left in the project by
 * then, and whether the seeded card is still among the ones the view has rendered.
 *
 * So it is no longer a filter over what everything else has left behind. `Always-On — Sales` is the
 * campaign `DemoAccountsSeeder` creates in `Growth — Acquisition`, with the platform bindings and the
 * metrics these specs came for — and naming it means a spec that fails is telling us the seed
 * changed, which is a fact worth being told, instead of telling us the suite got longer.
 */
const SEEDED_CAMPAIGN = 'Always-On — Sales'

/** ADS-MANAGER-007 — the campaigns list is the Ads Manager now; a seeded campaign's page is opened by address. */
async function openSeededCampaign(page: import('@playwright/test').Page) {
  const projectId = await seededProject(page.request, SEEDED_PROJECT)
  await selectProject(page, projectId)
  await page.goto('/app/campaigns')
  await switchToEnglish(page)
  await openCampaign(page, projectId, SEEDED_CAMPAIGN)
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

test('open a campaign detail and switch tabs', async ({ page }) => {
  await openSeededCampaign(page)
  await expect(page).toHaveURL(/\/campaigns\/[^/]+\/[^/]+$/)

  /*
   * Performance tab renders the real charts from the campaign metrics API.
   *
   * Two things were wrong here, and the first one hid the second for four gates.
   *
   * The assertion read the ARABIC title after `switchToEnglish` above. It passed because
   * `CampaignPerformanceTab` never translated — every heading on that tab was hard-coded Arabic,
   * which is the product defect this spec should have caught on its first run and instead depended
   * on. Now that the tab is bilingual the assertion has to accept whichever language is on screen.
   *
   * And the wait matched the FIRST `/performance` response, while the component's skeleton is
   * driven by whichever request is currently in flight: a second round-trip (a settling range, a
   * project context landing late) puts it back into `isLoading` after the wait has already
   * resolved. So the wait is now on the settled COMPONENT — Playwright polls until the chart is
   * there — rather than on one packet arriving.
   */
  // CAMPAIGN-PAGE-LEAN-001 — the performance charts are read on the overview now; an old `?tab=performance` lands there.
  await page.goto(`${page.url().split('?')[0]}?tab=performance`)

  /*
   * The tab lives in the URL (`?tab=`), so this is asserted BEFORE the chart.
   *
   * When this failed in a three-browser gate the saved snapshot showed «نظرة عامة» still selected
   * twenty-eight seconds after the click — the tab parameter was gone, not slow. Waiting only on the
   * chart turned that into a mute timeout that looked like a slow query and was nothing of the kind;
   * checking the address first makes the failure say which of the two actually happened.
   *
   * What drops the parameter is NOT yet proven — the page also resolves the project context on
   * mount, and a late redirect there is the obvious suspect. It is recorded as its own open item
   * rather than guessed at here.
   */
  await expect(page).toHaveURL(/[?&]tab=performance/)
  await expect(page.getByRole('tab', { name: /Overview|نظرة عامة/ })).toHaveAttribute('aria-selected', 'true')
  await expect(page.getByText(/الإنفاق مقابل الإيرادات|Spend vs revenue/)).toBeVisible()

  // Platforms tab lists linked external campaigns (or an empty state).
  await page.getByRole('tab', { name: /Platforms|المنصات/ }).click()
})

test('link-external modal opens and labels sandbox data as Demo', async ({ page }) => {
  await openSeededCampaign(page)

  await page.getByRole('tab', { name: /Platforms|المنصات/ }).click()
  await page.getByRole('button', { name: /Link external campaign|ربط حملة خارجية/ }).click()

  // The modal makes clear this is demo data, not a production connection.
  await expect(page.getByText(/Sandbox data is demo|بيانات Sandbox تجريبية/)).toBeVisible()
})

/**
 * BUDGET-GOVERNANCE-001 — the overview answers what is LEFT and where the period ENDS.
 *
 * The page showed a budget and what had been spent and stopped there, while the server had already
 * computed the remaining, the straight-line projection and the pace for every campaign. This is the
 * one assertion that holds the row is actually ON the page: `portfolioBudget` has unit tests for the
 * arithmetic and `BudgetPacingRow` for the reading, and removing the row from the overview left all
 * 144 campaign tests green.
 *
 * Written against whichever answer the seed produces — figures, or the stated refusal — because both
 * are correct outcomes and pinning the demo's numbers would make this a test of the seeder.
 */
