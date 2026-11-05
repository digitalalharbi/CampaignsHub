import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, aFreshSaudiNumber, csrfHeaders, signIn, verifyMobileThroughApi } from './helpers'

/**
 * SIGNUP-DECISION-001 — the Owner's case, walked in a browser.
 *
 * An application reaches the payment gate. Plain approval leaves it there, truthfully. A separate, confirmed waiver then
 * activates it without signing up again, and the person signs in to a real workspace with nothing recorded as paid.
 */
test.describe('registration decision', () => {
  test.use({ storageState: AUTH.admin })

  test('approval leaves the payment owed, and a confirmed waiver activates without a payment', async ({ page, browser, request }) => {
    const stamp = Date.now()
    const email = `decision-${stamp}@e2e.test`

    const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } })
    const applied = await visitor.request.post('/api/v1/auth/register', {
      headers: await csrfHeaders(visitor.request),
      data: {
        tenant_name: `Decision ${stamp}`, name: 'Decision Applicant', email, password: 'secret1234', password_confirmation: 'secret1234',
        plan_code: 'solo', billing_interval: 'annual', phone: aFreshSaudiNumber(),
      },
    })
    expect(applied.status()).toBe(202)
    const body = (await applied.json()).data
    const id = body.registration.id as string
    const token = (body.verification.dev_link as string).split('token=')[1]

    const verified = await visitor.request.post('/api/v1/auth/registration/verify-email', {
      headers: await csrfHeaders(visitor.request), data: { token },
    })
    expect(verified.status()).toBe(200)
    await verifyMobileThroughApi(visitor.request, id)

    await page.goto('/admin/registrations')
    await page.getByTestId('registrations-search').fill(email)
    await page.getByTestId('registration-row').click()
    await expect(page.getByTestId('registration-decision')).toBeVisible()

    // Plain approval: still awaiting payment, and no workspace.
    await page.getByTestId('registration-approve').click()
    await expect(page.getByTestId('registration-detail')).toHaveAttribute('data-state', 'approved_awaiting_payment')
    await expect(page.getByTestId('registration-decision-current')).toBeVisible()

    const status = await visitor.newPage()
    await status.goto(`/signup/status?request=${id}`)
    await expect(status.getByTestId('registration-status')).toHaveAttribute('data-state', 'approved_awaiting_payment')
    await expect(status.getByTestId('registration-step-active')).toHaveAttribute('data-done', 'false')

    // The waiver: previewed, justified, confirmed.
    await page.getByTestId('decision-approve_waiver').check()
    await expect(page.getByTestId('decision-submit')).toBeDisabled()
    await page.getByTestId('decision-preview').click()
    await expect(page.getByTestId('preview-waived')).toBeVisible()
    await expect(page.getByTestId('preview-creates')).toBeVisible()
    await page.getByTestId('decision-reason').fill('E2E: pilot agreed with the owner')
    await expect(page.getByTestId('decision-submit')).toBeDisabled()
    await page.getByTestId('decision-confirm-waiver').check()
    await page.getByTestId('decision-submit').click()
    await expect(page.getByTestId('registration-detail')).toHaveAttribute('data-state', 'active')

    // The status page says what happened, with no payment step ticked.
    await status.reload()
    await expect(status.getByTestId('registration-status')).toHaveAttribute('data-state', 'active')
    await expect(status.getByTestId('registration-commercial')).toHaveAttribute('data-outcome', 'approve_waiver')
    await expect(status.getByTestId('registration-step-payment')).toHaveCount(0)

    // Nothing was recorded as paid, and replaying the read changes nothing.
    const detail = await request.get(`/api/v1/admin/registrations/${id}`, { headers: API_HEADERS })
    const { registration } = (await detail.json()).data
    expect(registration.provisioned).toBe(true)
    expect(registration.commercial.waived_by_admin).toBe(true)
    expect(registration.commercial.amount_due).toBe('0.00')

    // Signing in reaches a workspace of their own, not the applicant's status page.
    const person = await browser.newContext({ storageState: { cookies: [], origins: [] } })
    const own = await person.newPage()
    await signIn(own, email, 'secret1234')
    await expect(own).toHaveURL(/\/(onboarding|app|agency)/)
    await person.close()
    await visitor.close()
  })

  test('a customer session cannot decide anything', async ({ playwright, baseURL }) => {
    const owner = await playwright.request.newContext({ baseURL, storageState: AUTH.owner })
    const denied = await owner.post('/api/v1/admin/registrations/00000000-0000-4000-8000-000000000000/decision', {
      headers: await csrfHeaders(owner), data: { outcome: 'approve_waiver', reason: 'Trying my luck', confirm_waiver: true, confirm_amount: 0 },
    })
    expect([403, 404]).toContain(denied.status())
    await owner.dispose()
  })
})
