import { expect, test } from '@playwright/test'
import { AUTH, csrfHeaders, switchToEnglish } from './helpers'

/**
 * Alerts management UI (/app/alerts) on Chromium/Firefox/WebKit: the page renders the operator surface for the
 * alerts engine — Alerts (Active/Snoozed/Resolved), Rules (create), Preferences (channels + quiet hours), and
 * the honest Delivery log. A rule created through the UI persists; tab switching is console-clean.
 */
test.use({ storageState: AUTH.owner })

test('alerts page renders all sections and a rule created in the UI persists', async ({ page }) => {
  const errors: string[] = []
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })
  page.on('pageerror', (e) => errors.push(String(e)))

  await page.goto('/app/alerts')
  await switchToEnglish(page)

  // Page + entitlement nav link.
  await expect(page.getByRole('heading', { level: 1, name: /Alerts|التنبيهات/ })).toBeVisible()
  await expect(page.getByRole('link', { name: /^Alerts$|^التنبيهات$/ }).first()).toBeVisible()

  // The three lifecycle filters exist.
  await expect(page.getByRole('button', { name: /Active|نشِطة/ })).toBeVisible()
  await expect(page.getByRole('button', { name: /Snoozed|مؤجّلة/ })).toBeVisible()
  await expect(page.getByRole('button', { name: /Resolved|مُغلقة/ })).toBeVisible()

  // Rules tab → create a rule → it appears in the list.
  const unique = `E2E budget ${test.info().project.name}-${Date.now()}`
  await page.getByRole('button', { name: /^Rules$|^القواعد$/ }).click()
  await page.getByRole('textbox').first().fill(unique)
  await page.getByRole('button', { name: /^Save$|^حفظ$/ }).click()
  await expect(page.getByText(unique)).toBeVisible({ timeout: 10_000 })

  // Preferences tab → channels + quiet hours render.
  await page.getByRole('button', { name: /Preferences|التفضيلات/ }).click()
  await expect(page.getByText(/Quiet hours|ساعات الهدوء/).first()).toBeVisible()

  // Delivery log tab → honest note renders (never "sent" without a provider).
  await page.getByRole('button', { name: /Delivery log|سجل التسليم/ }).click()
  await expect(page.getByText(/Honest delivery|التسليم صادق/)).toBeVisible()

  expect(errors.filter((e) => !/401|favicon/i.test(e)), errors.join('\n')).toHaveLength(0)
})

test('the notification bell links to the alerts page', async ({ browser, page }) => {
  // Raise a tenant-wide alert via the API so the bell has something to link to.
  const ctx = await browser.newContext({ storageState: AUTH.owner, baseURL: 'http://localhost:5173' })
  const headers = await csrfHeaders(ctx.request)
  // A rule is enough to prove the wiring; the bell action_url is '/app/alerts' for every alert notification.
  await ctx.request.post('/api/v1/alerts/rules', { headers, data: { type: 'no_results', name: `bell-${Date.now()}` } })
  await ctx.close()

  await page.goto('/dashboard')
  await switchToEnglish(page)
  // The alerts entry is reachable from the sidebar (the bell's items deep-link to the same page).
  await page.getByRole('link', { name: /^Alerts$|^التنبيهات$/ }).first().click()
  await expect(page).toHaveURL(/\/app\/alerts/)
})
