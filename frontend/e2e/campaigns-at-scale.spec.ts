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
/*
 * AF2 — the page size is the SCREEN's on `/agency` (a fitted page asks the server for the rows that fit, so the page
 * never scrolls), where it used to be a fixed 25. The claims below are unchanged; what is read rather than assumed
 * is the size, from the first page itself — and the pager must state a page count that follows from it.
 */

async function openTable(page: Page) {
  await page.goto('/agency/campaigns')
  await switchToEnglish(page)
  await page.getByTestId('view-table').click()
  await expect(page.getByTestId('campaign-row').first()).toBeVisible({ timeout: 30_000 })
  /* AF2 — the page size is measured from the screen, so the first reading is not the settled one: wait until the row count holds. */
  let last = -1
  let steady = 0
  for (let i = 0; i < 40 && steady < 4; i++) {
    const n = await page.getByTestId('campaign-row').count()
    steady = n === last ? steady + 1 : 0
    last = n
    await page.waitForTimeout(250)
  }
}

/*
 * The NAME cell, by its own test id. The first cell of a row is the bulk-selection checkbox since
 * CAMPAIGNS-TABLE-002, so «the first td» stopped being the name — the one thing this helper reads.
 */
const namesOnPage = (page: Page) =>
  page.getByTestId('campaign-row').evaluateAll((rows) =>
    rows.map((r) => (r.querySelector('[data-testid="campaign-row-name"]') as HTMLElement | null)?.textContent?.trim() ?? ''),
  )

test.describe('the campaigns listing at production cardinality', () => {
  test.use({ storageState: AUTH.owner, viewport: { width: 1920, height: 1080 } })

  test('reads the same twice, and pages through the whole estate exactly once', async ({ page, request }) => {
    test.setTimeout(240_000)
    await selectProject(page, await seededProject(request, SCALE_PROJECT))
    await openTable(page)

    const pager = page.getByTestId('campaigns-pager')
    await expect(pager).toContainText(`of ${TOTAL}`)

    /* 1. Deterministic: the same first page after a fresh load. */
    const first = await namesOnPage(page)
    const PER_PAGE = first.length
    expect(PER_PAGE, 'the first page is empty').toBeGreaterThan(0)
    expect(PER_PAGE, 'the first page is not a full page').toBeLessThanOrEqual(25)
    await expect(pager).toContainText(`page 1 of ${Math.ceil(TOTAL / PER_PAGE)}`)
    expect(first.every((n) => n !== ''), `a row has no readable name: ${first.join(' | ')}`).toBe(true)

    await openTable(page)
    const again = await namesOnPage(page)
    /*
     * The page size follows the screen and can differ by a row between two loads, and a page is ordered within itself,
     * so the two readings are compared as what they must agree on: the RELATIVE order of the campaigns both pages hold.
     * That every campaign appears once across the pages is asserted below.
     */
    const common = first.filter((n) => again.includes(n))
    expect(again.filter((n) => first.includes(n)), 'the first page reads differently on a second load — the order is not a property of the data').toEqual(common)
    expect(common.length, 'the two readings of the first page share almost nothing').toBeGreaterThanOrEqual(Math.min(first.length, again.length) - 2)

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
      await expect.poll(async () => (await namesOnPage(page))[0], { timeout: 20_000 }).not.toBe(seen[seen.length - again.length] ?? before)
      seen.push(...(await namesOnPage(page)))
    }

    expect(pages).toBe(Math.ceil(TOTAL / again.length))
    expect(seen.length, 'the pages together do not hold the count the pager states').toBe(TOTAL)
    expect(new Set(seen).size, `a campaign appears on two pages: ${seen.filter((n, i) => seen.indexOf(n) !== i).join(' | ')}`).toBe(TOTAL)

    const expected = new Set([...Array.from({ length: TOTAL - 1 }, (_, i) => `Scale campaign ${String(i + 1).padStart(3, '0')}`), 'Zayed launch — past the cap'])
    expect(new Set(seen), 'the pages together are not the seeded estate').toEqual(expected)
  })
})
