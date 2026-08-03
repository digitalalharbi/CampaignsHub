import { expect, test, type Page } from '@playwright/test'
import { AUTH, createCampaign, pinnedProject, seedExternals, switchToEnglish, unlinkExternalByName, selectProject } from './helpers'

/**
 * Full external-linking E2E (the acceptance-critical path):
 * link a Sandbox external to campaign A → try to link the SAME external to campaign B → prove the
 * backend returns 409 requires_confirmation and the UI shows the move-confirmation → confirm the
 * move → prove it moved → unlink → prove it returns to the unlinked pool.
 *
 * Preconditions (a fresh project with imported Sandbox externals) are set up via API for determinism;
 * the LINK / 409 / MOVE / UNLINK behaviour itself is exercised through the real UI.
 */
/*
 * The ADVERTISER account (LOGIN-002).
 *
 * This file exercises the advertiser portal's campaign surface — its project switcher, its view
 * modes, its mobile rail. It used to sign in as the demo AGENCY and assert that chrome anyway,
 * which only worked while `/app` had no portal guard: an agency operator now lands in `/agency`,
 * where the same engine renders without a project switcher because an agency picks a CLIENT first.
 */
test.use({ storageState: AUTH.advertiser })

/** The Sandbox external this test moves between two campaigns. */
const TARGET_EXTERNAL = 'Sandbox Awareness'

async function openCampaignLinkedTab(page: Page, name: string) {
  // The rebuilt list navigates via clickable campaign cards (no "Open" button), and the page opens on
  // the overview mode, so switch to the card list first (CAMPAIGN-010).
  await page.getByTestId('view-cards').click()
  await page.getByTestId('campaign-card').filter({ hasText: name }).first().click()
  await expect(page).toHaveURL(/\/campaigns\/[^/]+\/[^/]+$/)

  /*
   * Wait for everything ABOVE the tab strip before touching it.
   *
   * The related-entities panel sits between the header and the tabs and renders once its counts
   * arrive, which pushes the strip down. Playwright checks that the TAB is stable, not that the page
   * is — so under the full three-browser load, where that panel lands late, the strip could still
   * move between the hit-test and the mouse event and the click went nowhere. The page then sat on
   * Overview and the next step waited thirty seconds for a button that only exists under Platforms.
   *
   * Firefox only, because it was the browser slow enough to lose that race — the layout shift is
   * real in all three.
   */
  await expect(page.getByTestId('related-entities')).toBeVisible({ timeout: 20000 })

  const platforms = page.getByRole('tab', { name: /Platforms|المنصات/ })
  await platforms.click()
  // The tab lives in the query string, so this asserts the click actually took effect rather than
  // assuming it did.
  await expect(page).toHaveURL(/tab=platforms/)
}

test('link → 409 move-confirmation → confirm move → unlink (full path)', async ({ page }) => {
  // Fresh project + imported Sandbox externals (set up via the authenticated page.request context).
  /*
   * A project this spec names, not `projects[1]`.
   *
   * The list is neither ordered nor fixed — every registration run adds one — so that index landed
   * on a different project depending on what had run before. The spec passed alone and failed inside
   * the full gate, on whichever browser reached it after the list had grown.
   */
  const projectId = await pinnedProject(page.request, 'E2E Linking')
  await seedExternals(page.request, projectId)
  /*
   * State the precondition instead of inheriting it.
   *
   * This test links `Sandbox Awareness` to campaign A and only then expects the 409 from linking it
   * to B, so it has to START unlinked. That used to be true by accident — every run minted a fresh
   * Sandbox account and therefore a fresh, never-linked external — and stopped being true the moment
   * `seedExternals` began reusing the project's binding. An earlier run's link is now cleared here.
   */
  await unlinkExternalByName(page.request, projectId, TARGET_EXTERNAL)
  await selectProject(page, projectId)

  const stamp = Date.now()
  const campA = `E2E Link A ${stamp}`
  const campB = `E2E Link B ${stamp}`

  await page.goto('/app/campaigns')
  await switchToEnglish(page)
  await createCampaign(page, campA)
  await createCampaign(page, campB)

  const targetName = TARGET_EXTERNAL
  const linkBtn = { name: /^Link$|^ربط$/ }
  /*
   * The modal row, named by the component rather than guessed at.
   *
   * This used to be "the smallest div containing both the name and a Link button", which held while
   * exactly one external carried a given name. As soon as a second appeared — two projects each with
   * a Sandbox binding is enough — `.last()` picked a container with no button in it, and the failure
   * read as a missing row rather than as an ambiguous selector. It failed on whichever browser ran
   * after the first had seeded its own.
   */
  const modalRow = (name: string) =>
    page.locator(`[data-testid="link-external-row"][data-external-name="${name}"]`).first()

  // --- Link a Sandbox external to campaign A ---
  await openCampaignLinkedTab(page, campA)
  await page.getByRole('button', { name: /Link external campaign|ربط حملة خارجية/ }).click()
  await expect(page.getByText(/Sandbox data is demo|بيانات Sandbox تجريبية/)).toBeVisible()
  // Wait for the async external list to render THIS row before acting (avoids a race under heavy load).
  await expect(modalRow(targetName)).toBeVisible({ timeout: 15000 })
  await modalRow(targetName).getByRole('button', linkBtn).click()
  await page.getByRole('button', { name: 'Close' }).click()
  // Campaign A's Linked tab now lists it.
  await expect(page.getByText(targetName).first()).toBeVisible()

  // --- Try to link the SAME external to campaign B → 409 → move-confirm ---
  await page.goto('/app/campaigns')
  await openCampaignLinkedTab(page, campB)
  await page.getByRole('button', { name: /Link external campaign|ربط حملة خارجية/ }).click()
  await page.getByRole('checkbox').click() // show linked-elsewhere too ("Unlinked only" off)
  await expect(modalRow(targetName)).toBeVisible({ timeout: 15000 })
  await modalRow(targetName).getByRole('button', linkBtn).click()

  // The backend replied 409 requires_confirmation → the UI shows the move confirmation.
  await expect(page.getByText(/Move link|نقل الربط/)).toBeVisible()
  await page.getByRole('button', { name: /Confirm move|تأكيد النقل/ }).click()
  await expect(page.getByText(/Move link|نقل الربط/)).toHaveCount(0)
  await page.getByRole('button', { name: 'Close' }).click()
  // Campaign B now lists it (moved here).
  await expect(page.getByText(targetName).first()).toBeVisible()

  // --- Unlink from B → it returns to the unlinked pool ---
  await page.getByRole('button', { name: /^Unlink$|فك الربط/ }).click()
  await expect(page.getByText(/No linked platforms|لا منصات مرتبطة/)).toBeVisible()
})
