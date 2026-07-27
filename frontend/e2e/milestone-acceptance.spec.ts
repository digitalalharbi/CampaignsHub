import { expect, test } from '@playwright/test'
import { AUTH, API_HEADERS, csrfHeaders, switchToEnglish } from './helpers'

/**
 * Milestone acceptance (browser-observable slice) against the LIVE server, on Chromium/Firefox/WebKit:
 *   - Alerts API: create a rule → it persists across a re-read; the firing ledger is queryable.
 *   - Honest delivery: the delivery ledger NEVER logs an email as "sent" without a provider.
 *   - Fail-closed permissions: a limited member cannot create alert rules (needs alerts.manage).
 *   - UI persistence: the owner's entitlement-driven nav survives a reload.
 * The exhaustive end-to-end chain is proven deterministically by the backend MilestoneAcceptanceTest.
 */
test.use({ storageState: AUTH.owner }) // the UI test's `page` acts as the owner

test.describe('milestone acceptance', () => {
  test('alerts API persists and the delivery ledger stays honest', async ({ browser }, testInfo) => {
    const ownerCtx = await browser.newContext({ storageState: AUTH.owner, baseURL: 'http://localhost:5173' })
    const headers = await csrfHeaders(ownerCtx.request)
    const name = `Budget risk ${testInfo.project.name}-${Date.now()}`

    // Create a rule.
    const created = await ownerCtx.request.post('/api/v1/alerts/rules', {
      headers,
      data: { type: 'budget_risk', name, threshold: { ratio: 0.9 }, severity: 'warning', channels: ['in_app', 'email'] },
    })
    expect(created.status()).toBe(201)

    // Persistence: a fresh read (as after a page refresh) still returns the rule.
    const list = await ownerCtx.request.get('/api/v1/alerts/rules', { headers: API_HEADERS })
    expect(list.status()).toBe(200)
    const names = ((await list.json()).data as Array<{ name: string }>).map((r) => r.name)
    expect(names).toContain(name)

    // The firing ledger is queryable.
    const events = await ownerCtx.request.get('/api/v1/alerts/events', { headers: API_HEADERS })
    expect(events.status()).toBe(200)

    // Honest delivery: no email is ever logged as "sent" (no provider is wired).
    const deliveries = await ownerCtx.request.get('/api/v1/notifications/deliveries', { headers: API_HEADERS })
    expect(deliveries.status()).toBe(200)
    const rows = (await deliveries.json()).data as Array<{ channel: string; status: string }>
    expect(rows.filter((d) => d.channel === 'email' && d.status === 'sent')).toHaveLength(0)

    await ownerCtx.close()
  })

  test('a limited member is denied alert-rule creation (fail-closed)', async ({ browser }) => {
    const analystCtx = await browser.newContext({ storageState: AUTH.analyst, baseURL: 'http://localhost:5173' })
    const headers = await csrfHeaders(analystCtx.request)
    const res = await analystCtx.request.post('/api/v1/alerts/rules', {
      headers, data: { type: 'budget_risk', name: 'nope' },
    })
    expect(res.status()).toBe(403)
    await analystCtx.close()
  })

  test('owner entitlement nav renders and survives a reload', async ({ page }) => {
    await page.goto('/dashboard')
    await switchToEnglish(page)
    // The personal (agency) workspace shows the full operational menu — assert an entitled item is present.
    await expect(page.getByRole('link', { name: /Reports|التقارير/ }).first()).toBeVisible()

    await page.reload()
    await switchToEnglish(page)
    await expect(page.getByRole('link', { name: /Reports|التقارير/ }).first()).toBeVisible()
  })
})
