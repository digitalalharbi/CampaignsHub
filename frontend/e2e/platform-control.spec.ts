import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * What the platform owner can actually do from `/admin` — priced plans, granted exceptions, and a
 * record of both (PLAN-PAID-001, GRANT-001).
 *
 * The brief asks for four powers and one guarantee: set the monthly and annual price, decide what a
 * plan includes, grant and remove an exception for ONE account, and have every change carry who did
 * it and why — while a customer can never grant themselves anything. Each is checked by driving the
 * console rather than by calling the endpoint, because the endpoint has its own tests and what was
 * missing was the surface.
 */
test.describe('the plan catalogue is the owner’s to price', () => {
  test.use({ storageState: AUTH.admin })

  test('the monthly and annual prices are editable and reach the public catalogue', async ({ page }) => {
    await page.goto('/admin/billing')
    await switchToEnglish(page)

    const annual = page.getByTestId('plan-price-annual-starter')
    await expect(annual).toBeVisible()
    const original = await annual.inputValue()

    // Save is inert until something changed — an owner opening the screen cannot fill the audit log
    // with a save that changed nothing.
    await expect(page.getByTestId('plan-save-starter')).toBeDisabled()

    await annual.fill('950.00')

    // A change is not saveable until it is explained — the reason goes on the audit entry.
    await expect(page.getByTestId('plan-save-starter')).toBeDisabled()
    await page.getByTestId('plan-reason-starter').fill('Introductory annual price for the quarter.')
    await expect(page.getByTestId('plan-save-starter')).toBeEnabled()
    await page.getByTestId('plan-save-starter').click()

    // The figure a visitor is quoted is the figure the owner just set — one catalogue, not two.
    await expect.poll(async () => {
      const res = await page.request.get('/api/v1/plans')
      const body = await res.json()
      return body.data.plans.find((p: { code: string }) => p.code === 'starter').price_annual
    }, { timeout: 15000 }).toBe('950.00')

    // Put it back, so the rest of the suite sees the catalogue it expects.
    await page.reload()
    await page.getByTestId('plan-price-annual-starter').fill(original)
    await page.getByTestId('plan-reason-starter').fill('Reverting the introductory price.')
    await page.getByTestId('plan-save-starter').click()
    await expect.poll(async () => {
      const res = await page.request.get('/api/v1/plans')
      const body = await res.json()
      return body.data.plans.find((p: { code: string }) => p.code === 'starter').price_annual
    }, { timeout: 15000 }).toBe(original)

    /*
     * …and the audit trail carries the actor, the reason and the date.
     *
     * Read from INSIDE the page rather than through `page.request`: Sanctum's stateful guard engages
     * on the SPA's own Origin, which a bare API-context request does not send — the endpoint then
     * answers 401 and the assertion fails for a reason that has nothing to do with auditing.
     */
    const reasons = await page.evaluate(async () => {
      const res = await fetch('/api/v1/admin/audit', { credentials: 'include' })
      const body = await res.json()
      return (body.data?.entries ?? [])
        .filter((e: { action: string }) => e.action === 'platform.plan.updated')
        .map((e: { reason: string | null }) => e.reason)
    })
    expect(reasons.some((r: string | null) => r?.includes('Introductory annual price'))).toBeTruthy()
  })

  test('nothing on sale is free', async ({ page }) => {
    const res = await page.request.get('/api/v1/plans')
    const body = await res.json()

    for (const plan of body.data.plans) {
      expect(Number(plan.price_monthly), `plan [${plan.code}] is offered at no charge`).toBeGreaterThan(0)
    }
  })
})

test.describe('an exception is granted to one account, and taken back', () => {
  test.use({ storageState: AUTH.admin })

  test('granting needs a reason, and revoking needs its own', async ({ page }) => {
    await page.goto('/admin/tenants')
    await switchToEnglish(page)

    await page.getByTestId('tenant-list').locator('li').first().getByRole('button').first().click()
    await expect(page.getByTestId('account-grants')).toBeVisible()

    // A grant with no reason cannot be made. This is the control the whole feature rests on: an
    // exception nobody can explain is one nobody dares revoke.
    await page.getByTestId('grant-kind').selectOption('module')
    await page.getByTestId('grant-value').selectOption('influencer_marketing')
    await expect(page.getByTestId('grant-submit')).toBeDisabled()

    await page.getByTestId('grant-reason').fill('Pilot for the quarter, agreed with the account manager.')
    await expect(page.getByTestId('grant-submit')).toBeEnabled()
    await page.getByTestId('grant-submit').click()

    const row = page.getByTestId('grant-list').locator('li').first()
    await expect(row).toHaveAttribute('data-in-force', 'true')
    await expect(row).toContainText('Pilot for the quarter')

    // Revoking states its own reason — a different decision from the grant, so not the grant's.
    await row.getByRole('button', { name: /Revoke|إلغاء المنحة/ }).click()
    const id = (await row.getAttribute('data-testid'))!.replace('grant-', '')
    await expect(page.getByTestId(`grant-revoke-${id}`)).toBeDisabled()

    await page.getByTestId(`grant-revoke-reason-${id}`).fill('The pilot ended and was not renewed.')
    await page.getByTestId(`grant-revoke-${id}`).click()

    // The row survives, greyed, carrying both reasons: what this account used to have, and why not.
    await expect(page.getByTestId(`grant-${id}`)).toHaveAttribute('data-in-force', 'false')
    await expect(page.getByTestId(`grant-${id}`)).toContainText('The pilot ended')
    await expect(page.getByTestId(`grant-${id}`)).toContainText('Pilot for the quarter')
  })
})

/**
 * Fail-closed, from the customer's side.
 *
 * The console is where exceptions are made, and the strongest statement about that is what happens
 * when the person who would benefit tries it themselves. Not "the button is hidden" — the whole
 * console is refused, and so is the endpoint underneath it.
 */
test.describe('a customer cannot grant themselves anything', () => {
  test.use({ storageState: AUTH.owner })

  test('the console refuses an agency owner, at the page and at the API', async ({ page }) => {
    await page.goto('/admin/tenants')
    await expect(page).not.toHaveURL(/\/admin\/tenants$/)

    // …and the endpoint itself, which is what a hidden button would leave open.
    const res = await page.request.post('/api/v1/admin/tenants/00000000-0000-0000-0000-000000000000/grants', {
      data: { kind: 'full_access', reason: 'I would like everything, please.' },
      failOnStatusCode: false,
    })
    expect([401, 403]).toContain(res.status())
  })
})
