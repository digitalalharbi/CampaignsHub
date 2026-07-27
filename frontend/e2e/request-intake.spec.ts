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
  await page.getByLabel(/رقم الجوال|Phone/).fill(`+96650${String(Date.now()).slice(-7)}`)
  await page.getByLabel(/اسم النشاط أو الشركة|Company/).fill('QA Co')
  await page.getByRole('button', { name: /التالي|Next/ }).click()

  // Step 3 — details (objective required).
  await page.getByLabel(/هدف الطلب|Objective/).fill('Launch a Ramadan sales campaign across Meta and Google.')
  await page.getByRole('button', { name: /التالي|Next/ }).click()

  // Step 4 — budget & timeline.
  await page.getByRole('button', { name: /التالي|Next/ }).click()

  // Step 5 — attachments: upload a real file to the secure session, wait for it to finish.
  await page.setInputFiles('input[type="file"]', {
    name: 'brief.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 test brief'),
  })
  await expect(page.getByText('brief.pdf')).toBeVisible()
  // Next is disabled while an upload is in flight — waiting for it to enable proves the upload finished.
  await expect(page.getByRole('button', { name: /التالي|Next/ })).toBeEnabled()
  await page.getByRole('button', { name: /التالي|Next/ }).click()

  // Step 6 — review shows the uploaded file, then submit.
  await expect(page.getByText(/مراجعة الطلب|Review your request/)).toBeVisible()
  await expect(page.getByText('brief.pdf')).toBeVisible()
  await page.getByRole('button', { name: /تحقّق رقم الجوال|Verify Mobile number/ }).click()
  await expect(page.getByText(/تم التحقق|Verified/)).toHaveCount(1)
  await page.getByRole('button', { name: /تحقّق البريد|Verify Email/ }).click()
  await expect(page.getByText(/تم التحقق|Verified/)).toHaveCount(2)
  await page.getByRole('button', { name: /إرسال الطلب|Submit request/ }).click()

  // Success page shows a real REQ-YYYY-XXXXXX number and a tracking link.
  await expect(page.getByText(/تم استلام طلبك|Request received/)).toBeVisible()
  await expect(page.getByText(/REQ-\d{4}-[A-Z0-9]{6}/)).toBeVisible()
  await expect(page.getByText(/بانتظار اعتماد خدمة البريد|Awaiting mail credentials/)).toBeVisible()
})

test('after submit, the tracking link shows status and accepts a client reply', async ({ page }) => {
  await page.goto('/requests/new')
  await page.getByRole('button', { name: /استشارة|Consulting/ }).click()
  await page.getByRole('button', { name: /التالي|Next/ }).click()
  await page.getByLabel(/الاسم|Name/).fill('Track Tester')
  await page.getByLabel(/البريد|Email/).fill('track@example.com')
  await page.getByLabel(/رقم الجوال|Phone/).fill(`+96650${String(Date.now()).slice(-7)}`)
  await page.getByLabel(/اسم النشاط أو الشركة|Company/).fill('Track Co')
  await page.getByRole('button', { name: /التالي|Next/ }).click()
  await page.getByLabel(/هدف الطلب|Objective/).fill('Need advice on scaling our campaigns.')
  await page.getByRole('button', { name: /التالي|Next/ }).click() // → budget
  await page.getByRole('button', { name: /التالي|Next/ }).click() // → attachments
  await page.getByRole('button', { name: /التالي|Next/ }).click() // → review
  await page.getByRole('button', { name: /تحقّق رقم الجوال|Verify Mobile number/ }).click()
  await expect(page.getByText(/تم التحقق|Verified/)).toHaveCount(1)
  await page.getByRole('button', { name: /تحقّق البريد|Verify Email/ }).click()
  await expect(page.getByText(/تم التحقق|Verified/)).toHaveCount(2)
  await page.getByRole('button', { name: /إرسال الطلب|Submit request/ }).click()

  await expect(page.getByText(/تم استلام طلبك|Request received/)).toBeVisible()
  // Follow the tracking link from the success page.
  await page.getByRole('link', { name: /تتبع الطلب|Track request/ }).click()
  await expect(page).toHaveURL(/\/requests\/track\?token=/)
  await expect(page.getByText(/REQ-\d{4}-[A-Z0-9]{6}/)).toBeVisible()

  // Client adds a reply → it appears in the messages list (client-visible).
  await page.getByLabel(/إضافة رد|Add a reply/).fill('Thanks, here is more context on our goals.')
  await page.getByRole('button', { name: /إرسال الرد|Send reply/ }).click()
  await expect(page.getByText('Thanks, here is more context on our goals.')).toBeVisible()
})

test('draft persists ONLY the non-sensitive service selection — never PII', async ({ page }) => {
  await page.goto('/requests/new')
  await page.getByRole('button', { name: /تحسين الأداء|Performance optimization/ }).click()
  await page.getByRole('button', { name: /التالي|Next/ }).click()
  await page.getByLabel(/الاسم|Name/).fill('Should NOT persist')

  // Only {type, step, ts} may be stored — assert the raw localStorage contains no PII.
  const draft = await page.evaluate(() => localStorage.getItem('ch-request-draft-v2'))
  expect(draft).toBeTruthy()
  expect(draft!).not.toContain('Should NOT persist')
  const parsed = JSON.parse(draft!)
  expect(Object.keys(parsed).sort()).toEqual(['step', 'ts', 'type'])

  await page.reload()
  // The service selection is restored (non-sensitive), but the name field is empty.
  await page.getByRole('button', { name: /التالي|Next/ }).click()
  await expect(page.getByLabel(/الاسم|Name/)).toHaveValue('')
})

test('clear-draft button wipes the stored draft', async ({ page }) => {
  await page.goto('/requests/new')
  await page.getByRole('button', { name: /استشارة|Consulting/ }).click()
  await page.getByRole('button', { name: /حذف المسودة|Clear draft/ }).click()
  const draft = await page.evaluate(() => JSON.parse(localStorage.getItem('ch-request-draft-v2') || '{}'))
  expect(draft.type).toBeFalsy()
})
