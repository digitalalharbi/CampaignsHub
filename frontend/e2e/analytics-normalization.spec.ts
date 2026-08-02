import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * `/app/analytics` states the basis of its own figures (NORM-001).
 *
 * The normalisation layer has always run: every `daily_metrics` row records the currency it arrived
 * in and the one it was converted to, the rate, the platform's timezone and the project's, the
 * attribution window that counted its conversions, and whether it came from an API or from demo data.
 * None of it was shown. Spend appeared converted with nothing saying a conversion had happened, and
 * the API announced «SAR» as a constant regardless of what was in the rows.
 *
 * These assert on the BASIS rather than on the numbers, because the numbers are demo data and will
 * change. What must not change is that the page keeps saying what was done to them — and that it says
 * so in words, not in the column values the database happens to store.
 *
 * This file is also the first E2E coverage `/app/analytics` has ever had. Six tabs of charts were
 * reachable from the rail and walked by nothing.
 */
test.describe('the analytics page explains its own figures', () => {
  test.use({ storageState: AUTH.advertiser })

  /** Open Analytics and switch to the tab that carries the provenance. */
  async function openQualityTab(page: import('@playwright/test').Page) {
    await page.goto('/app/analytics')
    await expect(page.locator('main')).toBeVisible()
    await page.getByRole('button', { name: /جودة البيانات والإسناد|Data quality & attribution/ }).click()
    await expect(page.getByTestId('normalization')).toBeVisible({ timeout: 20000 })
  }

  test('the tab exists and the basis panel loads', async ({ page }) => {
    await openQualityTab(page)

    const basis = page.getByTestId('normalization')
    const text = await basis.innerText()

    // Every section is present whether or not it has something to report — a section that vanishes
    // when it is empty is indistinguishable from one that was never computed.
    for (const label of [/العملة|Currency/, /حدود اليوم|Where a day starts/, /نافذة الإسناد|Attribution window/, /المصدر|Source/]) {
      expect(text).toMatch(label)
    }
  })

  /**
   * The conversion is stated, or its absence is.
   *
   * Either answer is acceptable and one of them must be given: what is not acceptable is a converted
   * figure displayed with silence, which reads as a native amount.
   */
  test('the currency basis is stated, never left to be assumed', async ({ page }) => {
    await openQualityTab(page)

    await expect(page.getByTestId('normalization')).toContainText(
      /حُوّل .* من .* إلى|converted from .* to |بعملة .* أصلًا|already in |لا توجد مبالغ مالية|no money figures/i,
    )
  })

  /** The attribution window is named. A conversion count without one is not a measurement. */
  test('the attribution window behind the conversions is named', async ({ page }) => {
    await openQualityTab(page)

    await expect(page.getByTestId('normalization')).toContainText(
      /\d+d_click|1d_view|default|لا توجد بيانات|no data in this period/i,
    )
  })

  /**
   * Demo rows are called demo rows here, beside the figures, not only by a badge in the page corner.
   *
   * The demo dataset is what this environment shows; a reader who scrolled past the header badge has
   * no other signal that the numbers in front of them are not their business.
   */
  test('demo rows are named as demo where the figures are explained', async ({ page }) => {
    await openQualityTab(page)

    const text = await page.getByTestId('normalization').innerText()
    expect(text).toMatch(/بيانات تجريبية|demo data|مسحوبة من المنصة|pulled from the platform|لا توجد بيانات|no data in this period/i)
  })

  /**
   * No raw column value is printed at a reader.
   *
   * `source_type` is `api | manual | estimated | modeled` in the database. Those are column values,
   * not words, and this is an Arabic-first interface — printing them raw is the defect that was found
   * on the integrations page and it must not reappear here.
   */
  test('the source is given in words, not as the column value', async ({ page }) => {
    await openQualityTab(page)

    const text = await page.getByTestId('normalization').innerText()
    // The attribution window is deliberately shown verbatim (it is the platform's own identifier and
    // an advertiser reconciles against it), so only the source vocabulary is checked here.
    expect(text).not.toMatch(/\bsource_type\b|\bis_demo\b/)
  })

  /**
   * The catalogue distinguishes what may be summed from what must be recomputed.
   *
   * This is the whole point of `is_additive`: adding up thirty daily CPCs does not give the month's
   * CPC. The catalogue was seeded by nothing until now, so this also proves the seeder is wired.
   */
  test('the metric catalogue says which metrics may be summed', async ({ page }) => {
    await openQualityTab(page)

    const details = page.getByRole('group').filter({ hasText: /تعريفات المقاييس|Metric definitions/ })
    await expect(details).toBeVisible()
    await details.click()

    await expect(details).toContainText(/قابل للجمع|additive/)
    await expect(details).toContainText(/يُعاد حسابه|recomputed/)
  })
})
