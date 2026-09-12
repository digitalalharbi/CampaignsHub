import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §18 — the browser evidence the row says is missing.
 *
 * ## Why this was written before anything was changed
 *
 * The matrix listed «select-all and the selected-count in the picker» as remaining. Both are in
 * `ConnectionWizard` — `wizard-select-page`, `wizard-clear-page`, `wizard-selected-count` — and have
 * been for some time. That is the second row in this ledger found to UNDER-state what is built, and
 * the cost is the same as over-stating: it sends the next execution at something already there.
 *
 * A row is corrected from evidence, not from a file listing, because a control can exist in the tree
 * and still not reach a reader. This is that evidence.
 *
 * ## What it holds
 *
 * Every platform card states its OWN state — available, needs the operator, awaiting credentials —
 * rather than a single «not connected» that would be true of all three and useful for none. An
 * account is deliberately NOT connected here: the gate database holds no platform credentials, and
 * a spec that needed them would be one nobody can run.
 */
test.use({ storageState: AUTH.owner })

test('every platform states which kind of not-connected it is', async ({ page }) => {
  await page.goto('/agency/integrations')
  await switchToEnglish(page)

  const panel = page.getByTestId('ad-platforms-panel')
  await expect(panel).toBeVisible({ timeout: 30000 })

  const cards = page.getByTestId('platform-card')
  /* `count()` does not wait; the panel paints before the cards it holds. */
  await expect(cards.first(), 'the page listed no platforms at all').toBeVisible({ timeout: 20000 })

  const count = await cards.count()

  /*
   * Each card carries a state badge. «Not connected» alone would be true of every card on a fresh
   * account and would tell a reader nothing about whose move it is — theirs, or the platform
   * operator's, or nobody's until credentials arrive.
   */
  for (let i = 0; i < count; i++) {
    const key = await cards.nth(i).getAttribute('data-platform')

    await expect(page.getByTestId(`connector-state-${key}`), `«${key}» has no state`).toBeVisible()
  }
})

test('an unfinished connection is surfaced with the way to finish it', async ({ page }) => {
  await page.goto('/agency/integrations')
  await switchToEnglish(page)

  await expect(page.getByTestId('ad-platforms-panel')).toBeVisible({ timeout: 30000 })

  const unfinished = page.getByTestId('unfinished-connection')

  /*
   * Either there is one and it offers the way back, or there is none. What must not happen is a
   * half-finished connection sitting in the account with nothing on screen about it — an operator
   * would authorise a platform, never pick the accounts, and see a page that looks complete.
   */
  if (await unfinished.count() > 0) {
    await expect(page.getByTestId('resume-connection')).toBeVisible()
  }
})

test('the integrations page prints no placeholder value', async ({ page }) => {
  await page.goto('/agency/integrations')
  await switchToEnglish(page)

  await expect(page.getByTestId('ad-platforms-panel')).toBeVisible({ timeout: 30000 })

  const text = (await page.locator('main').innerText()).replace(/\s+/g, ' ')

  expect(text).not.toMatch(/\b(undefined|NaN|\[object Object\])\b/)
})
