import { expect, test } from '@playwright/test'

/**
 * Full conversion vertical: guest submits → owner converts the request into a client/project/draft
 * campaign → the conversion links appear on the request → the client shows in /app/clients and its
 * Command Center lists the project, campaign and the originating request. Repeat convert = no duplicate.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('convert a request → client appears in portfolio and command center', async ({ page }, testInfo) => {
  // Unique company name per run so portfolio/command-center assertions match exactly one client.
  const company = `Conversion Co ${testInfo.project.name}-${Date.now()}`
  // 1) Guest submits.
  await page.goto('/requests/new')
  await page.getByRole('button', { name: /إطلاق حملة إعلانية مدفوعة|Launch a paid campaign/ }).click()
  await page.getByRole('button', { name: /التالي|Next/ }).click()
  await page.getByLabel(/الاسم|Name/).fill('Conversion Client')
  await page.getByLabel(/البريد|Email/).fill('conv@example.com')
  await page.getByLabel(/اسم النشاط أو الشركة|Company/).fill(company)
  await page.getByRole('button', { name: /التالي|Next/ }).click()
  await page.getByLabel(/هدف الطلب|Objective/).fill('Convert this into a managed client.')
  await page.getByRole('button', { name: /التالي|Next/ }).click() // budget
  await page.getByRole('button', { name: /التالي|Next/ }).click() // attachments
  await page.getByRole('button', { name: /التالي|Next/ }).click() // review
  await page.getByRole('button', { name: /إرسال الطلب|Submit request/ }).click()
  const reference = (await page.getByText(/REQ-\d{4}-[A-Z0-9]{6}/).first().textContent())?.match(/REQ-\d{4}-[A-Z0-9]{6}/)?.[0]

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
  await expect(page).toHaveURL(/\/app\/clients\/[0-9A-Za-z-]+/)
  await expect(page.getByRole('heading', { name: company })).toBeVisible()

  // Campaigns tab shows the draft campaign; Requests tab shows the originating request.
  await page.getByRole('button', { name: /الحملات|Campaigns/ }).click()
  await expect(page.getByText('draft').first()).toBeVisible()
  await page.getByRole('button', { name: /الطلبات|Requests/ }).click()
  await expect(page.getByText(reference!)).toBeVisible()

  // 5) The client is in the portfolio.
  await page.goto('/app/clients')
  await expect(page.getByText(company)).toBeVisible()
})
