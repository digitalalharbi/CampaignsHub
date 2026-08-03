import { expect, test } from '@playwright/test'
import { submitVerifiedRequest } from './helpers'

/**
 * Full conversion vertical: guest submits → owner converts the request into a client/project/draft
 * campaign → the conversion links appear on the request → the client shows in /app/clients and its
 * Command Center lists the project, campaign and the originating request. Repeat convert = no duplicate.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('convert a request → client appears in portfolio and command center', async ({ page }, testInfo) => {
  // Unique company name per run so portfolio/command-center assertions match exactly one client.
  const company = `Conversion Co ${testInfo.project.name}-${Date.now()}`
  // 1) Guest submits a VERIFIED request (OTP phone + email), returns the reference.
  const reference = await submitVerifiedRequest(page, {
    name: 'Conversion Client', email: `conv.${Date.now()}@example.com`,
    phone: `+96650${String(Date.now()).slice(-7)}`, company, objective: 'Convert this into a managed client.',
  })

  // 2) Owner logs in and opens the request.
  await page.goto('/login')
  await page.locator('input[type="email"]').fill('owner@demo-agency.local')
  await page.locator('input[type="password"]').fill('password')
  await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
  await expect(page).not.toHaveURL(/\/login/)

  await page.goto('/app/requests')
  await page.getByPlaceholder(/بحث|Search/).fill(reference!)
  await page.getByPlaceholder(/بحث|Search/).press('Enter')
  await page.getByRole('link', { name: reference! }).click()

  // 3) Convert.
  await page.getByRole('button', { name: /تحويل|Convert/ }).click()
  await expect(page.getByText(/تم التحويل|محوّل|Converted/).first()).toBeVisible()

  // The convert button is replaced (idempotent — no second convert available).
  await expect(page.getByRole('button', { name: /^تحويل$|^Convert$/ })).toHaveCount(0)

  // 4) Open the client command center via the conversion link.
  await page.getByRole('link', { name: /عرض العميل|View client/ }).click()
  await expect(page).toHaveURL(/\/agency\/clients\/[0-9A-Za-z-]+/)
  await expect(page.getByRole('heading', { name: company })).toBeVisible()

  /*
   * Campaigns tab shows the draft campaign; Requests tab shows the originating request.
   *
   * Scoped to `main`: the agency rail now has a «الحملات / Campaigns» group of its own (SIMPLIFY-002),
   * so the unscoped locator matched the menu as well as the tab and strict mode rejected both. The tab
   * on the page is what this step was ever about.
   */
  const tab = (name: RegExp) => page.getByRole('main').getByRole('button', { name })
  await tab(/الحملات|Campaigns/).click()
  await expect(page.getByText('draft').first()).toBeVisible()
  await tab(/الطلبات|Requests/).click()
  await expect(page.getByText(reference!)).toBeVisible()

  /*
   * 5) The client is in the portfolio.
   *
   * The portfolio renders both a table and a card list, showing one and hiding the other by
   * breakpoint — so the name matches twice and one of the two matches is always `display: none`.
   * Strict mode rejected the ambiguity, and `.first()` then picked whichever the DOM happened to
   * order first, which on Firefox and WebKit was the hidden one.
   *
   * Asking for the VISIBLE match states the real claim — a person looking at this page can see the
   * client — and is independent of which layout the viewport chose.
   */
  await page.goto('/app/clients')
  await expect(page.getByText(company).locator('visible=true').first()).toBeVisible()
})
