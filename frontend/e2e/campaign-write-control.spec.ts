import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * CAMPAIGN-MGMT-DOMAIN-001 / CAMPAIGN-MGMT-SURFACE-001 — the campaign's Settings tab tells the truth
 * about write control: per provider, per capability, what CampaignsHub can write. Since
 * CAMPAIGN-MGMT-WRITE-001 the adapters implement real writes, so those cells read «awaiting
 * credentials» wherever the platform's app is not configured — as in this gate — and nothing is
 * allowed or verified. What a platform forbids says so; what is not built says so. No button is drawn
 * here in either language: the writes run from the «Platform control» tab.
 */
const SEEDED_PROJECT = 'Growth — Acquisition'
const SEEDED_CAMPAIGN = 'Always-On — Sales'

test.use({ storageState: AUTH.advertiser })

async function openSettings(page: import('@playwright/test').Page) {
  await selectProject(page, await seededProject(page.request, SEEDED_PROJECT))
  await page.goto('/app/campaigns')
  await page.getByTestId('view-cards').click()
  await expect(page.getByTestId('campaign-card').first()).toBeVisible({ timeout: 20_000 })
  await page.getByTestId('campaign-card').filter({ hasText: SEEDED_CAMPAIGN }).first().click()
  await expect(page).toHaveURL(/\/campaigns\/[^/]+\/[^/]+$/)
  await page.getByRole('tab', { name: /Settings|الإعدادات/ }).click()
  await expect(page).toHaveURL(/[?&]tab=settings/)
  await expect(page.getByTestId('campaign-write-control')).toBeVisible({ timeout: 20_000 })
}

test('the Settings tab states the write-control rule and each capability’s honest state (ar)', async ({ page }) => {
  await openSettings(page)

  const panel = page.getByTestId('campaign-write-control')
  await expect(panel.getByTestId('write-control-rule')).toContainText('منفَّذة ومقيَّدة بصلاحية')

  // Seven providers × fifteen capabilities, each with one honest state.
  const chips = panel.locator('[data-testid^="write-cap-status-"]')
  await expect(chips).toHaveCount(7 * 15)
  const awaiting = await chips.filter({ hasText: 'بانتظار بيانات الاعتماد' }).count()
  expect(awaiting, 'the adapters implement real writes, awaiting the platforms’ credentials here').toBeGreaterThan(0)
  await expect(panel.getByTestId('write-control-implemented')).toContainText(String(awaiting))
  await expect(panel.getByTestId('write-control-verified')).toContainText('0')
  await expect(panel.getByTestId('write-control-allowed')).toContainText('0')
  await expect(panel.getByTestId('write-control-where')).toContainText('التحكم على المنصات')
  await expect(panel.getByTestId('write-cap-status-meta-pause_resume')).toHaveText('بانتظار بيانات الاعتماد')
  await expect(panel.getByTestId('write-cap-status-google-objective')).toHaveText('لا تسمح به المنصة')
  await expect(panel.getByTestId('write-cap-status-linkedin-pause_resume')).toHaveText('غير منفَّذ')

  // The rule, enforced: nothing in this panel is a control.
  await expect(panel.getByRole('button')).toHaveCount(0)

  // No page-level horizontal scroll at the gate's viewport.
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)
})

test('the same truth in English', async ({ page }) => {
  await openSettings(page)
  await switchToEnglish(page)

  const panel = page.getByTestId('campaign-write-control')
  await expect(panel.getByTestId('write-control-rule')).toContainText('implemented and permission-gated')
  await expect(panel.getByTestId('write-cap-status-meta-budget_change')).toHaveText('Awaiting credentials')
  await expect(panel.getByTestId('write-cap-status-google-duplicate')).toHaveText('The platform does not allow it')
  await expect(panel.getByTestId('write-control-where')).toContainText('Platform control')
  await expect(panel.getByRole('button')).toHaveCount(0)
})
