import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * Expansion internal surfaces (Operations Console) render for a staff owner, with a heading and no console
 * errors. Also asserts the consolidation redirects: the legacy/duplicate routes land on the canonical ones.
 */
test.use({ storageState: AUTH.owner })

const REDIRECTS: { from: string; to: RegExp }[] = [
  { from: '/integrations', to: /\/app\/integrations/ },
  { from: '/app/connections', to: /\/app\/integrations/ },
  { from: '/app/drive', to: /\/app\/integrations\/drive/ },
  { from: '/app/branding', to: /\/settings\/branding/ },
]

for (const r of REDIRECTS) {
  test(`legacy route redirects to canonical: ${r.from}`, async ({ page }) => {
    await page.goto(r.from)
    await expect(page).toHaveURL(r.to, { timeout: 10_000 })
  })
}

// Canonical surfaces (post-consolidation). Integrations is the single integrations surface; Branding lives
// under Settings; Drive is a connector under Integrations.
const SURFACES = [
  { path: '/app/billing', name: 'finance' },
  { path: '/app/billing/invoices', name: 'invoices' },
  { path: '/app/billing/payments', name: 'payments' },
  { path: '/app/messages', name: 'messages' },
  { path: '/app/integrations', name: 'integrations' },
  { path: '/app/integrations/drive', name: 'drive-connector' },
  { path: '/settings/branding', name: 'branding' },
  { path: '/app/subscriptions', name: 'subscription' },
]

for (const s of SURFACES) {
  test(`operations console surface renders: ${s.name}`, async ({ page }) => {
    const errors: string[] = []
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })
    page.on('pageerror', (e) => errors.push(String(e)))

    await page.goto(s.path)
    await switchToEnglish(page)
    // The surface mounts inside the AppShell: a heading is present and the URL is correct.
    await expect(page).toHaveURL(new RegExp(s.path.replace(/\//g, '\\/')))
    await expect(page.getByRole('heading').first()).toBeVisible({ timeout: 10_000 })

    const unexpected = errors.filter((e) => !/401|403|favicon|Unauthorized/i.test(e))
    expect(unexpected, `console errors on ${s.path}:\n${unexpected.join('\n')}`).toHaveLength(0)
  })
}
