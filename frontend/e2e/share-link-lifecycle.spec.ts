import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * REPORT-LINK-COPY-001 and REPORT-SHARE-MODES-001 — the lifecycle of a published link, against the real API.
 *
 * An operator could extend, pause and resume a link but not copy it again, so the only way to resend one was to make a second link —
 * with its own expiry and password, leaving the first alive. Copying now asks the server for the SAME link's address. The manager also
 * says, per link, whether it is a frozen snapshot, live over fixed dates or live rolling, and the period it covers.
 */
test.use({ storageState: AUTH.advertiser })

async function api<T>(page: Page, method: string, url: string, body?: unknown): Promise<{ status: number; json: T }> {
  return page.evaluate(async ({ method, url, body }) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
    const res = await fetch(url, {
      method,
      credentials: 'include',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
      body: body === undefined ? undefined : JSON.stringify(body),
    })

    return { status: res.status, json: await res.json().catch(() => ({})) }
  }, { method, url, body })
}

test.describe('a published report link', () => {
  test('is copied again as the same link, states its mode and period, and lets the report manage pause and resume', async ({ page }) => {
    await page.goto('/app/reports')
    await expect(page.locator('main')).toBeVisible({ timeout: 20000 })

    const projects = await api<{ data: Array<{ id: string }> }>(page, 'GET', '/api/v1/projects')
    const project = projects.json.data[0].id
    const options = await api<{ data: { ad_accounts: Array<{ id: string }> } }>(page, 'GET', `/api/v1/projects/${project}/reports/live/options`)
    const account = options.json.data.ad_accounts[0].id
    const name = `E2E lifecycle ${Date.now()}`
    const made = await api<{ data: { report_id: string; share_id: string; token: string } }>(page, 'POST', `/api/v1/projects/${project}/reports/live`, {
      name, account_ids: [account], from: '2026-10-18', to: '2026-10-24', period_kind: 'custom',
    })
    expect(made.status).toBe(201)
    const { report_id: reportId, share_id: shareId, token } = made.json.data

    const countShares = async () => (await api<{ data: unknown[] }>(page, 'GET', `/api/v1/projects/${project}/reports/${reportId}/shares`)).json.data.length
    expect(await countShares()).toBe(1)

    // Copy returns the exact address that was sent — and creates nothing.
    const copy = await api<{ data: { url: string; alias_minted: boolean } }>(page, 'GET', `/api/v1/projects/${project}/reports/${reportId}/shares/${shareId}/link`)
    expect(copy.status).toBe(200)
    expect(copy.json.data.url).toContain(`/r/${token}`)
    expect(copy.json.data.alias_minted).toBe(false)
    expect(await countShares()).toBe(1)

    // The manager, in the UI: the row states the mode and the period, and offers Copy next to Pause.
    await page.reload()
    await page.getByPlaceholder(/ابحث|Search/).first().fill(name).catch(() => undefined)
    const row = page.locator('tr', { hasText: name }).first()
    await expect(row).toBeVisible({ timeout: 20000 })
    await row.getByRole('button', { name: /مشاركة رابط آمن|Share a secure link/ }).click()

    const shareRow = page.getByTestId('share-row').first()
    await expect(shareRow).toBeVisible({ timeout: 15000 })
    await expect(page.getByTestId(`share-mode-${shareId}`)).toHaveAttribute('data-kind', 'fixed')
    await expect(page.getByTestId(`share-period-${shareId}`)).toContainText('2026-10-18')
    await expect(page.getByTestId(`share-period-${shareId}`)).toContainText('2026-10-24')

    const copied = page.waitForResponse((r) => /\/shares\/[^/]+\/link$/.test(r.url()) && r.request().method() === 'GET')
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => undefined)
    await page.getByTestId(`share-copy-${shareId}`).click()
    expect((await copied).status()).toBe(200)
    expect(await countShares(), 'copying must never create a replacement link').toBe(1)

    // Pause keeps the link and its address; Copy still returns it; Resume restores it.
    await page.getByTestId(`share-pause-${shareId}`).click()
    await expect(page.getByTestId(`share-pause-${shareId}`)).toHaveAttribute('aria-pressed', 'true', { timeout: 15000 })
    const whilePaused = await api<{ data: { url: string } }>(page, 'GET', `/api/v1/projects/${project}/reports/${reportId}/shares/${shareId}/link`)
    expect(whilePaused.json.data.url).toBe(copy.json.data.url)
    await page.getByTestId(`share-pause-${shareId}`).click()
    await expect(page.getByTestId(`share-pause-${shareId}`)).toHaveAttribute('aria-pressed', 'false', { timeout: 15000 })
    expect(await countShares()).toBe(1)
  })

  test('the share dialog offers Live and Snapshot as an explicit choice', async ({ page }) => {
    await page.goto('/app/reports')
    await expect(page.locator('main')).toBeVisible({ timeout: 20000 })
    const row = page.locator('tr').filter({ has: page.getByRole('button', { name: /مشاركة رابط آمن|Share a secure link/ }) }).first()
    test.skip((await row.count()) === 0, 'the seeded project has no saved report to share')
    await row.getByRole('button', { name: /مشاركة رابط آمن|Share a secure link/ }).click()

    const modes = page.getByTestId('share-mode')
    await expect(modes).toBeVisible({ timeout: 15000 })
    await expect(modes.getByTestId('share-snapshot')).toBeVisible()
    await expect(modes.getByTestId('share-live')).toBeVisible()
    await modes.getByTestId('share-live').check()
    await expect(page.getByTestId('share-scope')).toBeVisible()
    await modes.getByTestId('share-snapshot').check()
    await expect(page.getByTestId('share-scope')).toHaveCount(0)
  })
})
