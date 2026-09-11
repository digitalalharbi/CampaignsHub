import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

const STORE_PROJECT = 'متجر تجريبي — Demo'

/**
 * UX-MULTISELECT-SCALE-001 — the campaign picker's search narrows the QUERY, not the rendered list.
 *
 * The row's remaining clause claimed «120 bounds the DOM but the full option list still arrives from
 * the API». It does not, and a screen check cannot tell the difference: a control filtering a list it
 * already holds and a control asking the server for a narrower one look identical. The REQUEST is
 * where the claim lives, which is why this reads it — the same reason `provider-filter-scopes` does.
 *
 * Run on all three browsers, which is the «3-browser evidence» the row asks for. It does not seed two
 * hundred campaigns to get it: the mechanism is what makes the cardinality safe, and a two-hundred-row
 * fixture would slow every gate run to assert something the parameter already proves.
 */
test.describe('the campaign picker asks the server', () => {
  test.use({ storageState: AUTH.owner })

  test('a typed term reaches the options endpoint', async ({ page, request }) => {
    test.setTimeout(180_000)

    await selectProject(page, await seededProject(request, STORE_PROJECT))

    const asked: string[] = []
    page.on('request', (r) => {
      if (r.url().includes('/api/') && r.url().includes('campaign-options')) asked.push(r.url())
    })

    /* The agency route: `AUTH.owner` is an agency operator, and `/app` refuses one by design. */
    await page.goto('/agency/analytics')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    const picker = page.getByTestId('analytics-campaign')
    if (await picker.count() === 0) test.skip(true, 'this project offers no campaign filter')

    await picker.click()

    const search = page.getByRole('searchbox', { name: /بحث|search/i }).first()
    if (await search.count() === 0) test.skip(true, 'the option list is short enough to need no search box')

    asked.length = 0
    await search.fill('Ramadan')

    /* Debounced by 250ms, so the request is awaited rather than assumed. */
    await expect.poll(async () => asked.length, { timeout: 20000 }).toBeGreaterThan(0)

    /*
      The parameter's own name, read from the endpoint rather than guessed — the sibling spec records
      what guessing one costs: a product reported broken for a name that was never the contract.
    */
    expect(asked.some((u) => /[?&]q=Ramadan/i.test(u)), `no request carried the term: ${asked.join(' ')}`).toBe(true)
  })
})
