import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * ENTITY-RELEVANCE-ORDERING-001 — «deterministic, job-appropriate ordering … across every listing»,
 * at a realistic entity count, in a real browser.
 *
 * The row's own closing names what was outstanding: «no 3-browser evidence at realistic entity
 * counts». Every ordering rule it cites is unit-tested on a handful of rows; what nobody had seen
 * was a listing of a couple of hundred, paged, in a browser — which is where an order that is only
 * deterministic in principle shows up as a row that moves between two loads, or a page boundary that
 * loses or repeats one.
 *
 * The seed's «Scale — 241 campaigns» (UX-MULTISELECT-SCALE-001) is the estate. None of its campaigns
 * has spend, so the server's first key cannot separate them and the whole list rides on the tiebreak
 * `CampaignOrderingTest` pins — which is exactly the case where an unstable order would surface.
 *
 * Two claims:
 *   1. the first page reads the same twice — the order is a property of the data, not of the load;
 *   2. paging through the whole estate yields every campaign exactly once, and the pager's count is
 *      the count it yields — the page, the count and the pager describe one set.
 */
const SCALE_PROJECT = 'Scale — 241 campaigns'
const TOTAL = 241
const PER_PAGE = 25

async function openTable(page: Page) {
  await page.goto('/agency/campaigns')
  await switchToEnglish(page)
  await page.getByTestId('view-table').click()
  await expect(page.getByTestId('campaign-row').first()).toBeVisible({ timeout: 30_000 })
}

const namesOnPage = (page: Page) =>
  page.getByTestId('campaign-row').evaluateAll((rows) =>
    rows.map((r) => (r.querySelector('td a, td') as HTMLElement | null)?.textContent?.trim() ?? ''),
  )

test.describe('the campaigns listing at production cardinality', () => {
  test.use({ storageState: AUTH.owner })

  test('reads the same twice, and pages through the whole estate exactly once', async ({ page, request }) => {
    test.setTimeout(240_000)
    await selectProject(page, await seededProject(request, SCALE_PROJECT))
    await openTable(page)

    const pager = page.getByTestId('campaigns-pager')
    await expect(pager).toContainText(`of ${TOTAL}`)
    await expect(pager).toContainText(`page 1 of ${Math.ceil(TOTAL / PER_PAGE)}`)

    /* 1. Deterministic: the same first page after a fresh load. */
    const first = await namesOnPage(page)
    expect(first.length, 'the first page is not a full page').toBe(PER_PAGE)
    expect(first.every((n) => n !== ''), `a row has no readable name: ${first.join(' | ')}`).toBe(true)

    await openTable(page)
    const again = await namesOnPage(page)
    expect(again, 'the first page reads differently on a second load — the order is not a property of the data').toEqual(first)

    /* 2. Complete and duplicate-free across every page. */
    const seen: string[] = [...again]
    const next = pager.getByRole('button').last()
    let pages = 1
    while (await next.isEnabled()) {
      const before = seen[seen.length - 1]
      await next.click()
      pages += 1
      await expect(pager).toContainText(`page ${pages} of`)
      /* Wait for the rows to be THIS page's, not the previous page still on screen. */
      await expect.poll(async () => (await namesOnPage(page))[0], { timeout: 20_000 }).not.toBe(seen[seen.length - PER_PAGE] ?? before)
      seen.push(...(await namesOnPage(page)))
    }

    expect(pages).toBe(Math.ceil(TOTAL / PER_PAGE))
    expect(seen.length, 'the pages together do not hold the count the pager states').toBe(TOTAL)
    expect(new Set(seen).size, `a campaign appears on two pages: ${seen.filter((n, i) => seen.indexOf(n) !== i).join(' | ')}`).toBe(TOTAL)

    const expected = new Set([...Array.from({ length: TOTAL - 1 }, (_, i) => `Scale campaign ${String(i + 1).padStart(3, '0')}`), 'Zayed launch — past the cap'])
    expect(new Set(seen), 'the pages together are not the seeded estate').toEqual(expected)
  })
})
