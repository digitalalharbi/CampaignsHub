import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §§22, 26 — what the Connection Hub must never fail to say.
 *
 * ## Why this spec was rewritten rather than deleted
 *
 * It used to walk a grid of provider cards and assert that each said WHICH kind of not-connected it
 * was. The grid is gone — the page is a table of authorisations now — but the claim underneath it
 * was never about cards: a surface that says «not connected» and nothing else tells a reader nothing
 * about whose move it is. The hub answers that with two chips per row and an explicit empty state,
 * and this holds it to that.
 *
 * ## What the gate can and cannot show
 *
 * The gate database holds no platform credentials, so nothing here is authorised by a real provider.
 * Whatever connections the seed does hold, the invariants below are true of any of them.
 */
test.use({ storageState: AUTH.owner })

test('the hub says which state every authorisation is in, on both axes', async ({ page }) => {
  await page.goto('/agency/integrations')
  await switchToEnglish(page)

  await expect(page.getByTestId('connection-hub')).toBeVisible({ timeout: 30000 })

  const rows = page.locator('[data-testid^="hub-row-"]')
  const count = await rows.count()

  if (count === 0) {
    // Empty is a state and is designed as one: it says what is missing and offers the one act.
    await expect(page.getByTestId('connection-hub-empty')).toBeVisible()
    await expect(page.getByTestId('hub-connect-empty')).toBeVisible()
    return
  }

  for (let i = 0; i < count; i++) {
    const id = ((await rows.nth(i).getAttribute('data-testid')) ?? '').replace('hub-row-', '')

    // BOTH, always. Either alone is the collapse that hid a Reconnect button on Production.
    await expect(page.getByTestId(`hub-auth-${id}`), `«${id}» has no authorisation state`).toBeVisible()
    await expect(page.getByTestId(`hub-sync-${id}`), `«${id}» has no data state`).toBeVisible()
    // «1 of 17» — chosen over reachable, which is the sentence that stops «everything syncs».
    await expect(page.getByTestId(`hub-accounts-${id}`)).toContainText(/of|من/)
  }
})

/**
 * An authorisation nobody finished is surfaced WITH the way to finish it.
 *
 * Consent completed, catalogue full, not one account chosen: nothing syncs and the page otherwise
 * looks complete. Either no such row exists, or the row carries its own way back — what must not
 * happen is one sitting in the account with nothing on screen about it.
 */
test('an unfinished authorisation carries the way to finish it', async ({ page }) => {
  await page.goto('/agency/integrations')
  await switchToEnglish(page)

  await expect(page.getByTestId('connection-hub')).toBeVisible({ timeout: 30000 })

  const unfinished = page.locator('[data-testid^="hub-unfinished-"]')

  if (await unfinished.count() > 0) {
    await expect(unfinished.first()).toBeVisible()
    await unfinished.first().click()
    await expect(page.getByTestId('connection-drawer')).toBeVisible({ timeout: 20000 })
    await expect(page.getByTestId('drawer-tab-accounts')).toHaveAttribute('aria-current', 'page')
  }
})

test('the integrations page prints no placeholder value', async ({ page }) => {
  await page.goto('/agency/integrations')
  await switchToEnglish(page)

  await expect(page.getByTestId('connection-hub')).toBeVisible({ timeout: 30000 })

  const text = (await page.locator('main').innerText()).replace(/\s+/g, ' ')

  expect(text).not.toMatch(/\b(undefined|NaN|\[object Object\])\b/)
})
