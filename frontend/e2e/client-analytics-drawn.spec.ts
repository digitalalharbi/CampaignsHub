import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * VIZ-CLIENT-001 — a client's analytics tab served a full daily series and drew none of it.
 *
 * The unit tests hold the currency contract; this holds the drawing, because recharts renders
 * nothing in jsdom and a chart asserted there passes whether or not it drew.
 *
 * It also pins the agreement the browser caught and no test could: the money chart's presence and
 * the money CARDS' presence are the same decision. A page that prints «96.1K SAR» in a card while
 * withholding a money line as «this spans currencies» contradicts itself, and that is exactly what
 * reading `money_blended` by its name rather than by its service produced.
 */
test.use({ storageState: AUTH.owner })

async function openAnalytics(page: import('@playwright/test').Page) {
  await page.goto('/agency/clients')
  await switchToEnglish(page)
  await page.getByRole('link', { name: /Demo Store/ }).first().click()
  await page.getByRole('button', { name: 'Analytics', exact: true }).click()
}

test('the daily series is drawn, and the money chart agrees with the money cards', async ({ page }) => {
  await openAnalytics(page)

  // The counts always draw where a series arrived — they carry no currency.
  await expect(page.getByTestId('client-count-trend')).toBeVisible({ timeout: 30000 })

  const money = page.getByTestId('client-money-trend')
  const withheld = page.getByTestId('client-money-trend-withheld')
  await expect(money.or(withheld)).toBeVisible()

  /*
    The agreement. A money KPI card and a withheld money chart on the same screen is a page arguing
    with itself, and it is the defect this spec exists for.
  */
  const spendCard = page.getByText(/^Spend SAR$/).first()
  if (await spendCard.count() > 0) {
    await expect(money).toBeVisible()
    await expect(withheld).toHaveCount(0)
  }
})

test('the count trend puts impressions on their own scale', async ({ page }) => {
  await openAnalytics(page)

  const chart = page.getByTestId('client-count-trend')
  await expect(chart).toBeVisible({ timeout: 30000 })

  // Two axes: results and clicks on one, impressions on the other. One shared axis makes the result
  // line a flat mark along the bottom, which reads as «this was zero».
  await expect(chart.locator('.recharts-yAxis')).toHaveCount(2)
  expect(await chart.locator('.recharts-line').count()).toBe(3)
})

test('the objective mix is a divided bar with translated labels, not the stored keys', async ({ page }) => {
  await openAnalytics(page)

  const mix = page.getByTestId('client-objective-mix-bar')
  await expect(mix).toBeVisible({ timeout: 30000 })

  const legend = page.getByTestId('client-objective-mix-legend')
  // `sales` and `app_installs` were printed raw. The product has had a canonical label since the
  // campaigns module; this was the one surface not using it.
  await expect(legend).not.toContainText('app_installs')
  await expect(legend).not.toContainText(/\bsales\b/)
})

for (const width of [1440, 768, 390]) {
  test(`the drawn analytics tab never scrolls the page sideways at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await openAnalytics(page)
    await expect(page.getByTestId('client-count-trend')).toBeVisible({ timeout: 30000 })

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow).toBeLessThanOrEqual(1)
  })
}
