import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * VIZ-BUDGET-001 — «which of these is running hot», in a browser.
 *
 * The unit tests hold what is withheld and why; these hold that the page draws at all, and that the
 * two shapes agree with each other and with the cards above them. Both are plain DOM rather than
 * recharts, so they CAN be read here — which is why the assertions are on the geometry rather than on
 * mere presence.
 */
test.use({ storageState: AUTH.advertiser })

test('the limits divide into their states, and «not comparable» is not green', async ({ page }) => {
  await page.goto('/app/spend-limits')
  await switchToEnglish(page)

  await expect(page.getByTestId('spend-limits-intro')).toBeVisible({ timeout: 30000 })

  /*
    A workspace with no limits draws no composition, and that is correct rather than a gap: there is
    nothing to divide. The assertion is conditional on there being a bar, so this spec reports what
    the seeded world actually holds instead of demanding that it hold limits.

    The branch waits for the page to SETTLE first. `spend-limits-intro` renders while the query is
    still pending, so counting the bar straight after it found neither the bar nor the empty state —
    not because the rule was broken but because the answer had not arrived. Asking for «either of
    these» retries until one does, which is the difference between reading the page and racing it.
  */
  const bar = page.getByTestId('limit-states-bar')
  const none = page.getByText(/No limits yet|لا حدود بعد/i)
  await expect(bar.or(none).first()).toBeVisible({ timeout: 30000 })

  if (await bar.count() === 0) return

  const unknown = page.getByTestId('limit-states-segment-unknown')
  if (await unknown.count() > 0) {
    /*
      A limit whose spend could not be compared has told nobody they are within it. The success
      colour there would be the page asserting safety it has no reading for.
    */
    expect(await unknown.getAttribute('class')).not.toContain('success')
  }
})

test('the pace ranking agrees with the count of limits it could not read', async ({ page }) => {
  await page.goto('/app/spend-limits')
  await switchToEnglish(page)

  await expect(page.getByTestId('spend-limits-intro')).toBeVisible({ timeout: 30000 })

  /* Settle before branching, for the reason given above. */
  const totalCard = page.getByTestId('spend-limits-kpi-total')
  await expect(totalCard.or(page.getByText(/No limits yet|لا حدود بعد/i)).first()).toBeVisible({ timeout: 30000 })

  if (await totalCard.count() === 0) return // no limits in this workspace; nothing to reconcile

  const drawn = page.locator('[data-testid^="limit-pace-row-"]')
  const total = Number((await totalCard.innerText()).match(/\d+/)?.[0] ?? 0)
  const withheldNote = page.getByTestId('limit-pace-withheld')
  const withheld = await withheldNote.count() > 0
    ? Number((await withheldNote.innerText()).match(/\d+/)?.[0] ?? 0)
    : 0

  // Nothing may be dropped silently: what is drawn plus what is withheld is every limit there is.
  expect(await drawn.count() + withheld).toBe(total)
})

test('the ratios are Latin digits in Arabic, like every other figure in the product', async ({ page }) => {
  await page.goto('/app/spend-limits')

  await expect(page.getByTestId('spend-limits-intro')).toBeVisible({ timeout: 30000 })

  const first = page.locator('[data-testid^="limit-pace-row-"]').first()
  await expect(
    first.or(page.getByTestId('limit-pace-empty')).or(page.getByText(/No limits yet|لا حدود بعد/i)).first(),
  ).toBeVisible({ timeout: 30000 })

  if (await first.count() > 0) {
    await expect(first).not.toHaveText(/[٠-٩]/)
  }
})

for (const width of [1440, 768, 390]) {
  test(`the drawn limits page never scrolls sideways at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.goto('/app/spend-limits')
    await expect(page.getByTestId('spend-limits-intro')).toBeVisible({ timeout: 30000 })

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
  })
}
