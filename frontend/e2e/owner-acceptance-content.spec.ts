import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * OWNER-ACCEPTANCE-CONTENT-001 — the owner's own checklist, run against rendered cards.
 *
 * ## Why this file exists separately from the unit guards
 *
 * Every defect on the list below had passing tests over it. «CPM 65.65%» had a dialog test. The
 * misaligned figure had a source guard that required the misalignment. The blank collection frame
 * had a presenter test that asserted the state and never looked at the URLs. Each check was true
 * about the thing it measured and none of them was about what a person sees.
 *
 * So this asserts the OUTPUT, on the real pages, in three browsers:
 *
 *   - no `undefined`, no `NaN`, no `null` printed as text anywhere on the surface;
 *   - no cost or return wearing a percent sign;
 *   - a figure the provider never sent reads as absent, not as zero;
 *   - the media box holds a picture or a short reason — never a paragraph;
 *   - the popup is what a thumbnail opens, and it offers the way to the ad's own page.
 *
 * It is deliberately about SHAPES rather than values: the demo figures change every time the seeder
 * runs, and a test pinned to «539K» would be rewritten every sprint until somebody deleted it.
 */
const GARBAGE = /\b(undefined|NaN|\[object Object\])\b/

/** «12.5%» attached to a cost or a return — the unit defect, as a reader meets it. */
const MONEY_AS_PERCENT = /\b(CPC|CPM|CPA|ROAS|تكلفة النقرة|تكلفة الألف ظهور|تكلفة النتيجة|العائد على الإنفاق)\b[^%\n]{0,24}\d[\d,.]*\s*%/

async function surfaceText(page: Page, selector: string): Promise<string> {
  return (await page.locator(selector).first().innerText()).replace(/\s+/g, ' ')
}

test.describe('the content surfaces the owner checked', () => {
  test.use({ storageState: AUTH.owner })

  test('the library prints no placeholder value and no cost as a percentage', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')
    await expect(page.locator('[data-testid="content-summary"]')).toBeVisible({ timeout: 30000 })
    await page.waitForLoadState('networkidle')

    const text = await surfaceText(page, 'main')

    expect(text, 'a placeholder reached the page as text').not.toMatch(GARBAGE)
    expect(text, 'a cost or a return is wearing a percent sign').not.toMatch(MONEY_AS_PERCENT)
  })

  /**
   * The media box is a picture or a few words — never a paragraph.
   *
   * The owner's word for the old state was «a large explanatory paragraph occupying the creative
   * image area». The sentence still exists, on the box's `title` and for a screen reader; what must
   * not come back is printing it where the picture goes.
   */
  test('an absent picture is stated in a few words, not a paragraph', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')
    await page.waitForLoadState('networkidle')

    const labels = page.locator('[data-testid$="-absent-label"]')
    const count = await labels.count()

    /* A library where every ad has its media is a pass, not a skip — there is nothing to check. */
    for (let i = 0; i < count; i++) {
      const words = (await labels.nth(i).innerText()).trim().split(/\s+/).length

      expect(words, 'the picture area is holding a sentence again').toBeLessThanOrEqual(4)
    }

    /* And the reason is still reachable rather than deleted. */
    if (count > 0) {
      const box = page.locator('[data-testid$="-absent"]').first()
      expect((await box.getAttribute('title')) ?? '').not.toBe('')
    }
  })

  /**
   * A thumbnail opens the quick popup, and the popup offers the ad's own page.
   *
   * `thumbnail → quick popup → the content analytics page`. Not the full-screen viewer, which is
   * retired, and not a dead end: before this the popup Analytics opened had no way onward at all.
   */
  test('a thumbnail opens the popup, and the popup leads to the ad’s page', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')
    await page.waitForLoadState('networkidle')

    await page.getByRole('article').first().getByRole('button').first().click()

    const dialog = page.getByTestId('ad-preview-dialog')
    await expect(dialog, 'a thumbnail opened no detail at all').toBeVisible({ timeout: 15000 })

    const text = (await dialog.innerText()).replace(/\s+/g, ' ')
    expect(text).not.toMatch(GARBAGE)
    expect(text, 'the popup is printing a cost as a percentage').not.toMatch(MONEY_AS_PERCENT)

    const onward = dialog.getByTestId('ad-preview-dialog-details')
    await expect(onward, 'the popup is a dead end').toBeVisible()

    /*
     * The link stays in the portal the reader is standing in. It was hardcoded to `/app/…`, so every
     * operator working in `/agency` was thrown across portals by the popup's only control.
     */
    expect(await onward.getAttribute('href')).not.toContain('/app/')

    await onward.click()
    /*
      A generous budget on the navigation alone. The route lazy-loads the creative's page and webkit
      under a three-project parallel run took longer than the default five seconds — which failed
      here and passed when the file ran by itself, the signature of a budget rather than a defect.
    */
    await expect(page, 'the popup did not reach the creative’s own page')
      .toHaveURL(/\/agency\/content\/[^/]+/, { timeout: 20000 })
  })

  /** The same, in English, because the direction switch has hidden defects before. */
  test('English: the library prints no placeholder value', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')
    await switchToEnglish(page)
    await page.waitForLoadState('networkidle')

    const text = await surfaceText(page, 'main')

    expect(text).not.toMatch(GARBAGE)
    expect(text).not.toMatch(MONEY_AS_PERCENT)
  })

  /**
   * And the Analytics content table — the surface the owner's second screenshot came from.
   *
   * Its popup is where «CPM 65.65%» was printed, over a row that said «1.25 USD» about the same
   * click.
   */
  /*
   * The tab slug is `creative`, singular.
   *
   * This case asked for `?tab=creatives`, which is not a tab: `useUrlState` fell back to
   * `performance`, and the assertions — both of them absence assertions — passed on the overview
   * having never rendered a content table. A guard made only of «X does not appear» passes loudest
   * on a page that rendered nothing, so it now names the table it is about FIRST and the absence
   * checks follow it.
   */
  test('the Analytics content table and its popup agree about units', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/analytics?tab=creative')
    await page.waitForLoadState('networkidle')

    const text = await surfaceText(page, 'main')
    expect(text, 'the content table did not render, so nothing below was measured').toMatch(/أداء المحتويات|Content performance/)
    expect(text).toMatch(/الإنفاق|Spend/)
    expect(text).not.toMatch(GARBAGE)
    expect(text).not.toMatch(MONEY_AS_PERCENT)
  })

  /**
   * CONTENT-SPEND-ALWAYS-001 — the reconciliation the owner asked for, on the rendered pages.
   *
   * The owner's words: «Spend is a mandatory operational figure for every promoted creative where
   * the provider reported it. It must remain visible regardless of objective.» The unit guards prove
   * each surface in isolation with a fixture; this proves the CHAIN — library, groups, the ad's own
   * page and the Analytics content table — in three browsers, because «every surface agrees» is a
   * claim about surfaces together and no single-surface test can make it.
   *
   * Shapes, not values: the seeder's figures change, and the acceptance is that spend is STATED.
   */
  const SPEND = /الإنفاق|Spend/

  /** A money figure carrying its own currency — the withheld state, which must never read as absent. */
  const ABSENT = /لا توجد بيانات|No data|غير متاح|Not provided/

  for (const [name, path, ready] of [
    ['the library', '/agency/content', '[data-testid="content-summary"]'],
    ['the groups page', '/agency/content/groups', 'main'],
    ['the Analytics content table', '/agency/analytics?tab=creative', 'main'],
  ] as const) {
    test(`${name} states spend whatever the objective headlines`, async ({ page, request }) => {
      await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
      await page.goto(path)
      await expect(page.locator(ready).first()).toBeVisible({ timeout: 30000 })
      await page.waitForLoadState('networkidle')

      const text = await surfaceText(page, 'main')

      expect(text, 'spend is not named on this surface').toMatch(SPEND)
      expect(text, 'a placeholder reached the page as text').not.toMatch(GARBAGE)

      /*
       * The figure beside the label must be a figure. An absence sentence directly after «Spend» is
       * the production defect itself — a withheld amount rendered as though nothing ran.
       */
      const beside = text.match(/(?:الإنفاق|Spend)\s*([^\n]{0,24})/)?.[1] ?? ''
      expect(beside, `«${beside}» follows the spend label`).not.toMatch(ABSENT)
    })
  }
})


