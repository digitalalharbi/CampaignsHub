import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, switchToEnglish } from './helpers'

/**
 * LOGO-UPLOAD-001 — a client's logo is uploaded as a FILE from the client's settings: no address field, instant preview, replace,
 * remove — and the bytes served back are the bytes uploaded. Runs against the real API, so a refused upload, a wrong content type or
 * a preview that does not load all fail here.
 */
test.use({ storageState: AUTH.owner })

// 1×1 PNGs of different colours: different bytes, so a replace is observable.
const RED = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==', 'base64')
const BLUE = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPj/HwADBwIAMCbHYQAAAABJRU5ErkJggg==', 'base64')

test('upload, preview, replace and remove a client logo from its settings', async ({ page }) => {
  const list = await page.request.get('/api/v1/app/clients', { headers: API_HEADERS })
  const clientId = (await list.json()).data[0].id as string

  await page.goto(`/agency/clients/${clientId}`)
  await switchToEnglish(page)
  await page.getByRole('main').getByRole('button', { name: /^Settings$/ }).click()

  const field = page.getByTestId('client-logo-field')
  await expect(field).toBeVisible()
  expect(await page.locator('input[type="url"]').count(), 'no address field for a logo').toBe(0)

  const state = async () => (await (await page.request.get(`/api/v1/app/clients/${clientId}/logo`, { headers: API_HEADERS })).json()).data

  // Start from a known state.
  if ((await state()).own) await page.getByTestId('client-logo-remove').click()
  await expect.poll(async () => (await state()).own).toBe(false)

  await page.getByTestId('client-logo-input').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: RED })
  await expect.poll(async () => (await state()).own, { timeout: 15_000 }).toBe(true)
  const preview = page.getByTestId('client-logo-preview').locator('img')
  await expect(preview).toHaveAttribute('src', /\/logo\/file\?v=/)
  const first = await page.request.get((await state()).url, { headers: API_HEADERS })
  expect(first.status()).toBe(200)
  expect(first.headers()['content-type']).toContain('image/png')
  expect(Buffer.compare(await first.body(), RED)).toBe(0)

  // Replace: same slot, new bytes, one logo.
  await page.getByTestId('client-logo-input').setInputFiles({ name: 'logo2.png', mimeType: 'image/png', buffer: BLUE })
  await expect.poll(async () => Buffer.compare(await (await page.request.get((await state()).url, { headers: API_HEADERS })).body(), BLUE), { timeout: 15_000 }).toBe(0)

  // A wrong file is refused before it is sent.
  await page.getByTestId('client-logo-input').setInputFiles({ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') })
  await expect(page.getByTestId('client-logo-problem')).toBeVisible()
  expect((await state()).own).toBe(true)

  // Remove.
  await page.getByTestId('client-logo-remove').click()
  await expect.poll(async () => (await state()).own).toBe(false)
  await expect(page.getByTestId('client-logo-choose')).toContainText(/Upload/)
})

test('the logo control fits a phone without sideways scroll', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 700 })
  const list = await page.request.get('/api/v1/app/clients', { headers: API_HEADERS })
  const clientId = (await list.json()).data[0].id as string
  await page.goto(`/agency/clients/${clientId}`)
  await switchToEnglish(page)
  await page.getByRole('main').getByRole('button', { name: /^Settings$/ }).click()
  await expect(page.getByTestId('client-logo-field')).toBeVisible()
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow).toBeLessThanOrEqual(0)
})
