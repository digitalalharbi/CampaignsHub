import { expect, test, type APIRequestContext, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * KPI-SELECTION-001 — four cards, and the metric on each is the reader's to choose.
 *
 * «Remove More metrics / Hide extra metrics … keep exactly 4 primary KPI cards visible by default …
 * Each KPI card must have a metric dropdown on the metric name … Search for a metric… … PERSIST THE
 * USER'S FOUR KPI CHOICES … AR/EN + RTL/LTR + Dark/Light must work.»
 *
 * The unit tests hold the mechanism against a mocked summary. What they cannot hold is the part the
 * Owner asked for by name: that the choice SURVIVES — a real reload, in a real browser, against the
 * real API, on all three engines. `localStorage` is per-origin and per-engine, and a card that keeps
 * its metric in jsdom is not evidence that webkit's storage did.
 *
 * Signed in as the ADVERTISER: `/app` is the advertiser's portal and refuses an agency operator by
 * design, so the agency `owner` would prove nothing about this page.
 */
test.describe('the dashboard KPI cards', () => {
  test.use({ storageState: AUTH.advertiser })

  /** Every card's picker, in the order they are rendered. */
  const pickers = (page: Page) => page.locator('[data-testid^="kpi-picker-"]')

  /**
   * A project that HAS data, chosen rather than inherited — and that is a product fact, not a fixture
   * convenience.
   *
   * METRICS-EMPTY-SCOPE-001 says a scope matching nothing renders one sentence about the filter
   * instead of a row of cards, so on an empty scope there are no cards and therefore no pickers. The
   * gate opened on «E2E Linking», whose window holds nothing, and these cases spent sixty seconds
   * waiting for a control that correctly was not there. The interaction between the two rules is
   * asserted below rather than left as a surprise for the next reader.
   */
  async function openDashboard(page: Page, request: APIRequestContext) {
    await selectProject(page, await seededProject(request, 'Growth — Acquisition'))
    await page.goto('/app/dashboard')
    await expect(page.getByTestId('kpi-picker-0')).toBeVisible({ timeout: 60000 })
  }

  test('four cards, no «more metrics», and a metric chosen through search', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openDashboard(page, request)

    /* Exactly four — «keep exactly 4 primary KPI cards visible by default». */
    await expect(pickers(page)).toHaveCount(4)

    /*
      And the control the Owner asked to have removed is gone. Matched in BOTH languages: the page
      loads in Arabic by default, and a regression that reintroduced the English string only would
      pass a check that looked for the Arabic one.
    */
    await expect(page.getByRole('button', { name: /More metrics|مؤشرات إضافية/i })).toHaveCount(0)

    await page.getByTestId('kpi-picker-0').click()

    /*
      «roas», not «Return». The catalogue's own name for this metric is «العائد على الإنفاق» in Arabic
      and «Return on ad spend» in English — neither contains the term an ads operator actually types,
      which is why the search matches the metric KEY as well as its label. Typing the abbreviation is
      the case that was broken.
    */
    await page.getByTestId('kpi-search').fill('roas')
    await expect(page.getByTestId('kpi-option-roas')).toBeVisible()

    await page.getByTestId('kpi-option-roas').click()

    /* The card now carries that metric — its name is on the control that changes it. */
    await expect(page.getByTestId('kpi-picker-0')).toHaveAttribute('aria-label', /roas|العائد على الإنفاق|Return on ad spend/i)
  })

  /**
   * The choice survives a reload, a language switch and the direction flip that comes with it.
   *
   * One test rather than three: the claim is that ONE stored choice outlives all of it, and splitting
   * it would let each half pass while the sequence a reader actually performs still lost the card.
   */
  test('the chosen metric survives a reload and a language switch', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openDashboard(page, request)

    await page.getByTestId('kpi-picker-0').click()
    await page.getByTestId('kpi-search').fill('reach')
    await page.getByTestId('kpi-option-reach').click()

    const chosen = /reach|الوصول|Reach/i
    await expect(page.getByTestId('kpi-picker-0')).toHaveAttribute('aria-label', chosen)

    await page.reload()
    await expect(page.getByTestId('kpi-picker-0')).toHaveAttribute('aria-label', chosen, { timeout: 60000 })

    /* And through the direction flip, where a re-mount would be the obvious place to lose it. */
    await page.getByRole('button', { name: 'Toggle language' }).first().click()

    await expect(page.locator('html')).toHaveAttribute('dir', /rtl|ltr/)
    await expect(page.getByTestId('kpi-picker-0')).toHaveAttribute('aria-label', chosen, { timeout: 60000 })
  })

  /**
   * SURFACE-SEPARATION-001, as the Owner corrected it — the dashboard is RICH, not stripped.
   *
   * «#362 went too far by removing useful Dashboard sections and replacing them with Open Analytics
   * for the reason and the detail.» The curve and the rate trends are back above the reasoning, and
   * this asserts them on the running page rather than in the source, because the source guard cannot
   * tell a block that renders from a block swallowed by an unclosed JSX comment — which is exactly
   * how the first restoration shipped nothing.
   */
  test('the dashboard draws the curve and the rate trends', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openDashboard(page, request)

    const overview = page.getByTestId('dashboard-overview')

    await expect(overview).toContainText(/Spend, results and revenue|الإنفاق والنتائج والإيرادات/i, { timeout: 60000 })
    /* CTR appears here only as a rate-trend panel, so its presence is the claim. */
    await expect(overview).toContainText(/CTR/)
  })

  /**
   * And a scope with nothing in it keeps its sentence — the selector does not override that rule.
   *
   * The obvious «fix» for the gate failure this spec caused would have been to render four cards on
   * an empty scope so the pickers exist. That is METRICS-EMPTY-SCOPE-001 undone: a filter matching
   * nothing would go back to making four claims about what the platforms reported. The reader gets
   * out of an empty scope by changing the FILTER, not the metric, and this pins that.
   */
  test('an empty scope keeps its sentence instead of four empty cards', async ({ page, request }) => {
    test.setTimeout(180_000)

    await openDashboard(page, request)

    await page.getByTestId('dashboard-objective').selectOption('awareness_engagement')

    await expect(page.getByTestId('dashboard-metrics-empty-scope')).toBeVisible({ timeout: 30000 })
    await expect(page.getByTestId('kpi-picker-0')).toHaveCount(0)
  })
})
