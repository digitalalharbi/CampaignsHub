import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * ADS-TERMINOLOGY-001 — one ad level, one tab, and content is not another word for it.
 *
 * The tab bar carried «الإعلانات» and «الإعلان» side by side — «Ads» and «Ad» — and both were
 * ad-level surfaces over the same entity. A reader choosing between two tabs whose names differ by a
 * plural has no way to know which one answers their question, and the honest answer was «either».
 *
 * The hierarchy is campaign → ad set → ad → content, where content is a library of media with a
 * surface of its own. This asserts the correction in a real browser, in both languages, because half
 * of it IS the words: the same defect in Arabic is a different pair of strings.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

/**
 * Locale-agnostic on purpose.
 *
 * The agency surface renders in Arabic on this estate whatever an init script puts in `localStorage`,
 * and a first draft of this asserted English strings and reported the product as broken for it. What
 * this case is actually about is DUPLICATION and ROUTING, neither of which is a language — so it
 * accepts whichever pair the page is written in, and the two-language terminology is asserted where
 * the locale can genuinely be controlled, in `entityTabs.test.tsx`.
 */
const ADS = /^(الإعلانات|Ads)$/
const AD_SINGULAR = /^(الإعلان|Ad)$/

test.describe('one ad level', () => {
  test.use({ storageState: AUTH.owner })

  test('no second ad-level tab, and the retired address still lands', async ({ page, request }) => {
    test.setTimeout(120_000)

    await selectProject(page, await seededProject(request, STORE_PROJECT))

    await page.goto('/agency/analytics')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    await expect(page.getByRole('tab', { name: ADS })).toHaveCount(1)
    await expect(
      page.getByRole('tab', { name: AD_SINGULAR }),
      'a second ad-level tab is back beside Ads',
    ).toHaveCount(0)

    /*
     * A link somebody already holds. `?tab=creative` was a real address for as long as that tab
     * existed, and retiring a surface is not a licence to break links shared while it was there.
     */
    await page.goto('/agency/analytics?tab=creative')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    await expect(
      page.getByRole('tab', { name: ADS }),
      'the retired address did not open the canonical ads surface',
    ).toHaveAttribute('aria-selected', 'true')
  })
})
