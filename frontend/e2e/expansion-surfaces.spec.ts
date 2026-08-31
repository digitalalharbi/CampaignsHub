import { expect, test, type Page } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * The expansion surfaces render, with a heading and no console errors — each one signed in as an
 * account that ACTUALLY HOLDS THE PORTAL it lives in (LOGIN-002).
 *
 * This whole file used one agency account for everything, including the advertiser portal's own
 * pages. That only worked because `/app` had no portal guard: the agency operator walked into the
 * advertiser tree and met a rail filtered down to whatever the two portals shared. Nothing here
 * failed, and nothing here was testing what it claimed to.
 *
 * Also asserts the consolidation redirects still land on the canonical routes.
 */

/**
 * One surface check, reused by both portals.
 *
 * ## Why the failures also record the REQUEST
 *
 * Chrome's console message for a failed fetch is the string «Failed to load resource: the server
 * responded with a status of 500» and nothing else — no URL, no body. `/app/integrations` has now
 * failed this check three times across different pull requests, and every one of those failures
 * produced that single sentence: enough to fail a gate, not enough to fix anything. Twice it was
 * dismissed as flake because there was no way to tell otherwise.
 *
 * So the response listener runs beside the console one. The console still decides PASS or FAIL —
 * this changes no verdict — but a 5xx now carries the method, the URL and the first of the body,
 * which is the difference between «something 500ed» and a defect somebody can go and fix.
 *
 * The body is truncated and comes from this suite's own throwaway server. It holds no credential:
 * `X-XSRF-TOKEN` and the session cookie travel in headers, and neither is read here.
 */
async function surfaceRenders(page: Page, path: string) {
  const errors: string[] = []
  const failedRequests: string[] = []

  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })
  page.on('pageerror', (e) => errors.push(String(e)))

  page.on('response', (r) => {
    if (r.status() < 500) return

    failedRequests.push(`${r.request().method()} ${r.url()} → ${r.status()}`)

    // Best effort: a body that cannot be read must not fail the run before the assertion does.
    r.text()
      .then((body) => failedRequests.push(`    body: ${body.slice(0, 400)}`))
      .catch(() => undefined)
  })

  await page.goto(path)
  await switchToEnglish(page)
  await expect(page).toHaveURL(new RegExp(path.replace(/\//g, '\\/')))
  await expect(page.getByRole('heading').first()).toBeVisible({ timeout: 10_000 })

  const unexpected = errors.filter((e) => !/401|403|favicon|Unauthorized/i.test(e))

  const detail = failedRequests.length > 0
    ? `\n\nserver errors seen while loading this page:\n${failedRequests.join('\n')}`
    : ''

  expect(unexpected, `console errors on ${path}:\n${unexpected.join('\n')}${detail}`).toHaveLength(0)
}

test.describe('the advertiser portal', () => {
  test.use({ storageState: AUTH.advertiser })

  const REDIRECTS: { from: string; to: RegExp }[] = [
    { from: '/integrations', to: /\/app\/integrations/ },
    { from: '/app/connections', to: /\/app\/integrations/ },
    /*
     * INTEG-RUNTIME §2 — Drive is a FILE source, not one of the eight providers.
     *
     * The canonical target moved from `/app/integrations/drive` to `/app/files/drive`. Its folder
     * links feed the files library and the client portal's attachments, so the capability stays;
     * what moved is the claim that it is an integration.
     */
    { from: '/app/drive', to: /\/app\/files\/drive/ },
    { from: '/app/branding', to: /\/app\/settings\/branding/ },
  ]

  for (const r of REDIRECTS) {
    test(`legacy route redirects to canonical: ${r.from}`, async ({ page }) => {
      await page.goto(r.from)
      await expect(page).toHaveURL(r.to, { timeout: 10_000 })
    })
  }

  // Integrations is the single integrations surface; Branding lives under Settings; Drive is a
  // connector under Integrations; Subscription is what this workspace pays CampaignsHub.
  const SURFACES = [
    { path: '/app/integrations', name: 'integrations' },
    { path: '/app/integrations/drive', name: 'drive-connector' },
    { path: '/app/settings/branding', name: 'branding' },
    { path: '/app/subscriptions', name: 'subscription' },
  ]

  for (const s of SURFACES) {
    test(`advertiser surface renders: ${s.name}`, async ({ page }) => surfaceRenders(page, s.path))
  }
})

test.describe('the agency portal', () => {
  test.use({ storageState: AUTH.owner })

  // Client invoicing and client conversations are the AGENCY's, and live in its portal (REG-001).
  const SURFACES = [
    { path: '/agency/billing', name: 'client invoicing' },
    { path: '/agency/billing/invoices', name: 'invoices' },
    { path: '/agency/billing/payments', name: 'payments' },
    { path: '/agency/messages', name: 'conversations' },
    { path: '/agency/settings', name: 'agency settings' },
  ]

  for (const s of SURFACES) {
    test(`agency surface renders: ${s.name}`, async ({ page }) => surfaceRenders(page, s.path))
  }

  /** A pre-move `/app` link still resolves for an agency operator — it must not meet the guard. */
  test('a pre-move /app/billing link redirects into the agency portal', async ({ page }) => {
    await page.goto('/app/billing')
    await expect(page).toHaveURL(/\/agency\/billing/, { timeout: 10_000 })
  })
})
