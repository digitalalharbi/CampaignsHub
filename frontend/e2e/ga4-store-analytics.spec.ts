import { expect, test, type Locator } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, seededProject, selectProject } from './helpers'

/**
 * GA4-STORE-ANALYTICS-001 / GA4-REPORT-SECTIONS-001 / TABLE-FIXED-PAGE-001 — the store as its GA4
 * property measured it, on the three surfaces that show it, against the DEMO property
 * (`DemoGa4Seeder`, labelled demo end to end). No live GA4 is involved.
 *
 * What only a browser can say: the reading reaches the rendered page; moving a table to another page
 * leaves its box exactly the size it was; and the client's link and the saved report carry the same
 * parts without a campaign name.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'
const LIVE_TOKEN = 'demo-live-report-token'

const box = async (l: Locator) => {
  const b = await l.boundingBox()
  expect(b, 'the table box was not laid out').not.toBeNull()

  return { w: Math.round(b!.width), h: Math.round(b!.height) }
}

test.describe('GA4 store analytics', () => {
  test.use({ storageState: AUTH.owner })

  test('Analytics draws the store reading, and paging a table never changes its size', async ({ page, request }) => {
    test.setTimeout(120_000)
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/analytics?tab=site')

    await expect(page.getByTestId('ga4-store')).toBeVisible({ timeout: 30000 })
    for (const key of ['sessions', 'bounce_rate', 'revenue', 'aov', 'conversion_rate']) {
      await expect(page.getByTestId(`ga4-tile-${key}`)).toBeVisible()
    }
    // Money reads in USD with the property's own currency kept beside it.
    await expect(page.getByTestId('ga4-tile-revenue')).toContainText(/\$|USD/)
    await expect(page.getByTestId('ga4-tile-revenue')).toContainText(/ر\.س|SAR/)
    await expect(page.getByTestId('ga4-channels')).toBeVisible()
    await expect(page.getByTestId('ga4-devices')).toBeVisible()
    // The match section below replaces the older comparison card on this tab.
    await expect(page.getByTestId('ga4-match')).toBeVisible({ timeout: 30000 })
    await expect(page.getByTestId('ga4-gap')).toHaveCount(0)

    // The match section replaces the comparison card once it has loaded; measure the page after that.
    await expect(page.getByTestId('ga4-match')).toBeVisible({ timeout: 30000 })
    await page.waitForLoadState('networkidle')
    const table = page.getByTestId('ga4-table-box')
    await table.scrollIntoViewIfNeeded()
    const first = await box(table)
    const pageBefore = await page.evaluate(() => document.documentElement.scrollHeight)

    // Sources has more rows than one page; walk to the last page.
    await page.getByTestId('ga4-table-tab-sources').click()
    while (await page.getByTestId('ga4-table-next').isEnabled()) {
      await page.getByTestId('ga4-table-next').click()
      expect(await box(table), 'a page change resized the table').toEqual(first)
    }
    await page.getByTestId('ga4-table-tab-products').click()
    expect(await box(table), 'a tab change resized the table').toEqual(first)
    expect(await page.evaluate(() => document.documentElement.scrollHeight), 'the page grew or shrank').toBe(pageBefore)
  })

  test('the client link shows the store parts, without a campaign name or a spend figure', async ({ page }) => {
    test.setTimeout(120_000)
    await page.context().clearCookies()
    // REPORT-WEBSITE-TAB-001 — everything about the website is one tab, «الموقع».
    await page.goto(`/r/${LIVE_TOKEN}?view=website`)
    await expect(page.getByTestId('live-mode-website')).toBeVisible({ timeout: 30000 })

    const ga4 = page.getByTestId('report-ga4')
    await expect(ga4).toBeVisible({ timeout: 30000 })
    await expect(ga4.getByTestId('report-ga4-overview')).toBeVisible()
    // The attribution match is the website set beside each platform; the older comparison card is not drawn beside it.
    await expect(ga4.getByTestId('report-ga4-attribution')).toBeVisible()
    await expect(ga4.getByTestId('report-ga4-gap')).toHaveCount(0)
    await expect(ga4.getByTestId('ga4-table-tab-campaigns')).toHaveCount(0)
    for (const campaign of ['eid_collection', 'retargeting_30d', 'abaya_search', 'new_arrivals']) {
      await expect(ga4).not.toContainText(campaign)
    }
    await expect(ga4).not.toContainText(/الإنفاق|Spend/)
  })

  test('the saved report carries the store pages it was saved with', async ({ page, request }) => {
    test.setTimeout(120_000)
    const projectId = await seededProject(request, STORE_PROJECT)
    const headers = await csrfHeaders(request)
    const list = ((await (await request.get(`/api/v1/projects/${projectId}/reports`, { headers: API_HEADERS })).json()).data?.reports ?? []) as Array<{ id: string; status?: string }>
    const report = list.find((r) => r.status === 'completed')
    expect(report, 'the store project has no completed report').toBeTruthy()

    const created = await request.post(`/api/v1/projects/${projectId}/reports/${report!.id}/shares`, {
      headers: { ...headers, 'Content-Type': 'application/json' },
      data: { mode: 'snapshot' },
    })
    expect(created.ok(), `share creation returned ${created.status()}`).toBe(true)
    const body = (await created.json()).data as Record<string, string>
    const raw = body.raw_token ?? body.token ?? body.url?.split('/r/')[1]
    expect(raw).toBeTruthy()

    await page.context().clearCookies()
    await page.goto(`/r/${raw}`)
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
    // The scroll mode lays every page out at once.
    await page.getByRole('button', { name: 'صفحة', exact: true }).click()
    await expect(page.getByTestId('slide-ga4-overview')).toBeVisible({ timeout: 30000 })
    // One comparison: the attribution match page replaces the older website-vs-platforms page.
    await expect(page.getByTestId('slide-ga4-attribution')).toBeVisible()
    await expect(page.getByTestId('slide-ga4-gap')).toHaveCount(0)
    await expect(page.getByTestId('slide-ga4-tables')).toBeVisible()
    await expect(page.getByTestId('slide-ga4-tables').getByTestId('ga4-table-tab-campaigns')).toHaveCount(0)
  })
  // ---------------------------------------------------------------- GA4-ATTRIBUTION-MATCH-001 / GA4-VISIBILITY-001 / GA4-GAP-EXPLAINED-001

  test('the match drills platform to campaign to landing pages in a box that never moves, and the filter chips narrow it', async ({ page, request }) => {
    test.setTimeout(150_000)
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/analytics?tab=site')

    await expect(page.getByTestId('ga4-match')).toBeVisible({ timeout: 30000 })
    await expect(page.getByTestId('match-basis')).toHaveText(/Website|الموقع/)
    const table = page.getByTestId('match-table-box')
    await table.scrollIntoViewIfNeeded()
    const level1 = await box(table)
    const pageHeight = await page.evaluate(() => document.documentElement.scrollHeight)

    // Platform -> campaigns.
    await page.locator('[data-testid^="match-drill-platform-"]').first().click()
    await expect(page.getByTestId('match-crumb-platform')).toBeVisible()
    expect(await box(page.getByTestId('match-table-box')), 'drilling to campaigns resized the table').toEqual(level1)
    await expect(page.getByTestId('confidence-utm').or(page.getByTestId('confidence-id')).first()).toBeVisible()

    // Campaign -> landing pages, then back with the breadcrumb.
    await page.locator('[data-testid^="match-drill-campaign-"]').first().click()
    await expect(page.getByTestId('match-crumb-campaign')).toBeVisible()
    expect(await box(page.getByTestId('match-landing-table-box')), 'the landing level resized the table').toEqual(level1)
    await page.getByTestId('match-crumb-platform').click()
    await expect(page.getByTestId('match-table-box')).toBeVisible()
    await page.getByTestId('match-crumb-root').click()
    expect(await box(page.getByTestId('match-table-box'))).toEqual(level1)
    expect(await page.evaluate(() => document.documentElement.scrollHeight)).toBe(pageHeight)

    // A platform chip narrows the match, the classification and the gap together.
    const rowsBefore = await page.locator('[data-testid^="match-drill-platform-"]').count()
    await page.locator('[data-testid^="ga4-filter-platform-"]:not([data-testid$="-all"])').first().click()
    await expect(page.locator('[data-testid^="match-drill-platform-"]')).toHaveCount(1)
    expect(rowsBefore).toBeGreaterThan(1)
    await page.getByTestId('ga4-filter-platform-all').click()
  })

  test('the classification switches grouping with one control and keeps its box', async ({ page, request }) => {
    test.setTimeout(120_000)
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/analytics?tab=site')
    await expect(page.getByTestId('ga4-classify')).toBeVisible({ timeout: 30000 })

    const table = page.getByTestId('ga4-classify-table-box')
    await table.scrollIntoViewIfNeeded()
    const first = await box(table)
    for (const group of ['source_medium', 'platform', 'campaign', 'landing_page', 'device', 'country', 'user_type', 'product', 'product_category', 'channel']) {
      await page.getByTestId(`ga4-group-${group}`).click()
      await expect(page.getByTestId(`ga4-group-${group}`)).toHaveAttribute('aria-checked', 'true')
      expect(await box(table), `grouping by ${group} resized the table`).toEqual(first)
    }
  })

  test('the reasons of the gap are measured: they sum to the gap, the unmeasured carry no number, and a reason opens its rows', async ({ page, request }) => {
    test.setTimeout(120_000)
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/analytics?tab=site')
    await expect(page.getByTestId('gap-reasons')).toBeVisible({ timeout: 30000 })

    await expect(page.getByTestId('gap-headline-gap')).toBeVisible()
    await expect(page.getByTestId('gap-bar')).toBeVisible()
    await expect(page.getByTestId('gap-unexplained')).toBeVisible()
    const unmeasured = page.getByTestId('gap-unmeasured-view_through')
    await expect(unmeasured).toBeVisible()
    expect(((await unmeasured.textContent()) ?? '').replace(/[^0-9]/g, '')).toBe('')
    // REPORT-WEBSITE-GAP-READING-001 — tracking paths, no source named, a visible sum, windows in words.
    const panel = page.getByTestId('gap-reasons')
    await expect(panel.getByTestId('gap-sum')).toBeVisible()
    await expect(panel.getByTestId('gap-reason-no_platform_trace')).toBeVisible()
    expect(await panel.innerText()).not.toMatch(/GA4|Google|بلا منصة|بلا أثر|بلا حملة|من خارج الحملات|\d+d_(click|view)|سجل المتجر/i)
    await page.getByTestId('gap-reason-no_trace').click()
    await expect(page.getByTestId('gap-reason-drill')).toBeVisible()
  })

  test('sales can be hidden in one click and the figures are gone from the data, not only the page; a saved view remembers it', async ({ page, request }) => {
    test.setTimeout(120_000)
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/analytics?tab=site')
    await expect(page.getByTestId('ga4-tile-revenue')).toBeVisible({ timeout: 30000 })

    const responses: string[] = []
    page.on('response', async (r) => {
      if (r.url().includes('/measurement/store')) responses.push(await r.text())
    })
    await page.getByTestId('ga4-visibility-preset-marketing').click()
    await expect(page.getByTestId('ga4-tile-revenue')).toHaveCount(0)
    await expect(page.getByTestId('ga4-tile-aov')).toHaveCount(0)
    await expect(page.getByTestId('ga4-tile-sessions')).toBeVisible()
    await expect.poll(() => responses.length).toBeGreaterThan(0)
    expect(responses.at(-1), 'the store response still carried a revenue tile').not.toContain('"key":"revenue"')

    await page.getByTestId('ga4-visibility-preset-all').click()
    await expect(page.getByTestId('ga4-tile-revenue')).toBeVisible()
  })

  test('the live link carries the attribution part for a client, scoped to what it names, without a campaign name or a spend figure', async ({ page }) => {
    test.setTimeout(120_000)
    await page.context().clearCookies()
    await page.goto(`/r/${LIVE_TOKEN}?view=website`)
    const ga4 = page.getByTestId('report-ga4')
    await expect(ga4).toBeVisible({ timeout: 30000 })

    await expect(ga4.getByTestId('report-ga4-attribution')).toBeVisible()
    // The demo link names campaigns, so the store-wide parts (pulse, classification, products, reasons) are not its to carry.
    await expect(ga4.getByTestId('report-ga4-reasons')).toHaveCount(0)
    await expect(ga4.getByTestId('report-ga4-pulse')).toHaveCount(0)
    await expect(ga4.getByTestId('report-ga4-classification')).toHaveCount(0)
    for (const campaign of ['eid_collection', 'retargeting_30d', 'abaya_search', 'new_arrivals']) {
      await expect(ga4).not.toContainText(campaign)
    }
    await expect(ga4).not.toContainText(/الإنفاق|Spend|ROAS/)
  })
})
