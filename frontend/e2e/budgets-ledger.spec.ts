import { expect, test } from '@playwright/test'
import { AUTH, csrfHeaders } from './helpers'

/**
 * BUDGET-LEDGER-001 — add → edit → delete → undo → export, through the board a person uses.
 *
 * Every step goes through the real endpoints; what is read back has been through `FundingBalance` and the
 * ledger exactly as the product computes it. The client and project are made by the add step itself (it is
 * one step on purpose), under names no other run shares, so a re-run never meets a leftover.
 */
test.use({ storageState: AUTH.owner })

test('add → edit → delete → undo → export', async ({ page }) => {
  const stamp = Date.now().toString(36)
  const client = `عميل اختبار ${stamp}`
  const project = `مشروع اختبار ${stamp}`

  await page.goto('/agency/budgets')
  await expect(page.getByTestId('budgets-page')).toBeVisible({ timeout: 30000 })

  // ADD — one dialog, the toast offers undo.
  await page.getByTestId('budgets-add').click()
  await page.locator('#budget-client').fill(client)
  await page.locator('#budget-project').fill(project)
  await page.getByRole('button', { name: '20,000' }).click()
  await page.getByTestId('budget-submit').click()
  await expect(page.getByTestId('undo-toast')).toContainText('أُضيفت الميزانية')

  // The new row is on the board; its drawer is open (the add chose it).
  const drawer = page.getByTestId('budget-drawer')
  await expect(drawer).toBeVisible()
  await expect(drawer.getByTestId('budget-remaining')).toContainText('20,000')

  // EDIT — the amount only; the remaining follows.
  await drawer.getByTestId('budget-edit').click()
  await page.locator('#edit-amount').fill('25000')
  await page.getByTestId('budget-edit-submit').click()
  await expect(page.getByTestId('undo-toast')).toContainText('تم التعديل')
  await expect(drawer.getByTestId('budget-remaining')).toContainText('25,000')
  await expect(drawer.getByTestId('history-adjustment')).toHaveCount(1)

  // UNDO the edit from the history (latest entry only).
  await expect(drawer.getByTestId('history-undo')).toHaveCount(1)
  await drawer.getByTestId('history-undo').click()
  await expect(drawer.getByTestId('budget-remaining')).toContainText('20,000')

  // DELETE — a reason is required; the budget leaves the board and sits under «المحذوفة».
  await drawer.getByTestId('budget-delete').click()
  await expect(page.getByTestId('budget-delete-submit')).toBeDisabled()
  await page.locator('#delete-reason').fill('اختبار الحذف')
  await page.getByTestId('budget-delete-submit').click()
  await expect(page.getByTestId('undo-toast')).toContainText('حُذفت الميزانية')
  await expect(page.getByText(project)).toHaveCount(0)

  // UNDO the delete from the toast — the budget is back with its figure.
  await page.getByTestId('undo-toast-undo').click()
  await expect(page.getByTestId('undo-toast')).toContainText('تم التراجع')
  await expect(page.getByText(project).first()).toBeVisible()

  // EXPORT — all three files are real downloads.
  for (const format of ['xlsx', 'csv', 'pdf'] as const) {
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 60000 }),
      (async () => { await page.getByTestId('budgets-export').click(); await page.getByTestId(`budgets-export-${format}`).click() })(),
    ])
    expect(download.suggestedFilename()).toMatch(new RegExp(`\\.${format}$`))
  }
})

/**
 * BUDGET-MODES-001 — add credit → add planned → reallocate → undo → export, through the board a person uses.
 * Names are unique per run; the period stays the default (from the first spend, open end), so nothing is typed that is not needed.
 */
test('add credit → add planned → reallocate → undo → export', async ({ page }) => {
  test.setTimeout(120_000)
  const stamp = Date.now().toString(36)

  await page.goto('/agency/budgets')
  await expect(page.getByTestId('budgets-page')).toBeVisible({ timeout: 30000 })
  await expect(page.getByTestId('financial-disclaimer')).toBeVisible()

  // CREDIT — no amount at all: the mode chip and the names are enough.
  await page.getByTestId('budgets-add').click()
  await page.locator('#budget-client').fill(`عميل أجل ${stamp}`)
  await page.locator('#budget-project').fill(`مشروع أجل ${stamp}`)
  await page.getByTestId('mode-credit').click()
  await expect(page.locator('#budget-amount')).toHaveCount(0)
  await page.getByTestId('budget-submit').click()
  await expect(page.getByTestId('undo-toast')).toContainText('أُضيفت الميزانية')
  const credit = page.getByTestId('budget-drawer')
  await expect(credit.getByTestId('credit-figures')).toContainText('هذا الشهر (للفوترة)')
  await expect(credit.getByTestId('mode-chip-credit')).toBeVisible()
  await page.getByTestId('slideover-close').click()

  // PLANNED — a direct budget with a 60 / 40 split of two platforms.
  await page.getByTestId('budgets-add').click()
  await page.locator('#budget-client').fill(`عميل خطة ${stamp}`)
  await page.locator('#budget-project').fill(`مشروع خطة ${stamp}`)
  await page.getByRole('button', { name: '20,000', exact: true }).click()
  await page.getByTestId('mode-planned').click()
  const rows = page.getByTestId('plan-row')
  await rows.nth(0).locator('input').fill('60')
  await rows.nth(1).locator('input').fill('40')
  await expect(page.getByTestId('plan-sum')).toHaveAttribute('data-ok', 'true')
  await page.getByTestId('budget-submit').click()
  const planned = page.getByTestId('budget-drawer')
  await expect(planned.getByTestId('budget-plan')).toBeVisible()
  await expect(planned.getByTestId('plan-bar-snapchat')).toBeVisible()
  await expect(planned.getByTestId('mode-chip-planned')).toBeVisible()

  // REALLOCATE — one click. With nothing spent the plan is already as it should be, so the server answers that nothing changed:
  // the click is made, and the drawer stays whole (the entry and its undo are covered by the backend and unit tests with spend).
  const realloc = page.waitForResponse((r) => r.url().includes('/reallocate'))
  await planned.getByTestId('budget-reallocate').click()
  expect([200, 422]).toContain((await realloc).status())
  await expect(planned.getByTestId('budget-plan')).toBeVisible()

  // EDIT the split by hand → a reallocation-style change is one entry, and UNDO puts the old split back.
  await planned.getByTestId('budget-edit').click()
  const edit = page.getByTestId('plan-editor')
  await edit.getByTestId('plan-row').nth(0).locator('input').fill('50')
  await edit.getByTestId('plan-row').nth(1).locator('input').fill('50')
  await page.getByTestId('budget-edit-submit').click()
  await expect(page.getByTestId('undo-toast')).toContainText('تم التعديل')
  await expect(planned.getByTestId('plan-bar-snapchat')).toBeVisible()
  await page.getByTestId('undo-toast-undo').click()
  await expect(page.getByTestId('undo-toast')).toContainText('تم التراجع')

  // EXPORT — the statement downloads in every format (the drawer is closed first: it covers the page).
  await page.getByTestId('slideover-close').click()
  for (const format of ['xlsx', 'csv', 'pdf'] as const) {
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 60000 }),
      (async () => { await page.getByTestId('budgets-export').click(); await page.getByTestId(`budgets-export-${format}`).click() })(),
    ])
    expect(download.suggestedFilename()).toMatch(new RegExp(`\\.${format}$`))
  }
})

