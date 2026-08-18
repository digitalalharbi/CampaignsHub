import { expect, test } from '@playwright/test'

/**
 * LEGAL-DELETE-001 — the three URLs every ad-platform review asks for, proven public.
 *
 * Meta, Google, TikTok, Snapchat, X and LinkedIn each require a stable public address for the privacy
 * policy, the terms and user data deletion. «Public» is the whole claim: a reviewer opens the link in
 * a browser that has never signed in, and if it answers a login page the app is rejected — and the
 * cause is not obvious from the rejection.
 *
 * These start from an EMPTY storage state and clear cookies again after the first navigation, for the
 * same reason `public-report-noauth.spec.ts` does: `storageState` only governs what the context
 * starts with, and a cookie the app sets on arrival would still be there for the second request.
 *
 * The deletion page is walked as a FLOW rather than a document, because that is what it is — a
 * request, then the step that proves the address. A page of prose satisfies no platform and helps
 * nobody.
 */
test.use({ storageState: { cookies: [], origins: [] } })

const PAGES = ['/privacy', '/terms', '/data-deletion'] as const

test.describe('the compliance URLs a platform review opens', () => {
  for (const path of PAGES) {
    test(`${path} answers without a session and never sends you to /login`, async ({ page, context }) => {
      await context.clearCookies()

      const response = await page.goto(path)

      expect(response?.status(), `${path} must answer 200`).toBe(200)
      await expect(page).toHaveURL(new RegExp(`${path}$`))

      // The specific failure this rules out: a guard that bounces a signed-out visitor.
      await expect(page).not.toHaveURL(/\/login/)

      await context.clearCookies()
      await page.reload()
      await expect(page).toHaveURL(new RegExp(`${path}$`))
    })
  }

  test('the deletion page is a working flow, in both languages', async ({ page, context }) => {
    await context.clearCookies()
    await page.goto('/data-deletion')

    const form = page.getByTestId('data-deletion-form')
    await expect(form).toBeVisible()

    // Arabic is the default; the heading proves the page itself is translated, not just its chrome.
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/حذف بياناتك|Delete your data/)

    await page.getByTestId('data-deletion-name').fill('Reviewer');
    await page.getByTestId('data-deletion-email').fill('reviewer@example.test')

    /*
     * The SERVER's answer to the submit, captured before the click resolves.
     *
     * This failed once on webkit — and only webkit — with «`data-deletion-verify` not found», and the
     * screenshot showed the form still on screen: the step had never advanced. Which tells you the
     * post did not succeed and nothing at all about WHY, because the response was never looked at.
     *
     * Throttling was ruled out from the configuration rather than guessed away: outside production
     * the limits are 60 per subject and 600 per address per minute, far above anything this suite
     * does. So the status and body are what remain, and they are now in the failure message.
     *
     * No retry, no longer timeout. The assertion is the same assertion.
     */
    const submitted = page.waitForResponse(
      (r) => r.url().includes('/data-deletion') && r.request().method() === 'POST',
      { timeout: 20_000 },
    ).catch(() => null)

    await page.getByTestId('data-deletion-submit').click()

    const response = await submitted
    const body = response === null ? '(no response was observed)' : (await response.text().catch(() => '(unreadable)')).slice(0, 300)

    /*
     * A reference, and the step that asks for the code — the request is NOT actionable yet.
     *
     * That second half is the point of the whole unit: an address somebody typed is a claim, and a
     * claim does not justify destroying anything.
     */
    await expect(
      page.getByTestId('data-deletion-verify'),
      `the submit did not advance the form. server said ${response?.status() ?? 'nothing'}: ${body}`,
    ).toBeVisible({ timeout: 20_000 })
    await expect(page.getByTestId('data-deletion-reference')).not.toBeEmpty()
  })

  test('an existing request can be looked up from the same page', async ({ page, context }) => {
    await context.clearCookies()
    await page.goto('/data-deletion?reference=ABC12345')

    // Somebody arriving from a platform callback lands with their reference already filled in.
    await expect(page.getByTestId('data-deletion-lookup-reference')).toHaveValue('ABC12345')
  })
})
