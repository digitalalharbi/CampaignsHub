import { expect, test, type APIRequestContext } from '@playwright/test'
import { API_HEADERS, AUTH, aFreshSaudiNumber, csrfHeaders } from './helpers'

/**
 * SIGNUP-VERIFY-POLICY-001 — the two switches, driven the way the owner drives them.
 *
 * The policy is one row shared by the whole database, and every other spec that signs up expects the secure default, so
 * each test here puts it back whatever happens. Playwright runs with one worker, so nothing signs up while it is off.
 */
const URL = '/api/v1/admin/registration-verification-policy'

async function restoreDefaults(request: APIRequestContext) {
  const read = await request.get(URL, { headers: API_HEADERS })
  if (!read.ok()) return
  const { policy } = (await read.json()).data
  if (policy.email_enabled && policy.mobile_enabled) return

  const write = await request.put(URL, {
    headers: await csrfHeaders(request),
    data: { ...policy, email_enabled: true, mobile_enabled: true, expected_version: policy.version, reason: 'E2E restores the default' },
  })
  expect(write.status(), 'the secure default must be restored for the specs that follow').toBe(200)
}

test.describe('registration and verification policy', () => {
  test.use({ storageState: AUTH.admin })

  test.afterEach(async ({ request }) => {
    await restoreDefaults(request)
  })

  test('a guest and a workspace owner cannot touch it', async ({ playwright, baseURL }) => {
    const guest = await playwright.request.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
    expect((await guest.get(URL, { headers: API_HEADERS })).status()).toBe(401)
    await guest.dispose()

    const owner = await playwright.request.newContext({ baseURL, storageState: AUTH.owner })
    expect((await owner.get(URL, { headers: API_HEADERS })).status()).toBe(403)
    await owner.dispose()
  })

  test('turning both off needs a reason and a yes, and then signup really skips them', async ({ page, browser, request }) => {
    await page.goto('/admin/settings')
    await page.getByTestId('admin-settings-tab-registration').click()

    await expect(page.getByTestId('policy-switch-email')).toBeChecked()
    await expect(page.getByTestId('policy-switch-mobile')).toBeChecked()
    // The estimate is honest about the price nobody entered.
    await expect(page.getByTestId('policy-cost-mobile')).toContainText(/غير معروفة|Unknown/)

    await page.getByTestId('policy-switch-email').uncheck()
    await page.getByTestId('policy-switch-mobile').uncheck()
    await expect(page.getByTestId('policy-risk')).toBeVisible()
    await expect(page.getByTestId('policy-save')).toBeDisabled()

    await page.getByTestId('policy-reason').fill('E2E: cost control walk')
    await expect(page.getByTestId('policy-save')).toBeDisabled()
    await page.getByTestId('policy-confirm').check()
    await page.getByTestId('policy-save').click()

    await expect(page.getByTestId('policy-audit')).toContainText('E2E: cost control walk')
    await expect(page.getByTestId('policy-switch-email')).not.toBeChecked()

    // A new visitor, with no session: no number is asked for.
    const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } })
    const signup = await visitor.newPage()
    await signup.goto('/register')
    await expect(signup.getByTestId('register-website')).toBeAttached()
    await expect(signup.getByTestId('phone')).toHaveCount(0)

    const email = `policy-off-${Date.now()}@e2e.test`
    const applied = await visitor.request.post('/api/v1/auth/register', {
      headers: await csrfHeaders(visitor.request),
      data: {
        tenant_name: 'Policy Off Co', name: 'Policy Off', email, password: 'secret1234', password_confirmation: 'secret1234',
        plan_code: 'solo', billing_interval: 'monthly',
      },
    })
    expect(applied.status()).toBe(202)
    const { registration, verification } = (await applied.json()).data
    expect(verification.delivery_status).toBe('not_required_by_policy')
    expect(registration.email_verified).toBe(false)
    expect(registration.mobile_verified).toBe(false)
    // The payment is still owed: turning verification off waived nothing.
    expect(registration.state).toBe('approved_awaiting_payment')
    expect(registration.provisioned).toBe(false)

    await signup.goto(`/signup/status?request=${registration.id}`)
    await expect(signup.getByTestId('registration-status')).toHaveAttribute('data-state', 'approved_awaiting_payment')
    await expect(signup.getByTestId('registration-step-email')).toHaveCount(0)
    await expect(signup.getByTestId('registration-step-mobile')).toHaveCount(0)
    await expect(signup.getByTestId('registration-step-payment')).toHaveAttribute('data-done', 'false')
    await visitor.close()

    // Back on: an application opened now is held at the gate again, and the one above keeps what it was opened under.
    await restoreDefaults(request)
    const back = await request.post('/api/v1/auth/register', {
      headers: await csrfHeaders(request),
      data: {
        tenant_name: 'Policy On Co', name: 'Policy On', email: `policy-on-${Date.now()}@e2e.test`,
        password: 'secret1234', password_confirmation: 'secret1234', plan_code: 'solo', billing_interval: 'monthly', phone: aFreshSaudiNumber(),
      },
    })
    expect(back.status()).toBe(202)
    expect((await back.json()).data.registration.state).toBe('email_verification_required')
  })
})
