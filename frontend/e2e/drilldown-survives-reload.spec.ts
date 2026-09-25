import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * HIERARCHY-ENTITY-ANALYTICS-DRILLDOWN — «deep-link + refresh», the clause nothing covered.
 *
 * ## What this proves that the unit guards cannot
 *
 * `drilldown.test.ts` proves the path survives a round trip through `encodePath`/`decodePath`, and
 * `entityDrilldown.test.tsx` proves a pinned parent narrows the FIRST request. Both drive the module
 * directly. Neither proves that a reader who drilled in, bookmarked the address and came back to it
 * lands anywhere near where they left — that needs the real router, the real URL and a real reload.
 *
 * ## Read from the NETWORK, not the screen
 *
 * A page that restored the breadcrumb and dropped `parent` from its query would look perfectly
 * correct and answer with every ad in the project. That is the failure worth catching here, and it is
 * invisible to a screen assertion — so the reload is judged by the request the page actually makes,
 * the same way `option-scale.spec.ts` judges the options endpoint.
 */
const PROJECT = 'متجر تجريبي — Demo'

test.describe('a drilled-in analytics link survives a reload', () => {
  test.use({ storageState: AUTH.owner })

  test('comes back to the same rung, still narrowed to the same parent', async ({ page, request }) => {
    const projectId = await seededProject(request, PROJECT)
    await selectProject(page, projectId)

    // Straight to the rung by address rather than by clicking a localised tab label: the tab is part
    // of what a deep link has to carry, so entering through it is the case, not a shortcut.
    //
    // `days` is carried deliberately and is not decoration: drilling REWRITES the query string, and a
    // setter that rebuilt it instead of amending it would silently return the reader to the default
    // window while they were looking at a narrowed table — the figures would change under a
    // breadcrumb that did not.
    await page.goto('/agency/analytics?tab=ad_sets&days=7')

    const adSets = page.getByTestId('entity-table-ad_set')
    await expect(adSets).toBeVisible({ timeout: 30_000 })

    const drill = adSets.locator('[data-testid^="drill-into-"]').first()
    await expect(drill).toBeVisible()
    const entityId = (await drill.getAttribute('data-testid'))!.replace('drill-into-', '')

    await drill.click()

    // The address is the whole point of the clause: it has to carry the rung — and to keep what it
    // was already carrying.
    await expect(page).toHaveURL(new RegExp(`drill=ad_set(%3A|:)${entityId}`))
    await expect(page).toHaveURL(/[?&]days=7(&|$)/)
    await expect(page.getByTestId('drill-crumb-ad_set')).toBeVisible()
    const crumb = (await page.getByTestId('drill-crumb-ad_set').innerText()).trim()
    expect(crumb.length).toBeGreaterThan(0)

    // Reload as a returning reader does, and watch what the page ASKS for.
    const narrowed = page.waitForRequest(
      (r) => /\/metrics\/entities\/ad\b/.test(r.url()) && r.url().includes(`parent=${entityId}`),
      { timeout: 30_000 },
    )
    await page.reload()
    await narrowed

    // And what it shows: the same rung, named the same way, over the same window.
    await expect(page.getByTestId('entity-table-ad')).toBeVisible()
    await expect(page.getByTestId('drill-crumb-ad_set')).toHaveText(crumb)
    await expect(page).toHaveURL(/[?&]days=7(&|$)/)
  })
})
