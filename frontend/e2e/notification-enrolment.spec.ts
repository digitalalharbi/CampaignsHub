import { expect, test, type Page } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * NOTIFICATION-ENROLMENT-001 — Owner, 2026-11-06: «الفكرة كل مستخدم يحتاج إشعارات وملخصات على الإيميل، هل هي فعّالة أو لا؟»
 *
 * The settings screen answers by doing it: a «أرسل لي الآن» chip on each digest row and one for a sample alert, each calling
 * its own endpoint for the signed-in person and printing ONE short word. This gate has no mail provider, so the honest
 * answers are «not connected», «no activity», «nothing in your scope», «save your preferences first» or «sent» — never a raw
 * key, never a silence. Nothing here reaches an inbox.
 *
 * `NOTIF_SHOTS=<dir>` also writes the screenshots (dark and light, desktop and 375px) that a human reads.
 */
const SETTINGS = '/agency/settings/workspace?tab=notifications'
const HONEST = /Sent|Email is not connected|No activity in the period|Nothing in your scope|Save your preferences first|Email is off for you|Quiet hours|Held for the digest|Your address is not verified|Try again in a minute|Already sent/

async function open(page: Page, theme: 'dark' | 'light' = 'dark'): Promise<void> {
  await page.addInitScript((t) => { try { localStorage.setItem('campaign-hub-theme', t) } catch { /* private window */ } }, theme)
  await page.goto(SETTINGS)
  await expect(page.getByTestId('channels-panel')).toBeVisible({ timeout: 30000 })
}

test.describe('settings chips', () => {
  test.use({ storageState: AUTH.owner })

  test('each digest row has a send-now chip and a press answers in one honest word', async ({ page }) => {
    await open(page)
    await switchToEnglish(page)

    for (const kind of ['daily', 'weekly', 'monthly']) {
      const chip = page.getByTestId(`send-now-${kind}`)
      await expect(chip).toBeVisible()
      await expect(chip).toHaveText('Send me now')
    }

    await page.getByTestId('send-now-daily').click()
    await expect(page.getByTestId('send-now-daily-result')).toHaveText(HONEST, { timeout: 20000 })
    // The press names no raw key.
    await expect(page.getByTestId('send-now-daily-result')).not.toHaveText(/_/)
    // The other rows did not move or answer.
    await expect(page.getByTestId('send-now-weekly-result')).toHaveText('')
  })

  test('the sample-alert chip answers in one honest word', async ({ page }) => {
    await open(page)
    await switchToEnglish(page)

    await expect(page.getByTestId('send-sample-alert')).toHaveText('Send a sample alert')
    await page.getByTestId('send-sample-alert').click()
    await expect(page.getByTestId('send-sample-alert-result')).toHaveText(HONEST, { timeout: 20000 })
  })

  for (const theme of ['dark', 'light'] as const) {
    for (const [label, size] of [['desktop', { width: 1280, height: 900 }], ['375', { width: 375, height: 812 }]] as const) {
      test(`the chips fit without sideways page scroll — ${theme}, ${label}`, async ({ page }) => {
        await page.setViewportSize(size)
        await open(page, theme)

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
        expect(overflow, 'the page scrolls sideways').toBeLessThanOrEqual(0)

        if (process.env.NOTIF_SHOTS) {
          await page.getByTestId('send-sample-alert').click()
          await expect(page.getByTestId('send-sample-alert-result')).not.toHaveText(/^(…)?$/)
          await page.getByTestId('send-sample-alert').scrollIntoViewIfNeeded()
          await page.screenshot({ path: `${process.env.NOTIF_SHOTS}/chips-${theme}-${label}-sample.png` })
          await page.getByTestId('send-now-weekly').click()
          await expect(page.getByTestId('send-now-weekly-result')).not.toHaveText(/^(…)?$/)
          const row = page.getByTestId('send-now-weekly').locator('xpath=ancestor::div[contains(@class,"rounded-2xl")][1]')
          await row.scrollIntoViewIfNeeded()
          await row.screenshot({ path: `${process.env.NOTIF_SHOTS}/chips-${theme}-${label}-digests.png` })
        }
      })
    }
  }
})

test.describe('the platform readout', () => {
  test.use({ storageState: AUTH.admin })

  for (const theme of ['dark', 'light'] as const) {
    for (const [label, size] of [['desktop', { width: 1280, height: 900 }], ['375', { width: 375, height: 812 }]] as const) {
      test(`the reach strip states members, enrolled and each digest — ${theme}, ${label}`, async ({ page }) => {
        await page.setViewportSize(size)
        await page.addInitScript((t) => { try { localStorage.setItem('campaign-hub-theme', t) } catch { /* private window */ } }, theme)
        await page.goto('/admin/email')
        await expect(page.getByTestId('mail-reach')).toBeVisible({ timeout: 30000 })

        for (const id of ['reach-members', 'reach-verified', 'reach-enrolled', 'reach-digest-on', 'reach-last-daily', 'reach-last-weekly', 'reach-last-monthly']) {
          await expect(page.getByTestId(id)).toBeVisible()
        }
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
        expect(overflow, 'the page scrolls sideways').toBeLessThanOrEqual(0)

        if (process.env.NOTIF_SHOTS) {
          // Centred, so the fixed bottom bar of a phone never covers the strip.
          await page.getByTestId('mail-reach').evaluate((el) => el.scrollIntoView({ block: 'center' }))
          await page.getByTestId('mail-reach').screenshot({ path: `${process.env.NOTIF_SHOTS}/reach-${theme}-${label}.png` })
        }
      })
    }
  }
})
