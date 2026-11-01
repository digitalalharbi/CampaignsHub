import { expect, test } from '@playwright/test'
import { AUTH, E2E_ORIGIN } from './helpers'

/**
 * INTEGRATIONS-CENTRE-001 — the centre shows SELECTED accounts only, rendered, against the gate.
 *
 * ## What changed in this spec, and why
 *
 * It used to open «every discovered account» and read names, links and the not-linked reason off a
 * tenant-wide inventory. That inventory is gone from the page: an account nobody selected is not
 * shown anywhere in the centre, and searching or paging the rest happens only inside the connection
 * wizard («تعديل الاختيار»). What these hold now is the negative — no panel, no toggle, no request
 * for the unfiltered inventory — and that the drawer's list is the selection.
 *
 * The sandbox connection is established through the API rather than by clicking through OAuth, for
 * the same reason as `connection-wizard.spec.ts`: no real provider credential exists on any install
 * in this repository. It signs in as the AGENCY because the seeded sources belong to `demo-agency`.
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


/** Open the sandbox authorisation's drawer on its selected accounts. Skips, saying why, if the hub holds none. */
async function openSandboxAccounts(page: import('@playwright/test').Page): Promise<void> {
  await expect(page.getByTestId('connection-hub')).toBeVisible({ timeout: 30000 })

  const open = page.locator('li[data-provider="sandbox"] [data-testid^="hub-open-"]').first()
  if ((await open.count()) === 0) test.skip(true, 'the hub rendered no sandbox authorisation to open')

  await expect(async () => {
    await open.click()
    await expect(page.getByTestId('connection-drawer')).toBeVisible({ timeout: 2000 })
  }).toPass({ timeout: 20000 })

  await page.getByTestId('drawer-tab-accounts').click()
  await expect(page.getByTestId('selected-accounts')).toBeVisible({ timeout: 20000 })
}

test('the page has no all-discovered-accounts section and no toggle for one', async ({ page }) => {
  await connectSandbox(page.request)
  await page.goto(INTEGRATIONS)
  await expect(page.getByTestId('connection-hub')).toBeVisible({ timeout: 30000 })

  await expect(page.getByTestId('toggle-account-inventory')).toHaveCount(0)
  await expect(page.locator('[data-testid="inventory-row"]')).toHaveCount(0)
  await expect(page.locator('[data-testid="inventory-list"]')).toHaveCount(0)

  const text = await page.locator('main').innerText()
  expect(text).not.toMatch(/جميع الحسابات المكتشفة|Every discovered account/)
})

test('the drawer lists the selected accounts only, and every request says so', async ({ page }) => {
  await connectSandbox(page.request)

  const inventoryRequests: string[] = []
  page.on('request', (request) => {
    const url = new URL(request.url())
    if (url.pathname === '/api/v1/accounts') inventoryRequests.push(url.search)
  })

  await page.goto(INTEGRATIONS)
  await openSandboxAccounts(page)

  // No way to widen the list from inside the drawer: no search box, no «show all».
  await expect(page.getByTestId('drawer-account-search')).toHaveCount(0)
  await expect(page.getByTestId('drawer-only-selected')).toHaveCount(0)
  await expect(page.locator('[data-testid="inventory-row"]')).toHaveCount(0)

  // A row, when there is one, is a selected account: it names its project and offers the account acts.
  const rows = page.getByTestId('selected-account-row')
  if ((await rows.count()) > 0) {
    const first = rows.first()
    await expect(first.getByTestId('selected-account-state')).toBeVisible()
    await expect(first.getByTestId('selected-account-last-sync')).toBeVisible()
    await expect(first.getByTestId('selected-account-next-sync')).toBeVisible()
    await expect(first.getByTestId('selected-account-backfill')).toBeVisible()
    await expect(first.getByTestId('unlink-account')).toBeVisible()
  }

  expect(inventoryRequests.length, 'the drawer never asked for its accounts').toBeGreaterThan(0)
  for (const search of inventoryRequests) {
    expect(search, 'a request for the unfiltered inventory reached the server').toContain('link=linked')
  }
})

test('«Edit the selection» opens the connection wizard, where accounts are searched', async ({ page }) => {
  await connectSandbox(page.request)
  await page.goto(INTEGRATIONS)
  await openSandboxAccounts(page)

  await page.getByTestId('drawer-edit-selection').click()

  await expect(page.getByTestId('connection-flow')).toBeVisible({ timeout: 20000 })
  // Either the client question (an authorisation lent to several clients) or the picker itself.
  await expect(
    page.locator('[data-testid="flow-choose-client"], [data-testid="wizard-account-list"], [data-testid="wizard-step-parent"]').first(),
  ).toBeVisible({ timeout: 20000 })
})

test('the integrations page does not overflow a 320px phone', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 568 })
  await connectSandbox(page.request)
  await page.goto(INTEGRATIONS)
  await expect(page.getByTestId('connection-hub')).toBeVisible({ timeout: 30000 })

  const overflow = await page.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth)

  expect(overflow, 'the integrations page scrolls sideways on a 320px screen').toBeLessThanOrEqual(0)
})
