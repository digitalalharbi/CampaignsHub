import { expect, type APIRequestContext, type Page } from '@playwright/test'

export const AUTH = {
  owner: 'e2e/.auth/owner.json',
  analyst: 'e2e/.auth/analyst.json',
  viewer: 'e2e/.auth/viewer.json',
} as const

/**
 * Sanctum only treats a request as stateful (session-authenticated) when its Origin/Referer matches
 * SANCTUM_STATEFUL_DOMAINS. Playwright's request contexts don't send these by default, so every API
 * call must include them or it comes back 401.
 */
export const API_HEADERS: Record<string, string> = {
  Accept: 'application/json',
  Origin: 'http://localhost:5173',
  Referer: 'http://localhost:5173/',
}

/** Prime the Sanctum CSRF cookie and return the headers needed for unsafe (POST/DELETE) API calls. */
export async function csrfHeaders(request: APIRequestContext): Promise<Record<string, string>> {
  await request.get('/sanctum/csrf-cookie', { headers: API_HEADERS })
  const state = await request.storageState()
  const xsrf = state.cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? ''
  return { ...API_HEADERS, 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
}

/** Flip the app to English for stable selectors (default locale is Arabic). */
export async function switchToEnglish(page: Page) {
  const toggle = page.getByRole('button', { name: /Toggle language|EN|اللغة/ }).first()
  if (await toggle.count()) await toggle.click().catch(() => {})
}

/** Force the global project switcher (zustand persist) to a specific project before the app boots. */
export async function useProject(page: Page, projectId: string) {
  await page.addInitScript((id) => {
    localStorage.setItem(
      'campaign-hub-project-storage',
      JSON.stringify({ state: { currentProjectId: id }, version: 0 }),
    )
  }, projectId)
}

/** Connect Sandbox + bind an ad account + sync → imports Sandbox external campaigns into `projectId`. */
export async function seedExternals(request: APIRequestContext, projectId: string) {
  const headers = await csrfHeaders(request)
  const connect = await request.post(`/api/v1/projects/${projectId}/integrations/connect`, { headers })
  const accounts = (await connect.json()).data.accounts as Array<{ id: string; account_type: string }>
  const adAccount = accounts.find((a) => a.account_type === 'ad_account')!
  const bind = await request.post(`/api/v1/projects/${projectId}/integrations/bindings`, {
    headers,
    data: { external_account_id: adAccount.id, purpose: 'advertising', confirm: true },
  })
  const bindingId = (await bind.json()).data.id as string
  await request.post(`/api/v1/projects/${projectId}/integrations/bindings/${bindingId}/sync`, { headers })
}

export async function createCampaign(page: Page, name: string) {
  await page.getByRole('button', { name: /New campaign|حملة جديدة/ }).click()
  await page.getByLabel(/Campaign name|اسم الحملة/).fill(name)
  await page.getByRole('button', { name: /^Save$|^حفظ$/ }).click()
  await expect(page.getByText(name)).toBeVisible()
}

/**
 * Fill and submit the public intake wizard through the mandatory OTP verification step. Assumes the app is in
 * English (call switchToEnglish first). Returns the created request reference (REQ-YYYY-XXXXXX).
 */
export async function submitVerifiedRequest(
  page: Page,
  opts: { name: string; email: string; phone: string; company: string; objective: string; service?: RegExp },
): Promise<string> {
  const service = opts.service ?? /Launch a paid campaign|إطلاق حملة إعلانية مدفوعة/
  await page.goto('/requests/new')
  await switchToEnglish(page)
  await page.getByRole('button', { name: service }).click()
  await page.getByRole('button', { name: /Next|التالي/ }).click()
  await page.getByLabel(/Name|الاسم/).fill(opts.name)
  await page.getByLabel(/Email|البريد/).fill(opts.email)
  await page.getByLabel(/Phone|رقم الجوال/).fill(opts.phone)
  await page.getByLabel(/Company|اسم النشاط أو الشركة/).fill(opts.company)
  await page.getByRole('button', { name: /Next|التالي/ }).click()
  await page.getByLabel(/Objective|هدف الطلب/).fill(opts.objective)
  await page.getByRole('button', { name: /Next|التالي/ }).click() // budget
  await page.getByRole('button', { name: /Next|التالي/ }).click() // attachments
  await page.getByRole('button', { name: /Next|التالي/ }).click() // review
  // Verify phone + email (dev auto-verifies via the exposed code). Submit stays disabled until BOTH are
  // verified — wait on that (generous timeout: WebKit is slow to resolve the two async OTP round-trips).
  await page.getByRole('button', { name: /Verify Mobile number|تحقّق رقم الجوال/ }).click()
  await expect(page.getByText(/Verified|تم التحقق/).first()).toBeVisible({ timeout: 15000 }) // async OTP round-trip
  await page.getByRole('button', { name: /Verify Email|تحقّق البريد الإلكتروني/ }).click()
  const submitBtn = page.getByRole('button', { name: /Submit request|إرسال الطلب/ })
  await expect(submitBtn).toBeEnabled({ timeout: 15000 })
  await submitBtn.click()
  // Wait for the success page to render the reference before reading it (avoids a null read race).
  const refLocator = page.getByText(/REQ-\d{4}-[A-Z0-9]{6}/).first()
  await expect(refLocator).toBeVisible()
  const ref = (await refLocator.textContent())?.match(/REQ-\d{4}-[A-Z0-9]{6}/)?.[0]
  return ref!
}
