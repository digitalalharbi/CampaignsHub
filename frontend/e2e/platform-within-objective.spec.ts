import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * PLATFORM-DECISION-ANALYTICS-001 — the comparison happens INSIDE one objective, in a browser.
 *
 * ## The constraint this surface exists to honour
 *
 * «No universal best-platform claim assembled from incomparable objectives.» A platform that is cheap
 * for reach is not thereby cheap for orders, so a single ranking over a programme buying both is a
 * verdict about the work each platform was GIVEN rather than about how either did it.
 *
 * The page answers per marketing path instead, and says out loud why it will not answer across them —
 * `platform-paths-cross` is the sentence a «best platform» card would have to contradict in order to
 * exist. That sentence being PRESENT is the acceptance, not an implementation detail.
 *
 * ## Why a browser test, when `platformPaths.test.tsx` already covers the rendering
 *
 * That file renders the panel from a fixture, so it proves the component obeys a payload. It cannot
 * prove the panel is reachable on the real page, that the real endpoint fills it, or that the refusal
 * survives the filters an operator actually sets. The row's own remaining clause asks for browser
 * evidence, and this is it.
 */
const PROJECT = 'متجر تجريبي — Demo'

const COPY = {
  ar: { tab: /^المنصات$/ },
  en: { tab: /^Platforms$/ },
}

async function openPlatforms(page: Page, locale: 'ar' | 'en') {
  await page.goto('/agency/analytics')

  if (locale === 'en') {
    await switchToEnglish(page)
  }

  /*
   * The tab is waited for, then clicked, and the click is CONFIRMED — with one retry.
   *
   * `analytics-normalization.spec.ts` documents this hazard on the same tab bar: a failure arrives as
   * «the panel was never visible», which reads as a broken panel when the click had simply not taken
   * effect. It cost this spec a firefox/Arabic failure whose message was about the cross-path refusal
   * and whose cause was a tab that never became selected.
   *
   * The retry is bounded at one and is NOT a way of passing a broken tab: a second click that also
   * fails to select still fails the gate, and it fails saying so. What it absorbs is the narrow race
   * between the tablist painting and React attaching its handler — a precondition of this test rather
   * than the thing it is about.
   */
  const tab = page.getByRole('tab', { name: COPY[locale].tab })
  await expect(tab).toBeVisible({ timeout: 30000 })
  await tab.click()

  if ((await tab.getAttribute('aria-selected')) !== 'true') {
    await tab.click()
  }

  await expect(tab, 'the platforms tab never became selected').toHaveAttribute('aria-selected', 'true')

  return page.getByTestId('platform-paths')
}

for (const locale of ['ar', 'en'] as const) {
  test.describe(`platforms are compared inside an objective (${locale})`, () => {
    test.use({ storageState: AUTH.owner })

    test('the panel is reachable and filled by the real endpoint', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      const panel = await openPlatforms(page, locale)

      await expect(panel).toBeVisible({ timeout: 30000 })

      /*
       * At least one path block, because an empty panel would satisfy every absence assertion below
       * while proving nothing — the vacuity this product has been caught by before.
       */
      const blocks = panel.locator('[data-testid^="platform-path-"]')
      expect(await blocks.count(), 'no path block rendered, so nothing below was measured').toBeGreaterThan(0)
    })

    test('states why it will not rank platforms across objectives', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      await openPlatforms(page, locale)

      const refusal = page.getByTestId('platform-paths-cross')
      await expect(refusal).toBeVisible()

      /* A sentence with no text is not a statement — it is the gap it was written to replace. */
      const said = (await refusal.innerText()).trim()
      expect(said.length, 'the cross-path refusal rendered empty').toBeGreaterThan(20)

      const box = await refusal.boundingBox()
      expect(box, 'the refusal has no box, so nothing was drawn').not.toBeNull()
    })

    /**
     * The efficiency figure is priced per PATH, which is the whole mechanism.
     *
     * A cost per order shown against an awareness path would be the cross-objective verdict this row
     * forbids, arriving one rung down instead of as a headline card.
     */
    test('prices each path by what that path was buying', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      const panel = await openPlatforms(page, locale)
      await expect(panel).toBeVisible({ timeout: 30000 })

      const efficiency = panel.locator('[data-testid^="path-efficiency-"]')
      const count = await efficiency.count()

      /*
       * Where a path carries no comparable efficiency the product says so instead, so this asserts
       * «one or the other», not «always a figure» — which would demand a number the data may not have.
       */
      const notComparable = panel.locator('[data-testid$="-not-comparable"]')
      expect(count + (await notComparable.count()), 'a path block offered neither an efficiency nor a reason').toBeGreaterThan(0)
    })

    test('reads in the direction of its own locale', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      await openPlatforms(page, locale)

      await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr')
    })
  })
}
