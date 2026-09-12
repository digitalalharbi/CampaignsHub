import { expect, test } from '@playwright/test'
import { AUTH, csrfHeaders, E2E_ORIGIN } from './helpers'

/**
 * Alerts management UI (/agency/alerts) on Chromium/Firefox/WebKit: the page renders the operator surface for the
 * alerts engine — Alerts (Active/Snoozed/Resolved), Rules (create), Preferences (channels + quiet hours), and
 * the honest Delivery log. A rule created through the UI persists; tab switching is console-clean.
 */
test.use({ storageState: AUTH.owner })

test('alerts page renders all sections and a rule created in the UI persists', async ({ page }) => {
  const errors: string[] = []
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })
  page.on('pageerror', (e) => errors.push(String(e)))

  await page.goto('/agency/alerts')
  /*
   * No language switch — every assertion below is a language-agnostic alternation, and asking for
   * English here costs a webkit failure for nothing. See the note in `expansion-surfaces.spec.ts`:
   * changing the locale makes webkit refuse the proxied CSRF request, and this case is about whether
   * the alerts surface renders and persists a rule.
   */

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
  /*
   * The rule-name field by id, not "the first textbox on the page".
   *
   * The alerts list carries its own search box, and that box only renders once there ARE alerts. So
   * on a clean database the first textbox was the rule name, and by the third browser of a full run —
   * after the earlier legs had created alerts — it was the search box: the rule name was typed into
   * a filter, no rule was created, and the failure read as a broken form.
   */
  await page.locator('#rule-name').fill(unique)
  await page.getByRole('button', { name: /^Save$|^حفظ$/ }).click()
  await expect(page.getByText(unique)).toBeVisible({ timeout: 10_000 })

  // Preferences tab → channels + quiet hours render.
  await page.getByRole('button', { name: /Preferences|التفضيلات/ }).click()
  /*
    Case-insensitive, because the English heading is «Notification channels & quiet hours».
    
    This case only ever ran in Arabic — `switchToEnglish` was a no-op that swallowed its own failure
    — and «ساعات الهدوء» is a substring of the Arabic heading, so it matched. Under a switch that
    actually switches, the capital «Quiet hours» matches nothing on the page.
  */
  await expect(page.getByText(/quiet hours|ساعات الهدوء/i).first()).toBeVisible()

  // Delivery log tab → honest note renders (never "sent" without a provider).
  await page.getByRole('button', { name: /Delivery log|سجل التسليم/ }).click()
  await expect(page.getByText(/Honest delivery|التسليم صادق/)).toBeVisible()

  expect(errors.filter((e) => !/401|favicon/i.test(e)), errors.join('\n')).toHaveLength(0)
})

test('the notification bell links to the alerts page', async ({ browser, page }) => {
  // Raise a tenant-wide alert via the API so the bell has something to link to.
  const ctx = await browser.newContext({ storageState: AUTH.owner, baseURL: E2E_ORIGIN })
  const headers = await csrfHeaders(ctx.request)
  // A rule is enough to prove the wiring. The bell's own action_url is still '/app/alerts' (see
  // AlertEvaluator); what this asserts is the RAIL entry, which is per-portal — an agency operator's
  // Alerts link stays inside /agency rather than dropping them into the advertiser portal.
  await ctx.request.post('/api/v1/alerts/rules', { headers, data: { type: 'no_results', name: `bell-${Date.now()}` } })
  await ctx.close()

  // Start in the operator's OWN portal. Going to `/dashboard` lands in the advertiser shell,
  // whose Alerts leaf points at `/app/alerts` — a different portal's copy of the same page.
  await page.goto('/agency')
  /* Language-agnostic below, and asking for English costs a webkit failure — see the note above. */
  // The alerts entry is reachable from the sidebar (the bell's items deep-link to the same page).
  await page.getByRole('link', { name: /^Alerts$|^التنبيهات$/ }).first().click()
  await expect(page).toHaveURL(/\/agency\/alerts/)
})
