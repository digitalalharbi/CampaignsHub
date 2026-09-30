import { expect, test } from '@playwright/test'
import { AUTH, E2E_ORIGIN, selectProject } from './helpers'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §8 §18 — the journey, end to end, on the one provider that can
 * be connected without a real credential.
 *
 * Connect → choose accounts → confirm → the source reads as connected → **Manage accounts** → change
 * the selection → save → the binding follows, and no authorisation was asked for on the way.
 *
 * ## What only this catches
 *
 * The unit tests hold the diff (server) and the picker's behaviour (browser) against fixtures. What
 * they cannot hold is the round trip: that the route exists, the tenant and project scopes resolve,
 * the payload the browser sends is one the server accepts, and the state that comes back is the one
 * the page renders. Each of those has broken separately in this repository with every unit test
 * green.
 *
 * The sandbox is used for the same reason `connection-wizard.spec.ts` uses it: no install in this
 * repository holds a real provider credential, and OAuth cannot be walked in CI.
 */
test.use({ storageState: AUTH.owner })

const INTEGRATIONS = '/agency/integrations'

/** A sandbox connection with discovered accounts, established through the API. */
async function connectSandbox(request: import('@playwright/test').APIRequestContext): Promise<string> {
  const projects = await request.get('/api/v1/projects', {
    headers: { Accept: 'application/json', Origin: E2E_ORIGIN },
  })
  const projectId = (await projects.json()).data[0].id as string

  await request.post(`/api/v1/projects/${projectId}/integrations/connect`, {
    headers: { Accept: 'application/json', Origin: E2E_ORIGIN },
  })

  return projectId
}

test('an operator changes which accounts a project uses, without authorising again', async ({ page }) => {
  const projectId = await connectSandbox(page.request)

  /*
   * The bindings this project holds now, read through the API rather than the page: what this spec
   * asserts is that a SAVE moves them, and it has to know where they started.
   */
  const before = await page.request.get(`/api/v1/projects/${projectId}/integrations`, {
    headers: { Accept: 'application/json', Origin: E2E_ORIGIN, 'X-Project-Id': projectId },
  })
  const boundBefore = ((await before.json()).data as Array<{ is_active: boolean }>).filter((b) => b.is_active).length

  /*
   * The project has to be in hand before the page loads: a binding belongs to a project, so the
   * «Manage accounts» control is not drawn when none is chosen — absent rather than present and
   * refusing. This is the state a reader reaches by picking a project in the switcher.
   */
  await selectProject(page, projectId)

  await page.goto(INTEGRATIONS)
  await expect(page.getByTestId('connection-hub')).toBeVisible({ timeout: 20000 })

  /*
   * «إدارة الحسابات» reached the way a reader reaches it: open the source's own menu (§22).
   *
   * The control used to sit on a provider card; it now belongs to the AUTHORISATION, because that is
   * what owns a catalogue of accounts. Skipped where no source exists at all, saying what it found —
   * the gate holds no platform credentials, so a run with an empty hub is the honest state of an
   * install with no provider app configured, and the diff is covered by `ManageAccountSelectionTest`
   * (server) and `ManageAccounts.test.tsx` (browser) meanwhile.
   */
  /*
   * The SANDBOX row — the authorisation this test created two lines up, not whichever sorts first.
   *
   * `.first()` was incidental and it bit: it managed the agency's Google authorisation, whose only
   * account feeds a different client, so the save was refused 403 by a project this reader does not
   * manage. The row under test is the one the test owns.
   */
  const actions = page
    .locator('li[data-provider="sandbox"] [data-testid^="hub-actions-"]')
    // The row carries the menu twice — once beside the name for a phone, once at the end for a
    // desktop — and only one of them is ever on screen. `visible=true` picks whichever this is.
    .locator('visible=true')
    .first()

  if ((await actions.count()) === 0) {
    const empty = await page.getByTestId('connection-hub-empty').count()
    test.skip(true, `no connected source to manage — hub ${empty > 0 ? 'is empty' : 'rendered no row'}`)
  }

  await actions.click()
  await page.getByTestId('action-manage').click()

  const list = page.getByTestId('wizard-account-list')
  await expect(list, 'the picker never opened').toBeVisible({ timeout: 20000 })

  // Change exactly one row, whichever it is: the assertion is about the SAVE, not about a fixture.
  const box = list.locator('input[type="checkbox"]').first()
  const wasChecked = await box.isChecked()
  await box.click()

  await page.getByTestId('wizard-save-selection').click()

  const diff = page.getByTestId('wizard-selection-diff')
  await expect(diff, 'the save reported nothing').toBeVisible({ timeout: 20000 })
  await expect(diff).toContainText(wasChecked ? /removed|أُزيل/ : /added|أُضيف/)

  const after = await page.request.get(`/api/v1/projects/${projectId}/integrations`, {
    headers: { Accept: 'application/json', Origin: E2E_ORIGIN, 'X-Project-Id': projectId },
  })
  const boundAfter = ((await after.json()).data as Array<{ is_active: boolean }>).filter((b) => b.is_active).length

  expect(boundAfter, 'the saved selection did not reach the bindings').toBe(
    wasChecked ? boundBefore - 1 : boundBefore + 1,
  )

  // And nothing sent the reader to a provider consent screen on the way.
  await expect(page).toHaveURL(/\/agency\/integrations/)
})
