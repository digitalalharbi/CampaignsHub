import { expect, test } from '@playwright/test'

/**
 * The full external→internal vertical flow — the acceptance path for the request portal:
 * guest submits → owner logs in → request appears in /app/requests → assign → change status →
 * add an INTERNAL note → verify the public tracking link cannot see that internal note.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('external submit → owner dashboard → assign/status/internal-note → tracking hides internal note', async ({ page }) => {
  // 1) Guest submits an external request.
  await page.goto('/requests/new')
  await page.getByRole('button', { name: /استشارة|Consulting/ }).click()
  await page.getByRole('button', { name: /التالي|Next/ }).click()
  await page.getByLabel(/الاسم|Name/).fill('Vertical Client')
  await page.getByLabel(/البريد|Email/).fill('vertical@example.com')
  await page.getByRole('button', { name: /التالي|Next/ }).click()
  await page.getByLabel(/هدف الطلب|Objective/).fill('End-to-end vertical flow verification request.')
  await page.getByRole('button', { name: /التالي|Next/ }).click() // budget
  await page.getByRole('button', { name: /التالي|Next/ }).click() // attachments
  await page.getByRole('button', { name: /التالي|Next/ }).click() // review
  await page.getByRole('button', { name: /إرسال الطلب|Submit request/ }).click()

  await expect(page.getByText(/تم استلام طلبك|Request received/)).toBeVisible()
  const reference = (await page.getByText(/REQ-\d{4}-[A-Z0-9]{6}/).first().textContent())?.match(/REQ-\d{4}-[A-Z0-9]{6}/)?.[0]
  expect(reference).toBeTruthy()
  const trackHref = await page.getByRole('link', { name: /تتبع الطلب|Track request/ }).getAttribute('href')
  const trackUrl = new URL(trackHref!, page.url()).pathname + new URL(trackHref!, page.url()).search

  // 2) Owner logs in.
  await page.goto('/login')
  await page.locator('input[type="email"]').fill('owner@demo-agency.local')
  await page.locator('input[type="password"]').fill('password')
  await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
  await expect(page).not.toHaveURL(/\/login/)

  // 3) The request appears in the internal dashboard.
  await page.goto('/app/requests')
  await page.getByPlaceholder(/بحث|Search/).fill(reference!)
  await page.getByPlaceholder(/بحث|Search/).press('Enter')
  const row = page.getByRole('link', { name: reference! })
  await expect(row).toBeVisible()

  // 4) Open detail, assign to me, move to under_review, add an internal note.
  await row.click()
  await expect(page).toHaveURL(/\/app\/requests\/[0-9A-Za-z]+/)
  await page.getByRole('button', { name: /أسند إليّ|Assign to me/ }).click()
  await page.getByLabel(/تغيير الحالة|Change status/).selectOption('under_review')
  await expect(page.getByText(/تحت المراجعة|Under Review/).first()).toBeVisible()

  const secret = 'INTERNAL ONLY vertical secret note'
  await page.getByLabel(/إضافة ملاحظة داخلية|Add internal note/).fill(secret)
  await page.getByRole('button', { name: /إضافة ملاحظة داخلية|Add internal note/ }).click()
  await expect(page.getByText(secret)).toBeVisible() // visible to the owner

  // 5) The public tracking view must NOT expose the internal note.
  await page.goto(trackUrl)
  await expect(page.getByText(reference!)).toBeVisible()
  await expect(page.getByText(secret)).toHaveCount(0)
})
