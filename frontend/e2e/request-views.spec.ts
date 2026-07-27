import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH } from './helpers'

/** The internal dashboard offers Table / Kanban / Cards over the same data. Runs as the authenticated owner. */
test.use({ storageState: AUTH.owner })

test('dashboard switches between Table, Kanban and Cards views', async ({ page }) => {
  // Ensure at least one request exists for the portal tenant (the owner's agency) via public intake.
  await page.request.post('/api/v1/requests', {
    headers: API_HEADERS,
    data: { type: 'consulting', contact_name: 'Views Seed', contact_email: 'views@example.com', objective: 'Seed a request for the views test.' },
  })

  await page.goto('/app/requests')
  await expect(page.getByRole('heading', { name: /الطلبات|Requests/ })).toBeVisible()
  await page.getByRole('button', { name: 'table' }).click() // known starting view

  // Table view renders request rows (the reference link).
  await expect(page.getByRole('link', { name: /REQ-\d{4}-[A-Z0-9]{6}/ }).first()).toBeVisible()

  // Switch to Kanban — cards become draggable across status columns.
  await page.getByRole('button', { name: 'kanban' }).click()
  await expect(page.locator('[draggable="true"]').first()).toBeVisible()

  // Switch to Cards — the reference still renders and the preference persists across reload.
  await page.getByRole('button', { name: 'cards' }).click()
  await expect(page.getByRole('link', { name: /REQ-\d{4}-[A-Z0-9]{6}/ }).first()).toBeVisible()
  await page.reload()
  await expect(page.locator('[draggable="true"]')).toHaveCount(0) // cards view has no draggables
  await expect(page.getByRole('link', { name: /REQ-\d{4}-[A-Z0-9]{6}/ }).first()).toBeVisible()
})