/**
 * CONTENT-SUMMARY-COMPACT-001 / CONTENT-POPUP-VISUAL-001 — the owner's UX ruling, measured.
 *
 * «Too many large KPI cards, clutter without enough value … compact summary → filters → content
 * grid → quick insight.» The strip had thirteen cards and nine of them read «no data» on the owner's
 * own account — my own doing, and truthful rather than readable.
 *
 * Counting them is the check, because «compact» is exactly the property a screenshot shows and a
 * unit test cannot.
 */
test.describe('the content library reads as a content page', () => {
  test.use({ storageState: AUTH.owner })

  test('the top is a compact summary, not a wall of cards', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')

    const summary = page.getByTestId('content-summary')
    await expect(summary).toBeVisible({ timeout: 30000 })

    /* Four figures. The other nine live on the creative's page and in the popup. */
    const figures = summary.getByTestId('content-summary-figures').locator('> div')
    await expect(figures).toHaveCount(4)

    /* And the old thirteen-card strip is gone, not merely shrunk. */
    await expect(page.getByTestId('content-metrics')).toHaveCount(0)
  })

  /**
   * The summary sits ABOVE the filters, which sit above the grid.
   *
   * «compact summary → filters → content grid/table → quick insight» is an order, and an order is
   * the one thing a component test cannot hold.
   */
  test('the page reads summary, then filters, then the grid', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')

    await expect(page.getByTestId('content-summary')).toBeVisible({ timeout: 30000 })

    const top = async (selector: string) => (await page.locator(selector).first().boundingBox())?.y ?? Infinity

    const summaryY = await top('[data-testid="content-summary"]')
    const filtersY = await top('[data-testid="content-filters"]')
    const gridY = await top('article')

    expect(summaryY).toBeLessThan(gridY)
    expect(filtersY, 'the filters are not between the summary and the grid').toBeLessThan(gridY)
    expect(summaryY).toBeLessThan(filtersY)
  })

  /** And the popup leads with the work and its figures, not with a stack of ids. */
  test('the popup puts the creative and its chart before its metadata', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')

    await page.getByRole('article').first().getByRole('button').first().click()

    const dialog = page.getByTestId('ad-preview-dialog')
    await expect(dialog).toBeVisible({ timeout: 15000 })

    const meta = dialog.getByTestId('ad-preview-dialog-meta')
    await expect(meta).toBeVisible()

    const poster = await dialog.locator('img, video, [data-testid$="-absent"]').first().boundingBox()
    const metaBox = await meta.boundingBox()

    expect(poster?.y ?? Infinity, 'the media is not the first thing in the panel')
      .toBeLessThan(metaBox?.y ?? 0)
  })
})
