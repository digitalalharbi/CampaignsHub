import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * ANALYTICS-FILTER-TRUTH-001 — «All» is every platform, and one platform is only that one.
 *
 * ## Why the request, and not the screen alone
 *
 * A filter that narrows only what is DRAWN is worse than no filter: the figures above the table are
 * still the unfiltered aggregate, so a reader who selects Meta reads Meta's rows under everyone's
 * totals and has no way to tell. The requirement says the filter scopes the BACKEND aggregation, so
 * the request is what has to carry it — and the rows are checked as well, because a request that
 * carries the filter and a table that ignores the answer is the same lie from the other end.
 *
 * ## Why the accounts tab
 *
 * It is the one surface that names the provider on every row, so «Meta only» is a claim this test
 * can actually check rather than infer. The filter itself is shared with every other tab.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

/** Every provider named in the accounts table, lower-cased. */
async function providersOnScreen(page: Page): Promise<string[]> {
  const table = page.getByTestId('account-table')
  await expect(table).toBeVisible({ timeout: 30000 })

  return page.evaluate(() => {
    const rows = [...(document.querySelector('[data-testid="account-table"]')?.querySelectorAll('tbody tr') ?? [])]

    /* The provider is the second column on this table. */
    return [...new Set(rows.map((r) => (r.children[1] as HTMLElement | undefined)?.innerText.trim().toLowerCase() ?? ''))]
      .filter((p) => p !== '')
  })
}

test.describe('the platform filter scopes what is asked for, not only what is drawn', () => {
  test.use({ storageState: AUTH.owner })

  test('all is every platform, and one platform is only that one', async ({ page, request }) => {
    test.setTimeout(180_000)

    await selectProject(page, await seededProject(request, STORE_PROJECT))

    /* Every request the page makes, so the narrowing can be read where it is actually applied. */
    const asked: string[] = []
    page.on('request', (r) => {
      if (r.url().includes('/api/') && r.url().includes('accounts')) asked.push(r.url())
    })

    await page.goto('/agency/analytics?tab=accounts')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    const everyone = await providersOnScreen(page)

    expect(everyone.length, 'the seeded estate must hold more than one platform for this to mean anything')
      .toBeGreaterThan(1)

    /* Now Meta alone. */
    asked.length = 0
    await page.getByTestId('analytics-platform-meta').click()
    await expect.poll(async () => asked.length, { timeout: 20000 }).toBeGreaterThan(0)

    /*
     * The REQUEST carries it — the half a screen check cannot see.
     *
     * `provider=meta`, singular: the first draft of this looked for `providers[]=meta` and reported
     * the product as broken for a parameter name I had guessed. The endpoint's own answer is what
     * this reads.
     */
    expect(
      asked.some((u) => /[?&]provider(s\[\])?=meta\b/.test(decodeURIComponent(u))),
      `no request carried the platform narrowing: ${asked.slice(-2).join(' ')}`,
    ).toBe(true)

    /*
     * ...and the answer is honoured.
     *
     * The expected name is read from the CHIP the reader just pressed, not written here. The table
     * shows the localised label — «ميتا» on an Arabic render — and a hardcoded «meta» reported the
     * product as broken for a translation. The chip and the row are the same product naming the same
     * platform, so they are what get compared.
     */
    const metaLabel = ((await page.getByTestId('analytics-platform-meta').innerText()) ?? '').trim().toLowerCase()

    await expect.poll(async () => (await providersOnScreen(page)).join(','), { timeout: 20000 }).toBe(metaLabel)

    /* Back to everyone, so «All» is proved to be a real state and not merely the opening one. */
    await page.getByTestId('analytics-platform-all').click()
    await expect
      .poll(async () => (await providersOnScreen(page)).sort().join(','), { timeout: 20000 })
      .toBe(everyone.sort().join(','))
  })
})
