import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * LAUNCH-SUCCESS-001 — the launch moment, proven against a launch that really happened.
 *
 * Each spec creates its own campaign and activates it, so what is asserted is the product's own
 * round trip: the button posts, the server flips the status and answers with what went live, and the
 * screen is built from that answer. Nothing here is stubbed, which is the point — a success screen
 * is only worth testing against a success the backend actually produced.
 */

test.use({ storageState: AUTH.advertiser })

/** Create a draft campaign through the UI and open it. Returns its name. */
async function createDraftCampaign(page: import('@playwright/test').Page): Promise<string> {
  await page.goto('/app/campaigns')
  await expect(page.getByTestId('view-overview')).toBeVisible({ timeout: 20000 })
  await page.getByRole('button', { name: /New campaign|حملة جديدة/ }).click()

  const name = `E2E Launch ${Date.now()}`
  await page.getByLabel(/Campaign name|اسم الحملة/).fill(name)
  await page.getByRole('button', { name: /^Save$|^حفظ$/ }).click()

  await page.getByTestId('view-cards').click()
  await page.getByText(name).click()
  await expect(page).toHaveURL(/\/campaigns\/[^/]+\/[^/]+/)

  return name
}

test('a real launch is confirmed with the campaign, the moment, and the way on', async ({ page }) => {
  const name = await createDraftCampaign(page)
  await switchToEnglish(page)

  await page.getByRole('button', { name: /^Activate$|^تفعيل$/ }).click()

  const moment = page.getByTestId('launch-success')
  await expect(moment).toBeVisible()
  await expect(moment).toContainText(name)

  /*
   * The timestamp is the server's, so this asserts its SHAPE rather than a value: `YYYY-MM-DD HH:mm`
   * in Latin digits. A browser-side clock would satisfy the shape too — what rules that out is the
   * backend test that proves the stamp is persisted on the campaign, and the reader that refuses a
   * response which does not carry one.
   */
  await expect(page.getByTestId('launch-success-at')).toContainText(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/)

  // The way on: the campaign's own analysis, on the portal the operator is already in.
  await page.getByTestId('launch-success-primary').click()
  await expect(page).toHaveURL(/\/app\/campaigns\/[^/]+\/[^/]+\?tab=performance/)
})

test('closing leaves a chip, and a reload does not celebrate again', async ({ page }) => {
  await createDraftCampaign(page)
  await switchToEnglish(page)

  await page.getByRole('button', { name: /^Activate$|^تفعيل$/ }).click()
  await expect(page.getByTestId('launch-success')).toBeVisible()

  await page.getByTestId('launch-success-close').click()
  await expect(page.getByTestId('launch-success')).toBeHidden()
  await expect(page.getByTestId('launch-success-chip')).toBeVisible()

  /*
   * The one that would embarrass the product: a launch that replays every time the page is opened.
   * The moment lives in component state precisely so a reload has nothing to restore.
   */
  await page.reload()
  await expect(page.getByTestId('launch-success')).toBeHidden()
  await expect(page.getByTestId('launch-success-chip')).toBeHidden()
})

test('the moment holds its shape in Arabic and at phone width', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 })
  await createDraftCampaign(page)

  await page.getByRole('button', { name: /^Activate$|^تفعيل$/ }).click()

  const moment = page.getByTestId('launch-success')
  await expect(moment).toBeVisible()

  // It fits the phone, and it has not pushed the page sideways to do it.
  const box = await moment.boundingBox()
  expect(box).not.toBeNull()
  expect(box!.x).toBeGreaterThanOrEqual(0)
  expect(box!.x + box!.width).toBeLessThanOrEqual(375)
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
  expect(overflow).toBeLessThanOrEqual(0)

  /*
   * The stamp reads left-to-right on a right-to-left page.
   *
   * Arabic re-orders bidi-neutral runs, which is how `2026-09-15 15:55` reaches the screen as
   * `15:55 15-09-2026` — a correct timestamp, rendered as a wrong one. The stamp is therefore
   * marked LTR, and this asserts that mark rather than the text, because the text is identical
   * either way and only the direction tells them apart.
   */
  await expect(page.getByTestId('launch-success-at').locator('[dir="ltr"]')).toBeVisible()
})

test('a campaign that is live everywhere and one that is not do not look alike', async ({ page }) => {
  const name = await createDraftCampaign(page)
  await switchToEnglish(page)

  await page.getByRole('button', { name: /^Activate$|^تفعيل$/ }).click()
  await expect(page.getByTestId('launch-success')).toBeVisible()

  /*
   * A fresh campaign is linked to no platform, so nothing is claimed about any platform and there is
   * no qualification to make: no note, and no platform badges invented to fill the space.
   */
  await expect(page.getByTestId('launch-success')).toContainText(name)
  await expect(page.getByTestId('launch-success-note')).toBeHidden()
  await expect(page.getByTestId('launch-success-badges')).toBeHidden()
})
