import { expect, test } from '@playwright/test'

/**
 * REPORT-CONTENT-BROWSER-001 — the content section's table, on the link a client actually opens, at
 * the two widths the row names.
 *
 * The section is a BROWSER: cards and a table over one dataset, both through the same `figuresFor`.
 * The unit tests hold the two views to the same figures; what they cannot say is whether the table
 * holds together on a phone and on a desk, in a real browser, on the public link. No session —
 * the client has a link and no account.
 *
 * Three claims per width: the toggle exists on the client's link and switches to the table; the
 * table holds every creative the cards held (one dataset); and the page does not scroll sideways
 * to hold it — «the table may scroll, the document may not».
 */
const URL = '/reports/share/demo-live-report-token'

test.use({ storageState: { cookies: [], origins: [] } })

for (const width of [390, 1440] as const) {
  test(`${width}px: the content table is a client-link surface, holds the same rows, and does not widen the page`, async ({ page }) => {
    await page.setViewportSize({ width, height: width === 390 ? 844 : 900 })
    await page.goto(URL)
    await expect(page.getByTestId('live-report')).toBeVisible({ timeout: 20_000 })

    const toCards = page.getByTestId('report-content-view-cards')
    const toTable = page.getByTestId('report-content-view-table')
    await expect(toCards.first(), 'the client link offers no cards/table toggle').toBeVisible({ timeout: 20_000 })
    await expect(toCards.first()).toHaveAttribute('aria-pressed', 'true')

    /*
     * The SECTION that owns the toggle, and nothing outside it. The first version walked up with an
     * XPath union, which returns ancestors in document order — so `.first()` was the outermost one,
     * and the count took every card on the page (the weakest strip, the per-platform leaders)
     * against one section's table. The product was right; the locator asked the wrong question.
     */
    const section = page.getByTestId('report-ads').filter({ has: toCards.first() }).first()
    const cards = await section.getByTestId('report-ad-card').count()
    expect(cards, 'the cards view shows nothing to compare against').toBeGreaterThan(0)

    await toTable.first().click()
    await expect(toTable.first()).toHaveAttribute('aria-pressed', 'true')
    const table = section.getByTestId('report-content-table').first()
    await expect(table).toBeVisible({ timeout: 20_000 })

    const rows = table.locator('tbody tr')
    expect(await rows.count(), 'the table and the cards do not hold the same creatives — one dataset').toBe(cards)

    /* The primitive's own guarantee, on this page: the wrapper contains, the document does not scroll. */
    await page.waitForLoadState('networkidle')
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1),
      `the content table pushes the client's page sideways at ${width}px`,
    ).toBe(false)

    /* Every figure column is centred — TABLE-NUMERIC-ALIGNMENT-001 on the client's own link. */
    const misaligned = await table.locator('thead th').evaluateAll((ths) =>
      ths.slice(3).filter((th) => getComputedStyle(th).textAlign !== 'center').map((th) => th.textContent?.trim() ?? '?'))
    expect(misaligned, 'a figure column is not centred').toEqual([])
  })
}
