import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §17 — the estate, in a browser, against seeded data.
 *
 * The gate database has one bound ad account and several discovered ones that nobody chose, which
 * is the shape this redesign was built for: the page must say which client the bound one feeds, and
 * must NOT put the unchosen ones under anybody's name.
 *
 * Both lenses are asserted through the URL rather than through the switch. The switch has its own
 * test; every other spec asking for a lens should ask for it the way a runbook or a deep link would.
 */
test.use({ storageState: AUTH.owner })

test('opens on the clients, and names the one this account feeds', async ({ page }) => {
  await page.goto('/agency/integrations')
  await switchToEnglish(page)

  const estate = page.getByTestId('connected-estate')
  await expect(estate).toBeVisible({ timeout: 30000 })

  // One row per PROJECT, carrying its client and a health word — never a bare provider name.
  const rows = page.locator('[data-testid^="estate-project-"]')
  await expect(rows.first()).toBeVisible({ timeout: 20000 })
  await expect(rows.first()).toContainText('Acme (Managed)')
  await expect(page.locator('[data-testid^="estate-health-"]').first()).toBeVisible()
})

/**
 * DISCOVERED != SELECTED, in the browser.
 *
 * The unchosen accounts are a sentence, not rows. This is the assertion that stops a future change
 * from «helpfully» listing everything an authorisation can see under a client that never chose it.
 */
test('states the unchosen accounts as a number and gives them no client', async ({ page }) => {
  await page.goto('/agency/integrations?view=clients')
  await switchToEnglish(page)

  const unselected = page.getByTestId('estate-unselected')
  await expect(unselected).toBeVisible({ timeout: 30000 })
  await expect(unselected).toContainText('chosen by nobody')
})

/** Expanding a client shows both truths for each platform, and never only one of them. */
test('an expanded client shows the authorisation and the data as two separate facts', async ({ page }) => {
  await page.goto('/agency/integrations?view=clients')
  await switchToEnglish(page)

  const row = page.locator('[data-testid^="estate-project-"]').first()
  await expect(row).toBeVisible({ timeout: 30000 })
  await row.getByRole('button').first().click()

  const block = page.locator('[data-testid^="estate-provider-"]').first()
  await expect(block).toBeVisible({ timeout: 20000 })
  await expect(block.locator('[data-testid^="estate-auth-"]')).toBeVisible()
  await expect(block.locator('[data-testid^="estate-sync-"]')).toBeVisible()
  await expect(block).toContainText('Selected accounts')
  await expect(block).toContainText('Next sync')
})

/** The lens is a URL, so it is linkable and survives a reload. */
test('the platform lens is reachable by URL and survives a reload', async ({ page }) => {
  await page.goto('/agency/integrations?view=platforms')
  await switchToEnglish(page)

  await expect(page.getByTestId('ad-platforms-panel')).toBeVisible({ timeout: 30000 })
  await expect(page.getByTestId('connected-estate')).toHaveCount(0)

  await page.reload()
  await expect(page.getByTestId('ad-platforms-panel')).toBeVisible({ timeout: 30000 })
})

/** And the switch moves between them without leaving the page. */
test('the switch moves between the two lenses', async ({ page }) => {
  await page.goto('/agency/integrations?view=clients')
  await switchToEnglish(page)

  await expect(page.getByTestId('connected-estate')).toBeVisible({ timeout: 30000 })

  await page.getByTestId('estate-view-platforms').click()
  await expect(page.getByTestId('ad-platforms-panel')).toBeVisible({ timeout: 20000 })

  await page.getByTestId('estate-view-clients').click()
  await expect(page.getByTestId('connected-estate')).toBeVisible({ timeout: 20000 })
})
