import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * `/admin/billing` keeps the four money streams apart (PAY-005).
 *
 * Money moves through this product in four directions and only ONE of them is the platform's: tenants
 * owe CampaignsHub for subscriptions, an agency's clients owe the AGENCY for its invoices, the request
 * payments are those same invoices filtered by where they came from, and creator payouts would be the
 * platform paying out except no payout ledger exists.
 *
 * Two mistakes are worth a test each. Adding the platform's subscriptions to an agency's client
 * invoices reports customers' money as the platform's business result — the most expensive lie an
 * owner's console could tell. And adding request payments to agency invoices counts the same invoice
 * twice, because the first is a VIEW of the second.
 *
 * These assert on what the page SAYS about each stream rather than on the figures, because the figures
 * are demo data and will change; what must not change is that each one names whose money it is.
 */
test.describe('the four money streams', () => {
  test.use({ storageState: AUTH.admin })

  async function openStreams(page: import('@playwright/test').Page) {
    await page.goto('/admin/billing')
    await expect(page.locator('main')).toBeVisible()
    await page.getByTestId('billing-tab-streams').click()
    await expect(page.getByTestId('revenue-streams')).toBeVisible({ timeout: 20000 })
  }

  test('all four are shown, each as its own stream', async ({ page }) => {
    await openStreams(page)

    for (const key of ['platform_subscriptions', 'agency_client_invoices', 'request_service_payments', 'creator_payouts']) {
      await expect(page.getByTestId(`stream-${key}`)).toBeVisible()
    }
  })

  /**
   * The one that matters most: an agency's client invoices are marked as NOT the platform's money,
   * on the card, where somebody reading the figure will see it.
   */
  test('the customer’s money is marked as the customer’s', async ({ page }) => {
    await openStreams(page)

    await expect(page.getByTestId('stream-agency_client_invoices')).toContainText(
      /مال العميل، لا المنصة|customer’s money, not the platform’s/,
    )
    await expect(page.getByTestId('stream-platform_subscriptions')).toContainText(/مال المنصة|the platform’s money/)
  })

  /** A subset announces itself, so nobody adds it to the stream it is part of. */
  test('the request payments say they are part of the invoices above, not extra money', async ({ page }) => {
    await openStreams(page)

    await expect(page.getByTestId('subset-request_service_payments')).toContainText(
      /جزء من|part of/,
    )
  })

  /**
   * The refusal to produce one number is ON the page.
   *
   * Leaving it out would be an omission a reader fills in with a calculator; saying it, and saying
   * why, is what stops the addition from looking reasonable.
   */
  test('there is no combined total, and the page says why', async ({ page }) => {
    await openStreams(page)

    await expect(page.getByTestId('no-combined-total')).toContainText(
      /لا يوجد مجموع واحد|no single total/,
    )
  })

  /**
   * Creator payouts read as not implemented, never as a zero.
   *
   * «0.00 SAR paid out» would claim nothing is owed to anybody, which is a measured result this
   * system has never measured.
   */
  /**
   * The explanations are in the reader's language, not the API's.
   *
   * Caught in live review: the cards took their paragraph straight from the backend's `note`, which is
   * English, so an Arabic page showed Arabic headings, Arabic chips and Arabic counts above a full
   * English paragraph. A source grep would not have found it — the English lives in PHP — so the check
   * is a WALK of the rendered panel asserting no long Latin-only paragraph survives in Arabic.
   */
  test('every explanation is in the language the page is in', async ({ page }) => {
    // Pin the language rather than inherit whatever the last test left behind — a check for «no
    // untranslated Arabic» that runs in English passes without measuring anything.
    await page.addInitScript(() => window.localStorage.setItem('campaign-hub-locale', 'ar'))
    await openStreams(page)
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')

    const untranslated = await page.getByTestId('revenue-streams').evaluate((el) =>
      [...el.querySelectorAll('p')]
        .map((p) => (p as HTMLElement).innerText)
        .filter((t) => t.trim().length > 60 && !/[؀-ۿ]/.test(t)),
    )

    expect(untranslated, 'a long paragraph with no Arabic in it, on an Arabic page').toEqual([])
  })

  test('creator payouts are unbuilt, not zero', async ({ page }) => {
    await openStreams(page)

    const card = page.getByTestId('stream-creator_payouts')
    await expect(card).toContainText(/غير منفَّذ|Not implemented/)
    await expect(card).toContainText(/لا رقم يُعرض، ولا صفر يُدّعى|no figure is shown, and no zero is claimed/)
  })
})
