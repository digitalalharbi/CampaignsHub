import { expect, test } from '@playwright/test'

/**
 * PUBLIC-REPORT-NOAUTH — `/r/<token>` works with no account, no session and no cookies. Ever.
 *
 * «رسالة انتهت جلستك مسموحة فقط داخل /app و/agency و/admin و/portal. إذا ظهرت في أي Public Report
 * فهي Regression.» The reader of a client link has no account. A sign-in prompt on this surface is
 * not a security outcome — it is the product asking somebody to do something they cannot do, on the
 * one page an agency sends to a paying client.
 *
 * `live-shared-report.spec.ts` already drives the report's behaviour. This file exists to prove the
 * separate, narrower claim: that none of it depends on being signed in. Every test therefore starts
 * from an EMPTY storage state AND clears cookies again after the first navigation, because
 * `storageState` only governs what the context starts with — a cookie the app sets on arrival would
 * still be there for the second request, and that is exactly the dependency being ruled out.
 *
 * `/r/` is the short form clients are actually sent. `/reports/share/` is the older address every
 * link already in the field uses, so both are walked: an auth regression on one is an auth
 * regression on the product.
 */
const TOKEN = 'demo-live-report-token'
const ADDRESSES = [`/r/${TOKEN}`, `/reports/share/${TOKEN}`]

/** Copy that must never appear on a public link, in either language. */
const SESSION_COPY = /انتهت جلستك|انتهت صلاحية الجلسة|Your session has ended|session has expired|Please sign in|سجل الدخول مرة أخرى/i

test.use({ storageState: { cookies: [], origins: [] } })

test.describe('a client with a link and no account', () => {
  for (const address of ADDRESSES) {
    test(`${address} opens the whole report with no session`, async ({ page, context }) => {
      await page.goto(address)
      // Belt and braces: anything the app set on arrival goes, then the page is asked again.
      await context.clearCookies()
      await page.reload()

      const report = page.getByTestId('live-report')
      await expect(report).toBeVisible({ timeout: 20000 })

      // Never sent to sign in — neither by a redirect nor by a link offering it as the way out.
      expect(new URL(page.url()).pathname, 'the public link redirected to a sign-in gate').toBe(address)
      await expect(page.locator('a[href*="/login"]')).toHaveCount(0)
      await expect(page.locator('body')).not.toHaveText(SESSION_COPY)

      // And it is the REPORT, not a shell: real figures, the platform split, the funnel, the filters.
      await expect(report.locator('.tnum').first()).toContainText(/SAR/)
      /*
       * `live-freshness` is no longer asserted here — CLIENT-DIAGNOSTIC-SEPARATION-001.
       *
       * It used to be the per-platform sync block, always rendered. It is now the sentence naming a
       * platform missing from the figures, and it is ABSENT when none is — so requiring it here
       * would be requiring the demo scope to have a broken connection. The range control is what
       * this case is actually about: the report is interactive without a session.
       */
      await expect(page.getByTestId('live-range-7')).toBeVisible()
    })
  }

  /**
   * The interactive half, driven with the cookie jar emptied first.
   *
   * A report that renders once from a server-side payload and then needs a session for every filter
   * would pass the test above and fail the moment the client touched anything.
   */
  test('the filters keep working after every cookie is thrown away', async ({ page, context }) => {
    await page.goto(`/r/${TOKEN}`)
    await expect(page.getByTestId('live-report')).toBeVisible({ timeout: 20000 })

    await context.clearCookies()

    const before = await page.getByTestId('live-report').locator('.tnum').first().innerText()
    await page.getByTestId('live-range-7').click()

    await expect
      .poll(async () => page.getByTestId('live-report').locator('.tnum').first().innerText(), { timeout: 20000 })
      .not.toBe(before)

    await expect(page.locator('body')).not.toHaveText(SESSION_COPY)
  })

  /**
   * Nothing the page fetches may be refused for want of a session.
   *
   * A 401 that the UI happens to swallow is still the dependency this file exists to rule out — the
   * next release renders it, and the client is asked to sign in to a product they have no account
   * for. Watched at the network layer so a silent one cannot hide behind a graceful empty state.
   */
  test('no request the report makes is answered with a 401', async ({ page }) => {
    const refused: string[] = []
    page.on('response', (r) => {
      if (r.status() === 401 || r.status() === 419) refused.push(`${r.status()} ${new URL(r.url()).pathname}`)
    })

    await page.goto(`/r/${TOKEN}`)
    await expect(page.getByTestId('live-report')).toBeVisible({ timeout: 20000 })
    await page.getByTestId('live-range-7').click()
    await page.waitForLoadState('networkidle')

    expect(refused, 'a public client link asked for authentication').toEqual([])
  })
})
