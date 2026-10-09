import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * LIVE-OPERATING-VIEW-001 — the consolidated live operating view on the Analytics page.
 *
 * The sentence comes first («not real-time»), then one row per bound source with its latest SUCCESSFUL
 * sync, its latest source timestamp, its next sync and its state, then the scheduler. Nothing on the
 * page says «real-time» / «لحظي» as a claim.
 */
const SEEDED_PROJECT = 'Growth — Acquisition'

test.use({ storageState: AUTH.advertiser })

async function openLive(page: import('@playwright/test').Page) {
  await selectProject(page, await seededProject(page.request, SEEDED_PROJECT))
  await page.goto('/app/analytics?tab=live')
  await expect(page.getByTestId('live-operating-view')).toBeVisible({ timeout: 30_000 })
}

test('the live view opens from the tab, says «not real-time» first, and lists the bound sources (ar)', async ({ page }) => {
  await openLive(page)
  await expect(page).toHaveURL(/[?&]tab=live/)

  const panel = page.getByTestId('live-operating-view')
  await expect(panel.getByTestId('live-not-realtime')).toContainText('لا لحظية')
  await expect(panel.getByTestId('live-verdict')).toBeVisible()

  // The seeded project reads from at least one source; every row states a next sync or the reason there is none.
  const sources = panel.locator('[data-testid^="live-source-"]')
  await expect(sources.first()).toBeVisible()
  const count = await sources.count()
  for (let i = 0; i < count; i++) {
    const provider = (await sources.nth(i).getAttribute('data-testid'))!.replace('live-source-', '')
    await expect(panel.getByTestId(`live-next-sync-${provider}`)).not.toBeEmpty()
    await expect(panel.getByTestId(`live-mechanisms-${provider}`)).toContainText(/Webhooks/)
  }

  await expect(panel.getByTestId('live-scheduler')).toBeVisible()
  await expect(panel.getByTestId('live-scheduler-integrations:sync')).toContainText('كل 30 دقيقة')

  // No page-level horizontal scroll.
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)
})

test('the same view in English never claims real-time', async ({ page }) => {
  await openLive(page)
  await switchToEnglish(page)

  const panel = page.getByTestId('live-operating-view')
  await expect(panel.getByTestId('live-not-realtime')).toContainText('not real-time')
  await expect(panel.getByTestId('live-scheduler-integrations:sync')).toContainText('Every 30 minutes')
  // Outside the sentence that denies it, the words never appear — no row, no label, no badge claims real-time.
  const banner = await panel.getByTestId('live-not-realtime').innerText()
  const text = (await panel.innerText()).replace(banner, '')
  expect(text.toLowerCase()).not.toContain('real-time')
  expect(text.toLowerCase()).not.toContain('realtime')
})
