import { expect, test } from '@playwright/test'
import { submitVerifiedRequest, switchToEnglish } from './helpers'

/**
 * Full Client Command Center journey: guest submits a request → owner converts → opens the client → updates
 * classification → verifies persistence across reload → opens Analytics → creates a Client Report → grants +
 * removes Team access → opens Files + Activity → updates Settings → marks a notification read. Runs on
 * Chromium/Firefox/WebKit. API-level Fail-Closed (cross-tenant, no-access denial, last-owner,
 * internal-not-shareable) is covered more reliably by backend feature tests; this proves the UI journey.
 */
test.use({ storageState: { cookies: [], origins: [] } })

/**
 * Pick a value from a taxonomy-fed custom combobox (SelectField/SearchableSelect): open it via its label,
 * then click the option by its visible name. These are no longer native <select>s (forms adoption), so they
 * are driven the way a user does — click to open, click the option.
 */
async function pickCombobox(page: import('@playwright/test').Page, labelRe: RegExp, optionRe: RegExp) {
  await page.getByLabel(labelRe).click()
  await page.getByRole('option', { name: optionRe }).click()
}

test('owner drives a converted client through every command-center tab', async ({ page }, testInfo) => {
  const company = `CC Co ${testInfo.project.name}-${Date.now()}`

  // 1) Guest submits a VERIFIED request (OTP), returns the reference.
  const reference = await submitVerifiedRequest(page, {
    name: 'CC Client', email: `cc.${Date.now()}@example.com`,
    phone: `+96650${String(Date.now()).slice(-7)}`, company, objective: 'Full command center journey.',
  })

  // 2) Owner logs in and opens the request.
  await page.goto('/login')
  await page.locator('input[type="email"]').fill('owner@demo-agency.local')
  await page.locator('input[type="password"]').fill('password')
  await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
  await expect(page).not.toHaveURL(/\/login/)

  await page.goto('/app/requests')
  await switchToEnglish(page)
  await page.getByPlaceholder(/Search|بحث/).fill(reference!)
  await page.getByPlaceholder(/Search|بحث/).press('Enter')
  await page.getByRole('link', { name: reference! }).click()
  await page.getByRole('button', { name: /^Convert$|^تحويل$/ }).click()
  await page.getByRole('link', { name: /View client|عرض العميل/ }).click()
  await expect(page).toHaveURL(/\/app\/clients\/[0-9A-Za-z-]+/)
  await expect(page.getByRole('heading', { name: company })).toBeVisible()

  // 2) Update classification → Save.
  await page.getByRole('button', { name: /Edit classification|تعديل التصنيف/ }).click()
  await pickCombobox(page, /^Status$|^الحالة$/, /Needs attention|يحتاج انتباه/i)
  await pickCombobox(page, /Industry|النشاط/, /E-?commerce|التجارة الإلكترونية/i)
  await page.getByRole('button', { name: /^Save$|^حفظ$/ }).click()
  // Badge reflects the new status.
  await expect(page.getByText(/Needs Attention|يحتاج متابعة/).first()).toBeVisible()

  // 3) Persistence across a full reload.
  await page.reload()
  await switchToEnglish(page)
  await expect(page.getByText(/Needs Attention|يحتاج متابعة/).first()).toBeVisible()

  // 4) Analytics tab renders (fresh client → honest "no data" state, source-of-truth still exposed).
  await page.getByRole('button', { name: /^Analytics$|^التحليلات$/ }).click()
  await expect(page.getByText(/No performance data|source of truth|لا توجد بيانات|مصدر البيانات/i).first()).toBeVisible()

  // 5) Reports tab → create a client report (queued) appears.
  await page.getByRole('button', { name: /^Reports$|^التقارير$/ }).click()
  await page.getByRole('button', { name: /New report|تقرير جديد/ }).click()
  await page.getByLabel(/Report name|اسم التقرير/).fill('Monthly CC')
  await page.getByRole('button', { name: /^Create$|^إنشاء$/ }).click()
  await expect(page.getByText('Monthly CC')).toBeVisible()

  // 6) Team tab → grant access to a same-tenant user, then remove.
  await page.getByRole('button', { name: /^Team$|^الفريق$/ }).click()
  await page.getByLabel(/^Member$|^العضو$/).selectOption({ index: 1 }) // first assignable user
  await page.getByRole('button', { name: /^Add$|^إضافة$/ }).click()
  await expect(page.getByLabel(/Remove|إزالة/).first()).toBeVisible()
  await page.getByLabel(/Remove|إزالة/).first().click()

  // 7) Files + Activity render (Activity has the real conversion/classification events).
  await page.getByRole('button', { name: /^Files$|^الملفات$/ }).click()
  await page.getByRole('button', { name: /^Activity$|^النشاط$/ }).click()
  await expect(page.getByText(/classification|converted|created/i).first()).toBeVisible()

  // 8) Settings → change display name → Save.
  await page.getByRole('button', { name: /^Settings$|^الإعدادات$/ }).click()
  await page.getByLabel(/Display name|اسم العرض/).fill(`${company} (edited)`)
  await page.getByRole('button', { name: /^Save$|^حفظ$/ }).click()

  // 9) Notification center: open the bell and mark all read (if any unread).
  await page.getByRole('button', { name: /Notifications|الإشعارات/ }).first().click()
  const markAll = page.getByRole('button', { name: /Mark all read|تعليم الكل كمقروء/ })
  if (await markAll.count()) await markAll.click()
})
