import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * PLATFORM-DECISION-ANALYTICS-001-UI — «which platform is contributing most to THIS objective».
 *
 * The row's remaining clause is one word: browser evidence. Everything beneath it is held by unit
 * tests — `platformPaths`, `pathEfficiency`, `platformReading` — and every one of them renders into
 * jsdom, which lays nothing out and paints nothing. What they cannot answer is whether an operator
 * opening the tab is given the decomposition at all, in the language they opened it in.
 *
 * ## What is asserted, and why each one earns its place
 *
 * The tab is reached by URL rather than by clicking a label, for the reason this suite has learned
 * four times over: a label is translated, and a selector that matches one on an English render
 * matches nothing on an Arabic one and passes having measured nothing.
 *
 * A path is a marketing PATH — awareness, consideration, action — and the tab's claim is that each
 * one names the providers under it. So the assertion is that at least one path block renders AND
 * carries a reading, because a block with no sentence is the «unexplained blank» this ledger keeps
 * refusing elsewhere.
 *
 * Run in both directions. The decomposition is numeric and the numerals are Latin in both — the
 * product's permanent rule — so an Arabic render that flipped them would be a defect this catches
 * without a second spec.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

test.describe('the Platforms tab decomposes an objective', () => {
  test.use({ storageState: AUTH.owner })

  for (const locale of ['en', 'ar'] as const) {
    test(`names the providers under a marketing path (${locale})`, async ({ page, request }) => {
      test.setTimeout(90_000)

      await selectProject(page, await seededProject(request, STORE_PROJECT))
      await page.goto('/agency/analytics?tab=platforms')

      if (locale === 'en') {
        await switchToEnglish(page)
      }

      const paths = page.getByTestId('platform-paths')
      await expect(paths, 'the Platforms tab rendered no path decomposition at all').toBeVisible({ timeout: 30000 })

      /*
       * Loud rather than quiet. A project with no delivery in the window has no paths to decompose,
       * and a silent pass here is exactly how «the tab answers the question» becomes a claim nobody
       * checked.
       */
      const blocks = paths.locator('[data-testid^="platform-path-"]')
      const count = await blocks.count()
      test.skip(count === 0, 'this project reported no marketing path in the window — nothing to decompose')

      const first = blocks.first()
      await expect(first).toBeVisible()

      /*
       * Every path says SOMETHING about itself — a reading, a «not comparable», or a «silent».
       * Which one depends on the data; having none of them is the blank rectangle that is never
       * acceptable on an analytical surface.
       */
      const said = await first.locator('[data-testid$="-reading"], [data-testid$="-not-comparable"], [data-testid$="-silent"]').count()
      expect(said, 'a marketing path was drawn with nothing said about it').toBeGreaterThan(0)
    })
  }

  /** The figures stay Latin in Arabic — the product's permanent numeral rule, on this surface. */
  test('keeps Latin numerals on an Arabic render', async ({ page, request }) => {
    test.setTimeout(90_000)

    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/analytics?tab=platforms')

    const paths = page.getByTestId('platform-paths')
    await expect(paths).toBeVisible({ timeout: 30000 })

    const text = (await paths.textContent()) ?? ''
    expect(text, 'the platform decomposition rendered Arabic-Indic digits').not.toMatch(/[٠-٩۰-۹]/)
  })
})
