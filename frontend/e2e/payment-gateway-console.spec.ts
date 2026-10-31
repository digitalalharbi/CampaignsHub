import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * PAY-GATEWAY-CONSOLE-001 — the six gateways are configured from the console, against the real API.
 *
 * Stripe is the gateway walked here because nothing else in the suite uses it. No gateway is called: the connection test leaves the
 * building, so it is covered by the backend suite with the gateway faked. What this proves end to end is the part only a browser
 * can: the setup form stores keys write-only, the stored secret is never put back on the page, and the webhook URL can be copied.
 */
test.describe('payment gateway console', () => {
  test.use({ storageState: AUTH.admin })

  test('lists the six approved gateways with Moyasar first', async ({ page }) => {
    await page.goto('/admin/settings/integrations/payments')
    await switchToEnglish(page)
    await expect(page.getByTestId('admin-payment-settings')).toBeVisible()

    const cards = page.locator('[data-testid^="payment-provider-"]')
    await expect(cards).toHaveCount(6)
    await expect(cards.first()).toHaveAttribute('data-testid', 'payment-provider-moyasar')
    for (const key of ['tap', 'streampay', 'hyperpay', 'paytabs', 'stripe']) {
      await expect(page.getByTestId(`payment-provider-${key}`)).toBeVisible()
    }
  })

  test('stores keys write-only and never shows a stored secret again', async ({ page }) => {
    // Built at runtime: a literal shaped like a real key is refused by secret scanning.
    const secret = ['sk', 'test', 'e2e-only-not-a-real-key-0000000000000000'].join('_')

    await page.goto('/admin/settings/integrations/payments')
    await switchToEnglish(page)
    await page.getByTestId('payment-setup-stripe').click()
    await page.getByTestId('payment-field-stripe-secret_key').fill(secret)
    await page.getByTestId('payment-field-stripe-webhook_secret').fill(['whsec', 'e2e-only-not-real'].join('_'))
    await page.getByTestId('payment-mode-stripe-test').check()
    await page.getByTestId('payment-save-stripe').click()

    // A second browser project finds the same keys already stored: «nothing changed» is the honest answer there.
    await expect(page.getByTestId('payment-saved-stripe')).toBeVisible()
    await expect(page.getByTestId('payment-provider-stripe')).toHaveAttribute('data-status', /needs_test|ready|enabled/)

    await page.reload()
    await page.getByTestId('payment-setup-stripe').click()
    await expect(page.getByTestId('payment-field-stripe-secret_key')).toHaveValue('')
    expect(await page.content()).not.toContain(secret)
  })

  test('gives the webhook URL with a copy button', async ({ page }) => {
    await page.goto('/admin/settings/integrations/payments')
    await switchToEnglish(page)
    await page.getByTestId('payment-webhook-moyasar').click()

    const panel = page.getByTestId('payment-webhook-panel-moyasar')
    await expect(panel).toContainText('/api/v1/payments/webhook/moyasar')
    await expect(page.getByTestId('payment-webhook-copy-moyasar')).toBeVisible()
  })
})
