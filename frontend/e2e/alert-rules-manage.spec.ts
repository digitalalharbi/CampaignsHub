import { expect, test } from '@playwright/test'
import { AUTH, csrfHeaders, E2E_ORIGIN } from './helpers'

/**
 * ALERT-RULES-MANAGE-001 — master task 3 in a real browser: a rule is edited in place, paused from its row, and
 * removed after an in-app dialog (never the browser's own box). The rules tab never scrolls sideways.
 *
 * Screenshots go to RULES_SHOTS_DIR when it is set (local evidence only); nothing is written otherwise.
 */
test.use({ storageState: AUTH.owner })

const shots = process.env.RULES_SHOTS_DIR

test('a rule is edited, paused and removed from the rules tab', async ({ browser, page }) => {
  const name = `E2E manage ${test.info().project.name}-${Date.now()}`
  const ctx = await browser.newContext({ storageState: AUTH.owner, baseURL: E2E_ORIGIN })
  const headers = await csrfHeaders(ctx.request)
  const created = await ctx.request.post('/api/v1/alerts/rules', { headers, data: { type: 'cpa_increase', name, threshold: { pct: 20 } } })
  expect(created.status()).toBe(201)
  const id = (await created.json()).data.id as string
  await ctx.close()

  let nativeDialog = false
  page.on('dialog', (d) => { nativeDialog = true; void d.dismiss() })

  await page.goto('/agency/alerts')
  await page.getByRole('button', { name: /^Rules$|^القواعد$/ }).click()
  const row = page.getByTestId(`alert-rule-${id}`)
  await expect(row).toBeVisible()
  if (shots) await page.screenshot({ path: `${shots}/rules-list-desktop.png`, fullPage: false })

  // Edit: the form loads the rule, the type is locked, and the save lands on the row.
  await page.getByTestId(`alert-rule-edit-${id}`).click()
  await expect(page.locator('#rule-name')).toHaveValue(name)
  await page.locator('#rule-name').fill(`${name} edited`)
  if (shots) await page.screenshot({ path: `${shots}/rules-edit-desktop.png`, fullPage: false })
  await page.getByTestId('alert-rule-save').click()
  await expect(row).toContainText(`${name} edited`, { timeout: 10_000 })

  // Pause from the row switch.
  const toggle = page.getByTestId(`alert-rule-toggle-${id}`)
  await toggle.click()
  await expect(toggle).toHaveAttribute('aria-checked', 'false', { timeout: 10_000 })

  // Remove: an in-app dialog first; cancel keeps it, confirm removes it.
  await page.getByTestId(`alert-rule-delete-${id}`).click()
  await expect(page.getByTestId('dialog-body')).toBeVisible()
  if (shots) await page.screenshot({ path: `${shots}/rules-delete-dialog.png`, fullPage: false })
  await page.getByTestId('dialog-cancel').click()
  await expect(row).toBeVisible()
  await page.getByTestId(`alert-rule-delete-${id}`).click()
  await page.getByTestId('dialog-confirm').click()
  await expect(row).toHaveCount(0, { timeout: 10_000 })
  expect(nativeDialog, 'no window.confirm / window.prompt').toBe(false)

  // No sideways scroll on the rules tab, phone width included.
  for (const width of [1280, 375]) {
    await page.setViewportSize({ width, height: 800 })
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    expect(overflow, `horizontal overflow at ${width}px`).toBeLessThanOrEqual(1)
    if (shots && width === 375) await page.screenshot({ path: `${shots}/rules-list-375.png`, fullPage: true })
  }
})
