import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * CONTENT-POPUP-SCALE-001 — the panel shows the creative, its figures and its trend at once.
 *
 * ## The owner's acceptance, in their own words
 *
 * «Larger than the current cramped modal, but NOT full-screen … keep compact KPI cards visible
 * without scrolling … keep the main trend chart visible in the first viewport … The user should not
 * need to scroll just to see: creative + KPIs + main chart.»
 *
 * ## Why a measurement and not a class assertion
 *
 * «Cramped» is a fact about pixels on a screen. The panel was `max-w-2xl` — 672 of a 1440-pixel
 * width — and the interesting part is not that number but its consequence, measured here before the
 * change: the trend chart sat below the fold of the panel somebody had opened in order to judge a
 * creative. A test asserting `lg:max-w-5xl` would pass on a layout that still pushed the chart off,
 * because a wider single column makes a taller hero and the chart moves DOWN.
 *
 * So this asks the three questions the owner asked, of the rendered page: is the creative there, are
 * the figures there, is the chart there, and are all three inside the first viewport.
 */

/** Bottom edge of an element, or null when it is not on the page at all. */
async function bottomOf(page: Page, testid: string): Promise<number | null> {
  const el = page.getByTestId(testid)

  if (await el.count() === 0) return null

  const box = await el.first().boundingBox()

  return box === null ? null : box.y + box.height
}

async function openTheQuickPanel(page: Page) {
  /*
   * The card's own poster button — the thumbnail the owner's flow starts from. It is addressed by
   * its position in the grid rather than by a testid because it has none, and adding one here would
   * be changing the product to suit the test.
   */
  const card = page.locator('article button').first()
  await expect(card, 'no content card offered a way into the panel').toBeVisible({ timeout: 30000 })
  await card.click()
  await expect(page.getByTestId('ad-preview-dialog')).toBeVisible({ timeout: 15000 })
}

test.describe('the quick panel is read without scrolling', () => {
  test.use({ storageState: AUTH.owner })

  test('creative, figures and trend all sit in the first viewport at 1440', async ({ page, request }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')
    await openTheQuickPanel(page)

    /*
     * The media COLUMN rather than the poster: a video creative draws a player, an image draws a
     * poster and an unavailable one draws the compact absence, and «the creative is visible» is the
     * same requirement for all three. Measuring whichever element happened to render would make the
     * case depend on which creative the seed put first.
     */
    const media = await bottomOf(page, 'ad-preview-dialog-media')

    expect(media, 'the panel drew no media area at all').not.toBeNull()

    const figures = await bottomOf(page, 'ad-preview-dialog-figures')
    const trend = await bottomOf(page, 'ad-preview-dialog-trend')

    /*
     * Each part is asserted only when the creative HAS it — a seeded ad with no reported day draws
     * no figures, and asserting their position would make this a test about the fixture. What is
     * not optional is that whatever IS drawn is drawn where the reader can see it.
     */
    for (const [what, edge] of [['media', media], ['figures', figures], ['trend', trend]] as const) {
      if (edge === null) continue

      expect(
        edge,
        `the ${what} ends ${Math.round(edge)}px down a 900px viewport — the owner asked to see the `
        + 'creative, the KPIs and the chart without scrolling for them',
      ).toBeLessThanOrEqual(900)
    }

    // At least two of the three must be present, or the case proved nothing about a layout.
    expect([media, figures, trend].filter((e) => e !== null).length).toBeGreaterThan(1)
  })

  test('it is a panel over the library, not a page — it never fills the screen', async ({ page, request }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')
    await openTheQuickPanel(page)

    /* The panel is the dialog's only child; the dialog itself is the backdrop and does cover the page. */
    const panel = await page.getByTestId('ad-preview-dialog').locator('> div').first().boundingBox()

    expect(panel).not.toBeNull()

    /*
      Wider than it was — the cramped 672 is the defect — and short of the screen, because the grid
      staying visible behind it is what makes this a panel somebody dismisses rather than a place
      they navigated to.
    */
    expect(panel!.width, 'the panel is still the cramped width the owner reported').toBeGreaterThan(800)
    expect(panel!.width, 'the panel has become a full-screen page').toBeLessThan(1440 * 0.9)
    expect(panel!.height, 'the panel has become a full-screen page').toBeLessThanOrEqual(900 * 0.95)
  })

  /* A phone keeps the single column — two columns on 390px would be four. */
  test('a phone still reads it as one column', async ({ page, request }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')
    await openTheQuickPanel(page)

    const panel = await page.getByTestId('ad-preview-dialog').locator('> div').first().boundingBox()

    expect(panel).not.toBeNull()
    expect(panel!.width).toBeLessThanOrEqual(390)

    // Nothing may overflow sideways — the page body must never scroll horizontally.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
    expect(overflow, 'the panel pushed the page sideways on a phone').toBeLessThanOrEqual(1)
  })

  /**
   * CONTENT-POPUP-TREND-404-001 — the chart LOADS, it does not just have a place reserved for it.
   *
   * The panel drew «تعذّر تحميل الاتجاه الزمني لهذا المحتوى» — a load error — for a creative sitting
   * in the library the click came from. `CreativeTrend` asked
   * `/projects/{project}/creatives/{creative}`, which is scoped to one project, while the library
   * spans projects and a card carries no project id. `CreativeAnalysisController::detail` says
   * exactly that in its own docblock, and `CreativeDetailPage` has always used the endpoint whose
   * ceiling is the reader's membership. The component written to stop the modal and the page
   * deriving a trend two ways had drifted from the page at the endpoint.
   *
   * Every unit test mocks that call, so none of them could see it. This asserts the rendered state:
   * the trend, or an honest «no days were reported» — never the error.
   */
  test('the trend actually loads rather than erroring', async ({ page, request }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')
    await openTheQuickPanel(page)

    /* Give the request its own wait rather than racing the dialog's appearance. */
    await expect
      .poll(
        async () => (await page.getByTestId('creative-trend').count())
          + (await page.getByTestId('creative-trend-empty').count())
          + (await page.getByTestId('creative-trend-error').count()),
        { timeout: 15000 },
      )
      .toBeGreaterThan(0)

    expect(
      await page.getByTestId('creative-trend-error').count(),
      'the panel could not load the trend for a creative that is in the library it was opened from '
      + '— the project-scoped endpoint cannot reach a library that spans projects',
    ).toBe(0)
  })
})
