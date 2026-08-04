import { expect, test } from '@playwright/test'
import { submitVerifiedRequest, signIn } from './helpers'

/**
 * The full external→internal vertical flow — the acceptance path for the request portal:
 * guest submits → owner logs in → request appears in /app/requests → assign → change status →
 * add an INTERNAL note → verify the public tracking link cannot see that internal note.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('external submit → owner dashboard → assign/status/internal-note → tracking hides internal note', async ({ page }) => {
  // 1) Guest submits a VERIFIED external request (Consulting), returns the reference.
  const reference = await submitVerifiedRequest(page, {
    name: 'Vertical Client', email: `vertical.${Date.now()}@example.com`,
    phone: `+96650${String(Date.now()).slice(-7)}`, company: 'Vertical Co',
    objective: 'End-to-end vertical flow verification request.', service: /Consulting|استشارة/,
  })
  await expect(page.getByText(/تم استلام طلبك|Request received/)).toBeVisible()
  const trackHref = await page.getByRole('link', { name: /تتبع الطلب|Track request/ }).getAttribute('href')
  const trackUrl = new URL(trackHref!, page.url()).pathname + new URL(trackHref!, page.url()).search

  // 2) Owner logs in.
  await page.goto('/login')
  await signIn(page, 'owner@demo-agency.local', 'password')
  await expect(page).not.toHaveURL(/\/login/)

  // 3) The request appears in the internal dashboard.
  await page.goto('/app/requests')
  await page.getByPlaceholder(/بحث|Search/).fill(reference!)
  await page.getByPlaceholder(/بحث|Search/).press('Enter')
  const row = page.getByRole('link', { name: reference! })
  await expect(row).toBeVisible()

  // 4) Open detail, assign to me, move to under_review, add an internal note.
  await row.click()
  await expect(page).toHaveURL(/\/agency\/requests\/[0-9A-Za-z]+/)
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
