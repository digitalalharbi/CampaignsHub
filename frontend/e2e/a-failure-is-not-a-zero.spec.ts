import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * NO-DATA-PROOF-001 — a request that failed is not an account that measured nothing.
 *
 * ## The two sentences a reader cannot tell apart
 *
 * «0» and «we could not ask» look identical on a card, and they lead somewhere opposite: the first
 * says the campaigns delivered nothing and somebody should act; the second says the product is
 * broken and nobody should conclude anything yet. A surface that renders a failed request as a zero
 * has told the reader a fact about their business that is not true — and it is the most dangerous
 * kind of wrong number, because it is confident.
 *
 * This refuses the summary at the network layer and asks what the page then says. It does not
 * prescribe the wording; it requires only that the figures are NOT presented as measured, which is
 * the part that can silently regress when a component gains a `?? 0`.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

test.describe('a request that failed says so', () => {
  test.use({ storageState: AUTH.owner })

  test('a refused summary is not rendered as an account that earned nothing', async ({ page, request }) => {
    test.setTimeout(120_000)

    await selectProject(page, await seededProject(request, STORE_PROJECT))

    /* Refused AFTER the project is chosen, so the page is asking for real figures when it breaks. */
    await page.route('**/metrics/summary*', (route) => route.fulfill({ status: 500, body: '{"message":"no"}' }))

    await page.goto('/agency/analytics')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
    await page.waitForTimeout(2500)

    const strip = page.getByTestId('analytics-metrics')
    await expect(strip).toBeVisible({ timeout: 30000 })

    /*
     * The strip has to declare itself. `data-strip-state` is the component's own answer — `error`
     * where it could not ask — and asserting on it rather than on copy keeps this test about the
     * STATE rather than about a translator's choice of words.
     */
    const state = await strip.getAttribute('data-strip-state')

    expect(
      state,
      `the strip reported «${state}» after its request was refused — a reader cannot tell that from a real zero`,
    ).toBe('error')

    /* And no confident zero anywhere in it. */
    const text = (await strip.innerText()).replace(/\s+/g, ' ')

    expect(text, `the strip printed a figure after a refused request: «${text.slice(0, 120)}»`)
      .not.toMatch(/(^|\s)0(\.00)?(\s|$)/)
  })
})
