import { expect, test, type Page } from '@playwright/test'
import { API_HEADERS, AUTH, createCampaign, seedExternals, switchToEnglish, useProject } from './helpers'

/**
 * Full external-linking E2E (the acceptance-critical path):
 * link a Sandbox external to campaign A → try to link the SAME external to campaign B → prove the
 * backend returns 409 requires_confirmation and the UI shows the move-confirmation → confirm the
 * move → prove it moved → unlink → prove it returns to the unlinked pool.
 *
 * Preconditions (a fresh project with imported Sandbox externals) are set up via API for determinism;
 * the LINK / 409 / MOVE / UNLINK behaviour itself is exercised through the real UI.
 */
test.use({ storageState: AUTH.owner })

async function openCampaignLinkedTab(page: Page, name: string) {
  // The rebuilt list navigates via clickable campaign cards (no "Open" button).
  await page.getByTestId('campaign-card').filter({ hasText: name }).first().click()
  await expect(page).toHaveURL(/\/campaigns\/[^/]+\/[^/]+$/)
  await page.getByRole('tab', { name: /Platforms|المنصات/ }).click()
}

test('link → 409 move-confirmation → confirm move → unlink (full path)', async ({ page }) => {
  // Fresh project + imported Sandbox externals (set up via the authenticated page.request context).
  const projects = (await (await page.request.get('/api/v1/projects', { headers: API_HEADERS })).json())
    .data as Array<{ id: string }>
  const projectId = projects[1].id
  await seedExternals(page.request, projectId)
  await useProject(page, projectId)

  const stamp = Date.now()
  const campA = `E2E Link A ${stamp}`
  const campB = `E2E Link B ${stamp}`

  await page.goto('/campaigns')
  await switchToEnglish(page)
  await createCampaign(page, campA)
  await createCampaign(page, campB)

  const targetName = 'Sandbox Awareness'
  const linkBtn = { name: /^Link$|^ربط$/ }
  // The modal row = the smallest div that contains BOTH the external's name AND its Link button.
  const modalRow = (name: string) =>
    page
      .locator('div')
      .filter({ hasText: name })
      .filter({ has: page.getByRole('button', linkBtn) })
      .last()

  // --- Link a Sandbox external to campaign A ---
  await openCampaignLinkedTab(page, campA)
  await page.getByRole('button', { name: /Link external campaign|ربط حملة خارجية/ }).click()
  await expect(page.getByText(/Sandbox data is demo|بيانات Sandbox تجريبية/)).toBeVisible()
  await modalRow(targetName).getByRole('button', linkBtn).click()
  await page.getByRole('button', { name: 'Close' }).click()
  // Campaign A's Linked tab now lists it.
  await expect(page.getByText(targetName).first()).toBeVisible()

  // --- Try to link the SAME external to campaign B → 409 → move-confirm ---
  await page.goto('/campaigns')
  await openCampaignLinkedTab(page, campB)
  await page.getByRole('button', { name: /Link external campaign|ربط حملة خارجية/ }).click()
  await page.getByRole('checkbox').click() // show linked-elsewhere too ("Unlinked only" off)
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
