import { expect, test } from '@playwright/test'

/**
 * External request intake (public, guest): from the homepage CTA through the dynamic multi-step form
 * to a real submission that returns a request number + tracking link. Uses the real backend intake.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('homepage → request → dynamic form → submit → success with request number', async ({ page }) => {
  await page.goto('/')
  // Service-request CTA opens the real intake route.
  await page.getByRole('link', { name: /أرسل طلب إدارة حملة|Request campaign management/ }).first().click()
  await expect(page).toHaveURL(/\/requests\/new$/)

  // Step 1 — service (loaded from /requests/meta).
  await page.getByRole('button', { name: /إطلاق حملة إعلانية مدفوعة|Launch a paid campaign/ }).click()
  await page.getByRole('button', { name: /التالي|Next/ }).click()

  // Step 2 — applicant. Empty submit is blocked by validation.
  await page.getByRole('button', { name: /التالي|Next/ }).click()
  await expect(page.getByText(/الاسم مطلوب|Name is required/)).toBeVisible()
  await page.getByLabel(/الاسم|Name/).fill('QA Requester')
  await page.getByLabel(/البريد|Email/).fill('qa-requester@example.com')
  await page.getByRole('button', { name: /التالي|Next/ }).click()

  // Step 3 — details (objective required).
  await page.getByLabel(/هدف الطلب|Objective/).fill('Launch a Ramadan sales campaign across Meta and Google.')
  await page.getByRole('button', { name: /التالي|Next/ }).click()

  // Step 4 — budget & timeline.
  await page.getByRole('button', { name: /التالي|Next/ }).click()

  // Step 5 — review + submit.
  await expect(page.getByText(/مراجعة الطلب|Review your request/)).toBeVisible()
  await page.getByRole('button', { name: /إرسال الطلب|Submit request/ }).click()

  // Success page shows a real REQ-YYYY-XXXXXX number and a tracking link.
  await expect(page.getByText(/تم استلام طلبك|Request received/)).toBeVisible()
  await expect(page.getByText(/REQ-\d{4}-[A-Z0-9]{6}/)).toBeVisible()
  await expect(page.getByText(/بانتظار اعتماد خدمة البريد|Awaiting mail credentials/)).toBeVisible()
})

test('request form draft persists across reload', async ({ page }) => {
  await page.goto('/requests/new')
  await page.getByRole('button', { name: /تحسين الأداء|Performance optimization/ }).click()
  await page.getByRole('button', { name: /التالي|Next/ }).click()
  await page.getByLabel(/الاسم|Name/).fill('Draft Persists')
  await page.reload()
  // The non-sensitive text draft is restored.
  await page.getByRole('button', { name: /التالي|Next/ }).click()
  await expect(page.getByLabel(/الاسم|Name/)).toHaveValue('Draft Persists')
})
