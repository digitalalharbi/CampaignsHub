import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * CAMPAIGNS-TABLE-002 — rows are selected and a bulk pause/resume is offered, confirmed before anything is sent.
 *
 * Nothing is sent to a platform here: the dialog is opened and dismissed. A real pause on a provider is an
 * authorised Owner action on a real account and is never exercised by a test.
 */
test.use({ storageState: AUTH.owner })

test('ticking rows counts them, select-all takes the page, and the pause dialog names them before sending', async ({ page, request }) => {
  await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
  await page.goto('/agency/campaigns?view=table')

  const table = page.getByTestId('campaigns-table')
  await expect(table).toBeVisible({ timeout: 30_000 })
  const rows = table.locator('[data-testid="campaign-row"]')
  await expect(rows.first()).toBeVisible()
  await expect(page.getByTestId('campaigns-bulk')).toHaveCount(0)

  await rows.first().locator('input[type="checkbox"]').check()
  await expect(page.getByTestId('campaigns-bulk-count')).toHaveText(/1/)

  await page.getByTestId('campaigns-select-all').check()
  const total = await rows.count()
  await expect(page.getByTestId('campaigns-bulk-count')).toHaveText(new RegExp(String(total)))

  await page.getByTestId('campaigns-bulk-pause').click()
  const dialog = page.getByTestId('campaigns-bulk-dialog')
  await expect(dialog).toBeVisible()
  await expect(dialog.locator('li')).toHaveCount(total)
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)

  await page.getByTestId('campaigns-select-all').uncheck()
  await expect(page.getByTestId('campaigns-bulk')).toHaveCount(0)
})
