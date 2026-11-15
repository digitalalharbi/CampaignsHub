import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * PROJECT-LIFECYCLE-001 §8, §10 UI (builder N2) — workspace settings → projects, end to end, in the agency and the
 * direct-business workspace (the client-portal user has no lifecycle permission: its API refusal is asserted).
 *
 * Self-contained: every project this touches is CREATED here first, so no seeded project is archived, deleted or
 * purged. Desktop (1366) and phone (390): 100% (never 10000%), Arabic status badges (never «active»), filters, the
 * windows, view-only archived page, trash, restore, permanent delete — and no horizontal scroll.
 */

const SHOTS = process.env.N2_SHOTS_DIR

async function noSideScroll(page: Page, where: string) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, `${where} scrolls sideways by ${overflow}px`).toBeLessThanOrEqual(1)
}

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false })
}

async function createProject(page: Page, name: string, needsClient: boolean) {
  if (needsClient) {
    const client = page.locator('#pj-client')
    const first = await client.locator('option').nth(1).getAttribute('value')
    await client.selectOption(first ?? '')
  }
  await page.locator('#pj-name').fill(name)
  await page.getByRole('button', { name: /^(إنشاء|Create)$/ }).click()
}

async function rowActions(page: Page, name: string) {
  const row = page.locator('tr, li').filter({ hasText: name }).first()
  await expect(row, `«${name}» is not listed`).toBeVisible({ timeout: 20_000 })

  return row
}

for (const ws of [
  { type: 'agency', storage: AUTH.owner, base: '/agency', needsClient: true },
  { type: 'direct business', storage: AUTH.advertiser, base: '/app', needsClient: false },
] as const) {
  for (const width of [1366, 390]) {
    test.describe(`${ws.type} @${width}`, () => {
      test.use({ storageState: ws.storage, viewport: { width, height: width > 500 ? 860 : 844 } })

      test('archive → view-only → restore → delete → trash → restore → delete → purge', async ({ page }) => {
        test.setTimeout(180_000)
        const name = `دورة ${ws.type === 'agency' ? 'وكالة' : 'نشاط'} ${width} ${Date.now().toString().slice(-6)}`
        await page.goto(`${ws.base}/settings/workspace?tab=projects`)
        await expect(page.getByTestId('settings-projects-filter')).toBeVisible({ timeout: 30_000 })

        // The seeded list: every setup figure is a whole percent ≤ 100 and no raw status is printed.
        const body = (await page.locator('main').innerText()).replace(/\s+/g, ' ')
        expect(body).not.toMatch(/\b\d{4,}%/)
        expect(body).not.toMatch(/\b(active|archived|paused|draft|deleted)\b/)
        await noSideScroll(page, 'the projects table')
        await shot(page, `table-${ws.type.replace(' ', '-')}-${width}`)

        await createProject(page, name, ws.needsClient)
        let row = await rowActions(page, name)
        await expect(row).toContainText('مسودة') // a new project starts as a draft (N1)
        await expect(row).toContainText(/\b\d{1,3}%/)

        // Archive (window with shared-account warning slot + platforms statement).
        await row.getByText('أرشفة').click()
        await expect(page.getByTestId('platforms-untouched')).toBeVisible()
        await shot(page, `archive-window-${ws.type.replace(' ', '-')}-${width}`)
        await page.getByTestId('lifecycle-archive-confirm').click()
        await expect(page.getByTestId('settings-project-notice')).toBeVisible({ timeout: 20_000 })

        await page.getByTestId('settings-projects-filter-archived').click()
        row = await rowActions(page, name)
        await expect(row).toContainText('مؤرشف')
        await row.getByRole('link', { name: /فتح/ }).click()

        // View-only archived page, not a redirect to the client.
        await expect(page.getByTestId('archived-project-page')).toBeVisible({ timeout: 20_000 })
        await expect(page.getByTestId('archived-project-banner')).toContainText('للعرض فقط')
        await noSideScroll(page, 'the archived project page')
        await shot(page, `view-only-${ws.type.replace(' ', '-')}-${width}`)
        await page.getByTestId('archived-project-restore').click()
        await page.getByTestId('lifecycle-restore-confirm').click()
        await expect(page.getByTestId('settings-projects-filter')).toBeVisible({ timeout: 20_000 })

        // Delete → trash.
        await page.getByTestId('settings-projects-filter-active').click()
        row = await rowActions(page, name)
        await row.getByText('حذف', { exact: true }).click()
        await expect(page.getByTestId('lifecycle-delete-impact')).toBeVisible({ timeout: 20_000 })
        await expect(page.getByTestId('lifecycle-delete-confirm')).toBeDisabled()
        await page.getByTestId('lifecycle-delete-name').fill(name)
        await shot(page, `delete-window-${ws.type.replace(' ', '-')}-${width}`)
        await page.getByTestId('lifecycle-delete-confirm').click()
        await expect(page.getByTestId('settings-project-notice')).toContainText('المحذوفات', { timeout: 20_000 })

        await page.getByTestId('settings-projects-filter-trash').click()
        row = await rowActions(page, name)
        await expect(row).toContainText('محذوف')
        await expect(row.getByText('فتح')).toHaveCount(0)
        await shot(page, `trash-${ws.type.replace(' ', '-')}-${width}`)
        await row.getByText('استعادة').click()
        await page.getByTestId('lifecycle-restore-confirm').click()
        await expect(page.getByTestId('settings-project-notice')).toContainText('استعادة', { timeout: 20_000 })

        // Delete again and purge for good (only this freshly created project).
        await page.getByTestId('settings-projects-filter-active').click()
        row = await rowActions(page, name)
        await row.getByText('حذف', { exact: true }).click()
        await page.getByTestId('lifecycle-delete-name').fill(name)
        await page.getByTestId('lifecycle-delete-confirm').click()
        await expect(page.getByTestId('settings-project-notice')).toBeVisible({ timeout: 20_000 })
        await page.getByTestId('settings-projects-filter-trash').click()
        row = await rowActions(page, name)
        await row.getByText('حذف نهائي', { exact: true }).click()
        await page.getByTestId('lifecycle-purge-name').fill(name)
        await page.getByTestId('lifecycle-purge-confirm').click()
        await expect(page.getByTestId('settings-project-notice')).toContainText('نهائيًا', { timeout: 20_000 })
        await expect(page.locator('tr, li').filter({ hasText: name })).toHaveCount(0)
      })
    })
  }
}

test.describe('client portal user', () => {
  test.use({ storageState: AUTH.client })

  test('is refused by the lifecycle API', async ({ page, request }) => {
    await page.goto('/portal')
    const list = await page.request.get('/api/v1/projects?status=deleted', { headers: { Accept: 'application/json' } })
    expect([401, 403]).toContain(list.status())
    void request
  })
})
