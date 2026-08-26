import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * SHARE-LINK-DOUBLE-ORIGIN-001 — the link an operator copies and sends to a client.
 *
 * The builder set `window.location.origin + r.url`, and `ShareService::urlFor()` already returns the
 * absolute link — so every link it produced read `https://hosthttps://host/r/…` and was broken
 * before it left the screen. `ReportsPage` had been fixed for exactly this; the newer builder
 * repeated it.
 *
 * Driven through the UI rather than the API, because the defect was entirely in the browser: the
 * payload was correct and only the assembly was wrong, so an API-level test would have passed
 * throughout.
 */
test.use({ storageState: AUTH.advertiser })

test.describe('the live client link an operator copies', () => {
  test('carries its host exactly once and points at the share route', async ({ page }) => {
    await page.goto('/app/reports')
    await expect(page.locator('main')).toBeVisible({ timeout: 20000 })

    await page.getByRole('button', { name: /رابط لحظي|Live client link/ }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible({ timeout: 10000 })

    await dialog.locator('input[type="text"], input:not([type])').first().fill(`E2E link ${Date.now()}`)
    await dialog.getByRole('button', { name: /إنشاء الرابط|Create link/ }).click()

    const url = page.getByTestId('live-link-url')
    await expect(url).toBeVisible({ timeout: 20000 })

    const value = await url.inputValue()

    // The bug, stated directly: the scheme must appear once, not twice.
    expect(value.match(/https?:\/\//g) ?? [], `the copied link repeats its host:\n${value}`).toHaveLength(1)
    expect(value).toMatch(/\/r\/[A-Za-z0-9]+$/)

    // And it must parse — «https://hosthttps://host/r/x» does not survive `new URL`'s host rules.
    const parsed = new URL(value)
    expect(parsed.pathname).toMatch(/^\/r\//)
    expect(parsed.host).not.toContain('http')
  })
})
