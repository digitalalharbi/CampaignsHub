import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * ADS-TERMINOLOGY-001 — Ads and Content are named for what they are, and both still exist.
 *
 * The tab bar carried «الإعلانات» and «الإعلان» — «Ads» and «Ad» — a pair differing by a plural, so
 * a reader could not tell which one answered their question. The second was NEVER a second view of
 * the same thing: it is the last rung of campaign → ad set → ad → CONTENT, with a grain of its own.
 *
 * A content item is not an ad. One creative can be carried by several ads, and its figures come
 * from `creative_daily_metrics` rather than an ad's row — so folding it into the ads table would
 * have to pick one ad per creative or double-count it. A first version of this correction retired
 * the surface outright and aliased its address to «Ads»; that lost a capability and answered a
 * content link with an ads table, which tells the reader they are looking at content when they are
 * not.
 *
 * Locale-agnostic on purpose. The agency surface renders in Arabic on this estate whatever an init
 * script puts in `localStorage`, and an earlier draft asserted English strings and reported the
 * product as broken for it. What this case is about is NAMING and ROUTING; the two-language check
 * lives where the locale can genuinely be controlled, in `entityTabs.test.tsx`.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

const ADS = /^(الإعلانات|Ads)$/
const AD_SINGULAR = /^(الإعلان|Ad)$/
const CONTENT = /(المحتويات|Content)/

test.describe('ads and content are named for what they are', () => {
  test.use({ storageState: AUTH.owner })

  test('the ambiguous singular is gone and both surfaces remain', async ({ page, request }) => {
    test.setTimeout(120_000)

    await selectProject(page, await seededProject(request, STORE_PROJECT))

    await page.goto('/agency/analytics')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    await expect(page.getByRole('tab', { name: ADS })).toHaveCount(1)
    await expect(
      page.getByRole('tab', { name: AD_SINGULAR }),
      'the ambiguous singular «الإعلان» / «Ad» is back beside Ads',
    ).toHaveCount(0)

    /* And the content level still has a surface — this correction removed a NAME, not a capability. */
    await expect(
      page.getByRole('tab', { name: CONTENT }),
      'the content analytics surface is missing',
    ).toHaveCount(1)
  })

  test('a content address opens content, not ads', async ({ page, request }) => {
    test.setTimeout(120_000)

    await selectProject(page, await seededProject(request, STORE_PROJECT))

    await page.goto('/agency/analytics?tab=creative')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    await expect(
      page.getByRole('tab', { name: CONTENT }),
      'a link that has always meant content did not open content',
    ).toHaveAttribute('aria-selected', 'true')
    await expect(page.getByRole('tab', { name: ADS })).toHaveAttribute('aria-selected', 'false')
  })
})