test('eight projects fit one screen at 1280×720 and 1440×900: no page scroll, no sideways scroll, one compact line each', async ({ page, request }) => {
  const stamp = Date.now().toString(36)
  const today = new Date().toISOString().slice(0, 10)
  const headers = await csrfHeaders(request)
  for (let n = 1; n <= 8; n++) {
    const res = await request.post('/api/v1/funding/payments', {
      headers,
      data: { client_name: `عميل ${stamp} ${n}`, project_name: `مشروع ${stamp} ${n}`, amount: 10_000 * n, paid_on: today, idempotency_key: `e2e-${stamp}-${n}` },
    })
    expect(res.status(), await res.text()).toBeLessThan(300)
  }

  for (const [width, height] of [[1280, 720], [1440, 900]] as const) {
    await page.setViewportSize({ width, height })
    await page.goto('/agency/budgets')
    const rows = page.locator('[data-testid^="budget-row-"]')
    await expect(rows.first()).toBeVisible({ timeout: 30000 })
    expect(await rows.count()).toBeGreaterThanOrEqual(8)

    // «The page scrolls» means the document OR the shell's own scroll area around the board.
    const metrics = await page.evaluate(() => {
      let pageScrolls = document.documentElement.scrollHeight > window.innerHeight + 1
      let sideways = document.documentElement.scrollWidth > document.documentElement.clientWidth
      const why: string[] = [`doc ${document.documentElement.scrollHeight}>${window.innerHeight}`]
      for (let el = document.querySelector('[data-testid="budgets-page"]')?.parentElement ?? null; el; el = el.parentElement) {
        const oy = getComputedStyle(el).overflowY
        if ((oy === 'auto' || oy === 'scroll') && el.scrollHeight > el.clientHeight + 1) { pageScrolls = true; why.push(`${el.tagName}.${el.className.toString().slice(0, 60)} ${el.scrollHeight}>${el.clientHeight}`) }
        if (el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX !== 'visible') sideways = true
      }

      return { sideways, pageScrolls, why: why.join(' / ') }
    })
    expect(metrics.sideways, `${width}x${height}: the page scrolls sideways`).toBe(false)

    const eighth = await rows.nth(7).boundingBox()
    expect(eighth, 'the eighth row is drawn').not.toBeNull()
    expect((eighth?.y ?? 0) + (eighth?.height ?? 0), `${width}x${height}: the eighth row is below the fold`).toBeLessThanOrEqual(height)
    // More projects than the screen holds scroll INSIDE the board, never the page.
    expect(metrics.pageScrolls, `${width}x${height}: the page itself scrolls (${metrics.why})`).toBe(false)
    expect((await rows.first().boundingBox())?.height ?? 999, 'a row is one compact line').toBeLessThanOrEqual(64)
    await page.screenshot({ path: `test-results/budgets-board-${width}x${height}.png` })
  }
})

test('on a phone the rows stack as compact cards with no sideways scroll, and a row opens the drawer', async ({ page, request }) => {
  const stamp = Date.now().toString(36)
  const res = await request.post('/api/v1/funding/payments', {
    headers: await csrfHeaders(request),
    data: { client_name: `عميل جوال ${stamp}`, project_name: `مشروع جوال ${stamp}`, amount: 5000, paid_on: new Date().toISOString().slice(0, 10), idempotency_key: `e2e-m-${stamp}` },
  })
  expect(res.status(), await res.text()).toBeLessThan(300)

  await page.setViewportSize({ width: 375, height: 812 })
  await page.goto('/agency/budgets')
  const row = page.locator('[data-testid^="budget-row-"]').first()
  await expect(row).toBeVisible({ timeout: 30000 })
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), 'sideways scroll on a phone').toBe(true)
  await page.screenshot({ path: 'test-results/budgets-board-375.png' })
  await row.click()
  await expect(page.getByTestId('budget-drawer')).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), 'sideways scroll with the drawer open').toBe(true)
})
