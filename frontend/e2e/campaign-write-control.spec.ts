import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * CAMPAIGN-MGMT-DOMAIN-001 / CAMPAIGN-MGMT-SURFACE-001 — the campaign's Settings tab tells the truth
 * about write control: per provider, per capability, what CampaignsHub can write, and that today it
 * can write nothing. No button is drawn for a provider write, in either language.
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

test('the Settings tab states the write-control rule and the honest zero (ar)', async ({ page }) => {
  await openSettings(page)

  const panel = page.getByTestId('campaign-write-control')
  await expect(panel.getByTestId('write-control-rule')).toContainText('منفَّذة ومقيَّدة بصلاحية')
  await expect(panel.getByTestId('write-control-implemented')).toContainText('0')
  await expect(panel.getByTestId('write-control-verified')).toContainText('0')
  await expect(panel.getByTestId('write-control-allowed')).toContainText('0')
  await expect(panel.getByTestId('write-control-none')).toBeVisible()

  // Seven providers × fourteen capabilities, every one of them honestly «غير منفَّذ».
  await expect(panel.locator('[data-testid^="write-cap-status-"]')).toHaveCount(7 * 14)
  await expect(panel.locator('[data-testid^="write-cap-status-"]').filter({ hasText: 'غير منفَّذ' })).toHaveCount(7 * 14)
  await expect(panel.getByTestId('write-cap-status-snapchat-publish')).toHaveText('غير منفَّذ')

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
  await expect(panel.getByTestId('write-cap-status-meta-budget_change')).toHaveText('Not implemented')
  await expect(panel.getByTestId('write-control-none')).toContainText('read-and-monitor only')
  await expect(panel.getByRole('button')).toHaveCount(0)
})
