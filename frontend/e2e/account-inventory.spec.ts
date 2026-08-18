import { expect, test } from '@playwright/test'
import { AUTH, E2E_ORIGIN } from './helpers'

/**
 * COMMAND-CENTER §§7–20 — the inventory, rendered, against real discovered accounts.
 *
 * ## What this catches that the unit tests cannot
 *
 * The panel's unit tests hold what it renders from a fixed payload. What they cannot hold is whether
 * the whole path works: the route is reachable, the tenant scope resolves, the query the panel sends
 * is one the server accepts, and the states it gets back are the ones it renders. Every one of those
 * has been broken at least once in this codebase by a change that left every unit test green.
 *
 * The sandbox connection is established through the API rather than by clicking through OAuth, for
 * the same reason as `connection-wizard.spec.ts`: no real provider credential exists on any install
 * in this repository.
 *
 * ## Why this signs in as the AGENCY
 *
 * The seeded sources — Meta, Google, TikTok, Snapchat ad accounts and a Salla store — belong to
 * `demo-agency`. Running as the advertiser first produced an empty inventory, and querying the gate
 * database showed exactly why: every discovered account is another tenant's, so zero rows was the
 * correct answer and the spec was asking the wrong tenant. That is tenant isolation working, and it
 * is worth keeping in mind before reading an empty list here as a defect.
 */
test.use({ storageState: AUTH.owner })

/** The agency portal. `/app/*` is the advertiser tree and is guarded (LOGIN-002). */
const INTEGRATIONS = '/agency/integrations'

async function connectSandbox(request: import('@playwright/test').APIRequestContext): Promise<void> {
  const projects = await request.get('/api/v1/projects', {
    headers: { Accept: 'application/json', Origin: E2E_ORIGIN },
  })
  const projectId = (await projects.json()).data[0].id as string

  await request.post(`/api/v1/projects/${projectId}/integrations/connect`, {
    headers: { Accept: 'application/json', Origin: E2E_ORIGIN },
  })
}

/** The panel has resolved — a row OR the empty state. Both are real answers; a spinner is not. */
async function inventoryResolved(page: import('@playwright/test').Page): Promise<void> {
  await expect(
    page.locator('[data-testid="inventory-row"], [data-testid="inventory-empty"]').first(),
    'the account inventory never finished loading',
  ).toBeVisible({ timeout: 15000 })
}

test('the inventory lists discovered accounts by name, never by identifier', async ({ page }) => {
  await connectSandbox(page.request)
  await page.goto(INTEGRATIONS)
  await inventoryResolved(page)

  const rows = page.locator('[data-testid="inventory-row"]')
  expect(await rows.count(), 'the sandbox connection discovered no accounts').toBeGreaterThan(0)

  const first = rows.first()

  /*
   * The heading is words. Asserted as «not a bare UUID» rather than against a fixed string, because
   * the sandbox's account names are seed data and pinning them here would make this spec a test of
   * the seeder.
   */
  const name = (await first.locator('span').first().innerText()).trim()
  expect(name, 'a raw UUID reached the screen where a name belongs').not.toMatch(
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
  )
  expect(name.length, 'the name is empty').toBeGreaterThan(0)

  // The identifier is present, and labelled as a reference rather than presented as the name.
  await expect(first.getByText(/المعرّف:|Reference:/)).toBeVisible()
})

test('an account with no project says so, and offers no history to pull', async ({ page }) => {
  await connectSandbox(page.request)
  await page.goto(INTEGRATIONS)
  await inventoryResolved(page)

  const unlinked = page.locator('[data-testid="inventory-row"][data-linked="false"]').first()
  await expect(unlinked, 'the sandbox connection produced no unlinked account').toBeVisible()

  /*
   * INTEG-RUNTIME §5 — the reason nothing is happening to it, said in words rather than implied by
   * an empty cell. There is no «enable» to press: enabling an account attached nothing, synced
   * nothing and cost nothing, so the step was removed rather than explained.
   */
  await expect(unlinked.getByText(/غير مرتبط بمشروع|Not linked to a project/)).toBeVisible()
  await expect(
    unlinked.getByRole('button', { name: /سحب بيانات سابقة|Pull history/ }),
    'history has nowhere to land for an account no project owns',
  ).toHaveCount(0)
})

test('no curation step is offered, because none of them ever did anything', async ({ page }) => {
  await connectSandbox(page.request)
  await page.goto(INTEGRATIONS)
  await inventoryResolved(page)

  const main = page.locator('main')
  await expect(main.getByRole('button', { name: /^تفعيل$|^Enable$/ })).toHaveCount(0)
  await expect(main.getByRole('button', { name: /^استبعاد$|^Exclude$/ })).toHaveCount(0)
  await expect(page.getByTestId('inventory-bulk-bar')).toHaveCount(0)
})

test('the inventory does not overflow a 320px phone', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 568 })
  await connectSandbox(page.request)
  await page.goto(INTEGRATIONS)
  await inventoryResolved(page)

  const overflow = await page.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth)

  expect(overflow, 'the integrations page scrolls sideways on a 320px screen').toBeLessThanOrEqual(0)
})
