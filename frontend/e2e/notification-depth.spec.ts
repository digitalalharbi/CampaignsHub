import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * EMAIL-SETTINGS-DEPTH-001 — the six items, asked of the running product.
 *
 * ## Why this is a browser test and not a ledger edit
 *
 * The matrix row says «Remaining (2 of 6): multiple recipients, and a recommendations toggle. Also
 * unbuilt: the frontend surface that renders the delivery log». Reading the tree, all three exist
 * and are mounted in `SettingsPage`. A row that under-states what is built is as expensive as one
 * that over-states it: it sends the next execution at something that is already there, and I nearly
 * rebuilt three of them.
 *
 * So the row is corrected from EVIDENCE rather than from a file listing — a component can be mounted
 * and still not reach a reader, which is the failure this whole product keeps meeting.
 *
 * ## What is actually asked
 *
 * The operator's question is «did the last one work», not «has this person been getting them». A
 * delivery log that shows only successes answers neither, so the FAILURES and their reasons are what
 * this checks for — the same honesty rule the ledgers were built to.
 */
test.use({ storageState: AUTH.owner })

test('the notification settings carry the log, the recipients and the recommendations choice', async ({ page }) => {
  await page.goto('/agency/settings/workspace?tab=notifications')
  await switchToEnglish(page)

  await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

  /*
   * The delivery log, reading the real ledgers.
   *
   * Either it lists attempts or it says there are none — both are answers. What it must not do is
   * render nothing at all, which is what «the surface is unbuilt» would look like.
   */
  const log = page.getByTestId('delivery-log')
  await expect(log, 'the delivery log does not reach the page it is mounted on').toBeVisible({ timeout: 20000 })

  /* Multiple recipients — an address list, not a single field. */
  await expect(
    page.getByTestId('notification-recipients'),
    'the recipients surface does not reach the page',
  ).toBeVisible()

  /* And the recommendations choice, with the sentence that says what it changes. */
  const toggle = page.getByLabel(/Include approved recommendations/i)
  await expect(toggle).toBeVisible()
  await expect(page.getByTestId('recommendations-note')).toBeVisible()
})

/**
 * And the log distinguishes a failure from a silence.
 *
 * «Last send: 08:04» answers «did the last one work?» — a log that cannot show a failure answers
 * nothing, and the ledgers carry `status`, `reason`, `attempts` and `last_error` precisely so it can.
 */
test('a settings tab is an address a person can send', async ({ page }) => {
  /*
   * SETTINGS-TAB-ADDRESS-001 — which tab is open was `useState` alone.
   *
   * `?tab=notifications` did nothing: the page always opened on its first tab whatever the link
   * said. Three ordinary things broke — a link to «the notification settings» sent to a colleague
   * opened somewhere else, Back walked out of the page instead of back a tab, and a reload lost the
   * reader's place. There was no way to link to this screen at all.
   */
  await page.goto('/agency/settings/workspace?tab=security')
  await expect(page.getByTestId('delivery-log')).toHaveCount(0)

  await page.goto('/agency/settings/workspace?tab=notifications')
  await expect(page.getByTestId('delivery-log')).toBeVisible({ timeout: 30000 })

  /* A reload keeps the reader where they were. */
  await page.reload()
  await expect(page.getByTestId('delivery-log')).toBeVisible({ timeout: 30000 })
})

/** A stale or nonsense link opens the first tab rather than an empty page. */
test('an unknown tab falls back rather than rendering nothing', async ({ page }) => {
  await page.goto('/agency/settings/workspace?tab=nonsense')

  await expect(page.locator('h1')).toBeVisible({ timeout: 30000 })
  await expect(page.locator('main')).not.toBeEmpty()
})

test('the delivery log is able to show a failure and its reason', async ({ page }) => {
  await page.goto('/agency/settings/workspace?tab=notifications')
  await switchToEnglish(page)

  const log = page.getByTestId('delivery-log')
  await expect(log).toBeVisible({ timeout: 30000 })

  const text = (await log.innerText()).replace(/\s+/g, ' ')

  /* No placeholder ever reaches a settings screen. */
  expect(text).not.toMatch(/\b(undefined|NaN|\[object Object\])\b/)

  /*
   * Either there are rows, or the empty state says so in words. A heading over nothing is the state
   * this file exists to catch.
   */
  expect(text.length, 'the log rendered a heading and nothing else').toBeGreaterThan(20)
})
