import { expect, request as playwrightRequest, test, type APIRequestContext } from '@playwright/test'
import { E2E_API_TARGET } from './env'
import { AUTH } from './helpers'

/**
 * «الطلبات» in the platform console (PLATFORM-SERVICE-INBOX-001).
 *
 * A service ordered on the official CampaignsHub host belongs to the platform owner. The spec orders one the
 * way a visitor's browser does — against the API, on the official host, with a verified phone and address —
 * and then works it as the owner: it is listed, opened, given an owner and moved on.
 *
 * The same order placed on the development host is the local default workspace's, as it always was, and must
 * NOT appear here: that is the wall between the platform's requests and a subscriber's.
 */

const OFFICIAL_HOST = 'campaignshub.io'

async function verify(api: APIRequestContext, channel: 'sms' | 'email', destination: string): Promise<string> {
  const start = await api.post('/api/v1/requests/verify/start', { data: { channel, destination, purpose: 'contact_verify' } })
  expect(start.ok(), `verify/start ${channel}: ${start.status()}`).toBeTruthy()
  const { verification_id: id, dev_code: code } = (await start.json()).data
  expect(code, 'the e2e backend must expose the development code').toBeTruthy()
  const check = await api.post('/api/v1/requests/verify/check', { data: { verification_id: id, code } })
  expect(check.ok(), `verify/check ${channel}: ${check.status()}`).toBeTruthy()

  return id as string
}

async function order(host: string | null, company: string): Promise<{ status: number; reference: string | null }> {
  const api = await playwrightRequest.newContext({
    baseURL: E2E_API_TARGET,
    extraHTTPHeaders: { Accept: 'application/json', ...(host ? { Host: host } : {}) },
  })
  const stamp = `${Date.now()}${Math.floor(Math.random() * 1000)}`
  const email = `visitor-${stamp}@example.test`
  const phone = `+96650${stamp.slice(-7)}`

  const response = await api.post('/api/v1/requests', {
    data: {
      type: 'paid_campaign_launch', contact_name: 'E2E Visitor', contact_email: email, contact_phone: phone, company_name: company,
      phone_verification_id: await verify(api, 'sms', phone),
      email_verification_id: await verify(api, 'email', email),
    },
  })
  const body = response.ok() ? await response.json() : null
  await api.dispose()

  return { status: response.status(), reference: body?.data?.reference ?? null }
}

test.describe('the platform console — service requests', () => {
  test.use({ storageState: AUTH.admin })

  test('a service ordered on the official site is listed, owned and moved on by the platform owner', async ({ page }, testInfo) => {
    const company = `Platform Order ${testInfo.project.name} ${Date.now()}`
    const mine = await order(OFFICIAL_HOST, company)
    expect(mine.status, 'the official host must be accepted as the platform').toBe(201)

    // The same order on the development host is the local default workspace's, not the platform's.
    const theirs = await order(null, `Workspace Order ${testInfo.project.name} ${Date.now()}`)

    await page.goto('/admin/requests')
    await expect(page.getByTestId('admin-service-requests')).toBeVisible()
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(/^(الطلبات|Requests)$/)

    const row = page.getByTestId(`service-request-${mine.reference}`)
    await expect(row).toBeVisible()
    await expect(row).toContainText(company)
    if (theirs.reference) {
      await expect(page.getByTestId(`service-request-${theirs.reference}`)).toHaveCount(0)
    }
    expect(Number(await page.getByTestId('service-requests-count-unassigned').innerText())).toBeGreaterThanOrEqual(1)

    await row.getByRole('button').click()
    const detail = page.getByTestId('service-request-detail')
    await expect(detail).toContainText(mine.reference!)
    await expect(detail).toContainText('@example.test')

    const owner = page.getByTestId('service-request-owner')
    await owner.selectOption({ index: 1 })
    await page.getByTestId('service-request-status').selectOption('under_review')
    await page.getByTestId('service-request-save').click()
    await expect(detail.getByRole('status')).toBeVisible()

    // Reloaded from the server: the owner and the status were stored, and the link names the request.
    await page.reload()
    await expect(page).toHaveURL(/open=/)
    await expect(page.getByTestId('service-request-status')).toHaveValue('under_review')
    await expect(page.getByTestId('service-request-owner')).not.toHaveValue('')
    await expect(page.getByTestId('service-request-activity').locator('li')).toHaveCount(3)
  })

  test('the screen is on the rail and holds together on a phone', async ({ page }) => {
    await page.goto('/admin')
    await expect(page.locator('a[href="/admin/requests"]').first()).toBeAttached()

    await page.setViewportSize({ width: 375, height: 812 })
    await page.goto('/admin/requests')
    await expect(page.getByTestId('admin-service-requests')).toBeVisible()
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)
    expect(overflow, 'the requests screen scrolls sideways on a phone').toBe(false)
  })
})
