import { expect, test, type APIRequestContext, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * CONTENT-FILTER-TRUTH-001 — the owner's own scope, in a real browser.
 *
 * `Project 1 · Snapchat · Content type = Video · Objective = Sales` answered «لا توجد إعلانات تطابق
 * هذا التحديد» over an estate that plainly holds Snapchat videos. Two causes, both fixed at the rule:
 * the Video filter matched the provider's format string while the CARD classifies by assets too, and
 * «Sales» meant one raw objective out of four while «Conversions» sat beside it as a rival choice.
 *
 * What the unit tests cannot hold is that the visible control and the backend agree — that choosing
 * «المبيعات» actually narrows the query rather than the label. Every assertion below reads the
 * REQUEST as well as the page, because a control showing a narrowing over an unnarrowed query is the
 * frontend-only filtering this product forbids.
 */
test.describe('the content library filters', () => {
  test.use({ storageState: AUTH.advertiser })

  async function openLibrary(page: Page, request: APIRequestContext) {
    await selectProject(page, await seededProject(request, 'Growth — Acquisition'))
    await page.goto('/app/content')
    await expect(page.getByTestId('content-filters')).toBeVisible({ timeout: 60000 })
  }

  test('offers the five product objectives and no «Marketing path» control', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openLibrary(page, request)

    const bar = page.getByTestId('content-filters')

    /* One axis, one question — the duplicate primary control is gone. */
    await expect(bar).not.toContainText('المسار التسويقي')
    await expect(bar).not.toContainText('Marketing path')

    await page.getByTestId('content-objectives').click()

    /*
      Scoped to THIS control's popover. `getByRole('option')` matched nineteen: every other filter on
      the bar contributes options too, and a page-wide count would pass or fail on an unrelated axis.
    */
    const options = page.getByTestId('content-objectives-options').getByRole('option')
    await expect(options).toHaveCount(5)

    /*
     * «Do NOT expose raw Conversions as a separate visible objective beside Sales.» Asserted by
     * NAME rather than by count: five options of the wrong five would pass a count.
     */
    const labels = await options.evaluateAll((nodes) => nodes.map((n) => (n.textContent ?? '').trim()))

    expect(labels.join(' ')).toContain('المبيعات')
    expect(labels.join(' ')).not.toContain('التحويلات')
  })

  /**
   * And every option says what it can reach, with the empty ones disabled rather than hidden.
   *
   * Hiding them would make the vocabulary look smaller than the product's, which is the failure the
   * library already records for creative shapes: an operator concludes the account has no collection
   * ads rather than that the picker has no word for them.
   */
  test('says what each objective can reach, and disables the ones that reach nothing', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openLibrary(page, request)
    await page.getByTestId('content-objectives').click()

    const popover = page.getByTestId('content-objectives-options')

    const counted = popover.locator('[role="option"][data-count]')
    await expect(counted).toHaveCount(5)

    const zeroed = popover.locator('[role="option"][data-count="0"]')

    if (await zeroed.count() > 0) {
      await expect(zeroed.first()).toBeDisabled()
    }
  })

  /**
   * The narrowing reaches the SERVER — and the CANONICAL key is what travels.
   *
   * This is the mechanism behind the owner's empty page, and it is worth being precise about where
   * the expansion happens. The request carries `objectives[]=sales`; the SERVER expands that into
   * `sales / conversions / add_to_cart / purchases`, because the raw vocabulary is a normalisation
   * fact and not something a URL should have to know. `ContentObjectiveTaxonomyTest` holds the
   * expansion where it happens; this holds that the choice reaches the query at all, which is the
   * half a frontend-only filter would fail.
   */
  test('sends the objective to the backend rather than narrowing the label', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openLibrary(page, request)

    const asked: string[] = []
    page.on('request', (r) => {
      if (r.url().includes('/creatives')) asked.push(decodeURIComponent(r.url()))
    })

    await page.getByTestId('content-objectives').click()
    await page.getByTestId('content-objectives-options').getByRole('option', { name: /المبيعات|Sales/ }).click()

    await expect.poll(() => asked.some((u) => /objectives(\[\])?=sales/.test(u)), {
      timeout: 30_000,
      message: 'the objective filter narrowed the label without narrowing the query',
    }).toBe(true)

    /* And the page says what is narrowing it, so the reader can take it back. */
    await expect(page.getByTestId('content-applied')).toBeVisible({ timeout: 30_000 })
  })

  /**
   * Removing a filter WIDENS the result — a narrowing that cannot be undone is not a filter.
   *
   * Counted on the CARDS the page draws rather than on a total it prints, because the total is a
   * figure the server sends and the cards are what the reader actually gets; the defect being
   * guarded is a filter that empties the page while the total keeps claiming rows.
   */
  test('resetting the filters widens the result', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openLibrary(page, request)

    const cards = page.locator('article')

    /*
      Awaited, not counted immediately: the filter bar renders before the list answers, so reading
      the count on arrival measures the request rather than the result — and a `before` of zero makes
      every later comparison trivially true, which is a test that cannot fail.
    */
    await expect(cards.first()).toBeVisible({ timeout: 60_000 })
    const before = await cards.count()

    await page.getByTestId('content-objectives').click()
    await page.getByTestId('content-objectives-options').getByRole('option', { name: /المبيعات|Sales/ }).click()

    await expect(page.getByTestId('content-applied')).toBeVisible({ timeout: 30_000 })
    const narrowed = await cards.count()

    expect(narrowed).toBeLessThanOrEqual(before)

    /*
      Close the picker before reaching for the reset — the popover is still open and overlays the bar.
      Firefox reported it exactly: the reset button RESOLVED and the click never landed. A reader
      closes it the same way, and a test that clicks through an overlay is testing a page nobody has.
    */
    await page.keyboard.press('Escape')
    await expect(page.getByTestId('content-objectives-options')).toHaveCount(0, { timeout: 10_000 })

    await page.getByTestId('content-reset').click()
    await expect(page.getByTestId('content-applied')).toHaveCount(0, { timeout: 30_000 })

    await expect.poll(() => cards.count(), { timeout: 30_000 }).toBeGreaterThanOrEqual(narrowed)
  })
})
