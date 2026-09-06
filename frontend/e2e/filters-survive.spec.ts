import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * ANALYTICS-FILTER-TRUTH-001 — a narrowing a reader chose has to still be there afterwards.
 *
 * ## Why this is its own file
 *
 * «It keeps forgetting what I chose» is one of the oldest complaints against this product, and the
 * one defect of that shape which was actually found — the content library's view control — was found
 * by a gate that was looking at something else entirely. Every other control on that toolbar seeds
 * from the address and is written back to it, and nothing has ever checked that the loop closes.
 *
 * Three things are asked, because they fail separately:
 *
 *   - a REFRESH re-mounts the page from the address alone;
 *   - a BACK arrives from another route with the address in history;
 *   - a DEEP LINK is somebody else's address, opened cold.
 *
 * The third is what a shared link is, and it is the one that matters to a person who was sent one.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

test.describe('a narrowing the reader chose survives', () => {
  test.use({ storageState: AUTH.owner })

  test('the content library keeps its view through refresh, back and a cold deep link', async ({ page, request }) => {
    test.setTimeout(120_000)

    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/content')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    const list = page.getByRole('button', { name: /^(قائمة|List)$/ })
    await expect(list).toBeVisible({ timeout: 30000 })
    await list.click()

    await expect.poll(async () => new URL(page.url()).searchParams.get('view'), { timeout: 15000 }).toBe('list')

    /* A refresh: the page is rebuilt from the address and nothing else. */
    await page.reload()
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
    await expect(list, 'the view was forgotten by a refresh').toHaveAttribute('aria-pressed', 'true')

    /* Away and back: the address travels in history. */
    await page.goto('/agency/dashboard')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
    await page.goBack()
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
    await expect(list, 'the view was forgotten by a back navigation').toHaveAttribute('aria-pressed', 'true')

    /*
     * A cold deep link — somebody else's address, opened without the click that produced it. This is
     * what a shared link IS, and the state has to come entirely from the URL.
     */
    const shared = page.url()
    await page.goto('/agency/dashboard')
    await page.goto(shared)
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
    await expect(list, 'a shared link did not open the view it was taken from').toHaveAttribute('aria-pressed', 'true')
  })

  /**
   * The period is a narrowing too, and the one most likely to be shared.
   *
   * A link sent with «last 7 days» that opens on 30 shows the recipient different figures from the
   * ones the sender was looking at, which is worse than showing none.
   */
  test('the chosen period survives a refresh', async ({ page, request }) => {
    test.setTimeout(120_000)

    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/content')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    await expect.poll(async () => new URL(page.url()).searchParams.get('from'), { timeout: 20000 }).not.toBeNull()

    const before = new URL(page.url()).searchParams.get('from')

    await page.reload()
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    await expect
      .poll(async () => new URL(page.url()).searchParams.get('from'), { timeout: 20000 })
      .toBe(before)
  })
})
